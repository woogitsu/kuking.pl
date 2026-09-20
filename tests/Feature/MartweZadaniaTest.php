<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoginLinkToken;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * `kuking:martwe-zadania` — komenda, która pokazuje, co stoi w `failed_jobs`,
 * ilu ludzi to dotyczy, i pozwala ŚWIADOMIE odrzucić zadania z martwym
 * żetonem (zdarzenie z 9 września 2026, D-047).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ŁADUNEK JEST PRAWDZIWY, NIE WPISANY RĘCZNIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wiersz w `failed_jobs` powstaje tu tak samo jak na produkcji: prawdziwe
 * powiadomienie idzie na kolejkę `database`, a `queue:work --once --tries=1`
 * bierze je i próbuje wysłać transportem, który odmawia. Dopiero to zapisuje
 * wiersz — i to Laravel go zapisuje, a nie test.
 *
 * To jest tu warunkiem sensu, nie estetyką. JSON „który pewnie tak wygląda"
 * dałby test zielony nad własnym wyobrażeniem o formacie ładunku: gdyby
 * Laravel zmienił kształt `displayName` albo `data.command`, taki test nadal
 * by przechodził, a komenda na produkcji przestałaby rozpoznawać klasę
 * zadania — czyli przestałaby cokolwiek kasować albo, gorzej, zaczęłaby
 * kasować nie to.
 *
 * DWA WYJĄTKI, NAZWANE: wiersz z ładunkiem, którego NIE DA SIĘ odczytać,
 * i wiersz wstawiony wprost w teście `/health`. Pierwszego nie da się
 * poprosić Laravela, żeby wyprodukował, a jest to dokładnie ten wiersz, na
 * którym naiwna komenda się wywala. Drugi jest wstawiony ręcznie, bo tamten
 * test pyta wyłącznie o LICZBĘ wierszy w tabeli i nie chce przy okazji
 * zakładać śladu w `mail_failures`, który zapala osobny alarm (`listy`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ZEGAR JEST PRZYMROŻONY, DATY SĄ STAŁE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Ten plik pyta o WIEK zadań, więc pułapka 9 z `docs/PULAPKI_TESTOW.md`
 * dotyczy go wprost: stała data przy idącym zegarze to bomba z opóźnionym
 * zapłonem. Dlatego zegar stoi (`travelTo`) i obie daty — chwila awarii
 * i „dziś" — są wpisane jawnie. Przesuwanie czasu w teście jest zawsze
 * jawnym `travelTo`, nigdy zależnością od tego, którego dziś jest.
 */
class MartweZadaniaTest extends TestCase
{
    use RefreshDatabase;

    private const KOMENDA = 'kuking:martwe-zadania';

    /** Chwila awarii SMTP z 9 września 2026 (D-047). */
    private const AWARIA = '2026-09-09 14:05:00';

    /** „Dziś" — trzy dni później. Żeton resetu hasła (60 min) jest martwy od dawna. */
    private const DZIS = '2026-09-12 09:00:00';

    /**
     * Znacznik w komunikacie transportu, a więc i w kolumnie `exception`
     * każdego wiersza, który powstanie w tym pliku. Żaden test nie ma prawa
     * zobaczyć go w wyjściu komendy.
     */
    private const SLAD_W_WYJATKU = 'SLAD-STOSU-DO-TESTU-7f21';

    protected function setUp(): void
    {
        parent::setUp();

        // Znacznik wchodzi do transportu PRZEZ KONSTRUKTOR, a nie stałą klasy
        // testu — klasa anonimowa jest osobną klasą i sięganie z niej po
        // prywatną stałą z zewnątrz byłoby sięganiem przez płot.
        Mail::extend('odmawia', fn (array $config): AbstractTransport => new class(self::SLAD_W_WYJATKU) extends AbstractTransport
        {
            public function __construct(private readonly string $slad)
            {
                parent::__construct();
            }

            public function __toString(): string
            {
                return 'odmawia://';
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Dostawca nie przyjął wiadomości ('.$this->slad.').');
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Dostawca nie przyjął wiadomości ('.$this->slad.').');
            }
        });

        config([
            'queue.default' => 'database',
            'mail.default' => 'odmawia',
            'mail.mailers.odmawia' => ['transport' => 'odmawia'],
        ]);

        $this->travelTo(Carbon::parse(self::DZIS, 'UTC'));
    }

    // ------------------------------------------------------------------
    //  1. CO STOI W KOLEJCE: kiedy, jakie zadanie, ilu LUDZI
    // ------------------------------------------------------------------

    public function test_pusta_tabela_mowi_o_tym_wprost_zamiast_wypisac_nic(): void
    {
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Test zaczyna od pustej tabeli.');

        $wyjscie = $this->uruchom();

        // Nie `assertNotEmpty` — cisza po pustej tabeli jest właśnie tą
        // pułapką, o którą chodzi (pułapka 2: „zero trafień to dla testu
        // sukces"). Żądamy ZDANIA.
        $this->assertStringContainsString('jest PUSTA', $wyjscie);

        // KONTROLA DODATNIA: to samo wywołanie przy NIEPUSTEJ tabeli tego
        // zdania nie drukuje. Bez niej „jest PUSTA" przechodziłoby także
        // wtedy, gdyby komenda drukowała je zawsze (pułapka 4).
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        $this->assertStringNotContainsString('jest PUSTA', $this->uruchom());
    }

    /**
     * Sedno punktu 1: LUDZIE, nie wiersze. Maria kliknęła „Nie pamiętam
     * hasła" trzy razy, Janusz raz — w tabeli są cztery wiersze, ale czekają
     * DWIE osoby. Komenda, która wypisze „cztery", każe właścicielowi szukać
     * czterech ludzi.
     */
    public function test_pokazuje_kiedy_jakie_zadanie_i_ilu_ludzi_a_nie_ile_wierszy(): void
    {
        $maria = $this->konto('maria@przyklad.pl', 'Maria');
        $janusz = $this->konto('janusz@przyklad.pl', 'Janusz');

        $this->wAwarii(function () use ($maria, $janusz): void {
            $this->nieudanyListHasla($maria);
            $this->nieudanyListHasla($maria);
            $this->nieudanyListHasla($maria);
            $this->nieudanyListHasla($janusz);
        });

        $this->assertSame(4, DB::table('failed_jobs')->count());

        $wyjscie = $this->uruchom();

        // KIEDY — data ORAZ godzina, nie sama data.
        $this->assertStringContainsString('2026-09-09 14:05', $wyjscie);

        // JAKIE ZADANIE — klasa.
        $this->assertStringContainsString('UstawienieNowegoHasla', $wyjscie);

        // ILU LUDZI — i to jest ta liczba, która ma się różnić od liczby wierszy.
        $this->assertStringContainsString('Wierszy w `failed_jobs`: 4', $wyjscie);
        $this->assertStringContainsString('różnych osób: 2', $wyjscie);

        // KONTROLA DODATNIA do asercji wyżej: gdy dołoży się trzecia osoba,
        // liczba osób rośnie, a nie stoi. Bez tego „różnych osób: 2"
        // przechodziłoby także wtedy, gdyby komenda drukowała stałą.
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('zofia@przyklad.pl', 'Zofia')));

        $drugie = $this->uruchom();

        $this->assertStringContainsString('Wierszy w `failed_jobs`: 5', $drugie);
        $this->assertStringContainsString('różnych osób: 3', $drugie);
    }

    // ------------------------------------------------------------------
    //  2. NA SUCHO JEST TRYBEM DOMYŚLNYM
    // ------------------------------------------------------------------

    /**
     * Para asercji, nie jedna: „bez opcji nic nie zniknęło" przechodziłoby
     * także wtedy, gdyby komenda nie umiała skasować NICZEGO. Dopiero drugi
     * przebieg — ten z `--skasuj` — dowodzi, że było co kasować.
     */
    public function test_bez_opcji_nie_kasuje_nic_a_kasuje_dopiero_na_jawny_przelacznik(): void
    {
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        $przed = $this->odciskTabeli();

        $naSucho = $this->uruchom();

        $this->assertSame($przed, $this->odciskTabeli(), 'Bez przełącznika komenda nie ma prawa niczego skasować.');
        $this->assertNotSame([], $przed, 'Kontrola dodatnia: było co kasować.');
        $this->assertStringContainsString('TRYB NA SUCHO', $naSucho);
        $this->assertStringContainsString('--skasuj', $naSucho);

        // Jawny `--na-sucho` robi dokładnie to samo co brak opcji.
        $this->uruchom(['--na-sucho' => true]);
        $this->assertSame($przed, $this->odciskTabeli());

        // I DOPIERO TERAZ — kontrola dodatnia całego mechanizmu.
        $skasowane = $this->uruchom(['--skasuj' => true]);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertStringContainsString('Skasowanych wierszy: 1', $skasowane);
    }

    public function test_skasuj_razem_z_na_sucho_nie_kasuje_i_mowi_dlaczego(): void
    {
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        $przed = $this->odciskTabeli();

        $wyjscie = $this->uruchom(['--skasuj' => true, '--na-sucho' => true]);

        $this->assertSame($przed, $this->odciskTabeli(), 'Przy dwóch sprzecznych intencjach wygrywa ta odwracalna.');
        $this->assertStringContainsString('NIC nie zostało skasowane', $wyjscie);

        // Kontrola dodatnia: sam `--skasuj` kasuje, więc asercja wyżej nie
        // przeszła nad komendą, która nie kasuje nigdy.
        $this->uruchom(['--skasuj' => true]);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    /**
     * Próg jest WŁASNY dla każdego żetonu i czytany z konfiguracji.
     * 45 minut po awarii żeton logowania linkiem (30 min,
     * `config('kuking.login_link.waznosc_minut')`) jest już martwy,
     * a żeton resetu hasła (60 min, `config/auth.php`) — jeszcze żywy.
     * Jeden wspólny próg wpisany w kod pomyliłby się na jednym z nich.
     */
    public function test_prog_jest_wlasny_dla_kazdego_zetonu_i_zywego_nie_rusza(): void
    {
        $maria = $this->konto('maria@przyklad.pl', 'Maria');

        $this->wAwarii(function () use ($maria): void {
            $this->nieudanyListHasla($maria);
            $this->nieudanyLinkDoLogowania($maria);
        });

        // 45 minut po awarii: link do logowania jest martwy, reset hasła nie.
        $this->travelTo(Carbon::parse(self::AWARIA, 'UTC')->addMinutes(45));

        $this->uruchom(['--skasuj' => true]);

        $zostalo = DB::table('failed_jobs')->pluck('payload')
            ->map(fn (string $payload): string => (string) (json_decode($payload, true)['displayName'] ?? '?'))
            ->all();

        // PARA: martwy zniknął, żywy został. Sama asercja „LinkDoLogowania
        // zniknął" przeszłaby także wtedy, gdyby komenda kasowała wszystko.
        $this->assertSame([UstawienieNowegoHasla::class], $zostalo);
    }

    /**
     * Zadanie spoza listy żetonów zostaje NIETKNIĘTE, choćby było najstarsze
     * w tabeli. Tam ponowienie nadal ma sens, a `queue:flush` kasujące
     * wszystko bez patrzenia jest dokładnie tym, czego ta komenda ma nie być.
     */
    public function test_zadania_bez_zetonu_zostaja_nietkniete(): void
    {
        $this->wAwarii(function (): void {
            $this->nieudaneZadanieSpozaPoczty();
            $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria'));
        });

        $this->assertSame(2, DB::table('failed_jobs')->count());

        $wyjscie = $this->uruchom(['--skasuj' => true]);

        // PARA: list z martwym żetonem zniknął, zadanie bez żetonu zostało.
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringNotContainsString(
            UstawienieNowegoHasla::class,
            (string) DB::table('failed_jobs')->value('payload'),
        );

        // I komenda mówi wprost, że `/health` NADAL będzie na `degraded` —
        // cisza w tym miejscu kazałaby właścicielowi szukać usterki.
        $this->assertStringContainsString('NADAL będzie mówił `degraded`', $wyjscie);
    }

    public function test_nieczytelny_wiersz_nie_przewraca_komendy_i_nie_wchodzi_do_kasowania(): void
    {
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        // Wiersz, którego nie da się odczytać — wstawiony PO tym czytelnym.
        // Naiwna komenda wywali się na nim i nigdy nie dojdzie do Marii.
        DB::table('failed_jobs')->insert([
            'uuid' => 'e2f1a0d4-0000-4000-8000-000000000009',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'to nie jest JSON',
            'exception' => 'RuntimeException: cokolwiek',
            'failed_at' => Carbon::parse(self::AWARIA, 'UTC'),
        ]);

        $wyjscie = $this->uruchom(['--skasuj' => true]);

        // 1. Komenda doszła do końca i skasowała list Marii…
        $this->assertStringContainsString('Skasowanych wierszy: 1', $wyjscie);

        // 2. …a wiersza, którego nie rozumie, NIE RUSZYŁA. Kasowanie czegoś,
        //    o czym nie wiadomo, czym jest, byłoby kasowaniem w ciemno.
        $this->assertSame(
            ['e2f1a0d4-0000-4000-8000-000000000009'],
            DB::table('failed_jobs')->pluck('uuid')->all(),
        );

        // 3. I powiedziała, że go nie umie odczytać, zamiast przemilczeć.
        $this->assertStringContainsString('nie umiem odczytać', $wyjscie);
    }

    // ------------------------------------------------------------------
    //  3. ŻETON NIE WYCHODZI NA EKRAN — TEST OSOBNY, JAK KAŻE ZADANIE
    // ------------------------------------------------------------------

    /**
     * W `failed_jobs.payload` żeton leży w JAWNEJ POSTACI, a w `exception`
     * bywa ślad stosu z argumentami wywołań. Kto ma żeton resetu, ustawia
     * komuś hasło; kto ma żeton logowania linkiem, wchodzi na konto od razu.
     *
     * KONTROLA DODATNIA JEST TU WARUNKIEM SENSU (pułapka 4): bez asercji,
     * że żeton NAPRAWDĘ jest w ładunku, „nie ma żetonu na ekranie"
     * przechodziłoby także wtedy, gdyby żetonu nie było nigdzie.
     */
    public function test_zeton_nie_pojawia_sie_na_ekranie_w_zadnej_galezi(): void
    {
        $zetonHasla = 'ZETON-RESETU-DO-TESTU-9f3a1c';
        $zetonLinku = str_repeat('L', 64);
        $zetonWNieczytelnym = 'ZETON-W-NIECZYTELNYM-WIERSZU-77b2';

        $maria = $this->konto('maria@przyklad.pl', 'Maria');

        $this->wAwarii(function () use ($maria, $zetonHasla, $zetonLinku, $zetonWNieczytelnym): void {
            $this->nieudanyListHasla($maria, $zetonHasla);
            $this->nieudanyLinkDoLogowania($maria, $zetonLinku);

            // Najgroźniejsza gałąź: „nie wiem, co to jest, więc wypiszę, co
            // mam". Ładunek jest poprawnym JSON-em, ale bez `displayName`,
            // więc komenda nie ma jak go zrozumieć — a żeton w nim siedzi.
            DB::table('failed_jobs')->insert([
                'uuid' => 'e2f1a0d4-0000-4000-8000-000000000010',
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['cos' => 'nieznanego', 'token' => $zetonWNieczytelnym]),
                'exception' => 'RuntimeException: '.self::SLAD_W_WYJATKU,
                'failed_at' => now(),
            ]);
        });

        // KONTROLA DODATNIA — bez niej cały ten test jest zielony nad niczym.
        $ladunki = implode("\n", DB::table('failed_jobs')->pluck('payload')->all());
        $wyjatki = implode("\n", DB::table('failed_jobs')->pluck('exception')->all());

        $this->assertStringContainsString($zetonHasla, $ladunki, 'Żeton resetu miał być w ładunku.');
        $this->assertStringContainsString($zetonLinku, $ladunki, 'Żeton logowania miał być w ładunku.');
        $this->assertStringContainsString($zetonWNieczytelnym, $ladunki, 'Żeton miał być w nieczytelnym wierszu.');
        $this->assertStringContainsString(self::SLAD_W_WYJATKU, $wyjatki, 'Ślad stosu miał być w kolumnie `exception`.');

        // Wszystkie kombinacje przełączników, KASUJĄCA NA KOŃCU — żeby
        // przebiegi przed nią miały jeszcze co pokazywać.
        $przebiegi = [
            [],
            ['--na-sucho' => true],
            ['--ile' => 1],
            ['--skasuj' => true, '--na-sucho' => true],
            ['--skasuj' => true],
        ];

        foreach ($przebiegi as $opcje) {
            $wyjscie = $this->uruchom($opcje);

            $opis = 'Przebieg: '.json_encode($opcje);

            $this->assertStringNotContainsString($zetonHasla, $wyjscie, $opis);
            $this->assertStringNotContainsString($zetonLinku, $wyjscie, $opis);
            $this->assertStringNotContainsString($zetonWNieczytelnym, $wyjscie, $opis);

            // Kolumna `exception` też nie ma prawa wyjść — ani wprost, ani
            // przez komunikat wyjątku.
            $this->assertStringNotContainsString(self::SLAD_W_WYJATKU, $wyjscie, $opis);

            // I dowód, że przebieg NIE BYŁ pusty: komenda coś wypisała.
            // Bez tego „nie ma żetonu" przechodziłoby nad pustym ekranem.
            $this->assertStringContainsString('failed_jobs', $wyjscie, $opis);
        }
    }

    // ------------------------------------------------------------------
    //  4. /health — para asercji: degraded, gdy coś stoi; zdrowy, gdy pusto
    // ------------------------------------------------------------------

    /**
     * Sama asercja „nie ma degraded" przechodzi także wtedy, gdy sprawdzenie
     * `kolejka` nie działa WCALE (pułapka 4). Dlatego obie połowy są tutaj,
     * w jednym teście: najpierw zielono przy pustej tabeli, potem `degraded`
     * po wstawieniu wiersza, potem znowu zielono po jego usunięciu.
     */
    public function test_health_jest_degraded_gdy_cos_stoi_w_nieudanych_i_zdrowieje_gdy_tabela_pusta(): void
    {
        Artisan::call('storage:link');

        // KONTROLA DODATNIA (pusto → zdrowo).
        $this->assertZdrowie('ok', true);

        // Wiersz wstawiony wprost: ten test pyta WYŁĄCZNIE o liczbę wierszy
        // w `failed_jobs` i nie chce przy okazji zakładać śladu
        // w `mail_failures`, który zapala osobny alarm (`checks.listy`).
        DB::table('failed_jobs')->insert([
            'uuid' => 'e2f1a0d4-0000-4000-8000-000000000011',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: cokolwiek',
            'failed_at' => Carbon::parse(self::AWARIA, 'UTC'),
        ]);

        // KONTROLA UJEMNA (coś stoi → degraded).
        $this->assertZdrowie('degraded', false);

        DB::table('failed_jobs')->where('uuid', 'e2f1a0d4-0000-4000-8000-000000000011')->delete();

        // I POWRÓT: pusta tabela znowu gasi alarm.
        $this->assertZdrowie('ok', true);
    }

    /**
     * To samo, ale drogą, którą naprawdę przejdzie właściciel: cztery martwe
     * listy „Ustaw nowe hasło" z 9 września, `kuking:martwe-zadania --skasuj`
     * i `/health` schodzący z `degraded`.
     *
     * `kuking:nieudane-listy --odhacz` jest tu potrzebne, bo prawdziwa
     * nieudana wysyłka zostawia DWA ślady: wiersz w `failed_jobs` (alarm
     * `kolejka`) i wiersz w `mail_failures` (alarm `listy`). To są dwa różne
     * alarmy o dwóch różnych rzeczach i żaden nie gasi drugiego — ten test
     * pokazuje, ile trzeba zrobić, żeby `/health` naprawdę zzieleniał.
     */
    public function test_po_skasowaniu_martwych_zadan_health_schodzi_z_degraded(): void
    {
        Artisan::call('storage:link');

        $maria = $this->konto('maria@przyklad.pl', 'Maria');
        $janusz = $this->konto('janusz@przyklad.pl', 'Janusz');

        $this->wAwarii(function () use ($maria, $janusz): void {
            $this->nieudanyListHasla($maria);
            $this->nieudanyListHasla($maria);
            $this->nieudanyListHasla($maria);
            $this->nieudanyListHasla($janusz);
        });

        $this->assertSame(4, DB::table('failed_jobs')->count());
        $this->assertZdrowie('degraded', false);

        // Na sucho NIC się nie zmienia — także w `/health`.
        $this->uruchom();
        $this->assertSame(4, DB::table('failed_jobs')->count());
        $this->assertZdrowie('degraded', false);

        $this->uruchom(['--skasuj' => true]);
        Artisan::call('kuking:nieudane-listy', ['--odhacz' => true]);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertZdrowie('ok', true);
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    private function assertZdrowie(string $status, bool $kolejkaOk): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('checks.kolejka.ok', $kolejkaOk)
            ->assertJsonPath('status', $status);
    }

    /**
     * Wykonuje `$co` w chwili awarii z 9 września i wraca do „dziś".
     * Zegar stoi przez cały czas — patrz nagłówek klasy.
     */
    private function wAwarii(callable $co): void
    {
        $this->travelTo(Carbon::parse(self::AWARIA, 'UTC'));

        $co();

        $this->travelTo(Carbon::parse(self::DZIS, 'UTC'));
    }

    private function konto(string $adres, string $nazwa): User
    {
        $uzytkownik = User::factory()->create(['email' => $adres]);

        Profile::query()->updateOrCreate(
            ['user_id' => $uzytkownik->getKey()],
            ['username' => 'kucharz'.substr(md5($adres), 0, 8), 'display_name' => $nazwa],
        );

        return $uzytkownik;
    }

    /** Prawdziwy nieudany list „Ustaw nowe hasło" — przez kolejkę i workera. */
    private function nieudanyListHasla(User $uzytkownik, string $zeton = 'zeton-do-testu'): void
    {
        // Token ma istnie?, ?eby awaria dotyczy?a transportu, a nie stra?nika wa?no?ci.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $uzytkownik->email],
            ['token' => Hash::make($zeton), 'created_at' => now()],
        );

        $uzytkownik->notify(new UstawienieNowegoHasla($zeton));

        $this->przepracujJedno();
    }

    /** Prawdziwy nieudany list z linkiem do zalogowania (żeton ważny 30 min). */
    private function nieudanyLinkDoLogowania(User $uzytkownik, ?string $zeton = null): void
    {
        // Worker pomija nieaktualny token (#889). Awaria transportu wymaga
        // prawdziwego, ważnego wiersza, nie tylko dowolnego tekstu w liście.
        $zeton ??= LoginLinkToken::nowyToken();
        $row = new LoginLinkToken;
        $row->user_id = $uzytkownik->getKey();
        $row->token_hash = LoginLinkToken::skrot($zeton);
        $row->created_at = now();
        $row->expires_at = now()->addMinutes(30);
        $row->save();
        $uzytkownik->notify(new LinkDoLogowania($zeton, $row->expires_at));

        $this->przepracujJedno();
    }

    /**
     * Nieudane zadanie, które NIE JEST wysyłką listu — żeby dało się sprawdzić
     * filtr klas na czymś, co Laravel naprawdę zapisał.
     */
    private function nieudaneZadanieSpozaPoczty(): void
    {
        dispatch(function (): void {
            throw new RuntimeException('To zadanie nie jest listem.');
        });

        $this->przepracujJedno();
    }

    private function przepracujJedno(): void
    {
        $przed = DB::table('failed_jobs')->count();

        Artisan::call('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--tries' => 1,
        ]);

        $this->assertSame(
            $przed + 1,
            DB::table('failed_jobs')->count(),
            'Worker miał odłożyć dokładnie jeden wiersz do `failed_jobs`.',
        );
    }

    /** @return list<string> */
    private function odciskTabeli(): array
    {
        return DB::table('failed_jobs')->orderBy('id')->get()->map(
            fn (object $w): string => md5((string) $w->uuid.(string) $w->payload.(string) $w->exception),
        )->all();
    }

    /**
     * @param  array<string, bool|int>  $opcje
     */
    private function uruchom(array $opcje = []): string
    {
        Artisan::call(self::KOMENDA, $opcje);

        return Artisan::output();
    }
}

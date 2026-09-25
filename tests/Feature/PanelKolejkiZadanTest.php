<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\PolecenieZadania;
use App\Models\Profile;
use App\Models\User;
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
 * `/admin/kolejka` — jedyna droga do odpowiedzi „KTÓRE zadanie padło"
 * dostępna BEZ powłoki serwera i bez dostępu do bazy (issue #599).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ŁADUNEK JEST PRAWDZIWY, NIE WPISANY RĘCZNIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wiersze w `failed_jobs` powstają tu tak samo jak na produkcji: prawdziwe
 * powiadomienie idzie na kolejkę `database`, a `queue:work --once --tries=1`
 * bierze je i próbuje wysłać transportem, który odmawia. Ten sam powód co
 * w `MartweZadaniaTest`: JSON „który pewnie tak wygląda" dałby test zielony
 * nad własnym wyobrażeniem o formacie `displayName`, a ekran na produkcji
 * pokazywałby `?` przy każdym wierszu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  KONTROLE DODATNIE, BO INACZEJ POŁOWA TEGO PLIKU JEST NO-OPEM
 * ────────────────────────────────────────────────────────────────────────
 *
 * Asercje „żetonu nie ma na ekranie" i „śladu stosu nie ma na ekranie"
 * przechodziłyby także wtedy, gdyby żetonu nie było nigdzie — w ładunku,
 * w bazie, w niczym (pułapka 4 z `docs/PULAPKI_TESTOW.md`). Dlatego każda
 * z nich ma parę: najpierw `assertStringContainsString` NA SUROWYM WIERSZU
 * z bazy, dopiero potem `assertDontSee` na odpowiedzi HTTP.
 */
class PanelKolejkiZadanTest extends TestCase
{
    use RefreshDatabase;

    /** Chwila awarii SMTP — ta sama data co w zdarzeniu z 9 września 2026. */
    private const AWARIA = '2026-09-09 14:05:00';

    /** „Dziś" — trzy dni później, czyli poza oknem czujki `StanKolejki`. */
    private const DZIS = '2026-09-12 09:00:00';

    /** Znacznik wchodzący do komunikatu wyjątku, a więc i do kolumny `exception`. */
    private const SLAD_W_WYJATKU = 'SLAD-STOSU-DO-TESTU-599a';

    /** Żeton wkładany w powiadomienie — ląduje w `payload` W JAWNEJ POSTACI. */
    private const ZETON = 'ZETON-DO-TESTU-599b';

    protected function setUp(): void
    {
        parent::setUp();

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
    //  1. ADMIN DOSTAJE ODPOWIEDŹ, KTÓREJ `/health` NIE DAJE
    // ------------------------------------------------------------------

    public function test_admin_widzi_klase_zadania_klase_wyjatku_liczbe_i_czas(): void
    {
        $maria = $this->konto('maria@przyklad.pl', 'Maria');

        $this->wAwarii(function () use ($maria): void {
            $this->nieudanyListHasla($maria);
            $this->nieudanyListHasla($maria);
        });

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();

        // JAKIE ZADANIE.
        $odpowiedz->assertSee('UstawienieNowegoHasla');

        // CO JE PRZEWRÓCIŁO — sama nazwa klasy wyjątku.
        $odpowiedz->assertSee('TransportException');

        // ILE i KIEDY.
        $odpowiedz->assertSee('2 zadania');
        $odpowiedz->assertSee('9.09.2026');
    }

    /**
     * Regresja z przeglądu PR #725: daty szły na ekran w UTC. Awaria z 14:05
     * UTC to w Polsce 16:05 (czas letni) — i tak ma ją zobaczyć człowiek,
     * który porównuje ekran z własnym zegarkiem i z `/health`.
     */
    public function test_daty_sa_w_strefie_polskiej_a_nie_w_utc(): void
    {
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('9.09.2026, 16:05');
        $odpowiedz->assertDontSee('9.09.2026, 14:05');
    }

    /**
     * Regresja z przeglądu PR #725: odmiana liczyła „< 5 → zadania", więc
     * przy 22 wychodziło „22 zadań" zamiast „22 zadania". Dwadzieścia dwa
     * prawdziwe wierszy powstaje przez skopiowanie jednego prawdziwego —
     * ładunek dalej pochodzi z workera, nie z wyobrażenia o formacie.
     */
    public function test_liczba_zadan_jest_odmieniona_po_polsku(): void
    {
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        DB::statement(
            'insert into failed_jobs (uuid, connection, queue, payload, exception, failed_at)
             select gen_random_uuid()::text, connection, queue, payload, exception, failed_at
             from failed_jobs cross join generate_series(1, 21)',
        );
        $this->assertSame(22, DB::table('failed_jobs')->count());

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('UstawienieNowegoHasla — 22 zadania');
        $odpowiedz->assertDontSee('22 zadań');
    }

    /**
     * Regresja z przeglądu PR #725: zaległość szła jako „900 sekund", czyli
     * liczba, którą człowiek musi dopiero przeliczyć w głowie.
     */
    public function test_zaleglosc_jest_podana_w_minutach(): void
    {
        config(['kuking.kolejka.prog_zaleglosci_sekundy' => 600]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Jobs\\\\Cokolwiek"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp() - 900,
            'created_at' => now()->getTimestamp() - 900,
        ]);

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('czeka 15 minut');
        $odpowiedz->assertSee('próg: 10 min');
        $odpowiedz->assertDontSee('900 sekund');
    }

    /**
     * KONTROLA UJEMNA do testu wyżej: ekran nie drukuje stałej. Gdy w tabeli
     * stoi INNE zadanie, na ekranie jest inna nazwa — a poprzedniej nie ma.
     */
    public function test_ekran_pokazuje_to_co_naprawde_lezy_a_nie_staly_napis(): void
    {
        $this->wAwarii(fn () => $this->nieudaneZadanieSpozaPoczty());

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('RuntimeException');
        $odpowiedz->assertDontSee('UstawienieNowegoHasla');
    }

    /**
     * Pusta tabela dostaje ZDANIE, nie ciszę — pułapka 2 z
     * `docs/PULAPKI_TESTOW.md` („zero trafień to dla testu sukces").
     */
    public function test_pusta_tabela_mowi_o_tym_wprost(): void
    {
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->actingAs($this->admin())->get('/admin/kolejka')->assertOk()->assertSee('Tabela jest pusta');
    }

    // ------------------------------------------------------------------
    //  2. CZEGO NA TYM EKRANIE NIE MA — Z KONTROLĄ DODATNIĄ
    // ------------------------------------------------------------------

    public function test_zeton_z_ladunku_nie_wychodzi_na_ekran(): void
    {
        $maria = $this->konto('maria@przyklad.pl', 'Maria');

        $this->wAwarii(fn () => $this->nieudanyListHasla($maria));

        // KONTROLA DODATNIA: żeton NAPRAWDĘ leży w ładunku. Bez tej asercji
        // `assertDontSee` niżej przechodziłby nad pustym miejscem.
        // Od audytu A5-10 ładunek jest zaszyfrowany, więc żeton szukamy
        // w nim PO odszyfrowaniu — tam, skąd dałoby się go wynieść.
        $surowy = (string) DB::table('failed_jobs')->value('payload');
        $polecenie = (string) (json_decode($surowy, true)['data']['command'] ?? '');
        $this->assertStringContainsString(self::ZETON, PolecenieZadania::zserializowane($polecenie), 'Żeton miał być w ładunku — inaczej test niżej niczego nie mierzy.');

        $this->actingAs($this->admin())->get('/admin/kolejka')->assertOk()->assertDontSee(self::ZETON);
    }

    public function test_slad_stosu_i_adres_e_mail_nie_wychodza_na_ekran(): void
    {
        $maria = $this->konto('maria@przyklad.pl', 'Maria');

        $this->wAwarii(fn () => $this->nieudanyListHasla($maria));

        // KONTROLA DODATNIA dla śladu stosu.
        $wyjatek = (string) DB::table('failed_jobs')->value('exception');
        $this->assertStringContainsString(self::SLAD_W_WYJATKU, $wyjatek, 'Znacznik miał być w `exception`.');

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee(self::SLAD_W_WYJATKU);
        $odpowiedz->assertDontSee('maria@przyklad.pl');
        $odpowiedz->assertDontSee('Maria');
    }

    /**
     * Wiersz, którego ładunku nie da się odczytać, ma zostać POLICZONY
     * i nazwany — „nie wiem, co to jest" jest informacją, a cisza byłaby
     * gorsza. I ani jeden znak surowego ładunku nie ma prawa trafić na ekran
     * właśnie w tej gałęzi: „nie wiem, więc wypiszę, co mam" jest odruchem,
     * który wynosi sekret (`MartweZadania`, punkt 1 w nagłówku klasy).
     */
    public function test_wiersz_nieczytelny_jest_policzony_ale_jego_ladunek_nie_jest_cytowany(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'e5a5a0e0-0000-4000-8000-000000000599',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'to-nie-jest-json-'.self::ZETON,
            'exception' => 'bez dwukropka i bez klasy '.self::SLAD_W_WYJATKU,
            'failed_at' => self::AWARIA,
        ]);

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('Wierszy razem:');
        $odpowiedz->assertDontSee(self::ZETON);
        $odpowiedz->assertDontSee(self::SLAD_W_WYJATKU);
    }

    /**
     * KOLEJKA jest na ekranie — ale tylko wtedy, gdy ma kształt nazwy.
     * Kontrola dodatnia (`poczta` widać) i ujemna (adres e-mail wpisany
     * w kolumnę `queue` nie wychodzi, zamiast niego jest `?`).
     */
    public function test_nazwa_kolejki_jest_widoczna_a_obcy_tekst_w_kolumnie_nie(): void
    {
        $wiersz = fn (string $uuid, string $kolejka): array => [
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $kolejka,
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessUploadedImage']),
            'exception' => 'RuntimeException: '.self::SLAD_W_WYJATKU,
            'failed_at' => self::AWARIA,
        ];

        DB::table('failed_jobs')->insert([
            $wiersz('e5a5a0e0-0000-4000-8000-000000000001', 'poczta'),
            $wiersz('e5a5a0e0-0000-4000-8000-000000000002', 'maria@przyklad.pl ma zly adres'),
        ]);

        $odpowiedz = $this->actingAs($this->admin())->get('/admin/kolejka');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('<code>poczta</code>', false);
        $odpowiedz->assertSee('<code>?</code>', false);
        $odpowiedz->assertDontSee('maria@przyklad.pl');
        $odpowiedz->assertDontSee(self::SLAD_W_WYJATKU);
    }

    // ------------------------------------------------------------------
    //  3. KTO TU NIE WCHODZI
    // ------------------------------------------------------------------

    /**
     * Moderator z potwierdzonym 2FA przechodzi przez `auth`, `moderator`
     * i `moderator.2fa` — i dopiero Policy go zatrzymuje. To jest ten test,
     * który odróżnia „bramkę na rolę" od „bramki na wejście do panelu".
     */
    public function test_moderator_bez_roli_admin_dostaje_odmowe(): void
    {
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        $this->actingAs($this->moderator())->get('/admin/kolejka')->assertForbidden();
    }

    /**
     * KONTROLA DODATNIA do testu wyżej — para, nie pojedyncza asercja.
     * Samo „moderator dostaje 403" przechodziłoby także wtedy, gdyby trasa
     * nie działała dla NIKOGO (np. była literówką w nazwie widoku).
     */
    public function test_ta_sama_trasa_dziala_dla_admina(): void
    {
        $this->wAwarii(fn () => $this->nieudanyListHasla($this->konto('maria@przyklad.pl', 'Maria')));

        $this->actingAs($this->admin())->get('/admin/kolejka')->assertOk();
    }

    public function test_zwykly_uzytkownik_nie_dostaje_potwierdzenia_ze_ekran_istnieje(): void
    {
        $this->actingAs($this->user())->get('/admin/kolejka')->assertNotFound();
    }

    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        $this->get('/admin/kolejka')->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------
    //  4. POZYCJA W MENU IDZIE ZA ROLĄ, NIE ZA WEJŚCIEM DO PANELU
    // ------------------------------------------------------------------

    public function test_pozycja_w_menu_jest_dla_admina_a_nie_dla_moderatora(): void
    {
        $this->actingAs($this->admin())->get('/admin/zgloszenia')->assertOk()->assertSee('Kolejka zadań');

        $this->actingAs($this->moderator())->get('/admin/zgloszenia')->assertOk()->assertDontSee('Kolejka zadań');
    }

    // ------------------------------------------------------------------
    //  POMOCNICZE
    // ------------------------------------------------------------------

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

    private function nieudanyListHasla(User $uzytkownik): void
    {
        // Żeton musi naprawdę istnieć w brokerze haseł: od #889
        // `UstawienieNowegoHasla::shouldSend()` pomija list z żetonem
        // nieznanym, zużytym albo wygasłym. Bez tego wiersza worker kończy
        // zadanie „sukcesem" bez wysyłki i `failed_jobs` zostaje puste —
        // test mierzyłby wtedy pominięcie, nie awarię SMTP.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $uzytkownik->getEmailForPasswordReset()],
            ['token' => Hash::make(self::ZETON), 'created_at' => now()],
        );

        $uzytkownik->notify(new UstawienieNowegoHasla(self::ZETON));

        $this->przepracujJedno();
    }

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
}

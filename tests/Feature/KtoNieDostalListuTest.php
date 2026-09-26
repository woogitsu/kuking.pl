<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\PolecenieZadania;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * `kuking:kto-nie-dostal-listu` — komenda, która mówi, do kogo list nie
 * doszedł (zdarzenie z 9 września 2026,
 * `docs/infra/ZDARZENIE_2026-09-09_NIEWYSLANE_HASLA.md`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ŁADUNEK JEST PRAWDZIWY, NIE WPISANY RĘCZNIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wiersz w `failed_jobs` powstaje tu tak samo jak na produkcji: prawdziwe
 * powiadomienie `UstawienieNowegoHasla` idzie na kolejkę `database`,
 * a `queue:work --once --tries=1` bierze je i próbuje wysłać transportem,
 * który odmawia. Dopiero to zapisuje wiersz — i to Laravel go zapisuje,
 * a nie test.
 *
 * To jest tu warunkiem sensu, nie estetyką. JSON „który pewnie tak wygląda"
 * dałby test zielony nad własnym wyobrażeniem o formacie ładunku: gdyby
 * Laravel zmienił kształt `data.command`, taki test nadal by przechodził,
 * a komenda na produkcji przestałaby cokolwiek odczytywać.
 *
 * JEDEN WYJĄTEK, NAZWANY: wiersz z ładunkiem, którego NIE DA SIĘ odczytać,
 * jest wpisany ręcznie (`test_nieczytelny_wiersz_...`). Nie ma sposobu,
 * żeby poprosić Laravela o wyprodukowanie zepsutego ładunku — a to jest
 * dokładnie ten wiersz, który wjeżdża do `failed_jobs` po zmianie nazwy
 * klasy albo po aktualizacji frameworka, i dokładnie ten, na którym naiwna
 * komenda się wywala.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PLIK NIE DOWODZI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie odtwarza awarii z 9 września. Tamta była ZAWIESZENIEM: zadanie
 * wchodziło w `RUNNING` i nie kończyło się nigdy (`App\Poczta\TransportEmailLabs`),
 * więc wiersz w `failed_jobs` powstawał dopiero po timeoucie kontenera.
 * Tutaj transport odmawia od razu. Dla tego, co ten plik sprawdza — czy
 * komenda umie odczytać wiersz i kogo wypisuje — jest to bez znaczenia:
 * wiersz w `failed_jobs` wygląda tak samo, bo powstaje z tego samego ładunku.
 */
class KtoNieDostalListuTest extends TestCase
{
    use RefreshDatabase;

    private const KOMENDA = 'kuking:kto-nie-dostal-listu';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::extend('odmawia', fn (array $config): AbstractTransport => new class extends AbstractTransport
        {
            public function __toString(): string
            {
                return 'odmawia://';
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Dostawca nie przyjął wiadomości.');
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Dostawca nie przyjął wiadomości.');
            }
        });

        config([
            'queue.default' => 'database',
            'mail.default' => 'odmawia',
            'mail.mailers.odmawia' => ['transport' => 'odmawia'],
        ]);
    }

    public function test_pusta_tabela_mowi_o_tym_wprost_zamiast_wypisac_nic(): void
    {
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'Test zaczyna od pustej tabeli.');

        $wyjscie = $this->uruchom();

        // Nie `assertNotEmpty` — cisza po pustej tabeli jest właśnie tą
        // pułapką, o którą chodzi (pułapka 2 z docs/PULAPKI_TESTOW.md:
        // „zero trafień to dla testu sukces"). Żądamy ZDANIA.
        $this->assertStringContainsString('jest PUSTA', $wyjscie);
        $this->assertStringContainsString('failed_jobs', $wyjscie);

        // I kontrola dodatnia do tej asercji: to samo wywołanie przy
        // NIEPUSTEJ tabeli tego zdania nie drukuje. Bez tego „jest PUSTA"
        // przechodziłoby także wtedy, gdyby komenda drukowała je zawsze.
        $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria');
        $this->assertStringNotContainsString('jest PUSTA', $this->uruchom());
    }

    public function test_wypisuje_do_kogo_list_nie_doszedl_i_kiedy(): void
    {
        $uzytkownik = $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria');

        $wyjscie = $this->uruchom();

        $this->assertStringContainsString('UstawienieNowegoHasla', $wyjscie);
        $this->assertStringContainsString('Maria', $wyjscie);
        $this->assertStringContainsString((string) $uzytkownik->getKey(), $wyjscie);
        $this->assertStringContainsString(now()->format('Y-m-d'), $wyjscie);
        $this->assertStringContainsString(
            (string) DB::table('failed_jobs')->value('uuid'),
            $wyjscie,
            'Bez uuid zadania właściciel nie ma jak wskazać konkretnego wiersza.',
        );
    }

    public function test_adres_jest_domyslnie_skrocony_a_pelny_tylko_na_zadanie(): void
    {
        $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria');

        $domyslne = $this->uruchom();

        $this->assertStringContainsString('m***@przyklad.pl', $domyslne);
        $this->assertStringNotContainsString('maria.kowalska', $domyslne);

        // Kontrola dodatnia: gdyby adresu nie było w wyniku wcale, asercja
        // „nie ma pełnego adresu" wyżej przechodziłaby nad niczym (pułapka 4).
        $pelne = $this->uruchom(['--pelne-adresy' => true]);

        $this->assertStringContainsString('maria.kowalska@przyklad.pl', $pelne);
    }

    public function test_token_resetu_hasla_nie_pojawia_sie_na_ekranie(): void
    {
        $token = 'TOKEN-RESETU-DO-TESTU-9f3a1c';

        $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria', $token);

        // KONTROLA DODATNIA — bez niej cały ten test jest zielony nad niczym.
        // Dowodzi, że token NAPRAWDĘ leży w ładunku, a więc że komenda ma co
        // przed nami ukryć.
        // Od audytu A5-10 ładunek jest zaszyfrowany — token leży w nim po
        // odszyfrowaniu, czyli tam, gdzie czyta go komenda.
        $polecenie = (string) (json_decode((string) DB::table('failed_jobs')->value('payload'), true)['data']['command'] ?? '');

        $this->assertStringContainsString(
            $token,
            PolecenieZadania::zserializowane($polecenie),
            'Token miał być w ładunku — jeśli go tam nie ma, asercje niżej nie mierzą niczego.',
        );

        foreach ([[], ['--pelne-adresy' => true], ['--wszystkie' => true]] as $opcje) {
            $this->assertStringNotContainsString($token, $this->uruchom($opcje));
        }
    }

    public function test_token_nie_wychodzi_takze_z_wiersza_ktorego_komenda_nie_umie_odczytac(): void
    {
        // Najgroźniejsza gałąź: „nie wiem, co to jest, więc wypiszę, co mam".
        // Ładunek jest tu poprawnym JSON-em, ale bez `data.commandName`,
        // więc komenda nie ma jak go zrozumieć — a token w nim siedzi.
        $token = 'TOKEN-W-NIECZYTELNYM-WIERSZU-77b2';

        DB::table('failed_jobs')->insert([
            'uuid' => 'e2f1a0d4-0000-4000-8000-000000000001',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Notifications\\StareCos', 'token' => $token]),
            'exception' => 'RuntimeException: cokolwiek',
            'failed_at' => now(),
        ]);

        $wyjscie = $this->uruchom();

        $this->assertStringNotContainsString($token, $wyjscie);
        $this->assertStringContainsString('e2f1a0d4-0000-4000-8000-000000000001', $wyjscie);
    }

    public function test_nieczytelny_wiersz_nie_przewraca_komendy_i_jest_nazwany(): void
    {
        $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria');

        // Wiersz, którego nie da się odczytać — wstawiony PO tym czytelnym,
        // czyli wychodzący na liście PRZED nim (sortujemy malejąco po dacie).
        // Naiwna komenda wywali się na nim i nigdy nie dojdzie do Marii.
        DB::table('failed_jobs')->insert([
            'uuid' => 'e2f1a0d4-0000-4000-8000-000000000002',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'to nie jest JSON',
            'exception' => 'RuntimeException: cokolwiek',
            'failed_at' => now()->addMinute(),
        ]);

        $wyjscie = $this->uruchom();

        // 1. Komenda dochodzi do końca i nadal pokazuje czytelny wiersz.
        $this->assertStringContainsString('Maria', $wyjscie);
        $this->assertStringContainsString('m***@przyklad.pl', $wyjscie);

        // 2. I MÓWI, którego wiersza nie umie odczytać — nie pomija go w ciszy.
        $this->assertStringContainsString('nie umiem odczytać', $wyjscie);
        $this->assertStringContainsString('e2f1a0d4-0000-4000-8000-000000000002', $wyjscie);
    }

    public function test_zadania_spoza_powiadomien_sa_schowane_ale_policzone_i_pokazywalne(): void
    {
        $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria');
        $this->nieudaneZadanieSpozaPoczty();

        $this->assertSame(2, DB::table('failed_jobs')->count());

        $domyslne = $this->uruchom();

        // Filtr jest GŁOŚNY: mówi, ile wierszy schował i czym je pokazać.
        $this->assertStringContainsString('Zadań, które nie są powiadomieniami: 1', $domyslne);
        $this->assertStringContainsString('schowane', $domyslne);
        $this->assertStringContainsString('--wszystkie', $domyslne);
        $this->assertStringContainsString('Wierszy w `failed_jobs`: 2', $domyslne);
        $this->assertStringNotContainsString('to nie jest powiadomienie', $domyslne);

        // A przełącznik naprawdę je pokazuje (kontrola dodatnia do asercji wyżej).
        $wszystkie = $this->uruchom(['--wszystkie' => true]);

        $this->assertStringContainsString('to nie jest powiadomienie', $wszystkie);
        $this->assertStringContainsString('Maria', $wszystkie);
    }

    public function test_komenda_niczego_nie_kasuje_ani_nie_zmienia(): void
    {
        $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria');

        $przed = DB::table('failed_jobs')->orderBy('id')->get()->map(
            fn (object $w): string => md5((string) $w->uuid.(string) $w->payload.(string) $w->exception),
        )->all();

        $this->uruchom();
        $this->uruchom(['--wszystkie' => true, '--pelne-adresy' => true]);

        $po = DB::table('failed_jobs')->orderBy('id')->get()->map(
            fn (object $w): string => md5((string) $w->uuid.(string) $w->payload.(string) $w->exception),
        )->all();

        $this->assertSame($przed, $po, 'Komenda ma tylko czytać — wiersze mają zostać nietknięte.');
        $this->assertNotSame([], $przed, 'Kontrola dodatnia: było co porównywać.');
    }

    public function test_mowi_dlaczego_queue_retry_jest_tu_zla_odpowiedzia(): void
    {
        $this->nieudanyListHasla('maria.kowalska@przyklad.pl', 'Maria');

        $wyjscie = $this->uruchom();

        $this->assertStringContainsString('queue:retry', $wyjscie);
        $this->assertStringContainsString('martwy', $wyjscie);
        $this->assertStringContainsString('Nie pamiętam hasła', $wyjscie);
    }

    /**
     * Prawdziwy nieudany list „Ustaw nowe hasło" — przez kolejkę i workera.
     */
    private function nieudanyListHasla(string $adres, string $nazwa, string $token = 'token-do-testu'): User
    {
        $uzytkownik = User::factory()->create(['email' => $adres]);

        // `UserFactory` zakłada profil sama — nadajemy mu tylko nazwę, którą
        // test potem rozpoznaje w wyniku komendy.
        Profile::query()->updateOrCreate(
            ['user_id' => $uzytkownik->getKey()],
            ['username' => 'maria'.substr(md5($adres), 0, 8), 'display_name' => $nazwa],
        );

        // Token ma istnie?, ?eby awaria dotyczy?a transportu, a nie stra?nika wa?no?ci.
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $uzytkownik->email],
            ['token' => Hash::make($token), 'created_at' => now()],
        );

        $uzytkownik->notify(new UstawienieNowegoHasla($token));

        $this->przepracujJedno();

        return $uzytkownik;
    }

    /**
     * Nieudane zadanie, które NIE JEST wysyłką listu — żeby dało się sprawdzić
     * filtr klas na czymś, co Laravel naprawdę zapisał, a nie na wpisanym
     * ręcznie `commandName`.
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
            // Ta sama lista co worker (`docker/entrypoint.sh`): listy
            // wpuszczające na konto idą na `high` (audyt B8-06).
            '--queue' => 'high,default',
            '--once' => true,
            '--tries' => 1,
        ]);

        $this->assertSame(
            $przed + 1,
            DB::table('failed_jobs')->count(),
            'Worker miał odłożyć dokładnie jeden wiersz do `failed_jobs`.',
        );
    }

    /**
     * @param  array<string, bool>  $opcje
     */
    private function uruchom(array $opcje = []): string
    {
        Artisan::call(self::KOMENDA, $opcje);

        return Artisan::output();
    }
}

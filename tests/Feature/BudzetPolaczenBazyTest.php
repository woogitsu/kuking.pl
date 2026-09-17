<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Polaczenia\AlarmPolaczen;
use App\Domain\Polaczenia\StanPolaczenBazy;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Pula połączeń PostgreSQL ma dać znać, ZANIM się wyczerpie (issue #598).
 *
 * DLACZEGO TO NIE JEST TO SAMO, CO `/health`
 * `/health` odpowiada „baza działa" także wtedy, gdy zostało ostatnie wolne
 * miejsce w puli — bo właśnie je zajął. Wyczerpanie `max_connections` jest
 * awarią skokową: po zajęciu ostatniego miejsca nie łączy się NIKT, łącznie
 * z administratorem, który przyszedł to naprawić. Sygnał musi więc przyjść
 * przy wykorzystaniu, przy którym jeszcze nic nie boli.
 *
 * CZEGO TEN PLIK NIE DOWODZI
 * Że produkcja ma zapas. Zapas produkcji jest stanem produkcji i mierzy go
 * `php artisan kuking:budzet-polaczen` uruchomiony NA produkcji. Tutaj
 * dowodzimy, że mechanizm mierzy prawdziwy serwer, że progi działają w obie
 * strony i że alarm nie wynosi na zewnątrz niczego poza liczbami.
 */
class BudzetPolaczenBazyTest extends TestCase
{
    private const ADRES_WEBHOOKA = 'https://przyklad.test/webhook-polaczen';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.polaczenia.budzet_szczytowy', 16);
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 50);
        config()->set('kuking.polaczenia.prog_krytyczny', 125);
        config()->set('kuking.polaczenia.cisza_godzin', 6);
        config()->set('logging.channels.blad_webhook.url', null);

        Cache::flush();
    }

    private function wlaczKanalAlarmu(): void
    {
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);

        // Kanał jest w Laravelu zapamiętywany po pierwszym użyciu, a inne
        // testy w tej klasie tworzą go z pustym adresem. Bez tego wiersza
        // pamiętany egzemplarz zostałby z `null` i test przechodziłby,
        // nic nie wysyłając.
        Log::forgetChannel('blad_webhook');

        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);
    }

    // -----------------------------------------------------------------
    //  Pomiar na PRAWDZIWYM serwerze — nie na atrapie
    // -----------------------------------------------------------------

    #[Test]
    public function pomiar_czyta_prawdziwe_ustawienia_serwera_a_nie_konfiguracje_aplikacji(): void
    {
        $wynik = app(StanPolaczenBazy::class)->sprawdz();

        $this->assertSame(StanPolaczenBazy::SPOKOJNY, $wynik['stan']);

        // Kontrola DODATNIA: liczby przyszły z serwera, a nie z `config()`.
        $prawdziweMax = (int) DB::selectOne("SELECT setting FROM pg_settings WHERE name = 'max_connections'")->setting;

        $this->assertGreaterThan(0, $prawdziweMax);
        $this->assertSame($prawdziweMax, $wynik['max_connections']);
        $this->assertSame(DB::getDatabaseName(), $wynik['baza']);

        // Test sam trzyma połączenie, więc co najmniej jeden backend MUSI być
        // widoczny. Zero znaczyłoby, że zapytanie liczy coś innego.
        $this->assertGreaterThanOrEqual(1, $wynik['zajete_baza']);
        $this->assertGreaterThanOrEqual($wynik['zajete_baza'], $wynik['zajete_serwer']);
    }

    #[Test]
    public function dostepne_miejsca_to_limit_pomniejszony_o_rezerwy_serwera(): void
    {
        $wynik = app(StanPolaczenBazy::class)->sprawdz();

        $this->assertSame(
            $wynik['max_connections'] - $wynik['rezerwa_superusera'] - $wynik['rezerwa_zwykla'],
            $wynik['dostepne'],
            'Rezerwa superusera NIE jest miejscem dla aplikacji — wliczanie jej zawyżałoby zapas.',
        );
    }

    // -----------------------------------------------------------------
    //  Progi — obie strony
    // -----------------------------------------------------------------

    #[Test]
    public function prog_ostrzegawczy_zapala_sie_gdy_zajetych_jest_wiecej_niz_budzet(): void
    {
        // Próg ustawiony na 1: pojedyncze połączenie tego testu wystarczy,
        // żeby go przekroczyć. To jest jedyny sposób na wywołanie tego stanu
        // bez otwierania setki prawdziwych połączeń do wspólnego serwera.
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);

        $this->assertSame(
            StanPolaczenBazy::OSTRZEZENIE,
            app(StanPolaczenBazy::class)->sprawdz()['stan'],
        );
    }

    #[Test]
    public function prog_krytyczny_zapala_sie_niezaleznie_od_ostrzegawczego(): void
    {
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);
        config()->set('kuking.polaczenia.prog_krytyczny', 1);

        $this->assertSame(
            StanPolaczenBazy::KRYTYCZNY,
            app(StanPolaczenBazy::class)->sprawdz()['stan'],
            'Przy obu progach przekroczonych wygrywa KRYTYCZNY, nie ten sprawdzany wcześniej w kodzie.',
        );
    }

    #[Test]
    public function prog_krytyczny_nigdy_nie_stoi_powyzej_twardego_limitu_serwera(): void
    {
        // Ktoś wpisuje próg krytyczny większy niż `max_connections` — na
        // małym klastrze deweloperskim albo przez pomyłkę w zmiennej.
        // Bez ograniczenia `min(prog, dostepne)` pula wyczerpałaby się,
        // zanim cokolwiek zdążyłoby zaalarmować. Prawdziwego serwera nie da
        // się do tego stanu doprowadzić bez otwarcia setek połączeń, więc
        // liczby podaje tu atrapa — sprawdzamy SAMĄ regułę oceny.
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 10_000);
        config()->set('kuking.polaczenia.prog_krytyczny', 10_000);

        $wynik = app(StanPolaczenBazy::class)->sprawdz(
            $this->atrapaSerwera(maxConnections: 20, rezerwaSuperusera: 3, zajete: 17),
        );

        $this->assertSame(17, $wynik['dostepne']);
        $this->assertSame(
            StanPolaczenBazy::KRYTYCZNY,
            $wynik['stan'],
            'Zajęte WSZYSTKIE dostępne miejsca to stan krytyczny, choćby próg z konfiguracji stał wyżej niż limit serwera.',
        );

        // Kontrola UJEMNA tej samej reguły: jedno miejsce wolne — jeszcze nie
        // krytycznie. Bez tego para asercji pilnowałaby tylko „zawsze czerwono".
        $wolniej = app(StanPolaczenBazy::class)->sprawdz(
            $this->atrapaSerwera(maxConnections: 20, rezerwaSuperusera: 3, zajete: 16),
        );

        $this->assertSame(StanPolaczenBazy::SPOKOJNY, $wolniej['stan']);
    }

    /**
     * Atrapa serwera PostgreSQL o zadanych liczbach. Używana WYŁĄCZNIE tam,
     * gdzie prawdziwego serwera nie da się doprowadzić do badanego stanu bez
     * otwarcia setek połączeń do klastra współdzielonego z innymi testami.
     */
    private function atrapaSerwera(int $maxConnections, int $rezerwaSuperusera, int $zajete): ConnectionInterface
    {
        $polaczenie = Mockery::mock(ConnectionInterface::class);
        $polaczenie->shouldReceive('getDriverName')->andReturn('pgsql');
        $polaczenie->shouldReceive('select')->andReturn([
            (object) ['name' => 'max_connections', 'setting' => (string) $maxConnections],
            (object) ['name' => 'superuser_reserved_connections', 'setting' => (string) $rezerwaSuperusera],
            (object) ['name' => 'reserved_connections', 'setting' => '0'],
        ]);
        $polaczenie->shouldReceive('selectOne')->andReturn((object) [
            'nazwa_bazy' => 'kuking_atrapa',
            'serwer' => $zajete,
            'baza' => $zajete,
            'bezczynne' => 0,
            'aktywne' => $zajete,
            'w_transakcji' => 0,
        ]);

        return $polaczenie;
    }

    #[Test]
    public function stan_spokojny_gdy_progi_sa_domyslne(): void
    {
        $this->assertSame(
            StanPolaczenBazy::SPOKOJNY,
            app(StanPolaczenBazy::class)->sprawdz()['stan'],
            'Baza testowa ma kilka połączeń, a próg ostrzegawczy stoi na 50 — to MUSI być spokój. '
            .'Czerwony wynik tutaj znaczy, że progi są za ciasne i nauczą ignorować alarm.',
        );
    }

    // -----------------------------------------------------------------
    //  Serwer, którego nie da się odpytać
    // -----------------------------------------------------------------

    #[Test]
    public function niedostepny_serwer_daje_stan_niedostepny_i_nie_wynosi_komunikatu_wyjatku(): void
    {
        $polaczenie = Mockery::mock(ConnectionInterface::class);
        $polaczenie->shouldReceive('getDriverName')->andReturn('pgsql');
        $polaczenie->shouldReceive('select')->andThrow(
            new RuntimeException('SQLSTATE[08006] host=tajny-host.internal user=kuking password=sekret'),
        );

        $wynik = app(StanPolaczenBazy::class)->sprawdz($polaczenie);

        $this->assertSame(StanPolaczenBazy::NIEDOSTEPNY, $wynik['stan']);
        $this->assertNull($wynik['max_connections']);

        $tresc = app(AlarmPolaczen::class)->tresc($wynik);

        // Kontrola UJEMNA: DSN z komunikatu sterownika NIE MA PRAWA wyjść
        // na zewnętrzny webhook (ta sama zasada, co w audycie A6-01).
        $this->assertStringNotContainsString('tajny-host.internal', $tresc);
        $this->assertStringNotContainsString('sekret', $tresc);
        $this->assertStringNotContainsString('SQLSTATE', $tresc);
    }

    #[Test]
    public function polaczenie_inne_niz_postgres_nie_udaje_pomiaru(): void
    {
        $polaczenie = Mockery::mock(ConnectionInterface::class);
        $polaczenie->shouldReceive('getDriverName')->andReturn('sqlite');

        $wynik = app(StanPolaczenBazy::class)->sprawdz($polaczenie);

        $this->assertSame(StanPolaczenBazy::NIEOBSLUGIWANY, $wynik['stan']);
        $this->assertNull($wynik['zajete_serwer'], 'Brak pomiaru to null, nie zero. Zero znaczyłoby „zmierzone i puste".');
    }

    // -----------------------------------------------------------------
    //  Treść alarmu
    // -----------------------------------------------------------------

    #[Test]
    public function alarm_mowi_co_zrobic_i_nie_wynosi_nazwy_bazy(): void
    {
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);

        $wynik = app(StanPolaczenBazy::class)->sprawdz();
        $tresc = app(AlarmPolaczen::class)->tresc($wynik);

        // Kontrola DODATNIA: to naprawdę jest nasz alarm i mówi, co zrobić.
        $this->assertStringContainsString('połączenia PostgreSQL', $tresc);
        $this->assertStringContainsString('kuking:budzet-polaczen', $tresc);
        $this->assertStringContainsString('DATABASE.md', $tresc);

        // Kontrola UJEMNA: nazwa bazy jest podpowiedzią dla kogoś, kto przejął
        // kanał, a diagnozy nie przyspiesza — stoi w zmiennych środowiskowych.
        $this->assertStringNotContainsString((string) $wynik['baza'], $tresc);
    }

    #[Test]
    public function naglowek_kuking_jest_w_dostarczonej_wiadomosci_dokladnie_raz(): void
    {
        // ZNALEZIONE POMIAREM NA PRAWDZIWYM ODBIORNIKU, nie w tym pliku.
        // `WebhookBleduHandler::tresc()` sam dokleja `[nazwa/środowisko]` do
        // każdej wiadomości na tym kanale. Klasa alarmu, która dokleja go
        // drugi raz, dostarcza „[Kuking/production] [Kuking/production] …".
        // Asercje typu `assertStringContainsString` tego NIE ŁAPIĄ — dlatego
        // sprawdzamy LICZBĘ wystąpień w treści, która naprawdę poszła.
        $this->wlaczKanalAlarmu();
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);

        app(AlarmPolaczen::class)->zadzwonJesliTrzeba(app(StanPolaczenBazy::class)->sprawdz());

        $naglowek = sprintf('[%s/%s]', config('app.name'), config('app.env'));

        Http::assertSent(function (Request $zadanie) use ($naglowek): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertSame(1, substr_count($wyslane, $naglowek), 'Nagłówek dokłada kanał, nie klasa alarmu.');
            $this->assertStringStartsWith($naglowek, $wyslane);

            return true;
        });
    }

    #[Test]
    public function stan_spokojny_nigdy_nie_dzwoni_nawet_z_wlaczonym_kanalem(): void
    {
        $this->wlaczKanalAlarmu();

        $wynik = app(StanPolaczenBazy::class)->sprawdz();
        $this->assertSame(StanPolaczenBazy::SPOKOJNY, $wynik['stan']);

        $this->assertFalse(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($wynik));

        Http::assertNothingSent();
    }

    #[Test]
    public function alarm_milczy_gdy_kanal_webhooka_jest_wylaczony(): void
    {
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);
        Http::fake();

        $wynik = app(StanPolaczenBazy::class)->sprawdz();
        $this->assertSame(StanPolaczenBazy::OSTRZEZENIE, $wynik['stan']);

        $this->assertFalse(
            app(AlarmPolaczen::class)->zadzwonJesliTrzeba($wynik),
            'Brak zmiennej = zero efektu. Tak jest DZIŚ na produkcji i test ma to nazywać wprost.',
        );

        Http::assertNothingSent();
    }

    #[Test]
    public function ostrzezenie_naprawde_wysyla_wiadomosc_na_kanal(): void
    {
        $this->wlaczKanalAlarmu();
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);

        $wynik = app(StanPolaczenBazy::class)->sprawdz();

        $this->assertTrue(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($wynik));

        Http::assertSentCount(1);

        Http::assertSent(function (Request $zadanie) use ($wynik): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertStringContainsString('połączenia PostgreSQL', $wyslane);
            $this->assertStringContainsString('budżet szczytowy', $wyslane);
            $this->assertStringNotContainsString((string) $wynik['baza'], $wyslane);

            return true;
        });
    }

    // -----------------------------------------------------------------
    //  Ograniczenie powtórzeń i powrót do zdrowia (issue #599)
    // -----------------------------------------------------------------

    #[Test]
    public function ten_sam_stan_w_oknie_ciszy_nie_dzwoni_drugi_raz(): void
    {
        $this->wlaczKanalAlarmu();
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);

        $alarm = app(AlarmPolaczen::class);
        $wynik = app(StanPolaczenBazy::class)->sprawdz();

        $this->assertTrue($alarm->zadzwonJesliTrzeba($wynik), 'Pierwszy raz MUSI dzwonić.');
        $this->assertFalse($alarm->zadzwonJesliTrzeba($wynik), 'Drugi raz w oknie ciszy MUSI milczeć.');
        $this->assertFalse($alarm->zadzwonJesliTrzeba($wynik));

        Http::assertSentCount(1);
    }

    #[Test]
    public function eskalacja_ostrzezenia_do_stanu_krytycznego_dzwoni_od_razu(): void
    {
        $this->wlaczKanalAlarmu();
        $alarm = app(AlarmPolaczen::class);

        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);
        $ostrzezenie = app(StanPolaczenBazy::class)->sprawdz();
        $this->assertSame(StanPolaczenBazy::OSTRZEZENIE, $ostrzezenie['stan']);
        $this->assertTrue($alarm->zadzwonJesliTrzeba($ostrzezenie));

        config()->set('kuking.polaczenia.prog_krytyczny', 1);
        $krytyczny = app(StanPolaczenBazy::class)->sprawdz();
        $this->assertSame(StanPolaczenBazy::KRYTYCZNY, $krytyczny['stan']);

        $this->assertTrue(
            $alarm->zadzwonJesliTrzeba($krytyczny),
            'Cisza po ostrzeżeniu NIE MA PRAWA zagłuszyć eskalacji do stanu krytycznego.',
        );

        Http::assertSentCount(2);
    }

    #[Test]
    public function powrot_do_normy_daje_dokladnie_jedna_wiadomosc(): void
    {
        $this->wlaczKanalAlarmu();
        $alarm = app(AlarmPolaczen::class);

        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(app(StanPolaczenBazy::class)->sprawdz()));

        config()->set('kuking.polaczenia.prog_ostrzegawczy', 50);
        $spokoj = app(StanPolaczenBazy::class)->sprawdz();
        $this->assertSame(StanPolaczenBazy::SPOKOJNY, $spokoj['stan']);

        $this->assertTrue($alarm->zadzwonJesliTrzeba($spokoj), 'Alarm bez odwołania zostawia pytanie „czy to jeszcze trwa".');
        $this->assertFalse($alarm->zadzwonJesliTrzeba($spokoj), 'Drugie „wszystko OK" to już szum.');

        Http::assertSentCount(2);

        Http::assertSent(function (Request $zadanie): bool {
            return str_contains((string) ($zadanie->data()['text'] ?? ''), 'wróciły do normy');
        });
    }

    #[Test]
    public function spokoj_bez_wczesniejszego_alarmu_nie_wysyla_nic(): void
    {
        $this->wlaczKanalAlarmu();

        $spokoj = app(StanPolaczenBazy::class)->sprawdz();
        $this->assertSame(StanPolaczenBazy::SPOKOJNY, $spokoj['stan']);

        $this->assertFalse(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($spokoj));

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    //  Komenda
    // -----------------------------------------------------------------

    #[Test]
    public function komenda_konczy_sie_sukcesem_gdy_jest_zapas(): void
    {
        $this->artisan('kuking:budzet-polaczen', ['--bez-alarmu' => true])
            ->assertExitCode(0);
    }

    #[Test]
    public function komenda_konczy_sie_bledem_gdy_prog_ostrzegawczy_jest_przekroczony(): void
    {
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);

        $this->artisan('kuking:budzet-polaczen', ['--bez-alarmu' => true])
            ->assertExitCode(1);
    }

    #[Test]
    public function komenda_bez_przelacznika_dzwoni_a_z_przelacznikiem_milczy(): void
    {
        $this->wlaczKanalAlarmu();
        config()->set('kuking.polaczenia.prog_ostrzegawczy', 1);

        $this->artisan('kuking:budzet-polaczen', ['--bez-alarmu' => true])->assertExitCode(1);
        Http::assertNothingSent();

        $this->artisan('kuking:budzet-polaczen')->assertExitCode(1);
        Http::assertSentCount(1);
    }

    #[Test]
    public function komenda_pokazuje_zmierzony_limit_a_nie_sama_ocene(): void
    {
        $max = (int) DB::selectOne("SELECT setting FROM pg_settings WHERE name = 'max_connections'")->setting;

        $this->artisan('kuking:budzet-polaczen', ['--bez-alarmu' => true])
            ->expectsOutputToContain((string) $max)
            ->assertExitCode(0);
    }
}

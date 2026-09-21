<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SprawdzAlarm;
use App\Logging\EmailBleduHandler;
use App\Logging\KanalyAlarmowe;
use App\Logging\WebhookBleduHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * DRUGI KANAŁ ALARMOWY: POCZTA (issue #599).
 *
 * DLACZEGO TEN PLIK ISTNIEJE
 * Na produkcji nie ma `LOG_BLAD_WEBHOOK_URL`, a pusty adres = całkowita
 * cisza. Błąd 500, awaria bazy, `/health degraded`, nieudane zadania
 * w kolejce — wszystko policzone, zalogowane i NIEZAUWAŻONE. Właściciel nie
 * używa Discorda ani Slacka, więc kanał, który wymaga jednego z nich, jest
 * dla niego kanałem, którego nie ma.
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU TO NIE FORMATOWANIE, TYLKO
 * `list_nie_niesie_komunikatu_wyjatku_z_e_mailem_i_hashem_hasla`. Audyt
 * A6-01 pokazał, że komunikat `QueryException` buduje sterownik i wkłada
 * w niego SQL RAZEM Z WARTOŚCIAMI — adresem e-mail i hashem hasła. Webhook
 * przestał to wysyłać 9 września 2026. Dołożenie drugiego kanału jest
 * dokładnie tym momentem, w którym ta poprawka może cicho przestać
 * obowiązywać na jednej z dwóch dróg, bo wszystko inne nadal działa.
 *
 * ŻADEN TEST W TYM PLIKU NIE WYSYŁA PRAWDZIWEGO LISTU: `MAIL_MAILER=array`
 * (`phpunit.xml`) plus `Mail::fake()` tam, gdzie liczymy sztuki.
 */
class KanalAlarmowyMailemTest extends TestCase
{
    private const SKRZYNKA = 'alarmy@przyklad.test';

    private const ADRES_WEBHOOKA = 'https://przyklad.test/webhook-alarmow';

    /** Dane, które NIE MAJĄ prawa opuścić serwera — dosłownie te z opisu audytu A6-01. */
    private const EMAIL_CZLOWIEKA = 'ktos@example.com';

    private const HASH_HASLA = '$2y$12$abcdefghijklmnopqrstuv';

    protected function setUp(): void
    {
        parent::setUp();

        // Oba kanały WYŁĄCZONE na start — to jest stan produkcji i stan
        // domyślny testów. Każdy test włącza sobie to, czego dowodzi.
        config()->set('logging.channels.blad_webhook.url', null);
        config()->set('logging.channels.blad_email.adres', null);

        Cache::flush();
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        EmailBleduHandler::zapomnijOstatniaWysylke();
    }

    private function wlaczPoczte(): void
    {
        config()->set('logging.channels.blad_email.adres', self::SKRZYNKA);

        // Kanał jest zapamiętywany po pierwszym użyciu, a `setUp()` zostawia
        // go z pustym adresem. Bez tego wiersza pamiętany egzemplarz
        // zostałby z `null` i test przechodziłby, nic nie wysyłając.
        Log::forgetChannel('blad_email');
    }

    private function wlaczWebhook(): void
    {
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);
        Log::forgetChannel('blad_webhook');
        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);
    }

    /**
     * `QueryException` z komunikatem DOKŁADNIE takim, jaki buduje sterownik
     * pgsql przy naruszeniu unikalności na `users` — z adresem e-mail
     * i hashem hasła w środku.
     */
    private function bladBazyZDanymiCzlowieka(): QueryException
    {
        $komunikat = sprintf(
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint '
            .'"users_email_unique" DETAIL: Key (email)=(%s) already exists.',
            self::EMAIL_CZLOWIEKA,
        );

        return new QueryException(
            connectionName: 'pgsql',
            sql: 'insert into "users" ("email", "password") values (?, ?)',
            bindings: [self::EMAIL_CZLOWIEKA, self::HASH_HASLA],
            previous: new PDOException($komunikat, 23505),
        );
    }

    /**
     * Listy, ktore NAPRAWDE wyszly z transportu `array` (`phpunit.xml`).
     * Zaden test w tym pliku nie wysyla prawdziwego listu.
     *
     * @return list<SentMessage>
     */
    private function wyslaneListy(): array
    {
        return Mail::mailer()->getSymfonyTransport()->messages()->values()->all();
    }

    private function ostatniList(): Email
    {
        $listy = $this->wyslaneListy();

        $this->assertNotEmpty($listy, 'Nie poszedł ani jeden list.');

        return end($listy)->getOriginalMessage();
    }

    private function trescOstatniegoListu(): string
    {
        return (string) $this->ostatniList()->getTextBody();
    }

    private function tematOstatniegoListu(): string
    {
        return (string) $this->ostatniList()->getSubject();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  SEDNO: CZEGO W LIŚCIE NIE MA
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function list_nie_niesie_komunikatu_wyjatku_z_e_mailem_i_hashem_hasla(): void
    {
        $this->wlaczPoczte();

        Log::channel('blad_email')->error(QueryException::class, [
            'exception' => $this->bladBazyZDanymiCzlowieka(),
        ]);

        $tresc = $this->trescOstatniegoListu();
        $temat = $this->tematOstatniegoListu();

        $this->assertStringNotContainsString(self::EMAIL_CZLOWIEKA, $tresc);
        $this->assertStringNotContainsString(self::HASH_HASLA, $tresc);
        $this->assertStringNotContainsString(self::EMAIL_CZLOWIEKA, $temat);
        $this->assertStringNotContainsString(self::HASH_HASLA, $temat);

        // Nie tylko same wartości: CAŁY komunikat wyjątku nie ma tu wstępu.
        $this->assertStringNotContainsString('duplicate key value', $tresc);
        $this->assertStringNotContainsString('DETAIL', $tresc);
        $this->assertStringNotContainsString('insert into', $tresc);

        // A to, po co ten list w ogóle jest, w nim JEST.
        $this->assertStringContainsString(QueryException::class, $tresc);
        $this->assertStringContainsString('kod: 23505', $tresc);
        $this->assertStringContainsString('odcisk: ', $tresc);
    }

    #[Test]
    public function slad_stosu_w_liscie_nie_niesie_ani_jednego_argumentu_wywolania(): void
    {
        $this->wlaczPoczte();

        // Wyjątek rzucony Z FUNKCJI, której podano sekret jako argument —
        // dokładnie ten układ, w którym `getTrace()` niesie prawdziwą wartość.
        $rzuc = static function (string $hasloCzlowieka): never {
            throw new \RuntimeException('cokolwiek');
        };

        try {
            $rzuc(self::HASH_HASLA);
        } catch (\RuntimeException $e) {
            Log::channel('blad_email')->error($e::class, ['exception' => $e]);
        }

        $tresc = $this->trescOstatniegoListu();

        $this->assertStringNotContainsString(self::HASH_HASLA, $tresc);
        $this->assertStringContainsString('RuntimeException', $tresc);
    }

    #[Test]
    public function oba_kanaly_wysylaja_dokladnie_te_sama_tresc(): void
    {
        $this->wlaczWebhook();
        $this->wlaczPoczte();

        $blad = $this->bladBazyZDanymiCzlowieka();

        KanalyAlarmowe::zadzwon($blad::class, ['exception' => $blad]);

        $naWebhook = null;
        Http::assertSent(function (Request $zadanie) use (&$naWebhook): bool {
            $naWebhook = $zadanie->data()['text'] ?? null;

            return true;
        });

        $this->assertNotNull($naWebhook, 'Na webhooka nic nie poszło.');

        // To jest cała umowa z #599: jedna treść, dwie drogi. Gdyby ktoś
        // kiedyś dopisał osobne formatowanie dla poczty, ten test upadnie
        // ZANIM upadnie test o braku PII — a to jest dokładnie ta kolejność,
        // w której chcemy się o tym dowiedzieć.
        $this->assertSame($naWebhook, $this->trescOstatniegoListu());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  UMOWA WŁĄCZANIA: PUSTY ADRES = CISZA, OBA NARAZ = OBA
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function pusty_adres_skrzynki_znaczy_calkowita_cisza(): void
    {
        Mail::fake();

        Log::channel('blad_email')->error('cokolwiek');

        Mail::assertNothingSent();
        $this->assertFalse(KanalyAlarmowe::jakikolwiekWlaczony());
    }

    #[Test]
    public function sama_poczta_wystarczy_zeby_blad_500_kogokolwiek_obudzil(): void
    {
        // Webhooka NIE MA — to jest stan produkcji.
        $this->wlaczPoczte();

        $this->assertTrue(KanalyAlarmowe::jakikolwiekWlaczony());
        $this->assertSame([KanalyAlarmowe::POCZTA], KanalyAlarmowe::wlaczone());

        $blad = new \RuntimeException('cokolwiek');
        KanalyAlarmowe::zadzwon($blad::class, ['exception' => $blad]);

        $this->assertCount(1, $this->wyslaneListy());
    }

    #[Test]
    public function oba_kanaly_moga_dzialac_naraz(): void
    {
        $this->wlaczWebhook();
        $this->wlaczPoczte();

        $this->assertSame(
            [KanalyAlarmowe::WEBHOOK, KanalyAlarmowe::POCZTA],
            KanalyAlarmowe::wlaczone(),
        );

        KanalyAlarmowe::zadzwon('próba');

        Http::assertSentCount(1);
        $this->assertCount(1, $this->wyslaneListy());
    }

    #[Test]
    public function list_idzie_na_adres_ze_zmiennej_a_temat_nie_niesie_tresci_wpisu(): void
    {
        $this->wlaczPoczte();

        Log::channel('blad_email')->error('sekretna treść wpisu, której nie chcemy w temacie');

        $listy = $this->wyslaneListy();
        $this->assertCount(1, $listy);

        $this->assertSame(
            [self::SKRZYNKA],
            array_map(static fn ($adres): string => $adres->getAddress(), $listy[0]->getOriginalMessage()->getTo()),
        );

        $this->assertStringNotContainsString('sekretna treść', $this->tematOstatniegoListu());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  ZALEW SKRZYNKI
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function tysiac_powtorzen_tej_samej_awarii_to_jeden_list(): void
    {
        $this->wlaczPoczte();

        $blad = $this->bladBazyZDanymiCzlowieka();

        for ($i = 0; $i < 50; $i++) {
            Log::channel('blad_email')->error($blad::class, ['exception' => $blad]);
        }

        $this->assertCount(1, $this->wyslaneListy());
    }

    #[Test]
    public function inna_awaria_w_tej_samej_chwili_nie_jest_wyciszana(): void
    {
        $this->wlaczPoczte();

        $pierwsza = $this->bladBazyZDanymiCzlowieka();
        $druga = new \LogicException('inna awaria');

        Log::channel('blad_email')->error($pierwsza::class, ['exception' => $pierwsza]);
        Log::channel('blad_email')->error($druga::class, ['exception' => $druga]);

        // Wyciszanie liczy się z ODCISKU, nie globalnie — inaczej nowa
        // awaria ginęłaby tylko dlatego, że stara jeszcze trwa.
        $this->assertCount(2, $this->wyslaneListy());
    }

    #[Test]
    public function proba_kanalu_nie_zapisuje_pamieci_wyciszania(): void
    {
        $this->wlaczPoczte();

        // DWIE próby pod rząd — obie muszą dojść. Gdyby rekordy bez wyjątku
        // trafiały do jednego wspólnego kubełka wyciszania, druga próba by
        // znikła, a człowiek powtarzający sprawdzenie zobaczyłby „komenda
        // mówi że wysłała, a listu nie ma" — czyli dokładnie ten objaw, który
        // ta komenda ma wykluczyć.
        $this->artisan('kuking:sprawdz-alarm')->assertSuccessful();
        $this->artisan('kuking:sprawdz-alarm')->assertSuccessful();

        $this->assertCount(2, $this->wyslaneListy());

        // A zaraz po próbach PRAWDZIWA awaria. Gdyby próba zapisała okno,
        // ten list by nie poszedł — i człowiek, który właśnie sprawdził,
        // że alarm dochodzi, przegapiłby pierwszą prawdziwą awarię.
        $blad = $this->bladBazyZDanymiCzlowieka();
        Log::channel('blad_email')->error($blad::class, ['exception' => $blad]);

        $this->assertCount(3, $this->wyslaneListy());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PĘTLA ZWROTNA I BRAK RZUCANIA DALEJ
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function awaria_wysylki_listu_nie_rzuca_i_nie_probuje_alarmowac_pocztą(): void
    {
        $this->wlaczPoczte();

        // Transport, który zawsze pada — czyli „poczta nie działa", czyli
        // dokładnie ta awaria, o której alarm miałby iść pocztą.
        Mail::shouldReceive('raw')
            ->atLeast()->once()
            ->andThrow(new \RuntimeException('EmailLabs nie odpowiada'));

        $blad = $this->bladBazyZDanymiCzlowieka();

        // Brak wyjątku stąd jest treścią testu: `write()` działa w środku
        // `$exceptions->report()`, więc rzucenie dałoby człowiekowi zamiast
        // strony 500 nieobsłużony wyjątek z samego mechanizmu alarmowania.
        Log::channel('blad_email')->error($blad::class, ['exception' => $blad]);

        $this->assertFalse(EmailBleduHandler::ostatniaWysylkaSieUdala());
    }

    /**
     * ZAPORA PONOWNEGO WEJŚCIA — sedno punktu 1, i do 22 września 2026 jedyna
     * rzecz w tym pliku, której nie pilnował ŻADEN test.
     *
     * `awaria_wysylki_listu_nie_rzuca_…` obiecuje nazwą, że wysyłka „nie
     * próbuje alarmować pocztą", ale tego nie dowodzi: jej transport tylko
     * rzuca i nigdy nie woła `Log::error()`, więc przechodzi także wtedy, gdy
     * zapory `$wSrodkuWysylki` nie ma w kodzie w ogóle (sprawdzone: wycięcie
     * warunku nie zapala ani jednego testu w tym pliku).
     *
     * Tu transport ROBI to, co robi prawdziwy transport poczty, gdy padnie
     * dostawca: loguje własną awarię. Wtórny błąd jest ŚWIADOMIE INNEJ KLASY
     * niż pierwotny, więc ma inny odcisk — pamięć wyciszania go nie zatrzyma.
     * Zatrzymać go może WYŁĄCZNIE zapora. Bez niej alarm o niedziałającej
     * poczcie jedzie pocztą, w kółko, aż do wyczerpania stosu.
     */
    #[Test]
    public function transport_poczty_wolajacy_log_nie_wpada_w_petle_zwrotna(): void
    {
        $this->wlaczPoczte();

        $proby = 0;

        Mail::shouldReceive('raw')->andReturnUsing(function () use (&$proby): void {
            $proby++;

            // Bezpiecznik SAMEGO TESTU: bez niego brak zapory nie daje
            // czerwieni, tylko zawieszony przebieg albo przepełniony stos.
            if ($proby > 5) {
                return;
            }

            $wtorny = new \RuntimeException('EmailLabs zerwał połączenie');
            Log::channel('blad_email')->error($wtorny::class, ['exception' => $wtorny]);
        });

        $blad = $this->bladBazyZDanymiCzlowieka();
        Log::channel('blad_email')->error($blad::class, ['exception' => $blad]);

        $this->assertSame(
            1,
            $proby,
            'Alarm o awarii poczty pojechał pocztą — zapora ponownego wejścia nie działa.',
        );
    }

    #[Test]
    public function nieudany_list_nie_kupuje_ciszy(): void
    {
        $this->wlaczPoczte();

        $blad = $this->bladBazyZDanymiCzlowieka();

        // Pierwsza próba pada …
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('chwilowa awaria'));
        Log::channel('blad_email')->error($blad::class, ['exception' => $blad]);

        // … więc DRUGA musi jeszcze raz spróbować. Inaczej jedna sekunda
        // pecha kupowałaby kwadrans ciszy o trwającej awarii (ta sama
        // klasa usterki co #33 przy webhooku).
        Mail::shouldReceive('raw')->once();
        Log::channel('blad_email')->error($blad::class, ['exception' => $blad]);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  KOMENDA SPRAWDZAJĄCA ROZUMIE OBA KANAŁY
    // ─────────────────────────────────────────────────────────────────────

    #[Test]
    public function komenda_mowi_wprost_ktory_kanal_jest_wlaczony_a_ktory_nie(): void
    {
        $this->wlaczPoczte();

        // CAŁE ZDANIE W JEDNYM NAPISIE, nie osobne kawałki: `expectsOutputToContain`
        // dopasowuje się do POJEDYNCZEJ linii wyjścia, a dwa oczekiwania pasujące
        // do tej samej linii zjadałyby się nawzajem (Mockery zalicza linię tylko
        // pierwszemu z nich). Przy okazji test mówi dokładnie to, co ma mówić:
        // KTÓRY kanał jest włączony, a który nie.
        $this->artisan('kuking:sprawdz-alarm --bez-wysylki')
            ->expectsOutputToContain('LOG_BLAD_EMAIL`) — WŁĄCZONY')
            ->expectsOutputToContain('LOG_BLAD_WEBHOOK_URL`) — wyłączony')
            ->assertSuccessful();
    }

    #[Test]
    public function komenda_oblewa_dopiero_gdy_zaden_kanal_nie_jest_wlaczony(): void
    {
        $this->artisan('kuking:sprawdz-alarm --bez-wysylki')
            ->expectsOutputToContain('ŻADEN kanał alarmowy nie jest włączony')
            ->assertFailed();
    }

    #[Test]
    public function komenda_z_sama_poczta_wysyla_jeden_list_i_melduje_sukces(): void
    {
        $this->wlaczPoczte();

        $this->artisan('kuking:sprawdz-alarm')->assertSuccessful();

        $listy = $this->wyslaneListy();
        $this->assertCount(1, $listy);
        $this->assertStringContainsString(
            'PRÓBA KANAŁU ALARMOWEGO',
            (string) $listy[0]->getOriginalMessage()->getTextBody(),
        );
    }

    #[Test]
    public function komenda_nie_wypisuje_adresu_skrzynki(): void
    {
        $this->wlaczPoczte();

        $this->artisan('kuking:sprawdz-alarm --bez-wysylki')
            ->doesntExpectOutputToContain(self::SKRZYNKA)
            ->assertSuccessful();
    }

    #[Test]
    public function komenda_wskazuje_palcem_kanal_ktory_nie_przyjal(): void
    {
        $this->wlaczPoczte();

        // Webhook włączony, ale odwołany — Discord oddaje 404 JAKO ZWYKŁĄ
        // ODPOWIEDŹ, bez wyjątku. Poczta działa.
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);
        Log::forgetChannel('blad_webhook');
        Http::fake([self::ADRES_WEBHOOKA => Http::response('nie ma', 404)]);

        $this->artisan('kuking:sprawdz-alarm')
            ->expectsOutputToContain('LOG_BLAD_WEBHOOK_URL`) — NIE PRZYJĄŁ')
            ->expectsOutputToContain('LOG_BLAD_EMAIL`) — PRZYJĄŁ')
            ->assertFailed();

        // Ale list i tak poszedł — kanały są niezależne i awaria jednego
        // nie ma prawa zabrać drugiego.
        $this->assertCount(1, $this->wyslaneListy());
    }

    #[Test]
    public function tresc_proby_nie_ma_wlasnego_naglowka_srodowiska(): void
    {
        // Ten sam rygor, co przed #599: nagłówek dokleja `TrescAlarmu`,
        // więc wpisany drugi raz dawałby „[Kuking/testing] [Kuking/testing] …".
        $this->assertStringNotContainsString(
            '[',
            app(SprawdzAlarm::class)->tresc('2026-09-21T00:00:00+00:00'),
        );
    }
}

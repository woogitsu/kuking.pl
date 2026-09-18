<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\AlarmKolejki;
use App\Domain\Kolejka\StanKolejki;
use App\Domain\Polaczenia\AlarmPolaczen;
use App\Domain\Polaczenia\StanPolaczenBazy;
use App\Logging\WebhookBleduHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regresja: NIEUDANY DZWONEK NIE MA PRAWA KUPIĆ CISZY (#599).
 *
 * CO BYŁO ZEPSUTE
 * `AlarmPolaczen::wyslij()` i `AlarmKolejki::wyslij()` uznawały wysyłkę za
 * udaną na SAM BRAK WYJĄTKU. Klient HTTP Laravela bez `throw()` oddaje 404
 * czy 500 jako zwykłą odpowiedź, a `WebhookBleduHandler::write()` z zasady
 * nigdy nie rzuca dalej — więc `try/catch` wokół `Log::channel(...)->error()`
 * nie łapał ŻADNEGO z tych przypadków. Skutek zmierzony przed poprawką:
 *
 *     webhook oddał                 : HTTP 404
 *     zadzwonJesliTrzeba() zwróciło : true          <- nieprawda
 *     pamięć wyciszania zapisana    : ['stan' => 'krytyczny', ...]
 *     drugi przebieg (kanał działa) : zadzwonił=false, żądań HTTP=0
 *
 * Czyli jedna nieudana próba wyciszała czujkę na `cisza_godzin`, a następny,
 * sprawny dzwonek o TRWAJĄCEJ awarii przepadał. To jest ta sama klasa
 * usterki, którą naprawiały `HealthController::powiadomWebhook()` (oddaje
 * swój odstęp przy porażce) i `kuking:sprawdz-alarm` (pyta handler o wynik).
 *
 * CZEGO TE TESTY NIE DOWODZĄ — ATRAPA TRANSPORTU
 * Wszystkie testy niżej stoją na `Http::fake()`, czyli na ATRAPIE KLIENTA
 * HTTP. Atrapa podstawia odpowiedź w miejsce prawdziwego gniazda, więc NIE
 * dowodzi: że da się rozwiązać nazwę hosta, że certyfikat jest ważny, że
 * timeouty (3 s / 2 s) są dobrane, ani że Discord albo Slack przyjmuje
 * wysyłany kształt ciała żądania. Dowodzi wyłącznie tego, CO KOD ROBI
 * Z ODPOWIEDZIĄ o danym kodzie — a to jest dokładnie przedmiot tej poprawki.
 * Pomiar na prawdziwym odbiorniku HTTP na 127.0.0.1 jest osobno, w dowodach
 * pakietu.
 *
 * I ANI JEDEN Z NICH NIE DOWODZI, ŻE KTOŚ TO ZOBACZYŁ. Kod 2xx znaczy
 * „usługa przyjęła wiadomość", nie „człowiek ją przeczytał"; tego stąd
 * sprawdzić się nie da (patrz `kuking:sprawdz-alarm` i §7.4 dokumentu
 * `docs/infra/MONITORING_BLEDOW.md`).
 */
class NieudanyDzwonekNieKupujeCiszyTest extends TestCase
{
    private const ADRES = 'https://przyklad.test/webhook-czujek';

    private const KLUCZ_POLACZEN = 'kuking:polaczenia:ostatni-alarm';

    private const KLUCZ_KOLEJKI = 'kuking:kolejka:ostatni-alarm';

    /**
     * Okno ponowienia po próbie, której kanał NIE potwierdził. Krótkie
     * z premedytacją — patrz komentarz przy `PONOWIENIE_PO_NIEUDANEJ_MINUT`
     * w klasach alarmu. Test mierzy ZACHOWANIE w czasie, więc musi znać
     * rząd wielkości tego okna.
     */
    private const PONOWIENIE_MINUT = 5;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config()->set('kuking.polaczenia.cisza_godzin', 6);
        config()->set('kuking.kolejka.cisza_godzin', 3);
        config()->set('logging.channels.blad_webhook.url', self::ADRES);

        // Kanał jest zapamiętywany po pierwszym użyciu — bez tego pamiętany
        // egzemplarz mógłby zostać z adresem (albo `null`) z innego testu.
        Log::forgetChannel('blad_webhook');

        // Pamięć wyniku wysyłki w handlerze jest STATYCZNA i przeżywa test.
        WebhookBleduHandler::zapomnijOstatniaWysylke();
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        Log::forgetChannel('blad_webhook');

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function krytyczne(): array
    {
        return [
            'stan' => StanPolaczenBazy::KRYTYCZNY,
            'zajete_serwer' => 90,
            'dostepne' => 97,
            'prog_krytyczny' => 80,
            'prog_ostrzegawczy' => 50,
            'budzet_szczytowy' => 16,
        ];
    }

    /** @return array<string, mixed> */
    private function zaleglosc(): array
    {
        return [
            'stan' => StanKolejki::ZALEGLOSC,
            'zaleglosc_sekundy' => 900,
            'prog_zaleglosci_sekundy' => 300,
            'oczekujace' => 12,
            'zawieszone' => 0,
        ];
    }

    // -----------------------------------------------------------------
    //  TEST CENTRALNY — pierwsza próba pada, druga MUSI dojść
    // -----------------------------------------------------------------

    #[Test]
    public function po_nieudanej_probie_nastepny_sprawny_dzwonek_dochodzi_polaczenia(): void
    {
        Http::fake([self::ADRES => Http::sequence()
            ->push('webhook skasowany', 404)
            ->push('ok', 200),
        ]);

        $alarm = app(AlarmPolaczen::class);
        $wynik = $this->krytyczne();

        // 1. Kanał ODDAŁ 404. To NIE jest dostarczony alarm.
        $this->assertFalse(
            $alarm->zadzwonJesliTrzeba($wynik),
            'HTTP 404 to nie jest dostarczony alarm — brak wyjątku nie jest dowodem przyjęcia.',
        );
        Http::assertSentCount(1);

        // 2. Kanał wrócił do życia, stan awarii TRWA. Alarm musi wyjść.
        $this->travel(self::PONOWIENIE_MINUT + 1)->minutes();

        $this->assertTrue(
            $alarm->zadzwonJesliTrzeba($wynik),
            'Nieudana próba WYCISZYŁA czujkę: sprawny dzwonek o trwającej awarii przepadł.',
        );

        // To jest asercja, która naprawdę mierzy dostarczenie: drugie żądanie
        // MUSIAŁO wyjść. Bez niej test przeszedłby także dla kodu, który
        // zwraca `true`, nic nie wysyłając (pułapka 5).
        Http::assertSentCount(2);
    }

    #[Test]
    public function po_nieudanej_probie_nastepny_sprawny_dzwonek_dochodzi_kolejka(): void
    {
        Http::fake([self::ADRES => Http::sequence()
            ->push('webhook skasowany', 404)
            ->push('ok', 200),
        ]);

        $alarm = app(AlarmKolejki::class);
        $wynik = $this->zaleglosc();

        $this->assertFalse($alarm->zadzwonJesliTrzeba($wynik));
        Http::assertSentCount(1);

        $this->travel(self::PONOWIENIE_MINUT + 1)->minutes();

        $this->assertTrue(
            $alarm->zadzwonJesliTrzeba($wynik),
            'Analog dla kolejki ma tę samą usterkę i musi być naprawiony tak samo.',
        );
        Http::assertSentCount(2);
    }

    #[Test]
    public function timeout_tez_nie_wycisza_czujki(): void
    {
        // Ten przypadek idzie DRUGĄ gałęzią: handler ŁAPIE wyjątek u siebie
        // i nie przepuszcza go do wołającego. Bez osobnego testu ta gałąź
        // nie byłaby zmierzona ani razu (pułapka 3b).
        $proby = 0;
        Http::fake(function () use (&$proby) {
            $proby++;

            return $proby === 1
                ? throw new ConnectionException('cURL error 28: Operation timed out')
                : Http::response('ok', 200);
        });

        $alarm = app(AlarmPolaczen::class);

        $this->assertFalse($alarm->zadzwonJesliTrzeba($this->krytyczne()));

        $this->travel(self::PONOWIENIE_MINUT + 1)->minutes();

        $this->assertTrue($alarm->zadzwonJesliTrzeba($this->krytyczne()));
        $this->assertSame(2, $proby, 'Drugie żądanie MUSIAŁO wyjść na kanał.');
    }

    // -----------------------------------------------------------------
    //  Pamięć wyciszania zapisuje się TYLKO po potwierdzonym przyjęciu
    // -----------------------------------------------------------------

    #[Test]
    public function pamiec_wyciszania_powstaje_dopiero_po_potwierdzonym_przyjeciu(): void
    {
        // JEDNA atrapa na cały test, bo drugie `Http::fake()` NIE zastępuje
        // pierwszego — Laravel dokłada kolejne odpowiedzi do listy, a wygrywa
        // pierwsza pasująca. Zmierzone: druga atrapa „404" po pierwszej „200"
        // oddawała nadal 200, więc kontrola ujemna niżej nie mierzyła niczego.
        Http::fake([self::ADRES => Http::sequence()
            ->push('ok', 200)
            ->push('nie ma', 404),
        ]);

        // Kontrola DODATNIA: przy 2xx pamięć MUSI powstać. Bez niej cały ten
        // test przechodziłby także dla kodu, który nie zapisuje nigdy
        // (pułapka 4 — asercja „czegoś nie ma" bez pary).

        $this->assertTrue(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($this->krytyczne()));

        $zapis = Cache::get(self::KLUCZ_POLACZEN);
        $this->assertIsArray($zapis);
        $this->assertSame(StanPolaczenBazy::KRYTYCZNY, $zapis['stan']);
        $this->assertGreaterThan(0, (int) ($zapis['dostarczony_o'] ?? 0),
            'Przyjęta wiadomość musi być zapisana jako DOSTARCZONA.');

        // Kontrola UJEMNA: po 404 pamięć NIE MOŻE twierdzić, że alarm doszedł.
        Cache::flush();
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        $this->assertFalse(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($this->krytyczne()));

        $poPorazce = Cache::get(self::KLUCZ_POLACZEN);
        $this->assertSame(0, (int) ($poPorazce['dostarczony_o'] ?? 0),
            'Porażka zapisana jako dostarczenie — to jest właśnie ta usterka.');
    }

    /** @return array<string, array{int}> */
    public static function odmowneKody(): array
    {
        return [
            'webhook skasowany po stronie Discorda (404)' => [404],
            'webhook odwołany (401)' => [401],
            'webhook zablokowany (403)' => [403],
            'usługa po drugiej stronie padła (500)' => [500],
            'brama nie odpowiada (502)' => [502],
            'ograniczenie ruchu (429)' => [429],
        ];
    }

    #[DataProvider('odmowneKody')]
    #[Test]
    public function odmowa_kanalu_nie_jest_dostarczonym_alarmem(int $kod): void
    {
        Http::fake([self::ADRES => Http::response('nie przyjęto', $kod)]);

        $this->assertFalse(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($this->krytyczne()));
        $this->assertFalse(app(AlarmKolejki::class)->zadzwonJesliTrzeba($this->zaleglosc()));

        // Żądania MUSIAŁY wyjść — inaczej test przechodziłby także dla kodu,
        // który w ogóle nic nie wysyła.
        Http::assertSentCount(2);
    }

    #[Test]
    public function przyjecie_dwusetka_nadal_dzwoni_i_nadal_wycisza(): void
    {
        // Kontrola DODATNIA całego pliku: poprawka nie miała prawa zepsuć
        // ani dzwonienia, ani wyciszania duplikatów.
        Http::fake([self::ADRES => Http::response('', 204)]);

        $alarm = app(AlarmPolaczen::class);

        $this->assertTrue($alarm->zadzwonJesliTrzeba($this->krytyczne()), '204 to przyjęcie.');
        $this->assertFalse($alarm->zadzwonJesliTrzeba($this->krytyczne()), 'Drugi raz w oknie ciszy MUSI milczeć.');

        Http::assertSentCount(1);
    }

    // -----------------------------------------------------------------
    //  Kompromis: ochrona przed lawiną prób, ale bez kupowania ciszy
    // -----------------------------------------------------------------

    #[Test]
    public function nieudana_proba_nie_zamienia_sie_w_petle_zadan(): void
    {
        Http::fake([self::ADRES => Http::response('nie ma', 404)]);

        $alarm = app(AlarmPolaczen::class);

        $this->assertFalse($alarm->zadzwonJesliTrzeba($this->krytyczne()));

        // Dziesięć przebiegów pod rząd w oknie ponowienia = JEDNO żądanie.
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($alarm->zadzwonJesliTrzeba($this->krytyczne()));
        }

        Http::assertSentCount(1);

        // Kontrola DODATNIA do powyższego: okno ponowienia jest KRÓTKIE
        // i liczone w minutach, nie w godzinach ciszy (tu: 6 h).
        $this->travel(self::PONOWIENIE_MINUT + 1)->minutes();
        $this->assertFalse($alarm->zadzwonJesliTrzeba($this->krytyczne()));
        Http::assertSentCount(2);
    }

    #[Test]
    public function zmiana_stanu_dzwoni_od_razu_takze_po_nieudanej_probie(): void
    {
        // Eskalacja `ostrzezenie` → `krytyczny` nie ma prawa czekać na okno
        // ponowienia: to jest NOWA informacja, nie powtórzenie starej.
        Http::fake([self::ADRES => Http::sequence()
            ->push('nie ma', 404)
            ->push('ok', 200),
        ]);

        $alarm = app(AlarmPolaczen::class);

        $ostrzezenie = $this->krytyczne();
        $ostrzezenie['stan'] = StanPolaczenBazy::OSTRZEZENIE;

        $this->assertFalse($alarm->zadzwonJesliTrzeba($ostrzezenie));
        $this->assertTrue($alarm->zadzwonJesliTrzeba($this->krytyczne()), 'Eskalacja to zmiana stanu — dzwoni od razu.');

        Http::assertSentCount(2);
    }

    // -----------------------------------------------------------------
    //  Brak konfiguracji kanału
    // -----------------------------------------------------------------

    #[Test]
    public function pusty_adres_kanalu_nie_wysyla_nic_i_nic_nie_zapisuje(): void
    {
        config()->set('logging.channels.blad_webhook.url', null);
        Log::forgetChannel('blad_webhook');
        Http::fake();

        $this->assertFalse(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($this->krytyczne()));
        $this->assertFalse(app(AlarmKolejki::class)->zadzwonJesliTrzeba($this->zaleglosc()));

        Http::assertNothingSent();
        $this->assertNull(Cache::get(self::KLUCZ_POLACZEN));
        $this->assertNull(Cache::get(self::KLUCZ_KOLEJKI));

        // Kontrola DODATNIA: z adresem ta sama czujka dzwoni. Bez tego
        // powyższe „nic nie wyszło" przechodziłoby także dla czujki, która
        // nie dzwoni NIGDY (pułapka 4).
        config()->set('logging.channels.blad_webhook.url', self::ADRES);
        Log::forgetChannel('blad_webhook');
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        $this->assertTrue(app(AlarmPolaczen::class)->zadzwonJesliTrzeba($this->krytyczne()));
        Http::assertSentCount(1);
    }

    // -----------------------------------------------------------------
    //  Statyczna pamięć handlera nie przecieka między próbami
    // -----------------------------------------------------------------

    #[Test]
    public function wynik_cudzej_wysylki_w_tym_samym_procesie_nie_udaje_naszego_sukcesu(): void
    {
        // `WebhookBleduHandler::$ostatniaWysylkaSieUdala` jest STATYCZNA,
        // czyli wspólna dla całego procesu. W jednym przebiegu harmonogramu
        // idą po sobie czujka kopii, połączeń i kolejki — a `/health` dzwoni
        // po kilka razy w jednym żądaniu.
        //
        // Jest dokładnie jedna sytuacja, w której handler NIE dotyka tej
        // pamięci: gdy kanał zbudowano z pustym adresem (`write()` wychodzi
        // pierwszą linią). Zdarza się to, gdy egzemplarz kanału powstał,
        // zanim adres się pojawił — Laravel zapamiętuje kanał po pierwszym
        // użyciu. Wtedy bez wyzerowania przed próbą wołający odczytałby
        // CUDZY wynik sprzed chwili i uznał go za swój.
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        $alarm = app(AlarmPolaczen::class);

        $this->assertTrue($alarm->zadzwonJesliTrzeba($this->krytyczne()));
        $this->assertTrue(WebhookBleduHandler::ostatniaWysylkaSieUdala());

        // Kanał zapamiętany z PUSTYM adresem, a `config()` mówi już „jest".
        Cache::flush();
        config()->set('logging.channels.blad_webhook.url', null);
        Log::forgetChannel('blad_webhook');
        Log::channel('blad_webhook');
        config()->set('logging.channels.blad_webhook.url', self::ADRES);

        $this->assertFalse(
            $alarm->zadzwonJesliTrzeba($this->krytyczne()),
            'Odczytano wynik CUDZEJ wysyłki sprzed chwili — pamięć handlera nie została wyzerowana przed próbą.',
        );

        // Druga wiadomość nie miała jak wyjść — kanał zbudowano z pustym adresem.
        Http::assertSentCount(1);
        $this->assertSame(0, (int) (Cache::get(self::KLUCZ_POLACZEN)['dostarczony_o'] ?? 0));
    }

    // -----------------------------------------------------------------
    //  Odwołanie alarmu („wróciło do normy") rządzi się tym samym prawem
    // -----------------------------------------------------------------

    #[Test]
    public function nieudane_odwolanie_nie_przepada_bezpowrotnie(): void
    {
        Http::fake([self::ADRES => Http::sequence()
            ->push('ok', 200)          // alarm dochodzi
            ->push('nie ma', 404)      // odwołanie NIE dochodzi
            ->push('ok', 200),         // ponowione odwołanie dochodzi
        ]);

        $alarm = app(AlarmPolaczen::class);
        $spokoj = ['stan' => StanPolaczenBazy::SPOKOJNY];

        $this->assertTrue($alarm->zadzwonJesliTrzeba($this->krytyczne()));

        $this->assertFalse($alarm->zadzwonJesliTrzeba($spokoj), '404 to nie jest dostarczone odwołanie.');

        $this->travel(self::PONOWIENIE_MINUT + 1)->minutes();

        $this->assertTrue($alarm->zadzwonJesliTrzeba($spokoj), 'Odwołanie przepadło razem z pamięcią alarmu.');
        $this->assertFalse($alarm->zadzwonJesliTrzeba($spokoj), 'Drugie „wszystko OK" to już szum.');

        Http::assertSentCount(3);
    }

    #[Test]
    public function alarm_ktory_do_nikogo_nie_doszedl_nie_dostaje_odwolania(): void
    {
        // Odwołanie alarmu, którego nikt nie dostał, jest gorsze niż cisza:
        // właściciel czyta „wróciło do normy (poprzedni stan: krytyczny)"
        // o awarii, o której nigdy się nie dowiedział.
        Http::fake([self::ADRES => Http::sequence()
            ->push('nie ma', 404)
            ->push('ok', 200),
        ]);

        $alarm = app(AlarmPolaczen::class);

        $this->assertFalse($alarm->zadzwonJesliTrzeba($this->krytyczne()));

        $this->travel(self::PONOWIENIE_MINUT + 1)->minutes();

        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => StanPolaczenBazy::SPOKOJNY]));

        // Wyszła tylko nieudana próba alarmu — żadnego odwołania.
        Http::assertSentCount(1);
        $this->assertNull(Cache::get(self::KLUCZ_POLACZEN), 'Pamięć po niedostarczonym alarmie ma się wyczyścić.');
    }
}

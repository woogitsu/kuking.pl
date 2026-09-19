<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kopie\AlarmKopii;
use App\Domain\Kopie\StanKopiiBazy;
use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `AlarmKopii` nie ma prawa meldować sukcesu, nie dodzwoniwszy się — #690.
 *
 * CO BYŁO NIE TAK
 * `zadzwonJesliTrzeba()` oddawało `true` na SAM BRAK WYJĄTKU.
 * `WebhookBleduHandler::write()` z zasady nigdy nie rzuca, a klient HTTP
 * Laravela bez `throw()` oddaje 404 z odwołanego webhooka i 500 z zepsutego
 * jako zwykłą odpowiedź — więc `try/catch` wokół wysyłki był w tej ścieżce
 * KODEM NIEOSIĄGALNYM dla każdej realnej awarii kanału.
 *
 * DLACZEGO TEN TEST ISTNIEJE, SKORO NIC NIE BOLAŁO
 * Bo usterka była LATENTNA, nie czynna: `AlarmKopii` nie ma pamięci
 * wyciszania, a `SprawdzKopieBazy` odrzucało wartość zwracaną, więc nikt tej
 * nieprawdy nie czytał. Wystarczyłoby jednak dołożyć wyciszanie duplikatów
 * albo zacząć czytać wynik, żeby latentna pułapka stała się czynna BEZ
 * ŻADNEJ zmiany w samej metodzie — a wtedy jedna sekunda niedostępności
 * kanału kupowałaby ciszę o trwającej awarii kopii bazy. Dokładnie tym
 * kosztowała ta sama klasa błędu w #599.
 *
 * To czwarte wystąpienie wzorca opisanego w `docs/PULAPKI_TESTOW.md` §5
 * („narzędzie melduje sukces, nie robiąc nic"): wcześniej
 * `HealthController::powiadomWebhook()`, `kuking:sprawdz-alarm` (#682) oraz
 * `AlarmPolaczen` i `AlarmKolejki` (#687).
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że wiadomość ktoś PRZECZYTAŁ. `2xx` znaczy tyle, że usługa po drugiej
 * stronie potwierdziła odbiór żądania — kto patrzy na kanał Discorda albo
 * Slacka, jest poza zasięgiem tego kodu. Nie ma tu też prawdziwego webhooka
 * ani produkcji: atrapą jest wyłącznie warstwa HTTP.
 */
class AlarmKopiiNieUfaBrakowiWyjatkuTest extends TestCase
{
    private const URL = 'https://przyklad.test/kopie';

    /** @return array{stan: string, wiek_godzin: int|null, liczba: int, prog_godzin: int} */
    private function alarmujacyWynik(): array
    {
        return ['stan' => StanKopiiBazy::BRAK_KOPII, 'wiek_godzin' => null, 'liczba' => 0, 'prog_godzin' => 26];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('logging.channels.blad_webhook.url', self::URL);
        Log::forgetChannel('blad_webhook');
        Http::preventStrayRequests();
        WebhookBleduHandler::zapomnijOstatniaWysylke();
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        Log::forgetChannel('blad_webhook');
        parent::tearDown();
    }

    /**
     * KONTROLA DODATNIA. Bez niej test przechodziłby również wtedy, gdyby
     * metoda po prostu zawsze oddawała `false` — czyli gdyby alarm przestał
     * działać w ogóle.
     */
    public function test_przyjeta_wysylka_jest_raportowana_jako_udana(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);

        $this->assertTrue((new AlarmKopii)->zadzwonJesliTrzeba($this->alarmujacyWynik()));
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{int}>
     *
     * Kody dobrane z rzeczywistych awarii kanału, nie z listy HTTP:
     * `404` oddaje odwołany webhook Discorda, `401` — webhook z unieważnionym
     * sekretem, `429` — przekroczony limit, `500` i `502` — awaria po stronie
     * usługi. ŻADEN z nich nie rzuca wyjątku w kliencie Laravela bez `throw()`.
     */
    public static function odmowy(): array
    {
        return [
            'odwołany webhook (404)' => [404],
            'unieważniony sekret (401)' => [401],
            'przekroczony limit (429)' => [429],
            'awaria usługi (500)' => [500],
            'zły gateway (502)' => [502],
        ];
    }

    #[DataProvider('odmowy')]
    public function test_wysylka_bez_potwierdzenia_2xx_nie_jest_udana(int $status): void
    {
        Http::fake([self::URL => Http::response('', $status)]);

        $this->assertFalse(
            (new AlarmKopii)->zadzwonJesliTrzeba($this->alarmujacyWynik()),
            "Kanał odpowiedział {$status}, a alarm zameldował sukces — to jest dokładnie usterka z #690.",
        );
        Http::assertSentCount(1);
    }

    /**
     * WCZEŚNIEJSZY SUKCES W TYM SAMYM PROCESIE NIE MOŻE ZAMASKOWAĆ PORAŻKI.
     * Pamięć wyniku w handlerze jest statyczna, czyli wspólna dla całego
     * procesu — a w JEDNYM przebiegu harmonogramu idą po sobie czujki kopii,
     * połączeń i kolejki. Bez zerowania przed próbą cudzy sukces sprzed
     * chwili zostałby odczytany jako nasz. To jest ta część kryterium
     * odbioru #690, której sam odczyt kodu odpowiedzi nie zapewnia.
     */
    public function test_sukces_sprzed_chwili_nie_maskuje_biezacej_odmowy(): void
    {
        Http::fake([self::URL => Http::sequence()->push('', 204)->push('', 500)]);

        $alarm = new AlarmKopii;
        $this->assertTrue($alarm->zadzwonJesliTrzeba($this->alarmujacyWynik()));
        $this->assertFalse($alarm->zadzwonJesliTrzeba($this->alarmujacyWynik()));
        Http::assertSentCount(2);
    }

    /**
     * Granica z drugiej strony: stan, który NIE jest alarmem, i wyłączony
     * kanał nie mają prawa wysłać niczego. Bez tego przypadku „poprawka"
     * polegająca na dzwonieniu zawsze przeszłaby resztę testu.
     */
    public function test_stan_spoza_alarmu_i_wylaczony_kanal_nie_dzwonia(): void
    {
        Http::fake([self::URL => Http::response('', 204)]);

        $alarm = new AlarmKopii;
        $this->assertFalse($alarm->zadzwonJesliTrzeba([
            'stan' => StanKopiiBazy::AKTUALNA, 'wiek_godzin' => 1, 'liczba' => 3, 'prog_godzin' => 26,
        ]));

        config()->set('logging.channels.blad_webhook.url', null);
        Log::forgetChannel('blad_webhook');
        $this->assertFalse($alarm->zadzwonJesliTrzeba($this->alarmujacyWynik()));

        Http::assertSentCount(0);
    }
}

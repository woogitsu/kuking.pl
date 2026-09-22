<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\WebhookBleduHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regresja do #676: „sprawdź, czy alarm dochodzi" ma rozróżniać PRZYJĘCIE
 * od samej PRÓBY WYSYŁKI.
 *
 * DLACZEGO TEN PLIK MUSI ISTNIEĆ OSOBNO
 * `ProbaKanaluAlarmowegoTest` stawia odbiornik wyłącznie na `Http::response('ok', 200)`.
 * Zmierzone: po podmianie tej jednej odpowiedzi na 404 **wszystkie osiem**
 * testów tamtego pliku nadal przechodzi — czyli suita nie mierzyła
 * dostarczenia ani razu, a komenda meldowała sukces na 404, 500, zerwanym
 * połączeniu, timeoucie i błędzie DNS.
 *
 * Przyczyna jest w kliencie, nie w komendzie: `Http` bez `throw()` oddaje
 * 404 i 500 jako ZWYKŁĄ odpowiedź, a `WebhookBleduHandler::write()` z zasady
 * nigdy nie rzuca dalej. `try/catch` wokół `Log::channel(...)->error()` nie
 * łapie więc żadnego z tych przypadków — jest kodem nieosiągalnym dla
 * wszystkiego poza awarią samej budowy kanału.
 *
 * KAŻDY TEST NIŻEJ MA PARĘ: kontrolę dodatnią (2xx → kod 0) i ujemną
 * (nie-2xx → kod 1). Sama asercja „kod 1 przy 404" przeszłaby także wtedy,
 * gdyby komenda oblewała ZAWSZE.
 */
class ProbaKanaluPotwierdzaPrzyjecieTest extends TestCase
{
    private const ADRES = 'https://przyklad.test/webhook-proby';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logging.channels.blad_webhook.url', self::ADRES);

        // Kanał jest zapamiętywany po pierwszym użyciu — bez tego pamiętany
        // egzemplarz mógłby zostać z adresem z innego testu.
        Log::forgetChannel('blad_webhook');

        // Pamięć wyniku wysyłki jest STATYCZNA i przeżywa test.
        WebhookBleduHandler::zapomnijOstatniaWysylke();
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    //  Kontrola DODATNIA — bez niej cały plik przechodziłby przy komendzie,
    //  która oblewa zawsze.
    // -----------------------------------------------------------------

    #[Test]
    public function kanal_ktory_przyjal_wiadomosc_daje_kod_zero(): void
    {
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        $this->artisan('kuking:sprawdz-alarm')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertTrue(WebhookBleduHandler::ostatniaWysylkaSieUdala());
    }

    #[Test]
    public function puste_ciało_przy_dwusetce_to_nadal_przyjecie(): void
    {
        // Discord na udanym webhooku oddaje 204 bez treści. Pusta odpowiedź
        // NIE jest tu awarią i nie wolno jej mylić z brakiem odpowiedzi.
        Http::fake([self::ADRES => Http::response('', 204)]);

        $this->artisan('kuking:sprawdz-alarm')->assertExitCode(0);
    }

    // -----------------------------------------------------------------
    //  Kontrole UJEMNE — każdy z tych przypadków dawał przed poprawką kod 0
    //  i komunikat „Wysłano jedną wiadomość próbną".
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{int}>
     */
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
    public function kanal_ktory_ni_e_przyjal_wiadomosci_daje_kod_jeden(int $kod): void
    {
        Http::fake([self::ADRES => Http::response('nie przyjęto', $kod)]);

        $this->artisan('kuking:sprawdz-alarm')
            ->expectsOutputToContain('NIE ZOSTAŁA PRZYJĘTA')
            ->assertExitCode(1);

        // Żądanie MUSIAŁO wyjść — inaczej test przechodziłby także dla
        // komendy, która w ogóle niczego nie wysyła (pułapka 5).
        Http::assertSentCount(1);
    }

    #[Test]
    public function zerwane_polaczenie_albo_timeout_daje_kod_jeden(): void
    {
        // To jest przypadek, w którym handler ŁAPIE wyjątek u siebie
        // i z zasady nie przepuszcza go do komendy.
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out',
        ));

        $this->artisan('kuking:sprawdz-alarm')
            ->expectsOutputToContain('NIE ZOSTAŁA PRZYJĘTA')
            ->assertExitCode(1);
    }

    #[Test]
    public function komunikat_porazki_nie_wynosi_adresu_ani_tresci_wyjatku(): void
    {
        // Komunikat klienta HTTP potrafi wnieść w siebie cały adres webhooka
        // razem z sekretem, a wyjście tej komendy bywa wklejane do zgłoszeń.
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 7: Failed to connect to '.self::ADRES.'/T000/B000/TAJNY-TOKEN',
        ));

        $this->artisan('kuking:sprawdz-alarm')
            ->doesntExpectOutputToContain('TAJNY-TOKEN')
            ->doesntExpectOutputToContain('przyklad.test')
            ->doesntExpectOutputToContain('cURL')
            ->assertExitCode(1);
    }

    #[Test]
    public function porazka_dostarczenia_nie_zagluszy_prawdziwego_alarmu(): void
    {
        // Komenda nie ma prawa zostawić po sobie stanu także wtedy, gdy
        // sama oblała — inaczej nieudana próba wyciszałaby czujki.
        Http::fake([self::ADRES => Http::response('nie ma', 404)]);

        $this->artisan('kuking:sprawdz-alarm')->assertExitCode(1);

        $this->assertNull(Cache::get('kuking:polaczenia:ostatni-alarm'));
        $this->assertNull(Cache::get('kuking:kolejka:ostatni-alarm'));
    }

    #[Test]
    public function przelacznik_bez_wysylki_nadal_nie_udaje_dostarczenia(): void
    {
        // `--bez-wysylki` odpowiada tylko na pytanie o konfigurację, więc
        // kod 0 znaczy tu co innego — i komunikat musi to mówić wprost.
        Http::fake();

        $this->artisan('kuking:sprawdz-alarm', ['--bez-wysylki' => true])
            ->expectsOutputToContain('NIE jest dowodem dostarczenia')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}

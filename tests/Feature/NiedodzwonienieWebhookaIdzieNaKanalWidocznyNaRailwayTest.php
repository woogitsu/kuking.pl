<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\WebhookBleduHandler;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresja #599: fakt niedodzwonienia się na webhook błędów ma trafiać do
 * kanału, który Railway NAPRAWDĘ pokazuje w panelu.
 *
 * CO BYŁO ZEPSUTE
 * `WebhookBleduHandler::zapiszNiedodzwonienie()` pisała na kanał `single`
 * (`config/logging.php`), czyli do pliku `storage/logs/laravel.log` na dysku
 * kontenera. Railway nie daje wglądu w pliki na dysku — panel „Logs" pokazuje
 * wyłącznie strumień `stdout`/`stderr` procesu. Wpis „Nie udało się zadzwonić
 * na webhook błędów" — czyli dokładnie ta informacja, po którą sięgałoby się
 * w środku prawdziwej awarii — szedł więc do miejsca, którego nikt nigdy nie
 * zobaczy. To jest ta sama klasa usterki, którą ta klasa ma naprawiać: cichy
 * brak powiadomienia, tyle że jedno piętro niżej.
 *
 * DLACZEGO WOŁAMY HANDLER WPROST, A NIE PRZEZ `Log::channel('blad_webhook')`
 * Wołanie przez kanał `blad_webhook` wymagałoby zmockowania CAŁEGO fasady
 * `Log` (patrz `KontaktPotwierdzeniaTest::pozwolNaKontekstKorelacji()`) —
 * a to podmieniłoby też rozwiązywanie SAMEGO kanału `blad_webhook`, więc
 * `WebhookBleduHandler::write()` (kod, o który tu chodzi) nigdy by się nie
 * wykonał. Budując handler bezpośrednio i wołając jego publiczne `handle()`
 * (odziedziczone z `Monolog\Handler\AbstractProcessingHandler`), ćwiczymy
 * PRAWDZIWĄ metodę `write()` — łącznie z jej wewnętrznym wywołaniem
 * `Log::channel('stderr')` — mockując `Log` tylko dla TEGO jednego,
 * wewnętrznego wywołania.
 */
final class NiedodzwonienieWebhookaIdzieNaKanalWidocznyNaRailwayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pamięć wyniku wysyłki w handlerze jest STATYCZNA i przeżywa test —
        // patrz `NieudanyDzwonekNieKupujeCiszyTest::setUp()`.
        WebhookBleduHandler::zapomnijOstatniaWysylke();
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        parent::tearDown();
    }

    private function handler(): WebhookBleduHandler
    {
        return new WebhookBleduHandler('https://przyklad.test/webhook-bledow', Level::Error);
    }

    private function rekord(): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'blad_webhook',
            level: Level::Error,
            message: 'Coś się zepsuło.',
            context: ['exception' => new RuntimeException('testowa awaria do tego testu')],
        );
    }

    #[Test]
    public function nieudana_wysylka_pisze_na_kanal_stderr_z_powodem_bez_tresci(): void
    {
        Http::fake(['*' => Http::response('webhook skasowany', 404)]);

        // ŚCISŁA atrapa: JEDYNE zadeklarowane wywołanie fasady `Log` to
        // `channel('stderr')`. Gdyby kod nadal wołał `channel('single')`
        // (regresja #599), Mockery nie ma dla niego oczekiwania — wywołanie
        // rzuci wewnątrz `zapiszNiedodzwonienie()`, ten `catch (Throwable)`
        // je połknie (zgodnie z resztą klasy — wysyłka nie ma prawa rzucić
        // dalej), a asercja niżej NIE ZOBACZY żadnego wpisu na `$dziennik`.
        // To jest właściwy dowód regresji, nie osobna asercja o „single",
        // którą dałoby się przeoczyć.
        $dziennik = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($dziennik);

        $this->handler()->handle($this->rekord());

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertStringContainsString('Nie udało się zadzwonić na webhook błędów', $wiadomosc);
                $this->assertSame('webhook odpowiedział HTTP 404', $kontekst['powod']);

                // Kontrola UJEMNA: treść wiadomości, która nie doszła (mogła
                // nieść cokolwiek z `$record`), nie ma prawa wejść do tego
                // wpisu — patrz komentarz klasy `WebhookBleduHandler`.
                $zserializowany = (string) json_encode($kontekst, JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString('testowa awaria', $zserializowany);
                $this->assertStringNotContainsString('przyklad.test', $zserializowany);

                return true;
            })
            ->once();
    }

    #[Test]
    public function wyjatek_polaczenia_tez_pisze_na_kanal_stderr(): void
    {
        // Druga gałąź `write()`: `catch (Throwable)` po nieudanym `Http::post()`
        // (np. zerwane łącze), nie samo HTTP >= 400. Bez osobnego testu ta
        // gałąź nie byłaby zmierzona ani razu.
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $dziennik = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->once()->with('stderr')->andReturn($dziennik);

        $this->handler()->handle($this->rekord());

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst): bool {
                $this->assertStringContainsString('Nie udało się zadzwonić na webhook błędów', $wiadomosc);
                $this->assertStringContainsString('ConnectionException', $kontekst['powod']);

                return true;
            })
            ->once();
    }

    /**
     * KONTROLA DODATNIA: bez niej powyższe dwa testy przechodziłyby także dla
     * kodu, który pisze na `stderr` przy KAŻDEJ wysyłce, udanej czy nie —
     * czyli mierzyłyby hałas, nie regresję (pułapka 4).
     */
    #[Test]
    public function udana_wysylka_nie_pisze_nigdzie(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        // Żadnego oczekiwania na `Log` w ogóle — jeśli `write()` sięgnie po
        // dziennik przy udanej wysyłce, ta ścisła atrapa to zgłosi.
        Log::shouldReceive('shareContext')->andReturnSelf()->byDefault();
        Log::shouldReceive('sharedContext')->andReturn([])->byDefault();
        Log::shouldReceive('withoutContext')->andReturnSelf()->byDefault();
        Log::shouldReceive('flushSharedContext')->andReturnSelf()->byDefault();

        $this->handler()->handle($this->rekord());

        $this->assertTrue(WebhookBleduHandler::ostatniaWysylkaSieUdala());
    }
}

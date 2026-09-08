<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Kanał `blad_webhook` (`config/logging.php`) — powiadomienie o błędzie 500
 * na Slacku/Discordzie, na czas, zanim da się zainstalować Sentry
 * (`docs/infra/MONITORING_BLEDOW.md`).
 *
 * DWIE RZECZY, KTÓRE TEN TEST MUSI UDOWODNIĆ
 *
 *   1. Bez `LOG_BLAD_WEBHOOK_URL` kanał jest CAŁKOWICIE martwy: zero żądań
 *      HTTP, zero wyjątku z SAMEGO mechanizmu powiadamiania. To jest warunek
 *      konieczny — lokalnie, w CI i w każdym innym teście tej zmiennej nikt
 *      nie ustawia, więc strona ma działać dokładnie tak, jak działa dziś.
 *
 *   2. Gdy zmienna JEST ustawiona, treść wysłana na webhook nie zawiera
 *      argumentu wywołania, które doprowadziło do wyjątku. `$e->getTrace()`
 *      potrafi zawierać dokładne wartości przekazane do funkcji — adres
 *      e-mail, treść formularza, hasło podane wprost. AGENTS.md §7 zakazuje
 *      PII w logach, a to jest jedyny log w serwisie, który wychodzi do
 *      ZEWNĘTRZNEJ usługi.
 *
 * DLACZEGO `Http::fake()`, A NIE `Log::spy()`
 * `Log::spy()`/`Log::listen()` widziałyby wywołanie `Log::channel(...)
 * ->error(...)` z `bootstrap/app.php` — czyli SUROWY obiekt wyjątku, ZANIM
 * `App\Logging\WebhookBleduHandler` przytnie go do bezpiecznej treści. Test na
 * tym poziomie nie udowodniłby niczego o bajtach, które NAPRAWDĘ wychodzą
 * z serwisu. `Http::fake()` przechwytuje dokładnie tę granicę — dlatego
 * kanał jest zbudowany na `Illuminate\Support\Facades\Http`, a nie na
 * wbudowanym `Monolog\Handler\SlackWebhookHandler` (ten łączy się przez
 * `curl_init()` z pominięciem klienta HTTP Laravela i nie da się go tak
 * przetestować — patrz komentarz `App\Logging\WebhookBleduLogger`).
 */
final class BladTrafiaNaWebhookBezDanychOsobowychTest extends TestCase
{
    private const ADRES_WEBHOOKA = 'https://discord.example.test/api/webhooks/000/tajny-token/slack';

    /**
     * Trasa istnieje TYLKO w pamięci tego testu (ten sam wzorzec, co
     * `StronyBleduPoPolskuTest::test_500_jest_po_polsku_i_nie_pyta_bazy`) —
     * po to, żeby przejść PRAWDZIWĄ, całą ścieżkę Laravela: routing → wyjątek
     * → `Handler::report()` → `$exceptions->report()` z `bootstrap/app.php`
     * → kanał `blad_webhook`. Wywołanie samego `report()` z pominięciem
     * routingu nie dowodziłoby, że strona dalej odpowiada normalnie.
     */
    private function zarejestrujTraseWybuchajacaZParametrem(string $tajnyEmail): void
    {
        Route::get('/_test/blad-webhook', function () use ($tajnyEmail): void {
            $wywolajZParametrem = function (string $email): never {
                throw new RuntimeException('Testowa awaria do testu webhooka błędów.');
            };

            $wywolajZParametrem($tajnyEmail);
        })->middleware('web');
    }

    public function test_bez_zmiennej_srodowiskowej_kanal_jest_martwy_i_strona_dziala(): void
    {
        config(['logging.channels.blad_webhook.url' => null, 'app.debug' => false]);

        Http::fake();

        $this->zarejestrujTraseWybuchajacaZParametrem('ktos@example.com');

        // Strona 500 — tyle samo, co dziś, bez tej funkcji. Awaria SAMEGO
        // mechanizmu powiadamiania nie może zamienić strony błędu w coś
        // gorszego (nieobsłużony wyjątek zamiast czytelnej strony).
        $this->get('/_test/blad-webhook')->assertStatus(500);

        Http::assertNothingSent();
    }

    public function test_z_ustawiona_zmienna_blad_trafia_na_webhook_bez_argumentow_wywolania(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA, 'app.debug' => false]);

        Http::fake();

        $tajnyEmail = 'sekret+test@example.com';
        $this->zarejestrujTraseWybuchajacaZParametrem($tajnyEmail);

        $this->get('/_test/blad-webhook')->assertStatus(500);

        Http::assertSent(function ($request) use ($tajnyEmail): bool {
            if ($request->url() !== self::ADRES_WEBHOOKA) {
                return false;
            }

            $tresc = (string) ($request['text'] ?? '');

            $this->assertStringContainsString('RuntimeException', $tresc);
            $this->assertStringContainsString('Testowa awaria do testu webhooka błędów.', $tresc);

            // Właściwy dowód wymogu #3: argument wywołania, które
            // doprowadziło do wyjątku, NIE WYCHODZI z serwisu.
            $this->assertStringNotContainsString($tajnyEmail, $tresc);

            return true;
        });
    }

    /**
     * Filtr POZIOMU samego kanału (`'level' => 'error'` w
     * `config/logging.php`) — sprawdzony NIEZALEŻNIE od tego, co Laravel
     * raportuje, żeby wykryć regresję nawet gdyby ktoś kiedyś zaczął pisać
     * na ten kanał z innego miejsca niż `bootstrap/app.php`.
     */
    public function test_wpisy_ponizej_poziomu_error_nie_trafiaja_na_webhook(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);

        Http::fake();

        Log::channel('blad_webhook')->info('To nie powinno wyjść na zewnątrz.');
        Log::channel('blad_webhook')->warning('To też nie.');

        Http::assertNothingSent();
    }
}

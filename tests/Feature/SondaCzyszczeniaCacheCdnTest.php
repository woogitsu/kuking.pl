<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Wyłączone czyszczenie cache CDN musi być WIDAĆ (audyt G-03).
 *
 * DLACZEGO TEN PLIK POWSTAŁ
 * Na produkcji `CLOUDFLARE_ZONE_ID` i `CLOUDFLARE_PURGE_TOKEN` nie są
 * ustawione, więc `App\Jobs\PurgePublicMediaCache` wychodzi na `return`
 * i nie czyści niczego — NIGDY. Samo w sobie nie jest to awarią: wyłącznik
 * jest legalny i opisany w `config/kuking.php`. Awarią jest to, że NIKT SIĘ
 * O TYM NIE DOWIE:
 *
 *   * zadanie kończy się SUKCESEM, więc nie ma go w `failed_jobs`;
 *   * pisze `Log::warning`, a kanał alarmowy `blad_webhook` ma w
 *     `config/logging.php` poziom `error` ustawiony na sztywno, więc
 *     ostrzeżenia nie przyjmuje;
 *   * skutek — „skasowane zdjęcie, które jeszcze się otwiera" — wygląda
 *     dokładnie tak samo jak działające czyszczenie.
 *
 * To jest ten sam rodzaj cichej porażki, co `turnstile_bez_kluczy`
 * i `analityka_bez_tokenu` (D-050), i dlatego mieszka w tym samym miejscu:
 * w `/health`, jako `degraded`, a nie jako wywrócone kasowanie zdjęcia.
 *
 * KONTROLA DODATNIA I UJEMNA STOJĄ TU OBOK SIEBIE I TO JEST CAŁY SENS PLIKU.
 * Sonda, która zapala się zawsze, jest szumem; sonda, która nie zapala się
 * nigdy, jest ozdobnikiem. Sprawdzamy więc oba końce.
 */
class SondaCzyszczeniaCacheCdnTest extends TestCase
{
    use RefreshDatabase;

    private const POWOD = 'czyszczenie_cdn_wylaczone';

    /**
     * KONTROLA DODATNIA: produkcja z pustą konfiguracją czyszczenia świeci.
     *
     * Dokładnie stan zastany na produkcji 21.09.2026: jest
     * `CLOUDFLARE_ANALYTICS_TOKEN`, nie ma `CLOUDFLARE_ZONE_ID` ani
     * `CLOUDFLARE_PURGE_TOKEN`.
     */
    public function test_produkcja_bez_konfiguracji_czyszczenia_jest_degraded(): void
    {
        $this->produkcjaBezSzumu();
        $this->bezKonfiguracjiCzyszczenia();

        $odpowiedz = $this->get('/health');

        $odpowiedz
            // ŚWIADOMIE 200, NIE 503. `cdn` nie jest na liście KRYTYCZNE:
            // healthcheck oddający 503 już raz położył ten serwis, a restart
            // kontenera nie dopisze nikomu tokenu Cloudflare.
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.cdn.ok', false)
            ->assertJsonPath('checks.cdn.error', self::POWOD)
            // Baza i zdjęcia są całe — sygnał dotyczy WYŁĄCZNIE konfiguracji.
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.media.ok', true);

        $this->assertContains(
            $odpowiedz->json('checks.cdn.error'),
            HealthController::POWODY,
            'Powód „'.self::POWOD.'" nie stoi w zamkniętym zbiorze '
            .'HealthController::POWODY. Ta odpowiedź jest publiczna, '
            .'a HealthNieZdradzaSzczegolowTest pilnuje, że nie wychodzi z niej '
            .'nic spoza tego zbioru.',
        );
    }

    /**
     * KONTROLA UJEMNA: z obiema zmiennymi na miejscu sonda MILCZY.
     *
     * Bez tej asercji nie wiadomo, czy poprzedni test zapalił się od pustej
     * konfiguracji, czy świeci zawsze.
     */
    public function test_produkcja_z_pelna_konfiguracja_czyszczenia_milczy(): void
    {
        $this->produkcjaBezSzumu();

        config([
            'kuking.media.cdn_purge.zone_id' => 'udawana-strefa',
            'kuking.media.cdn_purge.token' => 'udawany-token',
        ]);

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.cdn.ok', true);
    }

    /**
     * Sama strefa bez tokenu (i odwrotnie) to nadal wyłączone czyszczenie —
     * `PurgePublicMediaCache` wymaga OBU. Gdyby sonda pytała tylko o jedną
     * zmienną, wdrożenie z połową konfiguracji świeciłoby na zielono, nie
     * czyszcząc niczego.
     */
    public function test_polowa_konfiguracji_to_nadal_wylaczone_czyszczenie(): void
    {
        $this->produkcjaBezSzumu();

        config([
            'kuking.media.cdn_purge.zone_id' => 'udawana-strefa',
            'kuking.media.cdn_purge.token' => '',
        ]);

        $this->get('/health')->assertJsonPath('checks.cdn.error', self::POWOD);

        config([
            'kuking.media.cdn_purge.zone_id' => '',
            'kuking.media.cdn_purge.token' => 'udawany-token',
        ]);

        $this->get('/health')->assertJsonPath('checks.cdn.error', self::POWOD);
    }

    /**
     * POZA PRODUKCJĄ SONDA MILCZY — i to nie jest wyjątek dopisany dla wygody.
     *
     * Lokalnie i w testach nie ma żadnego CDN-u, więc pusta konfiguracja jest
     * tam stanem POPRAWNYM (mówi o tym wprost komentarz przy
     * `kuking.media.cdn_purge`). Sygnał świecący na każdej maszynie
     * dewelopera uczy ignorować całe pole `checks` — dokładnie ta sama lekcja,
     * co przy Turnstile i analityce.
     */
    public function test_poza_produkcja_pusta_konfiguracja_nie_jest_sygnalem(): void
    {
        Artisan::call('storage:link');

        $this->bezKonfiguracjiCzyszczenia();

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('checks.cdn.ok', true);
    }

    /**
     * Komunikat sondy NIE WYCHODZI do publicznej odpowiedzi — w JSON-ie stoi
     * sam kod powodu. `/health` czyta każdy, łącznie z osobą, która szuka,
     * po czym uderzyć; nazwy zmiennych i stan migracji bucketów zostają
     * w logu i na webhooku właściciela.
     */
    public function test_odpowiedz_nie_zdradza_nazw_zmiennych_ani_bucketow(): void
    {
        $this->produkcjaBezSzumu();
        $this->bezKonfiguracjiCzyszczenia();

        $tresc = $this->get('/health')->getContent();

        foreach (['CLOUDFLARE_ZONE_ID', 'CLOUDFLARE_PURGE_TOKEN', 'r2_legacy', 'kuking:przenies-zdjecia'] as $czego) {
            $this->assertStringNotContainsString(
                $czego,
                (string) $tresc,
                'Publiczna odpowiedź /health nie ma prawa nieść „'.$czego.'".',
            );
        }
    }

    /**
     * Produkcja bez ŻADNEGO innego powodu do `degraded` — żeby asercja
     * `status` mierzyła tę jedną sondę, a nie sumę wszystkich.
     */
    private function produkcjaBezSzumu(): void
    {
        Artisan::call('storage:link');

        config([
            'kuking.turnstile.klucz_publiczny' => 'test-klucz-publiczny',
            'kuking.turnstile.sekret' => 'test-sekret',
            'mail.default' => 'smtp',
            'kuking.google.wlaczone' => false,
            'kuking.facebook.wlaczone' => false,
            'kuking.analytics.cloudflare.token' => 'udawany-token-analityki',
        ]);

        $this->app->detectEnvironment(static fn (): string => 'production');
        // Produkcja z poprawnym trybem debugowania i ciasteczkiem sesji —
        // inaczej `/health` zgłosi własną, niezwiązaną awarię (audyt B10-04).
        config(['app.debug' => false, 'session.secure' => true]);
    }

    private function bezKonfiguracjiCzyszczenia(): void
    {
        config([
            'kuking.media.cdn_purge.zone_id' => null,
            'kuking.media.cdn_purge.token' => null,
        ]);
    }
}

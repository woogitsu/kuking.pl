<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * `kuking:sprawdz-alarm --przez-wyjatek` zastępuje sprawdzenie kanału błędów
 * przez `php artisan tinker --execute="report(…)"` (issue #2223, D-333).
 *
 * Droga jest inna niż przy zwykłej próbie: `report()` → wywołanie zwrotne
 * w `bootstrap/app.php` → `SeriaAlarmow` → kanał. Każdy test ma parę —
 * przyjęcie (kod 0) i odmowę (kod 1) — bo samo „kod 1 przy 404" przeszłoby
 * także przy komendzie, która oblewa zawsze.
 */
class ProbaKanaluPrzezWyjatekTest extends TestCase
{
    private const ADRES = 'https://przyklad.test/webhook-proby-2223';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logging.channels.blad_webhook.url', self::ADRES);
        Log::forgetChannel('blad_webhook');
        WebhookBleduHandler::zapomnijOstatniaWysylke();
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        parent::tearDown();
    }

    public function test_proba_idzie_droga_bledu_500_i_kanal_ja_przyjmuje(): void
    {
        Http::fake([self::ADRES => Http::response('', 204)]);

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])
            ->expectsOutputToContain('drogą błędu 500')
            ->assertExitCode(0);

        // To jest wiadomość z obsługi wyjątków (klasa wyjątku w treści),
        // a nie zwykła próba kanału — ta miałaby treść „PRÓBA KANAŁU…".
        Http::assertSentCount(1);
        Http::assertSent(fn ($zadanie) => str_contains((string) $zadanie['text'], 'RuntimeException')
            && ! str_contains((string) $zadanie['text'], 'PRÓBA KANAŁU'));
    }

    public function test_kanal_ktory_nie_przyjal_daje_kod_jeden(): void
    {
        Http::fake([self::ADRES => Http::response('nie ma', 404)]);

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])
            ->expectsOutputToContain('NIE ZOSTAŁA PRZYJĘTA')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_druga_proba_w_oknie_serii_mowi_ze_nie_wyszla(): void
    {
        Http::fake([self::ADRES => Http::response('ok', 200)]);

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])->assertExitCode(0);
        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])
            ->expectsOutputToContain('NIE DOSZŁA')
            ->assertExitCode(1);

        Http::assertSentCount(1);
    }

    public function test_bez_adresu_nic_nie_wysyla(): void
    {
        config()->set('logging.channels.blad_webhook.url', null);
        Http::fake();

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])
            ->expectsOutputToContain('WYŁĄCZONY')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PurgePublicMediaCache;
use App\Moderacja\KlientOpenAI;
use App\Poczta\BrakKonfiguracjiEmailLabs;
use App\Support\DozwolonyHostApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Klucz API i treść idą tylko do dostawcy, nie na dowolny host HTTPS (#991).
 *
 * Adresy EmailLabs, OpenAI i czyszczenia Cloudflare przychodzą ze zmiennych
 * środowiskowych. Przedtem wystarczało „https://" — każdy host dostawał klucz
 * i treść: cudze listy, cudze wpisy do oceny. Każdy przypadek odmowy sprawdza
 * DWIE rzeczy: klient odmawia ORAZ żadne żądanie nie wyszło. Kontrola dodatnia
 * (dozwolony host → żądanie wychodzi) pilnuje, że atrapa w ogóle działa.
 */
class SekretyTylkoDoDostawcyTest extends TestCase
{
    private const KLUCZ = 'tajny-klucz-do-testu-991';

    /** @return array<string, array{string}> */
    public static function obceAdresy(): array
    {
        return [
            'obcy host' => ['https://przechwyt.example.com/v1'],
            'dane logowania przed hostem' => ['https://api.openai.com@przechwyt.example.com/v1'],
            'odwrotny ukosnik' => ['https://przechwyt.example.com\\@api.openai.com/v1'],
            'poddomena podszywajaca' => ['https://api.openai.com.przechwyt.example.com/v1'],
            'inny port' => ['https://api.openai.com:8443/v1'],
            'zwykly http' => ['http://api.openai.com/v1'],
            'pusty' => [''],
        ];
    }

    #[DataProvider('obceAdresy')]
    public function test_straznik_odrzuca_adres_spoza_listy(string $adres): void
    {
        $this->assertFalse(DozwolonyHostApi::zgodny($adres, ['api.openai.com']));
    }

    public function test_straznik_przepuszcza_host_z_listy(): void
    {
        $this->assertTrue(DozwolonyHostApi::zgodny('https://api.openai.com/v1/moderations', ['api.openai.com']));
        $this->assertTrue(DozwolonyHostApi::zgodny('https://API.openai.com:443/v1/moderations', ['api.openai.com']));
    }

    // ---------------------------------------------------------------
    // EMAILLABS
    // ---------------------------------------------------------------

    private function poczta(string $adres): void
    {
        config([
            'mail.default' => 'emaillabs',
            'services.emaillabs.key' => self::KLUCZ,
            'services.emaillabs.secret' => self::KLUCZ.'-autoryzacja',
            'services.emaillabs.smtp_account' => '1.kuking.smtp',
            'services.emaillabs.endpoint' => $adres,
        ]);
        Mail::purge('emaillabs');
    }

    public function test_poczta_na_obcy_host_nie_startuje_i_nic_nie_wychodzi(): void
    {
        Http::fake();
        $this->poczta('https://przechwyt.example.com/v2.1/email');

        $wyjatek = null;

        try {
            Mail::mailer('emaillabs')->raw('Poufna treść listu', fn ($m) => $m->to('basia@wp.pl')->subject('Test'));
        } catch (Throwable $e) {
            $wyjatek = $e;
        }

        $this->assertInstanceOf(BrakKonfiguracjiEmailLabs::class, $wyjatek);
        $this->assertStringContainsString('EMAILLABS_ENDPOINT', $wyjatek->getMessage());
        $this->assertStringNotContainsString(self::KLUCZ, $wyjatek->getMessage());
        $this->assertStringNotContainsString('przechwyt', $wyjatek->getMessage());
        Http::assertNothingSent();
    }

    public function test_poczta_do_emaillabs_wychodzi(): void
    {
        Http::fake(['https://api.emaillabs.io/*' => Http::response([
            'meta' => ['numberOfErrors' => 0, 'numberOfData' => 1, 'status' => 200],
            'data' => [['to' => ['email' => 'basia@wp.pl'], 'status' => 'sent']],
        ])]);
        $this->poczta('https://api.emaillabs.io/v2.1/email');

        Mail::mailer('emaillabs')->raw('Treść listu', fn ($m) => $m->to('basia@wp.pl')->subject('Test'));

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------
    // OPENAI
    // ---------------------------------------------------------------

    private function model(string $adres): void
    {
        config([
            'kuking.moderation.model.klucz' => self::KLUCZ,
            'kuking.moderation.model.endpoint' => $adres,
        ]);
    }

    public function test_model_na_obcy_host_odmawia_i_nic_nie_wychodzi(): void
    {
        Http::fake();
        $log = Log::spy();
        $this->model('https://przechwyt.example.com/v1/moderations');

        $this->assertNull((new KlientOpenAI)->ocenTekst('Cudzy wpis do oceny'));

        Http::assertNothingSent();
        $log->shouldHaveReceived('error')->withArgs(
            fn (string $wiadomosc, array $kontekst) => ($kontekst['zmienna'] ?? null) === 'KUKING_MODEL_ENDPOINT'
                && ! str_contains($wiadomosc.json_encode($kontekst), self::KLUCZ)
                && ! str_contains($wiadomosc.json_encode($kontekst), 'przechwyt'),
        )->once();
    }

    public function test_model_na_openai_wychodzi(): void
    {
        Http::fake(['https://api.openai.com/*' => Http::response([
            'results' => [['category_scores' => ['hate' => 0.01]]],
        ])]);
        $this->model('https://api.openai.com/v1/moderations');

        $this->assertNotNull((new KlientOpenAI)->ocenTekst('Rosół z makaronem'));
        Http::assertSentCount(1);
    }

    public function test_komenda_diagnostyczna_nie_pyta_obcego_hosta(): void
    {
        Http::fake();
        $this->model('https://przechwyt.example.com/v1/moderations');

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('host spoza OpenAI')
            ->doesntExpectOutputToContain(self::KLUCZ)
            ->assertFailed();

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // CLOUDFLARE — CZYSZCZENIE CACHE
    // ---------------------------------------------------------------

    private function cdn(string $adres): void
    {
        config([
            'kuking.media.cdn_purge.zone_id' => 'zona123',
            'kuking.media.cdn_purge.token' => self::KLUCZ,
            'kuking.media.cdn_purge.endpoint' => $adres,
        ]);
    }

    public function test_czyszczenie_na_obcy_host_pada_i_nic_nie_wychodzi(): void
    {
        Http::fake();
        $this->cdn('https://przechwyt.example.com/zones/{zone}/purge_cache');

        $wyjatek = null;

        try {
            (new PurgePublicMediaCache(['https://cdn.kuking.pl/media/a_feed.webp']))->handle();
        } catch (Throwable $e) {
            $wyjatek = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $wyjatek);
        $this->assertStringContainsString('CLOUDFLARE_PURGE_ENDPOINT', $wyjatek->getMessage());
        $this->assertStringNotContainsString(self::KLUCZ, $wyjatek->getMessage());
        Http::assertNothingSent();
    }

    public function test_czyszczenie_w_cloudflare_wychodzi(): void
    {
        Http::fake(['https://api.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->cdn('https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache');

        (new PurgePublicMediaCache(['https://cdn.kuking.pl/media/a_feed.webp']))->handle();

        Http::assertSentCount(1);
    }
}

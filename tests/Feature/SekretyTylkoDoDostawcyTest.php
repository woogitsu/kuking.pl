<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PurgePublicMediaCache;
use App\Moderacja\KlientOpenAI;
use App\Poczta\BrakKonfiguracjiEmailLabs;
use App\Providers\PocztaServiceProvider;
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
            'obcy host' => ['https://przechwyt.example.com/v1/moderations'],
            'dane logowania przed hostem' => ['https://api.openai.com@przechwyt.example.com/v1/moderations'],
            'puste dane logowania' => ['https://@api.openai.com/v1/moderations'],
            'odwrotny ukosnik' => ['https://przechwyt.example.com\\@api.openai.com/v1/moderations'],
            'poddomena podszywajaca' => ['https://api.openai.com.przechwyt.example.com/v1/moderations'],
            'koncowa kropka hosta' => ['https://api.openai.com./v1/moderations'],
            'homoglif cyrylica' => ["https://\u{0430}pi.openai.com/v1/moderations"],
            'homoglif jako punycode' => ['https://xn--pi-7kc.openai.com/v1/moderations'],
            'fragment z malpa' => ['https://api.openai.com#@przechwyt.example.com'],
            'fragment za sciezka' => ['https://api.openai.com/v1/moderations#x'],
            'query' => ['https://api.openai.com/v1/moderations?klucz=1'],
            'pusty znak zapytania' => ['https://api.openai.com/v1/moderations?'],
            'inna sciezka' => ['https://api.openai.com/v1/chat/completions'],
            'brak sciezki' => ['https://api.openai.com'],
            'dopisek za sciezka' => ['https://api.openai.com/v1/moderations/../files'],
            'ukosnik na koncu' => ['https://api.openai.com/v1/moderations/'],
            'zakodowana litera' => ['https://api.openai.com/v1/%6doderations'],
            'inny port' => ['https://api.openai.com:8443/v1/moderations'],
            'zwykly http' => ['http://api.openai.com/v1/moderations'],
            'znak nowej linii' => ["https://api.openai.com/v1/moderations\n"],
            'pusty' => [''],
        ];
    }

    #[DataProvider('obceAdresy')]
    public function test_straznik_odrzuca_adres_spoza_listy(string $adres): void
    {
        $this->assertFalse(DozwolonyHostApi::zgodny($adres, KlientOpenAI::HOSTY, KlientOpenAI::SCIEZKA));
        $this->assertNotNull(DozwolonyHostApi::powod($adres, KlientOpenAI::HOSTY, KlientOpenAI::SCIEZKA));
    }

    public function test_straznik_przepuszcza_kanoniczne_adresy(): void
    {
        $this->assertTrue(DozwolonyHostApi::zgodny(KlientOpenAI::ADRES, KlientOpenAI::HOSTY, KlientOpenAI::SCIEZKA));
        $this->assertTrue(DozwolonyHostApi::zgodny('https://API.openai.com:443/v1/moderations', KlientOpenAI::HOSTY, KlientOpenAI::SCIEZKA));
        $this->assertTrue(DozwolonyHostApi::zgodny(PocztaServiceProvider::ADRES_API, PocztaServiceProvider::HOSTY_API, PocztaServiceProvider::SCIEZKA_API));
        $this->assertTrue(DozwolonyHostApi::zgodny(
            'https://api.cloudflare.com/client/v4/zones/023e105f4ecef8ad9ca31a8372d0c353/purge_cache',
            PurgePublicMediaCache::HOSTY,
            PurgePublicMediaCache::SCIEZKA,
        ));
    }

    /**
     * Warianty wprost z kryteriów #991 dla EmailLabs.
     *
     * @return array<string, array{string}>
     */
    public static function obceAdresyPoczty(): array
    {
        return [
            'sufiks podszywajacy' => ['https://api.emaillabs.io.example.org/v2.1/email'],
            'dane logowania' => ['https://user@api.emaillabs.io/v2.1/email'],
            'inny port' => ['https://api.emaillabs.io:8443/v2.1/email'],
            'inna sciezka' => ['https://api.emaillabs.io/v2.1/emails'],
            'stara wersja api' => ['https://api.emaillabs.io/v1/email'],
            'query' => ['https://api.emaillabs.io/v2.1/email?x=1'],
            'fragment z malpa' => ['https://api.emaillabs.io/v2.1/email#@przechwyt.example.com'],
        ];
    }

    #[DataProvider('obceAdresyPoczty')]
    public function test_straznik_poczty_odrzuca_warianty_z_kryteriow(string $adres): void
    {
        $this->assertFalse(DozwolonyHostApi::zgodny($adres, PocztaServiceProvider::HOSTY_API, PocztaServiceProvider::SCIEZKA_API));
    }

    /**
     * Powód nazywa CZĘŚĆ adresu, nigdy jej wartości — trafia do logu,
     * wyjątku i na webhook.
     */
    public function test_powod_nie_powtarza_adresu(): void
    {
        $powod = DozwolonyHostApi::powod('https://api.openai.com/v1/moderations?klucz='.self::KLUCZ, KlientOpenAI::HOSTY, KlientOpenAI::SCIEZKA);

        $this->assertNotNull($powod);
        $this->assertStringNotContainsString(self::KLUCZ, $powod);
        $this->assertStringNotContainsString('openai', $powod);
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

    #[DataProvider('obceAdresyPoczty')]
    public function test_poczta_na_zly_adres_nie_startuje_i_nic_nie_wychodzi(string $adres): void
    {
        Http::fake();
        $this->poczta($adres);

        $wyjatek = null;

        try {
            Mail::mailer('emaillabs')->raw('Poufna treść listu', fn ($m) => $m->to('basia@wp.pl')->subject('Test'));
        } catch (Throwable $e) {
            $wyjatek = $e;
        }

        $this->assertInstanceOf(BrakKonfiguracjiEmailLabs::class, $wyjatek);
        $this->assertStringContainsString('EMAILLABS_ENDPOINT', $wyjatek->getMessage());
        $this->assertStringNotContainsString(self::KLUCZ, $wyjatek->getMessage());
        $this->assertStringNotContainsString($adres, $wyjatek->getMessage());
        $this->assertStringNotContainsString('przechwyt', $wyjatek->getMessage());
        $this->assertStringNotContainsString('example.org', $wyjatek->getMessage());
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

        $this->assertFalse(KlientOpenAI::oceniamy());
        $this->assertNull((new KlientOpenAI)->ocenTekst('Cudzy wpis do oceny'));
        // Druga treść w tym samym oknie: nic nie wychodzi i NIE MA drugiego
        // alarmu — jeden błąd konfiguracji to jedno zgłoszenie na godzinę.
        $this->assertNull((new KlientOpenAI)->ocenTekst('Kolejny wpis'));

        Http::assertNothingSent();
        $log->shouldHaveReceived('error')->withArgs(
            fn (string $wiadomosc, array $kontekst) => ($kontekst['zmienna'] ?? null) === 'KUKING_MODEL_ENDPOINT'
                && ! str_contains($wiadomosc.json_encode($kontekst), self::KLUCZ)
                && ! str_contains($wiadomosc.json_encode($kontekst), 'przechwyt'),
        )->once();
    }

    /** Warianty z kryteriów #991 w kształcie `KUKING_MODEL_ENDPOINT`. */
    #[DataProvider('obceAdresy')]
    public function test_model_na_zly_adres_nie_wysyla_niczego(string $adres): void
    {
        Http::fake();
        $this->model($adres);

        $this->assertFalse(KlientOpenAI::oceniamy());
        $this->assertNull((new KlientOpenAI)->ocenTekst('Cudzy wpis do oceny'));
        $this->assertNull((new KlientOpenAI)->ocenObraz('data:image/png;base64,AAAA'));

        Http::assertNothingSent();
    }

    public function test_blad_konfiguracji_modelu_nazywa_zmienna_bez_adresu_i_klucza(): void
    {
        $this->model('https://przechwyt.example.com/v1/moderations');

        $blad = (string) KlientOpenAI::bladKonfiguracji();

        $this->assertStringContainsString('KUKING_MODEL_ENDPOINT', $blad);
        $this->assertStringNotContainsString('przechwyt', $blad);
        $this->assertStringNotContainsString(self::KLUCZ, $blad);

        // Kontrola dodatnia: poprawny adres = brak błędu i model ocenia.
        $this->model(KlientOpenAI::ADRES);
        $this->assertNull(KlientOpenAI::bladKonfiguracji());
        $this->assertTrue(KlientOpenAI::oceniamy());
    }

    public function test_zgloszenie_wraca_po_uplywie_okna(): void
    {
        Http::fake();
        $log = Log::spy();
        $this->model('https://przechwyt.example.com/v1/moderations');

        (new KlientOpenAI)->ocenTekst('Pierwszy');
        $this->travel(KlientOpenAI::OKNO_ZGLOSZENIA_SEKUND + 1)->seconds();
        (new KlientOpenAI)->ocenTekst('Po godzinie');

        $log->shouldHaveReceived('error')->twice();
        Http::assertNothingSent();
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
            ->expectsOutputToContain('nie prowadzi do API moderacji OpenAI')
            ->expectsOutputToContain('KUKING_MODEL_ENDPOINT')
            ->doesntExpectOutputToContain(self::KLUCZ)
            ->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * Wyjście komendy ląduje w czatach i zgłoszeniach: z adresu tylko host,
     * nawet gdy ktoś wkleił w zmienną coś z tokenem.
     */
    public function test_komenda_diagnostyczna_nie_drukuje_pelnego_adresu(): void
    {
        Http::fake();
        $this->model('https://api.openai.com/v1/moderations?token=wklejony-sekret-991');

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('host api.openai.com')
            ->doesntExpectOutputToContain('wklejony-sekret-991')
            ->doesntExpectOutputToContain(self::KLUCZ)
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_komenda_poczty_pokazuje_tylko_host(): void
    {
        Http::fake();
        $this->poczta('https://api.emaillabs.io/v2.1/email?token=wklejony-sekret-991');

        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'ty@wp.pl'])
            ->expectsOutputToContain('api.emaillabs.io')
            ->expectsOutputToContain('EMAILLABS_ENDPOINT')
            ->doesntExpectOutputToContain('wklejony-sekret-991')
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

    /**
     * `CLOUDFLARE_ZONE_ID` też trafia do adresu. Strefa z ukośnikiem
     * albo `?` przestawiłaby żądanie na inną metodę API z tym samym tokenem.
     */
    public function test_strefa_nie_przestawia_sciezki(): void
    {
        Http::fake();
        $this->cdn('https://api.cloudflare.com/client/v4/zones/{zone}/purge_cache');
        config(['kuking.media.cdn_purge.zone_id' => 'zona123/dns_records?x=']);

        $this->assertNotNull(PurgePublicMediaCache::powodZlegoAdresu());

        try {
            (new PurgePublicMediaCache(['https://cdn.kuking.pl/media/a_feed.webp']))->handle();
            $this->fail('Zadanie nie może wysłać tokenu pod przestawioną ścieżkę.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('dns_records', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * Błąd konfiguracji nie mija sam — w kolejce zadanie pada od razu,
     * zamiast pięć razy w ciągu kwadransa.
     */
    public function test_czyszczenie_w_kolejce_przy_zlym_adresie_pada_od_razu(): void
    {
        Http::fake();
        $this->cdn('https://przechwyt.example.com/client/v4/zones/{zone}/purge_cache');

        $zadanie = (new PurgePublicMediaCache(['https://cdn.kuking.pl/media/a_feed.webp']))->withFakeQueueInteractions();
        $zadanie->handle();

        $zadanie->assertFailed();
        $zadanie->assertNotReleased();
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

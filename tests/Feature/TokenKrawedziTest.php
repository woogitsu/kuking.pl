<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Token krawędziowy `X-Kuking-Edge-Token` (issue #1306).
 *
 * CO BYŁO OTWARTE
 * `NormalizeForwardedFor` liczy `X-Forwarded-For` od prawej i ufa temu,
 * że ostatni wpis dopisał Cloudflare. Żądanie wysłane wprost na origin
 * (z pominięciem Cloudflare) niosło łańcuch w całości od klienta — i jego
 * ostatni wpis stawał się `$request->ip()`, a `X-Forwarded-Proto` ustalał
 * schemat. Aplikacja nie miała czym odróżnić jednego od drugiego.
 *
 * Testy stoją na trasie testowej, która oddaje to, co aplikacja widzi PO
 * przejściu przez cały stos globalny: adres klienta i schemat.
 */
class TokenKrawedziTest extends TestCase
{
    private const SCIEZKA = '_test/pochodzenie';

    private const SEKRET = 'sekret-krawedzi-0123456789abcdef0123456789abcdef';

    private const PODROBIONY = '198.51.100.66';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/'.self::SCIEZKA, fn () => response()->json([
            'ip' => request()->ip(),
            'https' => request()->secure(),
            'token_widoczny' => request()->headers->has('X-Kuking-Edge-Token'),
        ]));
    }

    private function wlacz(string $tryb, string $poprzedni = ''): void
    {
        config([
            'proxy.token_krawedzi.aktualny' => self::SEKRET,
            'proxy.token_krawedzi.poprzedni' => $poprzedni,
            'proxy.token_krawedzi.tryb' => $tryb,
        ]);
    }

    /** @return array<string, string> */
    private function naglowki(?string $token): array
    {
        $naglowki = [
            'X-Forwarded-For' => self::PODROBIONY,
            'X-Forwarded-Proto' => 'https',
        ];

        if ($token !== null) {
            $naglowki['X-Kuking-Edge-Token'] = $token;
        }

        return $naglowki;
    }

    public function test_bez_sekretu_bramka_jest_wylaczona_i_nic_sie_nie_zmienia(): void
    {
        $log = Log::spy();

        $this->get('/'.self::SCIEZKA, $this->naglowki(null))
            ->assertOk()
            ->assertJson(['ip' => self::PODROBIONY, 'https' => true]);

        $log->shouldNotHaveReceived('warning');
    }

    public function test_obserwacja_przepuszcza_zadanie_bez_tokenu_i_tylko_loguje(): void
    {
        $this->wlacz('obserwacja');
        $log = Log::spy();

        $this->get('/'.self::SCIEZKA, $this->naglowki(null))
            ->assertOk()
            ->assertJson(['ip' => self::PODROBIONY]);

        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $wiadomosc, array $kontekst = []): bool => str_contains($wiadomosc, 'Token krawędzi')
                && $kontekst === ['powod' => 'brak', 'tryb' => 'obserwacja'],
        )->once();
    }

    public function test_egzekwowanie_odrzuca_brak_tokenu(): void
    {
        $this->wlacz('egzekwowanie');

        $odpowiedz = $this->get('/'.self::SCIEZKA, $this->naglowki(null));

        $odpowiedz->assertForbidden();
        $this->assertStringContainsString('https://kuking.pl', (string) $odpowiedz->getContent());
    }

    public function test_egzekwowanie_odrzuca_zly_token_i_nie_zdradza_sekretu(): void
    {
        $this->wlacz('egzekwowanie');
        $log = Log::spy();

        $odpowiedz = $this->post('/'.self::SCIEZKA, [], $this->naglowki('zgaduje'));

        $odpowiedz->assertForbidden();
        $this->assertStringNotContainsString(self::SEKRET, (string) $odpowiedz->getContent());

        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $wiadomosc, array $kontekst = []): bool => ($kontekst['powod'] ?? null) === 'niezgodny'
                && ! str_contains(json_encode($kontekst).$wiadomosc, 'zgaduje')
                && ! str_contains(json_encode($kontekst).$wiadomosc, self::SEKRET),
        )->once();
    }

    public function test_egzekwowanie_przepuszcza_poprawny_token_i_chowa_go_przed_aplikacja(): void
    {
        $this->wlacz('egzekwowanie');

        // KONTROLA DODATNIA: prawdziwy ruch przez Cloudflare dalej widzi
        // adres klienta i https — bramka nie psuje limitów ani schematu.
        $this->get('/'.self::SCIEZKA, $this->naglowki(self::SEKRET))
            ->assertOk()
            ->assertJson(['ip' => self::PODROBIONY, 'https' => true, 'token_widoczny' => false]);
    }

    public function test_rotacja_przyjmuje_poprzedni_sekret_tylko_poki_jest_ustawiony(): void
    {
        $stary = 'stary-sekret-krawedzi-fedcba9876543210fedcba98765';

        $this->wlacz('egzekwowanie', $stary);
        $this->get('/'.self::SCIEZKA, $this->naglowki($stary))->assertOk();
        $this->get('/'.self::SCIEZKA, $this->naglowki(self::SEKRET))->assertOk();

        $this->wlacz('egzekwowanie');
        $this->get('/'.self::SCIEZKA, $this->naglowki($stary))->assertForbidden();
    }

    /**
     * Właściwy dowód #1306: podrobiony nagłówek od niezaufanego źródła
     * (bez tokenu) nie zmienia `request()->ip()` ani schematu — także na
     * ścieżce, którą bramka wpuszcza bez tokenu.
     */
    public function test_sciezka_bez_tokenu_nie_ufa_naglowkom_proxy(): void
    {
        $this->wlacz('egzekwowanie');
        config(['proxy.token_krawedzi.bez_tokenu' => ['health', 'up', self::SCIEZKA]]);

        $this->get('/'.self::SCIEZKA, $this->naglowki(null))
            ->assertOk()
            ->assertJson(['ip' => '127.0.0.1', 'https' => false]);

        // Na tej samej ścieżce POST już nie przechodzi.
        $this->post('/'.self::SCIEZKA, [], $this->naglowki(null))->assertForbidden();
    }

    public function test_healthcheck_railwaya_przechodzi_bez_tokenu(): void
    {
        $this->wlacz('egzekwowanie');

        $this->get('/up')->assertOk();
        $this->assertNotSame(403, $this->get('/health')->getStatusCode());
    }

    public function test_egzekwowanie_bez_sekretu_nie_odcina_serwisu_ale_ostrzega(): void
    {
        config(['proxy.token_krawedzi.tryb' => 'egzekwowanie', 'proxy.token_krawedzi.aktualny' => '']);
        $log = Log::spy();

        $this->get('/'.self::SCIEZKA, $this->naglowki(null))->assertOk();

        $log->shouldHaveReceived('warning')->withArgs(
            fn (string $wiadomosc): bool => str_contains($wiadomosc, 'bez KUKING_EDGE_TOKEN'),
        )->once();
    }
}

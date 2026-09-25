<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Turnstile\KlientTurnstile;
use App\Turnstile\WynikTurnstile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Zły sekret Turnstile otwiera epizod alarmowy, a nie samotną linię w dzienniku (#599).
 *
 * Do tej pory `invalid-input-secret` dawał `Log::error` i nic więcej, a `/health`
 * mówił `turnstile.ok=true` (sprawdza tylko obecność kluczy). Formularz ma
 * nadal PRZECHODZIĆ (D-050) — to sprawdza każdy przypadek niżej obok alarmu.
 */
final class ZlySekretTurnstileDzwoniTest extends TestCase
{
    private const WEBHOOK = 'https://przyklad.test/webhook-bledow';

    private const SEKRET = 'sekret-testowy-nie-do-wyslania';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow('2026-09-25 10:00:00');
        config()->set('logging.channels.blad_webhook.url', self::WEBHOOK);
        config()->set('kuking.turnstile.klucz_publiczny', '1x00000000000000000000AA');
        config()->set('kuking.turnstile.sekret', self::SEKRET);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $odpowiedzi kolejne odpowiedzi `siteverify` */
    private function cloudflare(array $odpowiedzi): void
    {
        $sekwencja = Http::sequence();
        foreach ($odpowiedzi as $odpowiedz) {
            $sekwencja->push($odpowiedz, 200);
        }

        Http::fake([
            KlientTurnstile::ADRES => $sekwencja,
            self::WEBHOOK => Http::response('ok', 200),
        ]);
    }

    /** @return list<string> */
    private function alarmy(): array
    {
        return Http::recorded()
            ->filter(fn (array $para): bool => $para[0]->url() === self::WEBHOOK)
            ->map(fn (array $para): string => (string) ($para[0]['text'] ?? ''))
            ->values()
            ->all();
    }

    private function sprawdz(): WynikTurnstile
    {
        return (new KlientTurnstile)->sprawdz('token-od-widgetu', '203.0.113.9');
    }

    public function test_zly_sekret_dzwoni_raz_nie_zalewa_i_wraca_jednym_odwolaniem(): void
    {
        $zly = ['success' => false, 'error-codes' => ['invalid-input-secret']];
        $this->cloudflare([$zly, $zly, $zly, ['success' => true]]);

        $this->assertSame(WynikTurnstile::Nierozstrzygniety, $this->sprawdz());
        $this->assertSame(WynikTurnstile::Nierozstrzygniety, $this->sprawdz());
        Carbon::setTestNow('2026-09-25 11:00:00');
        $this->assertSame(WynikTurnstile::Nierozstrzygniety, $this->sprawdz());

        $alarmy = $this->alarmy();
        $this->assertCount(1, $alarmy);
        $this->assertStringContainsString('TURNSTILE_SECRET_KEY', $alarmy[0]);

        $this->assertSame(WynikTurnstile::Przeszedl, $this->sprawdz());

        $alarmy = $this->alarmy();
        $this->assertCount(2, $alarmy);
        $this->assertStringContainsString('weryfikacja znowu działa', $alarmy[1]);

        // Kontrola ujemna treści: ani sekretu, ani tokenu, ani adresu IP.
        foreach ($alarmy as $tresc) {
            $this->assertStringNotContainsString(self::SEKRET, $tresc);
            $this->assertStringNotContainsString('token-od-widgetu', $tresc);
            $this->assertStringNotContainsString('203.0.113.9', $tresc);
        }
    }

    public function test_odrzucony_token_tez_zamyka_epizod_bo_sekret_zadzialal(): void
    {
        $this->cloudflare([
            ['success' => false, 'error-codes' => ['missing-input-secret']],
            ['success' => false, 'error-codes' => ['invalid-input-response']],
        ]);

        $this->sprawdz();
        $this->assertSame(WynikTurnstile::Odrzucony, $this->sprawdz());

        $alarmy = $this->alarmy();
        $this->assertCount(2, $alarmy);
        $this->assertStringContainsString('weryfikacja znowu działa', $alarmy[1]);
    }

    public function test_awaria_u_cloudflare_nie_jest_zla_konfiguracja(): void
    {
        $this->cloudflare([['success' => false, 'error-codes' => ['internal-error']]]);

        $this->assertSame(WynikTurnstile::Nierozstrzygniety, $this->sprawdz());
        $this->assertSame([], $this->alarmy());
    }

    public function test_udana_weryfikacja_bez_epizodu_nie_wysyla_niczego(): void
    {
        $this->cloudflare([['success' => true], ['success' => true]]);

        $this->sprawdz();
        $this->sprawdz();

        $this->assertSame([], $this->alarmy());
    }

    public function test_nieudany_dzwonek_nie_kupuje_ciszy(): void
    {
        $zly = ['success' => false, 'error-codes' => ['invalid-input-secret']];
        Http::fake([
            KlientTurnstile::ADRES => Http::sequence()->push($zly)->push($zly),
            self::WEBHOOK => Http::sequence()->push('błąd', 500)->push('ok', 200),
        ]);

        $this->sprawdz();
        // Po przerwie na ponowienie (5 min), długo przed końcem 6-godzinnej ciszy.
        Carbon::setTestNow('2026-09-25 10:06:00');
        $this->sprawdz();

        $this->assertCount(2, $this->alarmy());
    }
}

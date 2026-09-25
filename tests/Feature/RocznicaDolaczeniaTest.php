<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Rocznice\RocznicaDolaczenia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rocznica dołączenia na `/home` (issue #1754).
 *
 * ZEGAR JEST ZAMRAŻANY W KAŻDYM TEŚCIE. Rocznica to błąd „o porze doby"
 * w czystej postaci: konto założone po północy czasu polskiego ma w bazie
 * datę z poprzedniego dnia UTC. Test na żywym zegarze sprawdzałby co innego
 * o 23:00 i o 10:00.
 */
class RocznicaDolaczeniaTest extends TestCase
{
    use RefreshDatabase;

    private const ZDANIE_ROK = 'Gotujesz z nami od roku';

    private function kontoZalozone(string $utc, array $atrybuty = []): User
    {
        return $this->user('basia', ['created_at' => Carbon::parse($utc, 'UTC')] + $atrybuty);
    }

    public function test_w_rocznice_na_stronie_glownej_jest_jedno_zdanie_od_gospodarza(): void
    {
        config(['kuking.community.host_name' => 'Ula']);
        $basia = $this->kontoZalozone('2025-03-15 10:00:00');
        $this->travelTo(Carbon::parse('2026-03-15 09:00:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee(self::ZDANIE_ROK, escape: false)
            ->assertSee('— Ula', escape: false);
    }

    public function test_dzien_przed_i_dzien_po_rocznicy_nic_nie_ma(): void
    {
        $basia = $this->kontoZalozone('2025-03-15 10:00:00');

        foreach (['2026-03-14 12:00:00', '2026-03-16 12:00:00'] as $kiedy) {
            $this->travelTo(Carbon::parse($kiedy, 'UTC'));

            $this->actingAs($basia)->get(route('home'))
                ->assertOk()
                ->assertDontSee('Gotujesz z nami', escape: false);
        }
    }

    public function test_dzien_zalozenia_konta_nie_jest_rocznica(): void
    {
        $basia = $this->kontoZalozone('2026-03-15 08:00:00');
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Gotujesz z nami', escape: false);
    }

    /**
     * Konto założone 15 marca 2025 o 00:30 w Warszawie, czyli 14 marca
     * 23:30 UTC. Rocznica ma być 15 marca — dzień człowieka, nie serwera.
     */
    public function test_konto_zalozone_po_polnocy_ma_rocznice_w_swoj_dzien_lokalny(): void
    {
        $basia = $this->kontoZalozone('2025-03-14 23:30:00');
        $rocznica = app(RocznicaDolaczenia::class);

        $this->assertSame(1, $rocznica->ileLatDzis($basia->created_at, Carbon::parse('2026-03-15 09:00:00', 'UTC')));
        $this->assertNull(
            $rocznica->ileLatDzis($basia->created_at, Carbon::parse('2026-03-14 12:00:00', 'UTC')),
            'Rocznica wypadła o dzień za wcześnie — dzień liczony z UTC zamiast ze strefy człowieka.',
        );

        // 15 marca 2026 o 00:30 w Warszawie to jeszcze 14 marca w UTC — a już rocznica.
        $this->assertSame(1, $rocznica->ileLatDzis($basia->created_at, Carbon::parse('2026-03-14 23:30:00', 'UTC')));
    }

    public function test_liczba_lat_brzmi_po_polsku(): void
    {
        $basia = $this->kontoZalozone('2023-06-01 10:00:00');
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Gotujesz z nami od 3 lat', escape: false)
            ->assertDontSee(self::ZDANIE_ROK, escape: false);

        $rocznica = app(RocznicaDolaczenia::class);
        $this->assertStringContainsString('od roku', $rocznica->tekst($basia, 1));
        $this->assertStringContainsString('od 2 lat', $rocznica->tekst($basia, 2));
        $this->assertStringContainsString('od 5 lat', $rocznica->tekst($basia, 5));
        $this->assertStringContainsString('od 22 lat', $rocznica->tekst($basia, 22));
    }

    public function test_konto_z_29_lutego_ma_rocznice_28_lutego_w_roku_nieprzestepnym(): void
    {
        $basia = $this->kontoZalozone('2024-02-29 12:00:00');
        $rocznica = app(RocznicaDolaczenia::class);

        $this->assertSame(1, $rocznica->ileLatDzis($basia->created_at, Carbon::parse('2025-02-28 12:00:00', 'UTC')));
        $this->assertNull($rocznica->ileLatDzis($basia->created_at, Carbon::parse('2025-03-01 12:00:00', 'UTC')));

        // W roku przestępnym rocznica wraca na swój dzień, a 28 lutego jest zwykłym dniem.
        $this->assertSame(4, $rocznica->ileLatDzis($basia->created_at, Carbon::parse('2028-02-29 12:00:00', 'UTC')));
        $this->assertNull($rocznica->ileLatDzis($basia->created_at, Carbon::parse('2028-02-28 12:00:00', 'UTC')));
    }

    /**
     * Wyłącznik wspólny ze Wspomnieniami — przez prawdziwy formularz
     * ustawień, nie przez zapis do bazy z pominięciem kontrolera.
     */
    public function test_wylacznik_wspomnien_wylacza_tez_rocznice(): void
    {
        $basia = $this->kontoZalozone('2025-03-15 10:00:00');
        $this->travelTo(Carbon::parse('2026-03-15 09:00:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))->assertSee(self::ZDANIE_ROK, escape: false);

        $this->actingAs($basia)->put(route('settings.privacy'), [
            'original_digest' => (int) $basia->fresh()->wants_weekly_digest,
            'original_memories' => (int) $basia->fresh()->memories_enabled,
            // Brak `memories_enabled` = odznaczony checkbox.
        ])->assertRedirect();

        $this->assertFalse($basia->fresh()->memories_enabled);

        $this->actingAs($basia->fresh())->get(route('home'))
            ->assertOk()
            ->assertDontSee('Gotujesz z nami', escape: false);
    }

    public function test_rocznica_nie_wysyla_maila_ani_nie_tworzy_powiadomienia(): void
    {
        Mail::fake();
        $basia = $this->kontoZalozone('2025-03-15 10:00:00');
        $this->travelTo(Carbon::parse('2026-03-15 09:00:00', 'UTC'));

        $this->actingAs($basia)->get(route('home'))->assertSee(self::ZDANIE_ROK, escape: false);

        Mail::assertNothingOutgoing();
        $this->assertSame(0, DB::table('notifications')->where('user_id', $basia->getKey())->count());
    }

    public function test_cudza_rocznica_nie_pojawia_sie_u_innej_osoby(): void
    {
        $this->kontoZalozone('2025-03-15 10:00:00');
        $halina = $this->user('halina', ['created_at' => Carbon::parse('2025-08-01 10:00:00', 'UTC')]);
        $this->travelTo(Carbon::parse('2026-03-15 09:00:00', 'UTC'));

        $this->actingAs($halina)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Gotujesz z nami', escape: false);
    }
}

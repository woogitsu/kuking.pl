<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cichy blok „Do zużycia w ciągu 3 dni” na Starcie (#1903, D-333): jedno
 * zdanie i jeden przycisk, tylko gdy są pilne produkty, tylko u właściciela.
 * „Dziś” to 10 października 2026.
 */
class BlokPilnychNaHomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function produkt(User $wlasciciel, string $nazwa, ?string $termin, bool $mrozone = false): void
    {
        $produkt = $wlasciciel->pantryItems()->create(['name' => $nazwa]);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update([
            'expires_on' => $termin,
            'expiry_kind' => $termin === null ? null : 'use_by',
            'frozen' => $mrozone,
        ]);
    }

    public function test_bez_pilnych_produktow_bloku_nie_ma(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', '2026-10-20');
        $this->produkt($ja, 'mąka', null);

        $this->actingAs($ja)->get(route('home'))->assertOk()
            ->assertDontSee('Do zużycia w ciągu')
            ->assertDontSee('Produkty do zużycia');
    }

    public function test_blok_pojawia_sie_z_nazwami_reszta_i_przyciskiem(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', '2026-10-09');
        $this->produkt($ja, 'szynka', '2026-10-11');
        $this->produkt($ja, 'jogurt', '2026-10-12');
        $this->produkt($ja, 'kefir', '2026-10-13');

        $this->actingAs($ja)->get(route('home'))->assertOk()
            ->assertSee('Do zużycia w ciągu 3 dni: mleko, szynka i jeszcze 2 produkty.')
            ->assertSee('Zobacz, co ugotować')
            ->assertSee(route('pantry.cook', ['najpierw' => 'termin']), false);
    }

    public function test_nie_pokazuje_mrozonych_ani_cudzych_danych(): void
    {
        $ja = $this->user();
        $obca = $this->user('obca');
        $this->produkt($ja, 'kurczak', '2026-10-10', mrozone: true);
        $this->produkt($obca, 'tajna szynka', '2026-10-10');

        $this->actingAs($ja)->get(route('home'))->assertOk()
            ->assertDontSee('kurczak')
            ->assertDontSee('tajna szynka')
            ->assertDontSee('Do zużycia w ciągu');

        // Cudza osoba widzi tylko swoje.
        $this->actingAs($obca)->get(route('home'))->assertOk()
            ->assertSee('Do zużycia w ciągu 3 dni: tajna szynka.');
    }

    public function test_blok_nie_ma_licznika_ikon_ani_slow_o_swiezosci(): void
    {
        $ja = $this->user();
        $this->produkt($ja, 'mleko', '2026-10-10');

        $html = (string) $this->actingAs($ja)->get(route('home'))->getContent();
        $this->assertSame(1, preg_match('#<section class="notice do-zuzycia".*?</section>#s', $html, $blok), 'Blok nie wyrenderował się.');
        $tekst = trim(preg_replace('/\s+/', ' ', strip_tags($blok[0])));

        $this->assertSame('Do zużycia w ciągu 3 dni: mleko. Zobacz, co ugotować', $tekst);
        $this->assertDoesNotMatchRegularExpression('/śwież|bezpiecz|zepsut|marnuj|uratuj/iu', $tekst);
        $this->assertStringNotContainsString('<svg', $blok[0]);
    }
}

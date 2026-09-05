<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessibilitySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_uzytkownik_moze_powiekszyc_tekst_i_ustawienie_zostaje_na_koncie(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->put(route('settings.accessibility'), ['text_scale' => 125])
            ->assertRedirect();

        $this->assertSame(125, $basia->fresh()->text_scale);
    }

    public function test_skala_tekstu_trafia_do_atrybutu_html(): void
    {
        $basia = $this->user('basia', ['text_scale' => 140]);

        $this->actingAs($basia)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('data-text-scale="140"', false);
    }

    public function test_nieobslugiwana_wartosc_skali_jest_odrzucana(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->put(route('settings.accessibility'), ['text_scale' => 999])
            ->assertSessionHasErrors('text_scale');

        $this->assertSame(100, $basia->fresh()->text_scale);
    }
}

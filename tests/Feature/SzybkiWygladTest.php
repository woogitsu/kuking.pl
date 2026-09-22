<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SzybkiWygladTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_zapisuje_obie_preferencje_bez_konta(): void
    {
        $this->postJson(route('theme.update'), ['theme' => 'dark', 'text_scale' => 80])
            ->assertOk()->assertJsonPath('text_scale', 80)
            ->assertCookie(config('kuking.text.cookie'), '80')
            ->assertCookie(config('kuking.theme.cookie'), 'dark');
        $this->withCookie(config('kuking.text.cookie'), '80')->get('/')
            ->assertOk()->assertSee('data-text-scale="80"', false);
    }

    public function test_wybor_konta_ma_pierwszenstwo_a_zapis_zmienia_tylko_wlasne_konto(): void
    {
        $user = $this->user('wyglad', ['text_scale' => 125]);
        $other = $this->user('innywyglad');
        $this->actingAs($user)->withCookie(config('kuking.text.cookie'), '80')->get('/')
            ->assertSee('data-text-scale="125"', false);
        $this->postJson(route('theme.update'), ['theme' => 'dark', 'text_scale' => 90])->assertOk();
        $this->assertSame(90, $user->fresh()->text_scale);
        $this->assertSame('dark', $user->fresh()->theme);
        $this->assertSame(100, $other->fresh()->text_scale);
    }

    public function test_reset_dziala_rowniez_bez_javascriptu(): void
    {
        $user = $this->user('resetwyglad', ['theme' => 'dark', 'text_scale' => 140]);
        $this->actingAs($user)->post(route('theme.update'), ['reset_appearance' => '1'])
            ->assertRedirect()->assertCookie(config('kuking.text.cookie'), '100');
        $this->assertSame(100, $user->fresh()->text_scale);
        $this->assertSame('light', $user->fresh()->theme);
    }

    public function test_bledna_skala_nie_zapisuje_rowniez_motywu(): void
    {
        $user = $this->user('bladwyglad');
        $this->actingAs($user)->postJson(route('theme.update'), ['theme' => 'dark', 'text_scale' => 42])
            ->assertUnprocessable()->assertJsonValidationErrors('text_scale');
        $this->assertSame('light', $user->fresh()->theme);
        $this->assertSame(100, $user->fresh()->text_scale);
    }

    public function test_stary_przelacznik_motywu_nie_resetuje_skali(): void
    {
        $user = $this->user('starywyglad', ['text_scale' => 80]);
        $this->actingAs($user)->post(route('theme.update'), ['theme' => 'dark'])->assertRedirect();
        $this->assertSame(80, $user->fresh()->text_scale);
    }

    public function test_panel_ma_prawdziwy_formularz_i_wszystkie_skale(): void
    {
        $response = $this->get('/')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//details[@data-szybki-wyglad]//form[@method="POST"]')->length);
        $this->assertSame(count(config('kuking.text.scales')), $xpath->query('//select[@id="szybka-skala"]/option')->length);
        $this->assertSame(1, $xpath->query('//details[@data-szybki-wyglad]//input[@name="_token"]')->length);
    }
}

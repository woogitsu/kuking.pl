<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pwa\InstallPromptContext;
use App\Models\ProductSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecyzjaInstalacjiPwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_nie_moze_zapisac_decyzji(): void
    {
        $this->postJson(route('pwa.decision'), ['context' => 'brak', 'action' => 'offer'])->assertUnauthorized();
    }

    public function test_zapis_wymaga_csrf_takze_z_poprawnym_kontekstem(): void
    {
        $user = $this->user('csrf_pwa', ['pwa_prompt_state' => 'eligible']);
        $this->actingAs($user)->get(route('home'))->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $context = app(InstallPromptContext::class)->issue($user, session()->getId());
        $csrf = session()->token();
        $this->app['env'] = 'production';
        try {
            $this->postJson(route('pwa.decision'), ['context' => $context, 'action' => 'offer'])->assertStatus(419);
            $this->assertSame('eligible', $user->refresh()->pwa_prompt_state);
            $this->postJson(route('pwa.decision'), ['context' => $context, 'action' => 'offer', '_token' => $csrf])
                ->assertOk()->assertExactJson(['changed' => true]);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_metryki_odrozniaja_rezerwacje_wyswietlenie_i_instalacje(): void
    {
        $user = $this->user('metryki', ['pwa_prompt_state' => 'eligible']);
        $this->actingAs($user)->get(route('home'))->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $context = app(InstallPromptContext::class)->issue($user, session()->getId());
        $send = fn (string $action) => $this->postJson(route('pwa.decision'), ['context' => $context, 'action' => $action]);

        $send('shown')->assertOk()->assertJson(['changed' => false]);
        $send('offer')->assertOk()->assertJson(['changed' => true]);
        $this->assertDatabaseMissing('product_signals', ['signal_name' => 'pwa_prompt_shown']);
        $send('shown')->assertOk()->assertJson(['changed' => true]);
        $send('shown')->assertOk()->assertJson(['changed' => false]);
        $send('request')->assertOk()->assertJson(['changed' => true]);
        $this->assertDatabaseMissing('product_signals', ['signal_name' => 'pwa_installed']);
        $send('installed')->assertOk()->assertJson(['changed' => true]);
        $send('installed')->assertOk()->assertJson(['changed' => false]);
        $this->assertSame(3, ProductSignal::query()->where('user_id', $user->id)->count());
        foreach (['pwa_prompt_shown', 'pwa_install_requested', 'pwa_installed'] as $signal) {
            $this->assertDatabaseHas('product_signals', ['user_id' => $user->id, 'signal_name' => $signal]);
        }
    }

    public function test_rezerwacja_jest_jednorazowa_a_odmowa_trwala(): void
    {
        $user = $this->user('instalacja', ['pwa_prompt_state' => 'eligible']);
        $this->actingAs($user)->get(route('home'))->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $context = app(InstallPromptContext::class)->issue($user, session()->getId());

        foreach ([['offer', true], ['offer', false], ['dismiss', true], ['offer', false]] as [$action, $changed]) {
            $this->postJson(route('pwa.decision'), compact('context', 'action'))
                ->assertOk()->assertExactJson(['changed' => $changed]);
        }
        $this->assertSame('dismissed', $user->refresh()->pwa_prompt_state);
    }

    public function test_stara_karta_nie_zapisze_odmowy_na_nowym_koncie(): void
    {
        $basia = $this->user('basia', ['pwa_prompt_state' => 'offered']);
        $marek = $this->user('marek', ['pwa_prompt_state' => 'offered']);
        $this->actingAs($basia)->get(route('home'))->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $context = app(InstallPromptContext::class)->issue($basia, session()->getId());

        $this->actingAs($marek)->postJson(route('pwa.decision'), ['context' => $context, 'action' => 'dismiss'])
            ->assertStatus(409);
        $this->assertSame('offered', $basia->refresh()->pwa_prompt_state);
        $this->assertSame('offered', $marek->refresh()->pwa_prompt_state);

        $context = app(InstallPromptContext::class)->issue($marek, session()->getId());
        $this->postJson(route('pwa.decision'), ['context' => $context, 'action' => 'dismiss'])
            ->assertOk()->assertExactJson(['changed' => true]);
        $this->assertSame('dismissed', $marek->refresh()->pwa_prompt_state);
    }
}

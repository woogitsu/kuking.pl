<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaPowrotHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_pierwsza_wizyta_i_odswiezenie_nie_sa_powrotem(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        $user = $this->user('powrot');

        $this->actingAs($user)->get(route('home'))->assertOk();
        $this->assertNull($user->refresh()->pwa_prompt_state);
        $this->assertNotNull($user->ostatnio_widziany_at);

        $this->travel(23)->hours();
        $this->actingAs($user->fresh())->get(route('home'))->assertOk();
        $this->assertNull($user->refresh()->pwa_prompt_state);
    }

    public function test_powrot_przez_inna_strone_zachowuje_kwalifikacje_na_starcie(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        $user = $this->user('czytelnik');
        $user->forceFill(['ostatnio_widziany_at' => now()->subDay()])->save();

        $this->actingAs($user)->get(route('about'))->assertOk();
        $this->assertSame('eligible', $user->refresh()->pwa_prompt_state);
        $this->assertTrue($user->ostatnio_widziany_at->equalTo(now()));

        $this->actingAs($user->fresh())->get(route('home'))->assertOk();
        $this->assertSame('eligible', $user->refresh()->pwa_prompt_state);
    }

    public function test_prefetch_i_ajax_nie_kwalifikuja_do_instalacji(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        foreach ([['Sec-Purpose' => 'prefetch'], ['X-Requested-With' => 'XMLHttpRequest'], ['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors']] as $index => $headers) {
            $user = $this->user('tlo'.$index);
            $user->forceFill(['ostatnio_widziany_at' => now()->subDays(2)])->save();
            $this->flushHeaders();
            $this->actingAs($user)->withHeaders($headers)->get(route('about'))->assertOk();
            $this->assertNull($user->refresh()->pwa_prompt_state);
        }

        // Ten sam nośnik HTTP bez nagłówków tła naprawdę kwalifikuje powrót.
        $this->flushHeaders();
        $user = $this->user('nawigacja');
        $user->forceFill(['ostatnio_widziany_at' => now()->subDays(2)])->save();
        $this->actingAs($user)->get(route('about'))->assertOk();
        $this->assertSame('eligible', $user->refresh()->pwa_prompt_state);
    }

    public function test_nawigacja_przez_service_workera_kwalifikuje_powrot(): void
    {
        $user = $this->user('service_worker');
        $user->forceFill(['ostatnio_widziany_at' => now()->subDays(2)])->save();

        $this->actingAs($user)->withHeaders([
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'navigate',
            'Accept' => 'text/html',
        ])->get(route('home'))->assertOk()->assertSee('data-pwa-install', false);

        $this->assertSame('eligible', $user->refresh()->pwa_prompt_state);
    }

    public function test_tlo_nie_zjada_powrotu_tego_samego_konta(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        foreach ([['Sec-Purpose' => 'prefetch'], ['X-Requested-With' => 'XMLHttpRequest'], ['Sec-Fetch-Dest' => 'empty', 'Sec-Fetch-Mode' => 'cors']] as $index => $headers) {
            $user = $this->user('most'.$index);
            $user->forceFill(['ostatnio_widziany_at' => now()->subHours(48)])->save();
            $this->flushHeaders();
            $this->actingAs($user)->withHeaders($headers)->get(route('about'))->assertOk();
            $this->assertNull($user->refresh()->pwa_prompt_state);
            $this->assertTrue($user->ostatnio_widziany_at->equalTo(now()), 'Tło nadal musi aktualizować tracker aktywności.');

            // Kolejne żądanie tła nie może zastąpić zachowanej starej wizyty nową.
            $this->actingAs($user->fresh())->get(route('about'))->assertOk();
            $this->assertNull($user->refresh()->pwa_prompt_state);
            $this->flushHeaders();
            $this->actingAs($user->fresh())->get(route('home'))->assertOk()->assertSee('data-pwa-install', false)
                ->assertSessionMissing('pwa_return_candidate');
            $this->assertSame('eligible', $user->refresh()->pwa_prompt_state);
        }
    }

    public function test_kandydat_powrotu_nie_przechodzi_na_inne_konto_w_tejsamej_sesji(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        $first = $this->user('pierwszy_most');
        $first->forceFill(['ostatnio_widziany_at' => now()->subHours(48)])->save();
        $this->actingAs($first)->withHeaders(['Sec-Purpose' => 'prefetch'])->get(route('about'))->assertOk()
            ->assertSessionHas('pwa_return_candidate');

        $second = $this->user('drugi_most');
        $second->forceFill(['ostatnio_widziany_at' => now()->subMinute()])->save();
        $this->flushHeaders();
        $this->actingAs($second)->get(route('home'))->assertOk()->assertSessionMissing('pwa_return_candidate');
        $this->assertNull($second->refresh()->pwa_prompt_state);
        $this->assertNull($first->refresh()->pwa_prompt_state);
    }

    public function test_kandydat_wygasa_bez_przedluzania_przez_tlo(): void
    {
        $this->travelTo(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
        $user = $this->user('wygasly_most');
        $user->forceFill(['ostatnio_widziany_at' => now()->subHours(48)])->save();
        $this->actingAs($user)->withHeaders(['Sec-Purpose' => 'prefetch'])->get(route('about'))->assertOk();
        $this->travel(30)->minutes();
        $this->actingAs($user->fresh())->get(route('about'))->assertOk();
        $this->travel(31)->minutes();
        $this->flushHeaders();
        $this->actingAs($user->fresh())->get(route('home'))->assertOk()->assertSessionMissing('pwa_return_candidate');
        $this->assertNull($user->refresh()->pwa_prompt_state);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1807 — pusty stan „Świeżo z Kuking" nigdy bez wyjścia.
 *
 * Rozróżnia „nic nowego" od „część ukrywasz" i prowadzi do listy ukrytych,
 * tablicy i „Dodaj wpis". Blokada zrobiona PRZEZ KOGOŚ INNEGO nie może się
 * tu zdradzić — widz dostaje wtedy zwykłe „nic nowego".
 *
 * Kontrola ujemna (sprawdzona): `ileUkrywa()` liczące też `blockedBy()` →
 * `test_blokada_od_kogos_innego_nie_zdradza_sie` oblewa.
 */
class OdkrywaniePustyStanZWyjsciemTest extends TestCase
{
    use RefreshDatabase;

    private function zablokuj(User $kto, User $kogo): void
    {
        DB::table('blocks')->insert(['blocker_id' => $kto->id, 'blocked_id' => $kogo->id, 'created_at' => now()]);
    }

    public function test_gosc_na_pustym_odkrywaniu_ma_konto_i_tablice(): void
    {
        $this->get(route('discover'))
            ->assertOk()
            ->assertSee('data-pusty-stan-odkrywania="nic-nowego"', false)
            ->assertSee('Jeszcze nic tu nie ma')
            ->assertSee(route('register'), false)
            ->assertSee('href="#kuking-na-dzis"', false)
            ->assertSee('id="kuking-na-dzis"', false)
            ->assertDontSee('Zobacz, co ukrywasz');
    }

    public function test_zalogowany_bez_ukryc_dostaje_nic_nowego_i_dodaj_wpis(): void
    {
        $widz = $this->user('widz');

        $this->actingAs($widz)->get(route('discover'))
            ->assertOk()
            ->assertSee('data-pusty-stan-odkrywania="nic-nowego"', false)
            ->assertSee('Dodaj wpis')
            ->assertSee(route('posts.create'), false)
            ->assertDontSee('Zobacz, co ukrywasz');
    }

    public function test_czesc_ukrywasz_prowadzi_do_listy_ukrytych(): void
    {
        $widz = $this->user('widz');
        $autorka = $this->user('autorka');
        Post::factory()->create(['author_id' => $autorka->id, 'published_at' => now()->subMinute()]);
        $this->zablokuj($widz, $autorka);

        $this->actingAs($widz)->get(route('discover'))
            ->assertOk()
            ->assertSee('data-pusty-stan-odkrywania="ukrywasz"', false)
            ->assertSee('Część wpisów albo osób ukrywasz')
            ->assertSee(route('settings.hidden'), false)
            ->assertSee('Dodaj wpis');

        // Start bez obserwowanych przechodzi do Odkrywania — ten sam pusty stan.
        $this->actingAs($widz)->get(route('home'))
            ->assertOk()
            ->assertSee('data-pusty-stan-odkrywania="ukrywasz"', false);

        // Lista, do której prowadzi odnośnik, istnieje i ma tę osobę.
        $this->actingAs($widz)->get(route('settings.privacy'))
            ->assertOk()
            ->assertSee('id="zablokowane"', false);
    }

    public function test_blokada_od_kogos_innego_nie_zdradza_sie(): void
    {
        $widz = $this->user('widz');
        $autorka = $this->user('autorka');
        Post::factory()->create(['author_id' => $autorka->id, 'published_at' => now()->subMinute()]);
        $this->zablokuj($autorka, $widz);

        $this->actingAs($widz)->get(route('discover'))
            ->assertOk()
            ->assertSee('data-pusty-stan-odkrywania="nic-nowego"', false)
            ->assertDontSee('ukrywasz');
    }

    public function test_pelna_lista_nie_pokazuje_pustego_stanu(): void
    {
        $autorka = $this->user('autorka');
        Post::factory()->create(['author_id' => $autorka->id, 'published_at' => now()->subMinute()]);

        $this->get(route('discover'))->assertOk()->assertDontSee('data-pusty-stan-odkrywania', false);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnboardingWynikZapisuTest extends TestCase
{
    use RefreshDatabase;

    public static function emptyChoices(): array
    {
        return ['brak' => [[]], 'pusta lista' => [['follow' => []]], 'null' => [['follow' => null]]];
    }

    #[DataProvider('emptyChoices')]
    public function test_pusty_wybor_nie_tworzy_relacji_ani_powiadomien(array $input): void
    {
        $this->actingAs($this->user('widz'))->post(route('onboarding.people'), $input)
            ->assertRedirect(route('onboarding.done'))->assertSessionMissing('status');
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_odmowa_czesci_wyboru_daje_widoczna_instrukcje_i_zostawia_reszte(): void
    {
        $viewer = $this->user('widz');
        $target = $this->user('halina');
        $this->actingAs($viewer)->post(route('onboarding.people'), ['follow' => ['halina', 'nieistniejacy']])
            ->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertOk()
            ->assertSee('Nie udało się dodać wszystkich wybranych osób.');
        $this->assertTrue($viewer->isFollowing($target));
        $this->assertDatabaseCount('follows', 1);
    }

    public function test_wszystkie_odmowy_daja_instrukcje_bez_ujawniania_blokady(): void
    {
        $viewer = $this->user('widz');
        $target = $this->user('halina');
        $viewer->blocking()->attach($target->id, ['created_at' => now()]);
        $this->actingAs($viewer)->post(route('onboarding.people'), ['follow' => ['halina']]);
        $this->get(route('onboarding.done'))->assertOk()->assertSee('Nie udało się dodać wybranych osób.')
            ->assertDontSee('zablokow');
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_istniejaca_relacja_i_duplikat_nie_sa_porazka_ani_drugim_powiadomieniem(): void
    {
        $viewer = $this->user('widz');
        $target = $this->user('halina');
        app(FollowUser::class)->handle($viewer, $target);
        $this->actingAs($viewer)->post(route('onboarding.people'), ['follow' => ['halina', 'HALINA']])
            ->assertRedirect(route('onboarding.done'))->assertSessionMissing('status');
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_nieprawidlowa_lista_ma_blad_walidacji(): void
    {
        $this->actingAs($this->user('widz'))->post(route('onboarding.people'), ['follow' => 'halina'])
            ->assertSessionHasErrors('follow');
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_awaria_powiadomienia_nie_udaje_pominietego_konta(): void
    {
        $viewer = $this->user('widz');
        $this->user('halina');
        $armed = true;
        Notification::creating(function () use (&$armed): void {
            if ($armed) {
                throw new \RuntimeException('Próba awarii zapisu powiadomienia.');
            }
        });
        try {
            $this->actingAs($viewer)->post(route('onboarding.people'), ['follow' => ['halina']])->assertStatus(500);
        } finally {
            $armed = false;
        }
        $this->assertDatabaseCount('follows', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_limit_dwudziestu_osob_zostaje(): void
    {
        $this->actingAs($this->user('widz'))->post(route('onboarding.people'), ['follow' => array_fill(0, 21, 'halina')])
            ->assertSessionHasErrors('follow');
        $this->assertDatabaseCount('follows', 0);
    }

    public function test_nadmiar_wyborow_widac_po_bledzie_i_mozna_go_poprawic(): void
    {
        $viewer = $this->user('widz');
        $this->actingAs($viewer)->withSession(['onboarding.selection' => [
            'user' => $viewer->getKey(), 'token' => 'proba-wyboru', 'expires' => now()->addMinutes(30)->getTimestamp(),
        ]]);
        $names = [];
        for ($i = 0; $i < 21; $i++) {
            $names[] = 'kucharz_'.$i;
            $this->user('kucharz_'.$i);
        }
        $response = $this->followingRedirects()->from(route('onboarding.people'))->post(route('onboarding.people'), [
            'follow' => $names, 'selection' => session('onboarding.selection.token'),
        ])->assertOk();
        $response->assertSee('id="f-follow-error"', false)->assertSee('href="#f-follow"', false);
        $this->assertSame(21, preg_match_all('/name="follow\[\]"[^>]*checked/', $response->getContent()));
    }
}

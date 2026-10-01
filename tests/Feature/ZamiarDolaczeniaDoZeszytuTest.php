<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Gość nie traci linku do wspólnego zeszytu podczas pierwszych kroków (#2420). */
final class ZamiarDolaczeniaDoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_gosc_po_rejestracji_i_pominieciu_wraca_na_podglad_a_dolacza_dopiero_po_kliknieciu(): void
    {
        [$zeszyt, $zaproszenie, $token] = $this->link();
        $cel = route('collections.link.show', $token);

        $this->get($cel)->assertRedirect(route('login'));
        $this->get(route('register'))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));

        $this->get(route('onboarding.done'))->assertRedirect($cel);
        $this->assertSame(CollectionInvitation::STATUS_PENDING, $zaproszenie->fresh()->status);
        $this->assertSame(0, DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->count());

        $this->get($cel)->assertOk()->assertSee('Obiady rodzinne')->assertSee('Dołączam');
        $this->post(route('collections.link.accept', $token))->assertRedirect(route('collections.show', $zeszyt));
        $this->assertSame(1, DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->count());
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_pelny_onboarding_tez_wraca_na_podglad(): void
    {
        [, , $token] = $this->link();
        $cel = route('collections.link.show', $token);

        $this->get($cel)->assertRedirect(route('login'));
        $this->get(route('register'))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.interests'), [])->assertRedirect(route('onboarding.people'));
        $this->post(route('onboarding.people'), [])->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertRedirect($cel);
    }

    public function test_zaproszenie_ktore_wygaslo_podczas_onboardingu_nie_otwiera_podgladu(): void
    {
        [, $zaproszenie, $token] = $this->link();
        $this->get(route('collections.link.show', $token))->assertRedirect(route('login'));
        $this->get(route('register'))->assertOk();
        $this->zarejestruj();
        $zaproszenie->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->post(route('onboarding.skip'));

        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
        $this->get(route('collections.link.show', $token))->assertStatus(410);
    }

    public function test_istniejace_konto_wraca_przez_zwykle_intended(): void
    {
        [, , $token] = $this->link();
        $konto = $this->user('stala');
        $cel = route('collections.link.show', $token);

        $this->get($cel)->assertRedirect(route('login'));
        $this->post(route('login'), ['login' => $konto->email, 'password' => 'haslo-testowe-123'])
            ->assertSessionHasNoErrors()->assertRedirect($cel);
        $this->get($cel)->assertOk()->assertSee('Dołączam');
    }

    public function test_odwolane_i_wygasle_zaproszenie_nie_odslaniaja_podgladu(): void
    {
        [$zeszyt, $zaproszenie, $token] = $this->link();
        $this->get(route('collections.link.show', $token))->assertRedirect(route('login'));
        $this->get(route('register'))->assertOk();
        $this->zarejestruj();
        $zaproszenie->forceFill(['status' => CollectionInvitation::STATUS_REVOKED, 'token_hash' => null])->save();
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
        $this->get(route('collections.link.show', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->count());

        // Nowy link jest ważny w bazie, ale zamiar gościa kończy się po 2 h.
        auth()->logout();
        [, , $drugi] = $this->link();
        $this->get(route('collections.link.show', $drugi))->assertRedirect(route('login'));
        $this->get(route('register'))->assertOk();
        $this->zarejestruj('druga');
        $this->post(route('onboarding.skip'));
        $this->travel(3)->hours();
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_zamiar_jest_przypisany_tylko_do_nowego_konta_i_nie_przyjmuje_obcego_adresu(): void
    {
        [, , $token] = $this->link();
        $this->withSession(['url.intended' => 'https://evil.example/zaproszenie-do-zeszytu/link/'.$token]);
        $this->get(route('register'))->assertOk();
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertOk()->assertViewIs('pages.onboarding.done');

        auth()->logout();
        $cel = route('collections.link.show', $token);
        $this->get($cel)->assertRedirect(route('login'));
        $this->get(route('register'))->assertOk();
        $this->zarejestruj('druga');
        $this->actingAs($this->user('obca'))->get(route('onboarding.done'))
            ->assertOk()->assertViewIs('pages.onboarding.done');
    }

    public function test_nowszy_jawny_zamiar_obserwowania_wypiera_link_zaproszenia(): void
    {
        [, , $token] = $this->link();
        $osoba = $this->user('znajoma');

        $this->get(route('collections.link.show', $token))->assertRedirect(route('login'));
        $this->get(route('register', ['follow_user' => $osoba->getKey()]))->assertOk();
        $this->assertNull(session('url.intended'));
        $this->zarejestruj();
        $this->post(route('onboarding.skip'));
        $this->get(route('onboarding.done'))->assertRedirect(route('profile.show', 'znajoma'));
    }

    /** @return array{Collection, CollectionInvitation, string} */
    private function link(): array
    {
        $wlasciciel = $this->user();
        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Obiady rodzinne',
            'visibility' => 'private',
        ]);
        [$zaproszenie, $token] = app(ZaprosDoZeszytu::class)->linkiem($wlasciciel, $zeszyt);

        return [$zeszyt, $zaproszenie, $token];
    }

    private function zarejestruj(string $prefiks = 'nowa'): User
    {
        $this->post(route('register'), [
            'display_name' => 'Nowa Osoba', 'username' => $prefiks.'osoba',
            'email' => $prefiks.'@example.test', 'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ])->assertRedirect(route('onboarding.interests'));

        return User::query()->where('email', $prefiks.'@example.test')->firstOrFail();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZamiarUgotowaniaPoRejestracjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['https://api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    public function test_gosc_wraca_z_ostatniego_kroku_do_formularza_po_rejestracji_bez_zapisu_wykonania(): void
    {
        $przepis = $this->publicznyPrzepis();
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Zamieszaj.']);

        $this->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertOk()
            ->assertSee(route('register', ['cook_recipe' => $przepis->slug]), false);

        $this->get(route('register', ['cook_recipe' => $przepis->slug]))->assertOk();
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertRedirect(route('cooked.create', $przepis->slug));
        $this->get(route('cooked.create', $przepis->slug))->assertOk();

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertAuthenticatedAs(User::query()->where('email', 'nowa@example.test')->firstOrFail());
    }

    public function test_niepoprawny_lub_niepubliczny_cel_nie_staje_sie_przekierowaniem(): void
    {
        $przepis = $this->publicznyPrzepis();
        $przepis->update(['visibility' => 'private']);

        $this->get(route('register', ['cook_recipe' => 'https://evil.example']))->assertOk();
        $this->get(route('register', ['cook_recipe' => $przepis->slug]))->assertOk();
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertOk();
    }

    public function test_przepis_ukryty_podczas_onboardingu_nie_jest_celem_powrotu(): void
    {
        $przepis = $this->publicznyPrzepis();
        $this->get(route('register', ['cook_recipe' => $przepis->slug]))->assertOk();
        $this->post(route('register'), $this->dane())->assertRedirect(route('onboarding.interests'));

        $przepis->update(['visibility' => 'private']);
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertOk();
        $this->assertSame(0, CookedEvent::query()->count());
    }

    private function publicznyPrzepis(): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function dane(): array
    {
        return [
            'display_name' => 'Nowa Osoba', 'username' => 'nowaosoba',
            'email' => 'nowa@example.test', 'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ];
    }
}

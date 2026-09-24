<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Przerwany onboarding ma drogę powrotu na Starcie, bez przymusu (#985).
 */
class OnboardingWznowienieTest extends TestCase
{
    use RefreshDatabase;

    private const ODNOSNIK = 'Dokończ pierwsze kroki';

    public function test_bez_zainteresowan_start_prowadzi_do_pierwszego_kroku(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);

        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee(self::ODNOSNIK)
            ->assertSee(route('onboarding.interests'), false);
    }

    public function test_po_zapisaniu_zainteresowan_start_prowadzi_od_razu_do_ludzi(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);
        $user->followedTags()->attach(Tag::factory()->create()->getKey(), ['created_at' => now()]);

        $this->actingAs($user)->get(route('home'))->assertOk()
            ->assertSee(self::ODNOSNIK)
            ->assertSee(route('onboarding.people'), false)
            ->assertDontSee(route('onboarding.interests'), false);
    }

    public function test_przerwanie_na_kroku_z_ludzmi_nie_konczy_onboardingu(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);

        $this->actingAs($user)->get(route('onboarding.interests'))->assertOk();
        $this->get(route('onboarding.people'))->assertOk();

        $this->assertNull($user->fresh()->onboarding_zakonczony_at);
        $this->get(route('home'))->assertSee(self::ODNOSNIK);
    }

    public function test_dojscie_do_konca_albo_pomin_trwale_wylacza_przypomnienie(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);

        // „Pomiń ten krok" na /witaj/ludzie prowadzi właśnie tutaj.
        $this->actingAs($user)->get(route('onboarding.done'))->assertOk();
        $zakonczony = $user->fresh()->onboarding_zakonczony_at;
        $this->assertNotNull($zakonczony);
        $this->get(route('home'))->assertOk()->assertDontSee(self::ODNOSNIK);

        // Ręczny powrót do wcześniejszych kroków i stary formularz niczego nie cofają.
        $this->travel(1)->day();
        $this->get(route('onboarding.interests'))->assertOk();
        $this->post(route('onboarding.people'), [])->assertRedirect(route('onboarding.done'));
        $this->get(route('onboarding.done'))->assertOk();
        $this->assertTrue($zakonczony->equalTo($user->fresh()->onboarding_zakonczony_at));
        $this->get(route('home'))->assertDontSee(self::ODNOSNIK);
    }

    public function test_nie_przypominaj_jest_trwala_decyzja(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);

        $this->actingAs($user)->post(route('onboarding.dismiss'))
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'Dobrze, nie będziemy już przypominać o pierwszych krokach.');

        $this->assertNotNull($user->fresh()->onboarding_zakonczony_at);
        $this->get(route('home'))->assertOk()->assertDontSee(self::ODNOSNIK);
    }

    public function test_zawieszone_konto_nie_dostaje_przypomnienia(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);
        $user->forceFill(['status' => User::STATUS_SUSPENDED, 'status_expires_at' => now()->addWeek()])->save();

        $this->assertNull($user->fresh()->onboardingDoDokonczenia());
    }

    public function test_logowanie_nie_odrywa_od_strony_ktora_czlowiek_chcial_otworzyc(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);
        $cel = route('settings.accessibility');

        $this->get($cel)->assertRedirect(route('login'));
        $this->post(route('login'), ['login' => $user->email, 'password' => 'haslo-testowe-123'])
            ->assertRedirect($cel);
    }

    /** Kontrola dodatnia: konto z fabryki (po pierwszych krokach) i konto istniejące przed migracją nie widzą przypomnienia. */
    public function test_konto_po_pierwszych_krokach_nie_widzi_przypomnienia(): void
    {
        $this->actingAs($this->user())->get(route('home'))->assertOk()
            ->assertDontSee(self::ODNOSNIK);
    }
}

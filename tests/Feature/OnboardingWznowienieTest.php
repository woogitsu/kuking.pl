<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    public function test_pomin_ten_krok_trwale_wylacza_przypomnienie(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);

        $this->actingAs($user)->post(route('onboarding.skip'))
            ->assertRedirect(route('onboarding.done'));
        $zakonczony = $user->fresh()->onboarding_zakonczony_at;
        $this->assertNotNull($zakonczony);
        $this->get(route('home'))->assertOk()->assertDontSee(self::ODNOSNIK);

        // Ręczny powrót do wcześniejszych kroków i stary formularz niczego nie cofają ani nie przesuwają.
        $this->travel(1)->day();
        $this->get(route('onboarding.interests'))->assertOk();
        $this->post(route('onboarding.people'), [])->assertRedirect(route('onboarding.done'));
        $this->post(route('onboarding.skip'))->assertRedirect(route('onboarding.done'));
        $this->assertTrue($zakonczony->equalTo($user->fresh()->onboarding_zakonczony_at));
        $this->get(route('home'))->assertDontSee(self::ODNOSNIK);
    }

    public function test_dalej_na_ostatnim_kroku_konczy_pierwsze_kroki(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);

        $this->actingAs($user)->post(route('onboarding.people'), [])
            ->assertRedirect(route('onboarding.done'));

        $this->assertNotNull($user->fresh()->onboarding_zakonczony_at);
        $this->get(route('home'))->assertOk()->assertDontSee(self::ODNOSNIK);
    }

    /** Prefetch przeglądarki pobiera GET — samo otwarcie `/witaj/gotowe` niczego nie zapisuje. */
    public function test_samo_otwarcie_strony_gotowe_nie_wylacza_przypomnienia(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);

        $this->actingAs($user)->get(route('onboarding.done'))->assertOk();

        $this->assertNull($user->fresh()->onboarding_zakonczony_at);
        $this->get(route('home'))->assertSee(self::ODNOSNIK);
    }

    public function test_pomin_ten_krok_to_przycisk_formularza_z_csrf_bez_js(): void
    {
        $html = $this->actingAs($this->user(null, ['onboarding_zakonczony_at' => null]))
            ->get(route('onboarding.people'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~<button class="btn btn-quiet" type="submit" formmethod="POST" formaction="'
            .preg_quote(route('onboarding.skip'), '~')
            .'" formnovalidate name="_token" value="[^"]+">Pomiń ten krok</button>~',
            $html,
        );
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

    /** Konto z fabryki to konto po pierwszych krokach — bez przypomnienia. */
    public function test_konto_po_pierwszych_krokach_nie_widzi_przypomnienia(): void
    {
        $this->actingAs($this->user())->get(route('home'))->assertOk()
            ->assertDontSee(self::ODNOSNIK);
    }

    /**
     * Konto, które było w trakcie pierwszych kroków w chwili wdrożenia:
     * uruchamiamy prawdziwą migrację na wierszu bez znacznika (stan sprzed
     * niej to brak kolumny), a Start przestaje pokazywać przypomnienie.
     * Szczegóły schematu i rollbacku: `OnboardingMigracjaZnacznikaTest`.
     */
    public function test_konto_istniejace_przed_migracja_nie_widzi_przypomnienia(): void
    {
        $user = $this->user(null, ['onboarding_zakonczony_at' => null]);
        $this->actingAs($user)->get(route('home'))->assertSee(self::ODNOSNIK);

        /** @var Migration $migracja */
        $migracja = require base_path('database/migrations/2026_09_24_130000_add_onboarding_zakonczony_at_to_users.php');
        $migracja->down();
        $migracja->up();

        // Nowe żądanie czyta konto z bazy — `actingAs` trzymałby stary obiekt.
        $this->actingAs($user->fresh())->get(route('home'))->assertOk()->assertDontSee(self::ODNOSNIK);
    }

    public function test_konta_demo_z_db_seed_nie_widza_przypomnienia(): void
    {
        Storage::fake('public');

        $this->seed(DemoSeeder::class);

        $moderator = User::query()->where('role', User::ROLE_MODERATOR)->firstOrFail();
        $this->assertNull($moderator->onboardingDoDokonczenia());
        $this->assertSame(0, User::query()->whereNull('onboarding_zakonczony_at')->where('is_seeded', false)->count());
    }
}

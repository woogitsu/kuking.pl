<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Zestaw przepisów dnia Planera do kolejki gotowania (#2450, V2).
 *
 * Kolejka żyje w przeglądarce, więc serwer dostarcza tylko pola wyboru
 * z przepisami, które wolno dodać. Logikę limitu i duplikatów pilnuje
 * `resources/js/kolejka-gotowania.test.mjs`.
 */
final class PlanerZestawDoKolejkiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function przepis(User $autor, string $tytul, int $kroki = 2, string $widocznosc = 'public'): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $widocznosc,
        ]);

        for ($i = 0; $i < $kroki; $i++) {
            RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => $i, 'instruction' => 'Krok '.($i + 1)]);
        }

        return $przepis;
    }

    private function pozycja(User $kto, string $dzien, ?Recipe $przepis = null, ?string $tekst = null): MealPlanEntry
    {
        $wpis = new MealPlanEntry(['day' => $dzien, 'recipe_id' => $przepis?->getKey(), 'label' => $tekst]);
        $wpis->user_id = $kto->getKey();
        $wpis->save();

        return $wpis;
    }

    public function test_dzien_z_przepisami_ma_pola_wyboru_i_linki_bez_skryptu(): void
    {
        $osoba = $this->user('planujaca');
        $autor = $this->user('autorpl');
        $zupa = $this->przepis($autor, 'Zupa niedzielna');
        $drugie = $this->przepis($autor, 'Kotlet schabowy');
        $this->pozycja($osoba, '2026-10-04', $zupa);
        $this->pozycja($osoba, '2026-10-04', $drugie);

        $this->actingAs($osoba)->get(route('planer.show', ['tydzien' => '2026-09-28']))
            ->assertOk()
            ->assertSee('data-planer-kolejka', false)
            ->assertSee('Dodaj do kolejki gotowania')
            ->assertSee('value="'.$zupa->slug.'"', false)
            ->assertSee('value="'.$drugie->slug.'"', false)
            ->assertSee('Gotuj: Zupa niedzielna')
            ->assertSee('Gotuj: Kotlet schabowy')
            ->assertSee('Kolejka mieści najwyżej 4 przepisy');
    }

    public function test_wpis_wlasny_przepis_bez_krokow_i_niedostepny_nie_maja_pola_wyboru(): void
    {
        $osoba = $this->user('planujaca2');
        $autor = $this->user('autorpl2');
        $zKrokami = $this->przepis($autor, 'Zupa z krokami');
        $bezKrokow = $this->przepis($autor, 'Przepis bez opisu', 0);
        $prywatny = $this->przepis($autor, 'Cudza nalewka', 2, 'private');
        $this->pozycja($osoba, '2026-10-04', $zKrokami);
        $this->pozycja($osoba, '2026-10-04', $bezKrokow);
        $this->pozycja($osoba, '2026-10-04', $prywatny);
        $this->pozycja($osoba, '2026-10-04', null, 'obiad u mamy');

        $this->actingAs($osoba)->get(route('planer.show', ['tydzien' => '2026-09-28']))
            ->assertOk()
            ->assertSee('value="'.$zKrokami->slug.'"', false)
            ->assertDontSee('value="'.$bezKrokow->slug.'"', false)
            ->assertDontSee('value="'.$prywatny->slug.'"', false)
            ->assertDontSee('Cudza nalewka')
            ->assertDontSee('Gotuj: obiad u mamy');
    }

    public function test_dzien_bez_przepisow_z_krokami_nie_pokazuje_bloku_kolejki(): void
    {
        $osoba = $this->user('planujaca3');
        $this->pozycja($osoba, '2026-10-04', null, 'obiad u mamy');

        $this->actingAs($osoba)->get(route('planer.show', ['tydzien' => '2026-09-28']))
            ->assertOk()
            ->assertDontSee('data-planer-kolejka', false);
    }

    public function test_cudzy_plan_nie_pojawia_sie_w_wyborze(): void
    {
        $osoba = $this->user('planujaca4');
        $inna = $this->user('inna4');
        $autor = $this->user('autorpl4');
        $cudze = $this->przepis($autor, 'Danie cudzego planu');
        $this->pozycja($inna, '2026-10-04', $cudze);

        $this->actingAs($osoba)->get(route('planer.show', ['tydzien' => '2026-09-28']))
            ->assertOk()
            ->assertDontSee($cudze->slug, false);
    }
}

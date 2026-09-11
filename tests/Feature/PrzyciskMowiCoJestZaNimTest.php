<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\CoMoznaDopisac;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Napis przycisku na stronie przepisu mówi, CO JEST ZA NIM (issue #364, D-135).
 *
 * PO CO TO TESTOWAĆ
 * Ekran po drugiej stronie ma nagłówek „Dopisz szczegóły", gdy jest co
 * dopisać. Przycisk mówił zawsze „Edytuj przepis" — a autorowi, który właśnie
 * opublikował przepis z samym zdjęciem i tytułem, „Edytuj" brzmi jak
 * poprawianie błędu, nie jak zaproszenie. Zaproszenie padało dotąd RAZ,
 * w komunikacie po publikacji, i znikało razem z nim.
 *
 * DLACZEGO OBA KIERUNKI MAJĄ WŁASNY TEST
 * Sam test „przepis bez składników pokazuje «Dopisz szczegóły»" przeszedłby
 * także dla kodu, który wpisuje ten napis na sztywno — czyli dla kodu
 * stawiającego zaproszenie pod przepisem, w którym nie ma już czego dopisać.
 * To jest martwy przycisk z D-053, tylko ubrany w inny napis. Dlatego drugi
 * test buduje przepis WYPEŁNIONY DO KOŃCA i wymaga, żeby napis wrócił.
 */
class PrzyciskMowiCoJestZaNimTest extends TestCase
{
    use RefreshDatabase;

    public function test_przepis_z_pustymi_polami_zaprasza_do_dopisania_szczegolow(): void
    {
        $basia = $this->user('basia');

        $przepis = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
            'summary' => null,
            'servings' => null,
        ]);

        $this->actingAs($basia)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Dopisz szczegóły')
            ->assertDontSee('Edytuj przepis');
    }

    public function test_przepis_wypelniony_do_konca_wraca_do_napisu_edytuj(): void
    {
        $basia = $this->user('basia');

        $przepis = $this->przepisBezAniJednegoPustegoPola($basia->getKey());

        $this->assertFalse(
            CoMoznaDopisac::jest($przepis->fresh()),
            'Przepis zbudowany na potrzeby tego testu MIAŁ nie mieć czego dopisać. '.
            'Jeśli ta asercja pada, to nie widok jest zepsuty, tylko dane testu — '.
            'a bez niej test niżej przechodziłby, nie sprawdzając niczego.',
        );

        $this->actingAs($basia)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Edytuj przepis')
            ->assertDontSee('Dopisz szczegóły');
    }

    public function test_obcy_nie_widzi_zadnego_z_tych_przyciskow(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        $przepis = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        // Napis się zmienił, bramka nie. Gdyby zmiana napisu przy okazji
        // wyprowadziła przycisk poza `@can('update')`, ten test to złapie.
        $this->actingAs($marek)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('Dopisz szczegóły')
            ->assertDontSee('Edytuj przepis');
    }

    /**
     * Przepis, w którym `CoMoznaDopisac` nie ma o co zapytać.
     *
     * Wypełniamy WSZYSTKIE dziesięć pól z listy tej klasy, zdjęcie główne oraz
     * po jednym składniku i kroku. Gdyby ktoś dopisał do listy jedenaste pole,
     * asercja kontrolna w teście wyżej oblewa i mówi wprost, że to dane testu
     * są nieaktualne — zamiast cicho zamienić ten test w atrapę.
     */
    private function przepisBezAniJednegoPustegoPola(string $autorId): Recipe
    {
        $zdjecie = Media::factory()->create(['owner_id' => $autorId]);

        $przepis = Recipe::factory()->create([
            'author_id' => $autorId,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
            'summary' => 'Rosół, jaki gotowała moja babcia w każdą niedzielę.',
            'servings' => 6,
            'prep_minutes' => 20,
            'cook_minutes' => 180,
            'difficulty' => 'easy',
            'source_person' => 'Babcia Zofia',
            'source_note' => 'Przepisany z zeszytu w kratkę.',
            'source_url' => 'https://example.com/rosol',
            'family_since_year' => 1962,
            'hero_media_id' => $zdjecie->getKey(),
            'source_scan_media_id' => $zdjecie->getKey(),
        ]);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'ingredient_text' => 'pół kurczaka, najlepiej zagrodowego',
        ]);

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Zalej mięso zimną wodą i gotuj bez pokrywki.',
        ]);

        return $przepis;
    }
}

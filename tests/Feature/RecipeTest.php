<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeTest extends TestCase
{
    use RefreshDatabase;

    public function test_szkic_zapisuje_sie_z_samym_tytulem(): void
    {
        $basia = $this->user('basia');

        $recipe = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Rosół babci Zofii'],
            publish: false,
        );

        // Przerwanie kreatora nie może niczego skasować — dlatego szkic
        // przechodzi walidację z samym tytułem.
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame('rosol-babci-zofii', $recipe->slug);
    }

    public function test_publikacja_wymaga_kroku_ale_nie_wymaga_skladnika(): void
    {
        $basia = $this->user('basia');

        // SKŁADNIK NIE JEST WARUNKIEM — zgoda właściciela z 11.09.2026
        // (issue #364). Pełny dowód regresyjny wraz z drogą przez formularz
        // stoi w `DodawaniePrzepisuSzescKontrolekTest`; tu pilnujemy samej
        // akcji domenowej, bo to ona jest jedyną bramką dla obu dróg zapisu.
        $bezSkladnikow = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Przepis bez składników'],
            steps: [['instruction' => 'Wymieszać wszystko.']],
            publish: true,
        );

        $this->assertSame(Recipe::STATUS_PUBLISHED, $bezSkladnikow->status);
        $this->assertCount(0, $bezSkladnikow->ingredients);

        // KROK JEST WARUNKIEM i zostaje: przepis, który nie mówi, co zrobić,
        // nie jest przepisem.
        $this->expectExceptionMessage('Opisz przynajmniej jeden krok');

        app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Przepis bez niczego'],
            publish: true,
        );
    }

    public function test_tekst_skladnika_zostaje_dokladnie_taki_jak_wpisany(): void
    {
        $basia = $this->user('basia');

        $recipe = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Ciasto drożdżowe'],
            ingredients: [['text' => 'szklanka mąki, ta lepsza']],
            steps: [['instruction' => 'Wymieszać.']],
            publish: true,
        );

        // Normalizacja jest dodatkiem, nie zamiennikiem. Tekst człowieka
        // musi przetrwać bez zmian.
        $this->assertSame('szklanka mąki, ta lepsza', $recipe->ingredients->first()->ingredient_text);
        $this->assertNotNull($recipe->ingredients->first()->ingredient_id);
    }

    public function test_publikacja_tworzy_wersje_przepisu(): void
    {
        $basia = $this->user('basia');

        $recipe = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Żurek'],
            ingredients: [['text' => 'zakwas']],
            steps: [['instruction' => 'Zagotować.']],
            publish: true,
        );

        $this->assertSame(1, $recipe->versions()->count());
        $this->assertSame('Żurek', $recipe->versions()->first()->snapshot['title']);
    }

    public function test_slug_transliteruje_polskie_znaki(): void
    {
        $basia = $this->user('basia');

        $recipe = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Żurek z jajkiem i chrzanem'],
            publish: false,
        );

        $this->assertSame('zurek-z-jajkiem-i-chrzanem', $recipe->slug);
    }

    public function test_slug_publikowanego_przepisu_nie_zmienia_sie_po_zmianie_tytulu(): void
    {
        $basia = $this->user('basia');

        $recipe = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Rosół'],
            ingredients: [['text' => 'kura']],
            steps: [['instruction' => 'Gotować.']],
            publish: true,
        );

        $slug = $recipe->slug;

        $recipe = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Rosół z kaczki'],
            ingredients: [['text' => 'kaczka']],
            steps: [['instruction' => 'Gotować.']],
            publish: true,
            existing: $recipe,
        );

        // Adres opublikowanego przepisu jest obietnicą — ludzie go wysyłają
        // rodzinie i zapisują w zakładkach.
        $this->assertSame($slug, $recipe->slug);
        $this->assertSame('Rosół z kaczki', $recipe->title);
    }

    public function test_szkic_widzi_tylko_autor(): void
    {
        $basia = $this->user('basia');
        $obcy = $this->user('obcy');
        $recipe = Recipe::factory()->draft()->create(['author_id' => $basia->getKey()]);

        $this->actingAs($basia)->get(route('recipes.show', $recipe->slug))->assertOk();
        $this->actingAs($obcy)->get(route('recipes.show', $recipe->slug))->assertForbidden();
    }

    public function test_przepis_rodzinny_podpisuje_oryginalnego_autora(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $recipe = Recipe::factory()->family('babci Zofii')->create(['author_id' => $basia->getKey()]);

        $this->assertStringContainsString('babci Zofii', $recipe->attributionLine());
        $this->assertStringContainsString('spisany przez', $recipe->attributionLine());
    }
}

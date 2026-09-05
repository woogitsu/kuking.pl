<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\RecipeStatusTransitions;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autor nie odwraca decyzji moderatora (audyt A08).
 *
 * Dwie dziury składały się na jedną: `RecipePolicy::update()` pytała tylko
 * „czy to Twój przepis", a `PublishRecipe` traktowała status `hidden`
 * dokładnie jak `draft` (`if ($publish && ! $recipe->isPublished())`).
 *
 * Skutek: przepis ukryty przez moderatora wracał do sieci w dwóch
 * kliknięciach — „Edytuj" → „Opublikuj" — i robiła to dokładnie ta osoba,
 * której decyzja dotyczyła. Moderacja bez trwałości nie jest moderacją.
 *
 * Naprawa nie dokłada kolejnego `if`, tylko wprowadza jawną macierz przejść
 * statusu (`RecipeStatusTransitions`). Testy sprawdzają OBIE drogi wejścia —
 * Policy (HTTP) i akcję domenową (kod) — bo naprawienie jednej nie naprawia
 * drugiej, a akcja jest wspólna dla kreatora i formularza bez JavaScriptu.
 */
class UkryciePrzezModeratoraTest extends TestCase
{
    use RefreshDatabase;

    private function ukrytyPrzepis(): array
    {
        $autor = $this->user('autorka');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Nalewka na spirytusie',
            'slug' => 'nalewka-na-spirytusie',
            'status' => Recipe::STATUS_HIDDEN,
        ]);

        return [$autor, $recipe];
    }

    public function test_autor_nie_publikuje_ponownie_przepisu_ukrytego_przez_moderatora(): void
    {
        [$autor, $recipe] = $this->ukrytyPrzepis();

        $this->actingAs($autor)
            ->put(route('recipes.update', $recipe->slug), [
                'title' => 'Nalewka na spirytusie',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [['text' => 'wiśnie']],
                'steps' => [['instruction' => 'Zalać.']],
                'action' => 'publish',
            ])
            ->assertForbidden();

        $this->assertSame(Recipe::STATUS_HIDDEN, $recipe->refresh()->status);
    }

    public function test_ukryty_przepis_zostaje_niedostepny_dla_odwiedzajacych(): void
    {
        [$autor, $recipe] = $this->ukrytyPrzepis();

        $obcy = $this->user('obca');

        // Zanim: 403 → PUT action=publish → 200. To jest cała ścieżka ataku,
        // więc test przechodzi ją od strony zwykłego odwiedzającego.
        $this->actingAs($obcy)->get(route('recipes.show', $recipe->slug))->assertForbidden();

        $this->actingAs($autor)
            ->put(route('recipes.update', $recipe->slug), [
                'title' => 'Nalewka na spirytusie',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [['text' => 'wiśnie']],
                'steps' => [['instruction' => 'Zalać.']],
                'action' => 'publish',
            ]);

        $this->actingAs($obcy)->get(route('recipes.show', $recipe->slug))->assertForbidden();
    }

    public function test_autor_nie_wchodzi_nawet_w_edycje_ukrytego_przepisu(): void
    {
        [$autor, $recipe] = $this->ukrytyPrzepis();

        // Zablokowanie samego zapisu nie wystarcza: formularz, który wygląda
        // na działający i dopiero po wysłaniu mówi „nie", to zła obietnica.
        $this->actingAs($autor)
            ->get(route('recipes.edit', $recipe->slug))
            ->assertForbidden();
    }

    public function test_akcja_domenowa_odmawia_zmiany_ukrytego_przepisu(): void
    {
        [$autor, $recipe] = $this->ukrytyPrzepis();

        // Policy pilnuje WEJŚCIA na adres. Kreator Livewire i formularz bez
        // JavaScriptu kończą w tej samej akcji, więc regułę trzeba mieć także
        // tutaj — inaczej wystarczy drugi endpoint, żeby ją obejść.
        $this->expectException(\RuntimeException::class);

        app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => 'Nalewka na spirytusie', 'visibility' => 'public'],
            ingredients: [['text' => 'wiśnie']],
            steps: [['instruction' => 'Zalać.']],
            publish: true,
            existing: $recipe,
        );
    }

    public function test_zwykla_publikacja_szkicu_dalej_dziala(): void
    {
        $autor = $this->user('autorka');

        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosol niedzielny',
            'slug' => 'rosol-niedzielny',
        ]);

        // Naprawa moderacji nie może zabrać ludziom publikowania własnych
        // szkiców — to jest główna droga produktu, nie przypadek brzegowy.
        $this->actingAs($autor)
            ->put(route('recipes.update', $szkic->slug), [
                'title' => 'Rosol niedzielny',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [['text' => 'kura']],
                'steps' => [['instruction' => 'Gotować trzy godziny.']],
                'action' => 'publish',
            ])
            ->assertRedirect();

        $szkic->refresh();

        $this->assertSame(Recipe::STATUS_PUBLISHED, $szkic->status);
        $this->assertNotNull($szkic->published_at);
    }

    public function test_edycja_opublikowanego_przepisu_nie_zmienia_daty_publikacji(): void
    {
        $autor = $this->user('autorka');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Bigos',
            'slug' => 'bigos',
            'published_at' => now()->subYear(),
        ]);

        $pierwotnaData = $recipe->published_at;

        $this->actingAs($autor)
            ->put(route('recipes.update', $recipe->slug), [
                'title' => 'Bigos, wersja poprawiona',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [['text' => 'kapusta']],
                'steps' => [['instruction' => 'Dusić.']],
                'action' => 'publish',
            ])
            ->assertRedirect();

        $recipe->refresh();

        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertTrue($pierwotnaData->equalTo($recipe->published_at),
            'Data publikacji jest obietnicą w archiwum — edycja treści nie może jej przestawiać.');
    }

    public function test_macierz_przejsc_mowi_wprost_co_wolno_autorowi(): void
    {
        // Macierz jest po to, żeby regułę dało się przeczytać w jednym miejscu,
        // zamiast rekonstruować ją z rozsypanych warunków.
        $this->assertTrue(RecipeStatusTransitions::authorMay(Recipe::STATUS_DRAFT, Recipe::STATUS_PUBLISHED));
        $this->assertTrue(RecipeStatusTransitions::authorMay(Recipe::STATUS_DRAFT, Recipe::STATUS_DRAFT));
        $this->assertTrue(RecipeStatusTransitions::authorMay(Recipe::STATUS_PUBLISHED, Recipe::STATUS_PUBLISHED));

        // „Cofnięcie do szkicu" nie istnieje w produkcie — adres opublikowanego
        // przepisu jest obietnicą, a znikanie go z sieci na żądanie to
        // usunięcie, nie edycja.
        $this->assertFalse(RecipeStatusTransitions::authorMay(Recipe::STATUS_PUBLISHED, Recipe::STATUS_DRAFT));

        // Statusy moderacyjne są dla autora końcowe.
        $this->assertFalse(RecipeStatusTransitions::authorMay(Recipe::STATUS_HIDDEN, Recipe::STATUS_PUBLISHED));
        $this->assertFalse(RecipeStatusTransitions::authorMay(Recipe::STATUS_HIDDEN, Recipe::STATUS_DRAFT));
        $this->assertFalse(RecipeStatusTransitions::authorMay(Recipe::STATUS_REMOVED, Recipe::STATUS_PUBLISHED));

        $this->assertTrue(RecipeStatusTransitions::authorMayEdit(Recipe::STATUS_DRAFT));
        $this->assertTrue(RecipeStatusTransitions::authorMayEdit(Recipe::STATUS_PUBLISHED));
        $this->assertFalse(RecipeStatusTransitions::authorMayEdit(Recipe::STATUS_HIDDEN));
        $this->assertFalse(RecipeStatusTransitions::authorMayEdit(Recipe::STATUS_REMOVED));

        // Nieznany status to nie jest „wolno wszystko".
        $this->assertFalse(RecipeStatusTransitions::authorMayEdit('cokolwiek'));
        $this->assertFalse(RecipeStatusTransitions::authorMay('cokolwiek', Recipe::STATUS_PUBLISHED));
    }

    public function test_ukryty_przepis_mowi_autorowi_co_sie_stalo(): void
    {
        [$autor, $recipe] = $this->ukrytyPrzepis();

        // Bez tego autor widział komunikat „To jest szkic. Kliknij Edytuj",
        // a „Edytuj" oddawało 403 — czyli interfejs kłamał i nie mówił,
        // co zrobić (UX 50+).
        $this->actingAs($autor)
            ->get(route('recipes.show', $recipe->slug))
            ->assertOk()
            ->assertSee('ukryty przez moderację', false)
            ->assertDontSee('To jest szkic', false);
    }
}

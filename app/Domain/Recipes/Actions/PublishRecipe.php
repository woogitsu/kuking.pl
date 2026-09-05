<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\AuditLogEntry;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Zapis przepisu — szkicu albo publikacji.
 *
 * Kreator ma trzy kroki (informacje → składniki → przygotowanie) i po KAŻDYM
 * kroku zapisuje szkic. Reguła nadrzędna: przerwanie kreatora nie może
 * skasować niczego, co człowiek już wpisał. Dlatego szkic da się zapisać
 * z samym tytułem, a walidacja kompletności działa tylko przy publikacji.
 */
final class PublishRecipe
{
    public function __construct(
        private readonly GenerateRecipeSlug $slugs,
        private readonly SnapshotRecipeVersion $snapshots,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{text: string, group_name?: ?string, quantity?: ?float, unit_id?: ?string, note?: ?string}>  $ingredients
     * @param  list<array{instruction: string, timer_seconds?: ?int, media_id?: ?string}>  $steps
     */
    public function handle(
        User $author,
        array $attributes,
        array $ingredients = [],
        array $steps = [],
        bool $publish = false,
        ?Recipe $existing = null,
        ?string $ip = null,
    ): Recipe {
        $title = trim((string) ($attributes['title'] ?? ''));

        if ($title === '') {
            throw new RuntimeException('Podaj nazwę przepisu — choćby roboczą, zmienisz ją później.');
        }

        $cleanIngredients = $this->cleanIngredients($ingredients);
        $cleanSteps = $this->cleanSteps($steps);

        // WARUNEK BRZMI „czy po zapisie przepis BĘDZIE publiczny", nie „czy
        // ktoś kliknął Opublikuj".
        //
        // Wcześniej było `if ($publish)`, przez co zapis szkicu na JUŻ
        // OPUBLIKOWANYM przepisie omijał kontrolę kompletności. `syncIngredients()`
        // kasuje i odtwarza wiersze, a status zostawał `published` — więc jedno
        // kliknięcie przycisku, który brzmi jak prywatny zapis roboczy,
        // zamieniało opublikowany przepis w pustą skorupę:
        //
        //     status: published   ingredients: 0   steps: 0   versions: 0
        //
        // Strona publiczna nadal zwracała 200 i pokazywała „Autor jeszcze nie
        // dodał składników". Bez ostrzeżenia i bez wersji do odtworzenia,
        // bo snapshot powstaje tylko przy publikacji (audyt A07).
        $bedziePubliczny = $publish || ($existing !== null && $existing->isPublished());

        if ($bedziePubliczny) {
            if ($cleanIngredients === []) {
                throw new RuntimeException('Dodaj przynajmniej jeden składnik — bez tego przepis nie może być opublikowany.');
            }

            if ($cleanSteps === []) {
                throw new RuntimeException('Opisz przynajmniej jeden krok przygotowania — bez tego przepis nie może być opublikowany.');
            }
        }

        $recipe = DB::transaction(function () use (
            $author, $attributes, $title, $cleanIngredients, $cleanSteps, $publish, $existing
        ): Recipe {
            $payload = [
                'author_id' => $author->getKey(),
                'title' => $title,
                'summary' => $this->nullIfBlank($attributes['summary'] ?? null),
                'servings' => $attributes['servings'] ?? null,
                'prep_minutes' => $attributes['prep_minutes'] ?? null,
                'cook_minutes' => $attributes['cook_minutes'] ?? null,
                'difficulty' => $this->nullIfBlank($attributes['difficulty'] ?? null),
                'visibility' => $attributes['visibility'] ?? 'public',
                'hero_media_id' => $attributes['hero_media_id'] ?? null,
                'source_type' => $attributes['source_type'] ?? Recipe::SOURCE_OWN,
                'source_url' => $this->nullIfBlank($attributes['source_url'] ?? null),
                'source_person' => $this->nullIfBlank($attributes['source_person'] ?? null),
                'source_note' => $this->nullIfBlank($attributes['source_note'] ?? null),
                'family_since_year' => $attributes['family_since_year'] ?? null,
                'source_scan_media_id' => $attributes['source_scan_media_id'] ?? null,
            ];

            if ($existing === null) {
                $payload['slug'] = $this->slugs->handle($title);
                $payload['status'] = $publish ? Recipe::STATUS_PUBLISHED : Recipe::STATUS_DRAFT;
                $payload['published_at'] = $publish ? now() : null;

                $recipe = Recipe::create($payload);
            } else {
                $recipe = $existing;

                // Slug zmieniamy tylko dla szkicu. Po publikacji adres
                // przepisu jest obietnicą — ludzie go zapisują i wysyłają.
                if (! $recipe->isPublished()) {
                    $payload['slug'] = $this->slugs->handle($title, $recipe->getKey());
                }

                if ($publish && ! $recipe->isPublished()) {
                    $payload['status'] = Recipe::STATUS_PUBLISHED;
                    $payload['published_at'] = now();
                }

                $recipe->update($payload);
            }

            $this->syncIngredients($recipe, $cleanIngredients);
            $this->syncSteps($recipe, $cleanSteps);

            return $recipe->refresh();
        });

        if ($publish) {
            $this->snapshots->handle($recipe, $author, $existing === null ? 'Pierwsza publikacja' : 'Aktualizacja przepisu');

            AuditLogEntry::record(
                action: 'recipe.published',
                actor: $author,
                subject: $recipe,
                metadata: ['ingredients' => count($cleanIngredients), 'steps' => count($cleanSteps)],
                ip: $ip,
            );
        }

        return $recipe;
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     * @return list<array<string, mixed>>
     */
    private function cleanIngredients(array $ingredients): array
    {
        $clean = [];

        foreach ($ingredients as $row) {
            $text = trim((string) ($row['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $clean[] = [
                'group_name' => $this->nullIfBlank($row['group_name'] ?? null),
                'ingredient_text' => mb_substr($text, 0, 240),
                'quantity' => $row['quantity'] ?? null,
                'unit_id' => $row['unit_id'] ?? null,
                'note' => $this->nullIfBlank($row['note'] ?? null),
            ];
        }

        return $clean;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function cleanSteps(array $steps): array
    {
        $clean = [];

        foreach ($steps as $row) {
            $instruction = trim((string) ($row['instruction'] ?? ''));

            if ($instruction === '') {
                continue;
            }

            $clean[] = [
                'instruction' => $instruction,
                'timer_seconds' => $row['timer_seconds'] ?? null,
                'media_id' => $row['media_id'] ?? null,
            ];
        }

        return $clean;
    }

    /** @param  list<array<string, mixed>>  $ingredients */
    private function syncIngredients(Recipe $recipe, array $ingredients): void
    {
        $recipe->ingredients()->delete();

        foreach ($ingredients as $position => $row) {
            RecipeIngredient::create([
                'recipe_id' => $recipe->getKey(),
                'group_name' => $row['group_name'],
                // Normalizacja jest DODATKIEM do tekstu użytkownika, nigdy go
                // nie zastępuje — tekst zostaje dokładnie taki, jak wpisany.
                'ingredient_id' => Ingredient::findOrCreateByName($row['ingredient_text'])->getKey(),
                'ingredient_text' => $row['ingredient_text'],
                'quantity' => $row['quantity'],
                'unit_id' => $row['unit_id'],
                'note' => $row['note'],
                'position' => $position,
            ]);
        }
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function syncSteps(Recipe $recipe, array $steps): void
    {
        $recipe->steps()->delete();

        foreach ($steps as $position => $row) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $position,
                'instruction' => $row['instruction'],
                'timer_seconds' => $row['timer_seconds'],
                'media_id' => $row['media_id'],
            ]);
        }
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

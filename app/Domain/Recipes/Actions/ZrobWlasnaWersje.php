<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * „Zrób swoją wersję" — prywatny szkic będący kopią cudzego przepisu
 * (issue #23, D-301).
 *
 * CO SIĘ KOPIUJE, A CO NIE
 *
 * Kopiujemy to, z czego się gotuje: tytuł, opis, porcje, czasy, trudność,
 * składniki (z grupami, uwagami i „bez ilości") i treść kroków z minutnikami.
 * NIE kopiujemy zdjęć — ani głównego, ani przy krokach, ani skanu kartki. To są
 * zdjęcia autora oryginału (jego kuchnia, jego ręce, jego zeszyt), a wersja
 * ma pokazywać, jak TY to robisz. Nie kopiujemy też pochodzenia
 * (`source_person`, `source_note`, `family_since_year`): „od mamy Haliny"
 * jest historią autora oryginału, nie autora wersji.
 *
 * SZKIC JEST PRYWATNY. Status `draft`, widoczność `private` — o tym, kto
 * zobaczy wersję, autor decyduje sam, przy publikacji, w kreatorze.
 * Opublikowanie wersji bez żadnej zmiany odbija `PublishRecipe`
 * (`MojaWersja::pilnujRoznicy()`).
 *
 * PODPIS JEST NIEUSUWALNY. `forked_from_id` i `forked_at` ustawia wyłącznie
 * ta akcja, przez `forceFill()` — kolumn nie ma w `$fillable`, więc żaden
 * formularz ich nie przepnie ani nie wyczyści.
 *
 * DRUGIE KLIKNIĘCIE NIE ZAKŁADA DRUGIEGO SZKICU. Jeśli ta osoba ma już
 * niedokończoną wersję tego przepisu, oddajemy ją — `wasRecentlyCreated`
 * mówi kontrolerowi, który przypadek zaszedł. Opublikowana wersja nie
 * blokuje kolejnej: ktoś może robić ten sam przepis na dwa sposoby.
 */
final class ZrobWlasnaWersje
{
    public function __construct(
        private readonly GenerateRecipeSlug $slugs,
    ) {}

    public function handle(User $user, Recipe $oryginal, ?string $ip = null): Recipe
    {
        // UUID w adresie to nie autoryzacja (AGENTS.md §7) — i Policy nie
        // może być wyłącznie ochroną kontrolera (AGENTS.md §4).
        Gate::forUser($user)->authorize('fork', $oryginal);

        return DB::transaction(function () use ($user, $oryginal, $ip): Recipe {
            // Wiersz autora pod `FOR NO KEY UPDATE` serializuje dwa
            // równoległe kliknięcia tej samej osoby — drugie widzi szkic
            // pierwszego. `NO KEY`, bo ta blokada nie jest w konflikcie
            // z `FOR KEY SHARE`, którą biorą cudze zapisy wskazujące to konto
            // (komentarz, obserwowanie), więc nikogo poza tą samą akcją nie
            // ustawia w kolejce. Kolejność `users` → `recipes`, ta sama co
            // w kasowaniu konta (`EraseAccountData`, D-079 §1).
            DB::select('SELECT 1 FROM users WHERE id = ? FOR NO KEY UPDATE', [(string) $user->getKey()]);

            $istniejacy = Recipe::query()
                ->where('author_id', $user->getKey())
                ->where('forked_from_id', $oryginal->getKey())
                ->where('status', Recipe::STATUS_DRAFT)
                ->orderByDesc('created_at')
                ->first();

            if ($istniejacy !== null) {
                return $istniejacy;
            }

            $wersja = new Recipe([
                'author_id' => $user->getKey(),
                'title' => $oryginal->title,
                'slug' => $this->slugs->handle($oryginal->title),
                'summary' => $oryginal->summary,
                'servings' => $oryginal->servings,
                'prep_minutes' => $oryginal->prep_minutes,
                'cook_minutes' => $oryginal->cook_minutes,
                'difficulty' => $oryginal->difficulty,
                'visibility' => 'private',
                'status' => Recipe::STATUS_DRAFT,
                'source_type' => Recipe::SOURCE_ADAPTATION,
                'published_at' => null,
            ]);
            $wersja->forceFill([
                'forked_from_id' => $oryginal->getKey(),
                'forked_at' => now(),
            ])->save();

            foreach ($oryginal->ingredients()->get() as $skladnik) {
                RecipeIngredient::create([
                    'recipe_id' => $wersja->getKey(),
                    'group_name' => $skladnik->group_name,
                    'ingredient_id' => $skladnik->ingredient_id,
                    'ingredient_text' => $skladnik->ingredient_text,
                    'quantity' => $skladnik->quantity,
                    'unit_id' => $skladnik->unit_id,
                    'note' => $skladnik->note,
                    'position' => $skladnik->position,
                    'no_amount' => $skladnik->no_amount,
                ]);
            }

            foreach ($oryginal->steps()->get() as $krok) {
                RecipeStep::create([
                    'recipe_id' => $wersja->getKey(),
                    'position' => $krok->position,
                    'instruction' => $krok->instruction,
                    'media_id' => null,
                    'timer_seconds' => $krok->timer_seconds,
                ]);
            }

            AuditLogEntry::record(
                action: 'recipe.forked',
                actor: $user,
                subject: $wersja,
                metadata: ['forked_from_id' => (string) $oryginal->getKey()],
                ip: $ip,
            );

            return $wersja;
        });
    }
}

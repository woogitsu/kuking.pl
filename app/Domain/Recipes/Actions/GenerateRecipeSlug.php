<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\Recipe;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slug przepisu.
 *
 * Polskie znaki są transliterowane ("żurek" → "zurek"), bo URL z procentami
 * jest nieczytelny, gdy ktoś chce go przeczytać przez telefon albo wpisać
 * z kartki. Jednocześnie stary slug NIGDY nie umiera — przy zmianie tytułu
 * dopisujemy przekierowanie (patrz UpdateRecipeSlug), bo link wysłany córce
 * SMS-em musi działać po roku.
 *
 * ATOMOWOŚĆ (audyt GPT-6 Astra, DB-004, #2403)
 *
 * `handle()` sprawdza wolny slug przez `exists()`, a insert robi się później —
 * dwie równoległe publikacje tego samego tytułu mogą więc wybrać ten sam
 * slug. Ostateczną gwarancją jest `UNIQUE` w bazie (`recipes_slug_unique`),
 * ale bez ponowienia przegrany dostawał błąd 500 zamiast kolejnego wolnego
 * adresu. `zapisz()` robi insert w SAVEPOINT-cie (zagnieżdżona transakcja
 * cofa się tylko do niego, więc nadrzędny zapis przepisu żyje dalej), a przy
 * naruszeniu WŁAŚNIE TEGO indeksu bierze następny wolny slug. Limit prób jest
 * twardy, a każde inne naruszenie unikalności (np. `klucz_wyslania`) i każdy
 * inny błąd bazy idzie dalej bez zmian.
 */
final class GenerateRecipeSlug
{
    /** Ile razy najwyżej próbujemy zapisać przepis, zanim oddamy błąd kolizji. */
    public const MAKSYMALNA_LICZBA_PROB = 5;

    /** Nazwa indeksu `UNIQUE` na `recipes.slug` (migracja `create_recipes_tables`). */
    public const INDEKS_SLUGU = 'recipes_slug_unique';

    /**
     * Zapisuje przepis pod pierwszym wolnym slugiem i ponawia, gdy ktoś
     * równolegle zajął ten sam. `$zapis` dostaje slug i ma wykonać insert
     * (albo update) — woła się go w SAVEPOINT-cie, więc musi być
     * powtarzalny i nie może mieć skutków poza bazą.
     *
     * @template T
     *
     * @param  Closure(string): T  $zapis
     * @return T
     */
    public function zapisz(string $title, Closure $zapis, ?string $ignoreRecipeId = null): mixed
    {
        $zajete = [];

        for ($proba = 1; ; $proba++) {
            $slug = $this->handle($title, $ignoreRecipeId, $zajete);

            try {
                return DB::transaction(static fn (): mixed => $zapis($slug));
            } catch (UniqueConstraintViolationException $e) {
                if ($proba >= self::MAKSYMALNA_LICZBA_PROB || ! str_contains($e->getMessage(), self::INDEKS_SLUGU)) {
                    throw $e;
                }

                // Slug zajął ktoś inny, zanim zdążył go zobaczyć nasz
                // `exists()`. Nie wracamy do niego, nawet gdyby tamten zapis
                // się cofnął — następny kandydat jest zawsze dalej.
                $zajete[] = $slug;
            }
        }
    }

    /**
     * @param  list<string>  $pomijane  slugi, które już odbiły się o `UNIQUE` w tej próbie
     */
    public function handle(string $title, ?string $ignoreRecipeId = null, array $pomijane = []): string
    {
        $base = Str::slug(Str::ascii($title));

        if ($base === '') {
            $base = 'przepis';
        }

        $base = Str::limit($base, 200, '');
        $slug = $base;
        $suffix = 2;

        while (in_array($slug, $pomijane, true) || $this->taken($slug, $ignoreRecipeId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function taken(string $slug, ?string $ignoreRecipeId): bool
    {
        $query = Recipe::withTrashed()->where('slug', $slug);

        if ($ignoreRecipeId !== null) {
            $query->whereKeyNot($ignoreRecipeId);
        }

        return $query->exists();
    }
}

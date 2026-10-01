<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * „Zrób swoją wersję" — prywatny szkic będący kopią cudzego przepisu
 * (issue #23, D-301).
 *
 * CO SIĘ KOPIUJE, A CO NIE
 *
 * Kopiujemy to, z czego się gotuje: tytuł, opis, porcje, czasy, trudność,
 * składniki (z grupami, uwagami, zamiennikami i „bez ilości") i treść kroków z minutnikami.
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
        return DB::transaction(function () use ($user, $oryginal, $ip): Recipe {
            /*
             * DOSTĘP SPRAWDZANY POD BLOKADĄ, NA ŚWIEŻYM STANIE (#2323).
             *
             * Przedtem Policy stała PRZED transakcją i pytała o modele podane
             * z zewnątrz, a kopia czytała składniki i kroki ze starego
             * `$oryginal`. Autor przełączał w tym czasie przepis na „tylko
             * ja", blokował kopiującego, moderacja ukrywała przepis albo
             * banowała autora — a pełna treść i tak lądowała w szkicu osoby,
             * która w chwili zapisu nie miała już prawa jej widzieć.
             *
             * Od tej linijki `$user` i `$oryginal` to wiersze odczytane POD
             * BLOKADĄ. Zmiana zatwierdzona przed nami jest tu już widoczna,
             * a ta, która przyjdzie po nas, poczeka na koniec tej transakcji
             * — razem z kopią. Wzór i uzasadnienie trybów blokad:
             * `RecordCookedEvent::zablokujStanDostepu()` (#2017).
             */
            $stan = $this->zablokujStanDostepu($user, $oryginal);

            if ($stan === null) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            [$user, $oryginal] = $stan;

            // UUID w adresie to nie autoryzacja (AGENTS.md §7) — i Policy nie
            // może być wyłącznie ochroną kontrolera (AGENTS.md §4).
            Gate::forUser($user)->authorize('fork', $oryginal);

            $istniejacy = Recipe::query()
                ->where('author_id', $user->getKey())
                ->where('forked_from_id', $oryginal->getKey())
                ->where('status', Recipe::STATUS_DRAFT)
                ->orderByDesc('created_at')
                ->first();

            if ($istniejacy !== null) {
                return $istniejacy;
            }

            $atrybuty = [
                'author_id' => $user->getKey(),
                'title' => $oryginal->title,
                'summary' => $oryginal->summary,
                'servings' => $oryginal->servings,
                'prep_minutes' => $oryginal->prep_minutes,
                'cook_minutes' => $oryginal->cook_minutes,
                'difficulty' => $oryginal->difficulty,
                'visibility' => 'private',
                'status' => Recipe::STATUS_DRAFT,
                'source_type' => Recipe::SOURCE_ADAPTATION,
                'published_at' => null,
            ];

            // Slug i insert razem, z ponowieniem przy równoległym zajęciu
            // tego samego adresu (#2403).
            $wersja = $this->slugs->zapisz($oryginal->title, static function (string $slug) use ($atrybuty, $oryginal): Recipe {
                $nowa = new Recipe([...$atrybuty, 'slug' => $slug]);
                $nowa->forceFill([
                    'forked_from_id' => $oryginal->getKey(),
                    'forked_at' => now(),
                ])->save();

                return $nowa;
            });

            foreach ($oryginal->ingredients()->get() as $skladnik) {
                RecipeIngredient::create([
                    'recipe_id' => $wersja->getKey(),
                    'group_name' => $skladnik->group_name,
                    'ingredient_id' => $skladnik->ingredient_id,
                    'ingredient_text' => $skladnik->ingredient_text,
                    'quantity' => $skladnik->quantity,
                    'unit_id' => $skladnik->unit_id,
                    'note' => $skladnik->note,
                    // Zamiennik od autora („margaryna albo olej kokosowy",
                    // D-284). Ktoś robi własną wersję właśnie po to, żeby
                    // dopasować przepis — ta podpowiedź nie może zniknąć po
                    // cichu (#2238, audyt BP-02).
                    'substitutes' => $skladnik->substitutes,
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

    /**
     * Konta kopiującego i autora oraz wiersz oryginału — odczytane POD
     * BLOKADĄ, do końca bieżącej transakcji (#2323).
     *
     * TRYBY BLOKAD
     *  - konto kopiującego: `FOR NO KEY UPDATE`, jak przed #2323 — serializuje
     *    dwa równoległe kliknięcia tej samej osoby (drugie widzi szkic
     *    pierwszego). `NO KEY`, bo nie koliduje z `FOR KEY SHARE`, którą biorą
     *    cudze zapisy wskazujące to konto (komentarz, obserwowanie). Koliduje
     *    za to z `ZamekKonta`/`ZamekPary` i `UPDATE users` — czyli z banem,
     *    zawieszeniem i blokadą, które odbierają prawo do kopii;
     *  - konto autora: `FOR SHARE` — koliduje z tymi samymi zmianami konta
     *    autora (ban, karencja usunięcia, blokada przez `ZamekPary`), a nie
     *    ustawia w kolejce dwóch niezależnych kopii różnych osób;
     *  - oryginał: `FOR SHARE` — koliduje z każdą zmianą widoczności, statusu
     *    i treści (`UPDATE recipes`, `FOR UPDATE` w `PublishRecipe`
     *    i moderacji), więc składniki i kroki czytane niżej są tymi z wersji,
     *    której dostęp właśnie sprawdziliśmy.
     *
     * KOLEJNOŚĆ: konta rosnąco po `id` (jak `ZamekPary`), potem przepis —
     * `users` → `recipes`, ta sama co w `RecordCookedEvent`, `PublishRecipe`
     * (D-103) i kasowaniu konta (`EraseAccountData`, D-079 §1).
     *
     * @return array{0: User, 1: Recipe}|null `null`, gdy któregoś wiersza już
     *                                        nie ma (np. przepis zdjęty przez moderację — miękko usunięty — albo
     *                                        podmieniony autor)
     */
    private function zablokujStanDostepu(User $user, Recipe $oryginal): ?array
    {
        $idKopiujacego = (string) $user->getKey();
        $idAutora = (string) $oryginal->author_id;

        $doZablokowania = array_values(array_unique([$idKopiujacego, $idAutora]));
        sort($doZablokowania, SORT_STRING);

        $konta = [];

        foreach ($doZablokowania as $id) {
            $zapytanie = User::query()->whereKey($id);

            $konta[$id] = $id === $idKopiujacego
                ? $zapytanie->lock('for no key update')->first()
                : $zapytanie->sharedLock()->first();
        }

        // Miękko usunięty (zdjęty przez moderację) przepis nie wraca tu przez
        // domyślny zakres `SoftDeletes` — i ma nie wracać.
        $swiezy = Recipe::query()->whereKey($oryginal->getKey())->sharedLock()->first();

        $kopiujacy = $konta[$idKopiujacego] ?? null;
        $autor = $konta[$idAutora] ?? null;

        if ($swiezy === null || $kopiujacy === null || $autor === null || (string) $swiezy->author_id !== $idAutora) {
            return null;
        }

        $swiezy->setRelation('author', $autor);

        return [$kopiujacy, $swiezy];
    }
}

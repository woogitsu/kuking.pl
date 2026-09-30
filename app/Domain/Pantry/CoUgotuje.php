<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * „Co ugotuję z tego, co mam” (V2, D-285).
 *
 * REGUŁA DOBORU, JEDNYM ZDANIEM (pokazywana też na ekranie):
 *
 *     Najpierw przepisy, do których masz najwięcej — czyli brakuje w nich
 *     najmniej składników z Twojej listy; przy tej samej liczbie brakujących
 *     najpierw te, które zajmują najmniej czasu.
 *
 * Nic poza tym. Kolejność NIE zależy od reakcji innych ludzi — ani od
 * „Ugotowałem”, ani od zapisów w zeszytach, ani od obserwujących
 * (AGENTS.md §8 i §12). Pilnuje tego `FeedNieSortujePoMierzeReakcjiTest`,
 * który skanuje cały katalog `app/Domain/Pantry`.
 *
 * DOPASOWANIE SKŁADNIKA
 * Linijka składnika w przepisie „jest w domu”, gdy któryś produkt z listy
 * ma wszystkie swoje rdzenie w rdzeniach tej linijki:
 * `pantry_items.rdzenie <@ public.kuking_rdzenie_skladnika(ingredient_text)`.
 * Reguła rdzeni (małe litery, bez polskich znaków, słownik form krótkich
 * słów, prosta liczba mnoga dłuższych — #1969) jest opisana w migracji `2026_09_28_233700_create_pantry_items_table`
 * i mieszka wyłącznie w bazie. Bez AI.
 *
 * TRYB „NAJPIERW TO, CO SIĘ PSUJE” (#1903, D-333)
 * `?najpierw=termin` zmienia tylko kolejność i zbiór: wchodzą przepisy,
 * w których jest choć jeden pilny produkt, a pierwszym kluczem jest liczba
 * takich produktów (`REGULA_NAJPIERW_TERMIN`). Widok domyślny się nie zmienia.
 *
 * KTÓRE PRZEPISY W OGÓLE WCHODZĄ
 * Te same, które ta osoba może otworzyć (`widoczneDla`), wyłącznie
 * opublikowane (`published()` PRZED `widoczneDla()` — bez tego weszłyby
 * własne szkice, tak jak w `SearchQuery`), od aktywnych kont, i tylko takie,
 * w których pasuje co najmniej jeden składnik. Przepis, do którego nie masz
 * nic, nie jest odpowiedzią na pytanie „co ugotuję z tego, co mam”.
 */
final class CoUgotuje
{
    public const NA_STRONE = 20;

    /**
     * Zdanie z regułą — jedno źródło dla widoku i dokumentacji.
     */
    public const REGULA = 'Najpierw przepisy, do których masz najwięcej — czyli brakuje w nich najmniej składników z Twojej listy. '
        .'Przy tej samej liczbie brakujących najpierw te, które zajmują najmniej czasu.';

    /**
     * Reguła trybu „Najpierw to, co się psuje” (`?najpierw=termin`, #1903):
     * ta sama baza zapytania, nowy pierwszy klucz — liczba PILNYCH produktów
     * tej osoby (termin do dziś + N dni, nie mrożone), które pasują do
     * składników przepisu. Dalej bez zmian. Kolejność ustawia wyłącznie data,
     * którą człowiek sam wpisał przy swoim produkcie — nie cudze reakcje.
     */
    public const REGULA_NAJPIERW_TERMIN = 'Najpierw przepisy, w których jest najwięcej Twoich produktów z krótkim terminem. '
        .'Przy tej samej liczbie najpierw te, do których brakuje najmniej składników, a potem te, które zajmują najmniej czasu. '
        .'Kolejność ustawia tylko data, którą wpisujesz Ty.';

    /**
     * Warunek „ta linijka składnika jest na liście tej osoby”. `ri` to alias
     * `recipe_ingredients` w zapytaniu, które go używa.
     */
    /**
     * Ta sama linijka, ale tylko wobec PILNYCH produktów tej osoby: z terminem
     * do dziś + N dni (także minionym), nie mrożonych (#1903). Parametry:
     * id konta, granica pilnych (`Y-m-d`). Reguła pilności mieszka
     * w `PriorytetZuzycia`, tu tylko jej wyraz w SQL.
     */
    private const PILNY_SQL = 'EXISTS (SELECT 1 FROM pantry_items p WHERE p.user_id = ? '
        .'AND p.expires_on IS NOT NULL AND p.expires_on <= ? AND NOT p.frozen '
        .'AND p.rdzenie <@ public.kuking_rdzenie_skladnika(ri.ingredient_text))';

    private const MAM_SQL = 'EXISTS (SELECT 1 FROM pantry_items p WHERE p.user_id = ? '
        .'AND p.rdzenie <@ public.kuking_rdzenie_skladnika(ri.ingredient_text))';

    /**
     * @return array{
     *     przepisy: Collection<int, Recipe>,
     *     brakujace: array<string, list<string>>,
     *     jest_wiecej: bool,
     *     produktow: int,
     *     do_zuzycia: array<string, list<array{nazwa: string, termin: string}>>
     * }
     */
    public function dla(User $widz, int $offset = 0, int $limit = self::NA_STRONE, bool $najpierwTermin = false): array
    {
        $uid = (string) $widz->getKey();
        $offset = max(0, $offset);
        $granica = PriorytetZuzycia::granicaPilnych();

        // Po jednym, najdłuższym rdzeniu z każdego produktu: wstępny filtr
        // `LIKE` na `ingredient_text_search`, który może pójść po indeksie
        // trigramowym `recipe_ingredients_text_trgm_idx`. Rdzeń dłuższy niż
        // 4 litery powstaje z obcięcia końcówki, więc jest fragmentem każdej
        // formy słowa. Krótszy może pochodzić ze słownika form (#1969: `maka`
        // dla „mąki”) — wtedy szukamy jego pierwszych trzech liter, od których
        // zaczyna się każda forma w słowniku (niezmiennik z migracji, pilnowany
        // testem). Filtr niczego prawdziwego nie gubi — tylko zawęża
        // kandydatów przed dokładnym porównaniem tablic.
        // Tryb „najpierw to, co się psuje” (#1903): zbiór przepisów wyznaczają
        // wyłącznie PILNE produkty — przepis bez żadnego z nich nie jest
        // odpowiedzią na to pytanie.
        $rdzenie = collect(DB::select(
            'SELECT DISTINCT ON (p.id) CASE WHEN length(t) > 4 THEN t ELSE left(t, 3) END AS rdzen '
            .'FROM pantry_items p, unnest(p.rdzenie) AS t '
            .'WHERE p.user_id = ? '
            .($najpierwTermin ? 'AND p.expires_on IS NOT NULL AND p.expires_on <= ? AND NOT p.frozen ' : '')
            .'ORDER BY p.id, length(t) DESC, t',
            $najpierwTermin ? [$uid, $granica] : [$uid],
        ))->pluck('rdzen')->unique()->values();

        if ($rdzenie->isEmpty()) {
            return [
                'przepisy' => new Collection, 'brakujace' => [], 'jest_wiecej' => false,
                'produktow' => $najpierwTermin ? $widz->pantryItems()->count() : 0,
                'do_zuzycia' => [],
            ];
        }

        $wstepnie = implode(' OR ', array_fill(0, $rdzenie->count(), 'ri.ingredient_text_search LIKE ?'));
        $wzorce = $rdzenie->map(fn (string $r): string => '%'.$r.'%')->all();

        $zapytanie = Recipe::query()
            ->published()
            ->widoczneDla($widz)
            ->whereHas('author', fn ($autor) => $autor->where('status', User::STATUS_ACTIVE))
            ->whereRaw(
                'recipes.id IN (SELECT ri.recipe_id FROM recipe_ingredients ri WHERE ('.$wstepnie.') AND '
                .($najpierwTermin ? self::PILNY_SQL : self::MAM_SQL).')',
                $najpierwTermin ? [...$wzorce, $uid, $granica] : [...$wzorce, $uid],
            )
            ->select('recipes.*')
            ->selectRaw('(SELECT count(*) FROM recipe_ingredients ri WHERE ri.recipe_id = recipes.id) AS skladnikow_razem')
            ->selectRaw(
                '(SELECT count(*) FROM recipe_ingredients ri WHERE ri.recipe_id = recipes.id AND NOT '.self::MAM_SQL.') AS skladnikow_brakuje',
                [$uid],
            )
            ->with(Recipe::RELACJE_KARTY);

        if ($najpierwTermin) {
            // Ile Twoich PILNYCH produktów pasuje do składników tego przepisu
            // (produkty, nie linijki). Pierwszy klucz kolejności w tym trybie.
            $zapytanie->selectRaw(
                '(SELECT count(*) FROM pantry_items p WHERE p.user_id = ? AND p.expires_on IS NOT NULL '
                .'AND p.expires_on <= ? AND NOT p.frozen AND EXISTS (SELECT 1 FROM recipe_ingredients ri '
                .'WHERE ri.recipe_id = recipes.id AND p.rdzenie <@ public.kuking_rdzenie_skladnika(ri.ingredient_text))) AS pilnych_pasuje',
                [$uid, $granica],
            )->orderByDesc('pilnych_pasuje');
        }

        $wiersze = $zapytanie
            // REGUŁA — patrz nagłówek klasy. Najpierw liczba brakujących,
            // potem łączny czas (przepis bez podanego czasu na końcu remisu:
            // brak danych nie znaczy „szybki”, ta sama zasada co filtr
            // „Do 30 minut” w wyszukiwarce), potem czas publikacji i `id`
            // wyłącznie po to, żeby kolejność była stała między stronami.
            ->orderBy('skladnikow_brakuje')
            ->orderByRaw('(recipes.prep_minutes + recipes.cook_minutes) ASC NULLS LAST')
            ->orderByDesc('recipes.published_at')
            ->orderBy('recipes.id')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();

        $jestWiecej = $wiersze->count() > $limit;
        $przepisy = $wiersze->take($limit)->values();

        return [
            'przepisy' => $przepisy,
            'brakujace' => $this->brakujace($przepisy, $uid),
            'jest_wiecej' => $jestWiecej,
            'produktow' => $widz->pantryItems()->count(),
            'do_zuzycia' => $najpierwTermin ? $this->doZuzycia($przepisy, $uid, $granica) : [],
        ];
    }

    /**
     * „Zużyjesz: mleko (do 2 października), szynka” — pilne produkty TEJ osoby,
     * które pasują do składników przepisu. Tylko jej własne wiersze
     * (`p.user_id = ?`): cudzych produktów tu nie ma skąd wziąć.
     *
     * @param  Collection<int, Recipe>  $przepisy
     * @return array<string, list<array{nazwa: string, termin: string}>> id przepisu => produkty
     */
    private function doZuzycia(Collection $przepisy, string $uid, string $granica): array
    {
        if ($przepisy->isEmpty()) {
            return [];
        }

        $wynik = array_fill_keys($przepisy->modelKeys(), []);
        $miejsca = implode(', ', array_fill(0, $przepisy->count(), '?'));

        $wiersze = DB::select(
            'SELECT DISTINCT ri.recipe_id, p.id, p.name, p.expires_on FROM recipe_ingredients ri '
            .'JOIN pantry_items p ON p.user_id = ? AND p.expires_on IS NOT NULL AND p.expires_on <= ? AND NOT p.frozen '
            .'AND p.rdzenie <@ public.kuking_rdzenie_skladnika(ri.ingredient_text) '
            ."WHERE ri.recipe_id IN ({$miejsca}) ORDER BY p.expires_on, p.name, p.id",
            [$uid, $granica, ...$przepisy->modelKeys()],
        );

        foreach ($wiersze as $wiersz) {
            $wynik[(string) $wiersz->recipe_id][] = ['nazwa' => (string) $wiersz->name, 'termin' => (string) $wiersz->expires_on];
        }

        return $wynik;
    }

    /**
     * Linijki składników, których brakuje — dosłownie tak, jak napisał je
     * autor przepisu, w kolejności z przepisu.
     *
     * @param  Collection<int, Recipe>  $przepisy
     * @return array<string, list<string>> id przepisu => brakujące linijki
     */
    private function brakujace(Collection $przepisy, string $uid): array
    {
        if ($przepisy->isEmpty()) {
            return [];
        }

        $wynik = array_fill_keys($przepisy->modelKeys(), []);

        RecipeIngredient::query()
            ->from('recipe_ingredients as ri')
            ->whereIn('ri.recipe_id', $przepisy->modelKeys())
            ->whereRaw('NOT '.self::MAM_SQL, [$uid])
            ->orderBy('position')
            ->get(['ri.recipe_id', 'ri.ingredient_text'])
            ->each(function (RecipeIngredient $linijka) use (&$wynik): void {
                $wynik[(string) $linijka->recipe_id][] = (string) $linijka->ingredient_text;
            });

        return $wynik;
    }
}

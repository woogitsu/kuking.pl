<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Recipes\Historia\HistoriaWersji;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\Historia\PorownanieWersji;
use App\Domain\Recipes\Historia\UkrywanieWersji;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Support\Komunikat;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Historia zapisanych wersji przepisu (issue #2024) i ukrywanie pojedynczej
 * wersji (issue #2270).
 *
 * Dostęp: `RecipePolicy::view` (403 jak na stronie przepisu) i dodatkowo
 * przepis musi być opublikowany (404 — nic nie zdradzamy o przepisie ukrytym
 * albo zdjętym). Reguły w `HistoriaWersji`. Wersja ukryta istnieje tylko dla
 * autora i moderacji (`HistoriaWersji::widziUkryte`); dla reszty jej adres
 * daje 404, jak wersja, której nie ma.
 */
class HistoriaPrzepisuController extends Controller
{
    private const KOMUNIKAT_NAJNOWSZA = 'Najnowszej wersji nie da się ukryć, bo to jest treść przepisu, którą widać na jego stronie. '
        .'Popraw przepis i zapisz zmiany — powstanie nowa wersja, a tę będzie można wtedy ukryć.';

    public function index(Request $request, string $recipe): View
    {
        $model = $this->przepis($request, $recipe, 'recipes.history');
        $zUkrytymi = HistoriaWersji::widziUkryte($request->user(), $model);

        $wersje = HistoriaWersji::zapytanie($model, $zUkrytymi)
            ->select(['id', 'recipe_id', 'version_number', 'change_note', 'created_at', 'hidden_at', 'hidden_by_role'])
            ->orderByDesc('version_number')
            ->simplePaginate(HistoriaWersji::NA_STRONE);

        $numery = HistoriaWersji::numery($model, $zUkrytymi);

        return view('pages.recipes.historia', [
            'recipe' => $model,
            'wersje' => $wersje,
            'najnowsza' => $numery[0] ?? null,
            'zUkrytymi' => $zUkrytymi,
            'uprawnienia' => $this->uprawnieniaUkrycia($request, $model),
            // Faktyczny poprzednik każdej wersji z listy (numeracja może mieć luki).
            'poprzednicy' => $wersje->getCollection()
                ->mapWithKeys(fn (RecipeVersion $w): array => [$w->version_number => $this->sasiad($numery, $w->version_number, -1)])
                ->all(),
        ]);
    }

    public function show(Request $request, string $recipe, int $numer): View
    {
        $model = $this->przepis($request, $recipe, 'recipes.history.version', $numer);
        $zUkrytymi = HistoriaWersji::widziUkryte($request->user(), $model);
        $wersja = $this->wersja($model, $numer, $zUkrytymi);
        $numery = HistoriaWersji::numery($model, $zUkrytymi);

        $this->zapiszWgladModeracji($request, $model, $wersja);

        return view('pages.recipes.historia-wersja', [
            'recipe' => $model,
            'wersja' => $wersja,
            'migawka' => new MigawkaWersji($wersja->snapshot ?? []),
            'starszy' => $this->sasiad($numery, $numer, -1),
            'nowszy' => $this->sasiad($numery, $numer, 1),
            'czyNajnowsza' => ($numery[0] ?? null) === $numer,
            'uprawnienia' => $this->uprawnieniaUkrycia($request, $model),
        ]);
    }

    public function zmiany(Request $request, string $recipe, int $numer): View
    {
        $model = $this->przepis($request, $recipe, 'recipes.history.changes', $numer);
        $zUkrytymi = HistoriaWersji::widziUkryte($request->user(), $model);
        $wersja = $this->wersja($model, $numer, $zUkrytymi);
        $numery = HistoriaWersji::numery($model, $zUkrytymi);
        $poprzedniNumer = $this->sasiad($numery, $numer, -1);

        $poprzednia = $poprzedniNumer === null ? null : $this->wersja($model, $poprzedniNumer, $zUkrytymi);

        $this->zapiszWgladModeracji($request, $model, $wersja);
        if ($poprzednia !== null) {
            $this->zapiszWgladModeracji($request, $model, $poprzednia);
        }

        return view('pages.recipes.historia-zmiany', [
            'recipe' => $model,
            'wersja' => $wersja,
            'poprzednia' => $poprzednia,
            // Ile ukrytych wersji porównanie przeskoczyło (dla autora
            // i moderacji zawsze 0 — oni porównują po kolei, z ukrytymi).
            'pominieteUkryte' => $zUkrytymi ? 0 : HistoriaWersji::ukryteMiedzy($model, $poprzedniNumer, $numer),
            'porownanie' => $poprzednia === null
                ? null
                : PorownanieWersji::porownaj($poprzednia->snapshot ?? [], $wersja->snapshot ?? []),
            'nowszy' => $this->sasiad($numery, $numer, 1),
        ]);
    }

    /**
     * Ekran potwierdzenia „Ukryć wersję N?" — bez JavaScriptu (AGENTS.md §5:
     * akcja zmieniająca to, co widzą inni, wymaga potwierdzenia).
     */
    public function potwierdzUkrycie(Request $request, string $recipe, int $numer): View|RedirectResponse
    {
        $model = $this->przepis($request, $recipe, 'recipes.history.hide', $numer);
        $wersja = $this->wersjaDoDecyzji($request, $model, $numer);
        $this->authorize('hide', $wersja);

        if ($wersja->czyUkryta()) {
            return $this->doHistorii($model, Komunikat::informacja("Wersja {$numer} jest już ukryta."));
        }
        if (HistoriaWersji::numerNajnowszej($model) === $numer) {
            return $this->doHistorii($model, Komunikat::blad(self::KOMUNIKAT_NAJNOWSZA));
        }

        return view('pages.recipes.historia-ukryj', [
            'recipe' => $model,
            'wersja' => $wersja,
            'strona' => UkrywanieWersji::strona($request->user(), $model),
        ]);
    }

    public function ukryj(Request $request, string $recipe, int $numer, UkrywanieWersji $ukrywanie): RedirectResponse
    {
        $model = $this->przepisDoZapisu($request, $recipe);
        $this->authorize('hide', $this->wersjaDoDecyzji($request, $model, $numer));

        $wynik = $ukrywanie->ukryj($request->user(), $model, $numer, $request->ip());

        return $this->doHistorii($model, match ($wynik) {
            UkrywanieWersji::UKRYTO => Komunikat::sukces("Wersja {$numer} jest ukryta. Widzisz ją tylko Ty i moderacja; w każdej chwili możesz ją przywrócić."),
            UkrywanieWersji::JUZ_UKRYTA => Komunikat::informacja("Wersja {$numer} jest już ukryta."),
            UkrywanieWersji::NAJNOWSZA => Komunikat::blad(self::KOMUNIKAT_NAJNOWSZA),
            default => Komunikat::blad('Tej wersji już nie ma. Odśwież historię zmian.'),
        });
    }

    public function potwierdzPrzywrocenie(Request $request, string $recipe, int $numer): View|RedirectResponse
    {
        $model = $this->przepis($request, $recipe, 'recipes.history.restore', $numer);
        $wersja = $this->wersjaDoDecyzji($request, $model, $numer);
        $this->authorize('restore', $wersja);

        if (! $wersja->czyUkryta()) {
            return $this->doHistorii($model, Komunikat::informacja("Wersja {$numer} nie jest ukryta — widzi ją każdy, kto widzi przepis."));
        }

        return view('pages.recipes.historia-przywroc', [
            'recipe' => $model,
            'wersja' => $wersja,
        ]);
    }

    public function przywroc(Request $request, string $recipe, int $numer, UkrywanieWersji $ukrywanie): RedirectResponse
    {
        $model = $this->przepisDoZapisu($request, $recipe);
        $this->authorize('restore', $this->wersjaDoDecyzji($request, $model, $numer));

        $wynik = $ukrywanie->przywroc($request->user(), $model, $numer, $request->ip());

        return $this->doHistorii($model, match ($wynik) {
            UkrywanieWersji::PRZYWROCONO => Komunikat::sukces("Wersja {$numer} jest znowu widoczna dla każdego, kto widzi przepis."),
            UkrywanieWersji::NIE_BYLA_UKRYTA => Komunikat::informacja("Wersja {$numer} nie jest ukryta — widzi ją każdy, kto widzi przepis."),
            default => Komunikat::blad('Tej wersji już nie ma. Odśwież historię zmian.'),
        });
    }

    /**
     * Które przyciski ukrycia pokazać — liczone RAZ na stronę, tą samą
     * `RecipeVersionPolicy`, która pilnuje zapisu. Odpowiedź zależy tylko od
     * przepisu, widza i tego, kto wersję ukrył, więc wystarczą trzy wersje
     * próbne zamiast pytania o każdą wersję listy (stała liczba zapytań).
     *
     * @return array{ukryj: bool, author: bool, moderator: bool}
     */
    private function uprawnieniaUkrycia(Request $request, Recipe $recipe): array
    {
        $widz = $request->user();
        if ($widz === null) {
            return ['ukryj' => false, RecipeVersion::UKRYL_AUTOR => false, RecipeVersion::UKRYLA_MODERACJA => false];
        }

        $proba = static fn (?string $kto): RecipeVersion => (new RecipeVersion)
            ->forceFill(['hidden_at' => $kto === null ? null : now(), 'hidden_by_role' => $kto])
            ->setRelation('recipe', $recipe);
        $gate = Gate::forUser($widz);

        return [
            'ukryj' => $gate->allows('hide', $proba(null)),
            RecipeVersion::UKRYL_AUTOR => $gate->allows('restore', $proba(RecipeVersion::UKRYL_AUTOR)),
            RecipeVersion::UKRYLA_MODERACJA => $gate->allows('restore', $proba(RecipeVersion::UKRYLA_MODERACJA)),
        ];
    }

    /**
     * @param  array{status: string, status_rodzaj: string}  $komunikat
     */
    private function doHistorii(Recipe $recipe, array $komunikat): RedirectResponse
    {
        return redirect()->route('recipes.history', $recipe->slug)->with($komunikat);
    }

    /**
     * Wgląd moderacji w wersję ukrytą — ta sama zasada 3.2 co
     * `moderation.hidden_post_viewed` (wpis przy OGLĄDANIU, nie tylko przy
     * zmianie). Autor oglądający własną wersję wpisu nie zostawia. Wpis
     * pomocniczy: awaria dziennika nie zamyka ekranu.
     */
    private function zapiszWgladModeracji(Request $request, Recipe $recipe, RecipeVersion $wersja): void
    {
        $widz = $request->user();
        if ($widz === null || ! $wersja->czyUkryta() || $widz->getKey() === $recipe->author_id) {
            return;
        }

        AuditLogEntry::recordBezWywracania('moderation.hidden_recipe_version_viewed', $widz, $wersja, [], $request->ip());
    }

    /**
     * @param  string  $trasa  nazwa trasy, na którą wraca 301 ze starego sluga
     */
    private function przepis(Request $request, string $slug, string $trasa, ?int $numer = null): Recipe
    {
        $model = Recipe::where('slug', $slug)->first();

        // Stary adres przepisu działa też na ekranach historii (jak w
        // `RecipeController::show`): 301 na aktualny slug, ta sama bramka
        // `view` i ten sam 404 zamiast 403 (nie zdradzamy, że przepis istnieje).
        if ($model === null) {
            $przekierowanie = DB::table('recipe_slug_redirects')->where('slug', $slug)->first();
            abort_if($przekierowanie === null, 404);

            $cel = Recipe::findOrFail($przekierowanie->recipe_id);
            abort_unless(Gate::forUser($request->user())->allows('view', $cel), 404);
            // Przepis nieopublikowany nie ma historii — także pod starym adresem.
            abort_unless(HistoriaWersji::opublikowanyPoAutoryzacji($cel), 404);

            throw new HttpResponseException(
                redirect()->route($trasa, $numer === null ? [$cel->slug] : [$cel->slug, $numer], 301),
            );
        }

        // 403 jak na stronie przepisu (`RecipeController::show`) ...
        $this->authorize('view', $model);
        // ... a przepis nieopublikowany (ukryty, zdjęty) nie ma historii.
        // `view` policzone wyżej — nie liczymy go drugi raz.
        abort_unless(HistoriaWersji::opublikowanyPoAutoryzacji($model), 404);

        return $model;
    }

    /**
     * Zapis (POST) idzie tylko pod aktualnym adresem: przekierowanie 301
     * zamieniłoby POST w GET i zgubiło akcję. Formularz zawsze bierze
     * aktualny slug z ekranu potwierdzenia.
     */
    private function przepisDoZapisu(Request $request, string $slug): Recipe
    {
        $model = Recipe::where('slug', $slug)->firstOrFail();
        $this->authorize('view', $model);
        abort_unless(HistoriaWersji::opublikowanyPoAutoryzacji($model), 404);

        return $model;
    }

    /**
     * Wersja do decyzji „ukryj/przywróć". Kto nie widzi ukrytych, dostaje pod
     * numerem ukrytej wersji 404 — jak pod numerem, którego nie ma — a nie
     * 403, które zdradzałoby, że taka wersja istnieje.
     */
    private function wersjaDoDecyzji(Request $request, Recipe $recipe, int $numer): RecipeVersion
    {
        return $this->wersja($recipe, $numer, HistoriaWersji::widziUkryte($request->user(), $recipe));
    }

    private function wersja(Recipe $recipe, int $numer, bool $zUkrytymi): RecipeVersion
    {
        $wersja = HistoriaWersji::zapytanie($recipe, $zUkrytymi)
            ->where('version_number', $numer)
            ->firstOrFail();

        // Policy potrzebuje przepisu — ten sam obiekt, bez drugiego zapytania.
        return $wersja->setRelation('recipe', $recipe);
    }

    /**
     * Numer sąsiedniej wersji: -1 = starsza, 1 = nowsza. `$numery` malejąco.
     *
     * @param  list<int>  $numery
     */
    private function sasiad(array $numery, int $numer, int $kierunek): ?int
    {
        $pozycja = array_search($numer, $numery, true);
        if ($pozycja === false) {
            return null;
        }

        // Malejąco: starsza to następny indeks, nowsza — poprzedni.
        return $numery[$pozycja - $kierunek] ?? null;
    }
}

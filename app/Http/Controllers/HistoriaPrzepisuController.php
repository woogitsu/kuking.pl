<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\PodstawaDecyzji;
use App\Domain\Recipes\Historia\DecyzjaOWersjiPrzepisu;
use App\Domain\Recipes\Historia\HistoriaWersji;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\Historia\PodgladPoprawkiZWersji;
use App\Domain\Recipes\Historia\PorownanieWersji;
use App\Domain\Recipes\Historia\UkrywanieWersji;
use App\Domain\Recipes\Historia\ZastosujWersjeJakoPoprawke;
use App\Exceptions\BladDlaCzlowieka;
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
 *
 * Ukrycie i przywrócenie PRZEZ MODERACJĘ to decyzja moderacyjna (DSA,
 * decyzja właściciela z 30.09.2026): formularz niesie podstawę
 * i uzasadnienie jak „Zdejmij z urzędu”, a zapis idzie przez
 * `DecyzjaOWersjiPrzepisu`. Autor ukrywa i przywraca swoje wersje bez tego.
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
                : PorownanieWersji::porownaj($poprzednia->snapshot ?? [], $wersja->snapshot ?? [], (bool) config('kuking.alergeny.wlaczone')),
            'nowszy' => $this->sasiad($numery, $numer, 1),
        ]);
    }

    /**
     * „Zastosuj jako nową poprawkę” (#2525) — ODCZYTOWY podgląd. Nic nie jest
     * zapisywane (ani wersja, ani przepis, ani autozapis): zapis dopiero po jawnym
     * POST-cie. Wejście: autor własnego, opublikowanego przepisu.
     */
    public function podgladPoprawki(Request $request, string $recipe, int $numer): View|RedirectResponse
    {
        $model = $this->przepis($request, $recipe, 'recipes.history.apply', $numer);
        $this->authorize('applyVersion', $model);

        $wersja = $this->wersja($model, $numer, false);

        if (HistoriaWersji::numerNajnowszej($model) === $numer) {
            return $this->doHistorii($model, Komunikat::informacja("Wersja {$numer} jest najnowszą — to jest dzisiejszy przepis, więc nie ma czego stosować."));
        }

        return view('pages.recipes.historia-poprawka', [
            'recipe' => $model,
            'wersja' => $wersja,
            'podglad' => PodgladPoprawkiZWersji::dla($model, $wersja),
            'rewizja' => $model->content_revision,
        ]);
    }

    public function zastosujPoprawke(Request $request, string $recipe, int $numer, ZastosujWersjeJakoPoprawke $zastosuj): RedirectResponse
    {
        $model = $this->przepisDoZapisu($request, $recipe);
        $this->authorize('applyVersion', $model);

        $dane = $request->validate([
            'sekcje' => ['required', 'array', 'min:1'],
            'sekcje.*' => ['string', 'in:'.implode(',', PodgladPoprawkiZWersji::SEKCJE)],
            'rewizja' => ['required', 'integer', 'min:0'],
        ], [
            'sekcje.required' => 'Zaznacz, co z tej wersji chcesz zastosować. Nic nie zostało zmienione.',
            'sekcje.min' => 'Zaznacz, co z tej wersji chcesz zastosować. Nic nie zostało zmienione.',
            'sekcje.*.in' => 'Zaznacz części z listy i spróbuj jeszcze raz.',
            'rewizja.*' => 'Otwórz podgląd jeszcze raz — brakuje informacji o stanie przepisu z ekranu podglądu.',
        ]);

        try {
            $wynik = $zastosuj->handle($request->user(), $model, $numer, $dane['sekcje'], (int) $dane['rewizja'], $request->ip());
        } catch (BladDlaCzlowieka $blad) {
            return redirect()->route('recipes.history.apply', [$model->slug, $numer])
                ->withInput()
                ->with(Komunikat::blad($blad->getMessage()));
        }

        if ($wynik['sekcje'] === []) {
            return $this->doHistorii($model, Komunikat::informacja('Po zastosowaniu treść przepisu byłaby taka sama jak dziś, więc nic nie zapisaliśmy.'));
        }

        $nowa = $wynik['wersja'];

        return redirect()->route('recipes.history', $model->slug)->with(Komunikat::sukces(
            'Zastosowane jako nowa poprawka'.($nowa !== null ? ' — powstała wersja '.$nowa->version_number : '')
            .'. Dotychczasowe wersje zostały bez zmian.',
        ));
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

        $strona = UkrywanieWersji::strona($request->user(), $model);
        $przejecie = $this->czyPrzejecie($wersja, $strona);

        if ($wersja->czyUkryta() && ! $przejecie) {
            return $this->doHistorii($model, Komunikat::informacja("Wersja {$numer} jest już ukryta."));
        }
        if (HistoriaWersji::numerNajnowszej($model) === $numer) {
            return $this->doHistorii($model, Komunikat::blad(self::KOMUNIKAT_NAJNOWSZA));
        }

        return view('pages.recipes.historia-ukryj', [
            'recipe' => $model,
            'wersja' => $wersja,
            'strona' => $strona,
            'przejecie' => $przejecie,
        ]);
    }

    public function ukryj(Request $request, string $recipe, int $numer, UkrywanieWersji $ukrywanie, DecyzjaOWersjiPrzepisu $decyzja): RedirectResponse
    {
        $model = $this->przepisDoZapisu($request, $recipe);
        $this->authorize('hide', $this->wersjaDoDecyzji($request, $model, $numer));

        $zModeracji = UkrywanieWersji::strona($request->user(), $model) === RecipeVersion::UKRYLA_MODERACJA;

        if ($zModeracji) {
            $data = $this->podstawaUkrycia($request, $model, $numer);
            $wynik = $decyzja->ukryj(
                moderator: $request->user(),
                recipe: $model,
                numer: $numer,
                reasonCode: $data['reason_code'],
                userMessage: $data['user_message'],
                note: $data['note'] ?? null,
                ip: $request->ip(),
            );
        } else {
            $wynik = $ukrywanie->ukryj($request->user(), $model, $numer, $request->ip());
        }

        return $this->doHistorii($model, match ($wynik) {
            UkrywanieWersji::UKRYTO => $zModeracji
                ? Komunikat::sukces("Wersja {$numer} jest ukryta. Autor dostał powiadomienie z podstawą i uzasadnieniem i może się odwołać. Przywrócić ją może moderacja.")
                : Komunikat::sukces("Wersja {$numer} jest ukryta. Widzisz ją tylko Ty i moderacja; w każdej chwili możesz ją przywrócić."),
            UkrywanieWersji::PRZEJETO => Komunikat::sukces("Przejęto ukrycie wersji {$numer}. Autor dostał powiadomienie z podstawą i uzasadnieniem i może się odwołać. Sam jej już nie przywróci — może to zrobić moderacja."),
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
            'strona' => UkrywanieWersji::strona($request->user(), $model),
        ]);
    }

    public function przywroc(Request $request, string $recipe, int $numer, UkrywanieWersji $ukrywanie, DecyzjaOWersjiPrzepisu $decyzja): RedirectResponse
    {
        $model = $this->przepisDoZapisu($request, $recipe);
        $this->authorize('restore', $this->wersjaDoDecyzji($request, $model, $numer));

        if (UkrywanieWersji::strona($request->user(), $model) === RecipeVersion::UKRYLA_MODERACJA) {
            $data = $request->validate([
                'reason_code' => ['required', 'string', 'max:80'],
                'user_message' => ['nullable', 'string', 'max:1500'],
            ], [
                'reason_code.required' => 'Podaj powód przywrócenia — w rejestrze decyzji musi zostać ślad, dlaczego zdjęto ukrycie.',
                'reason_code.max' => 'Powód jest za długi. Zmieść się w 80 znakach.',
                'user_message.max' => 'Wiadomość jest za długa. Zmieść się w 1500 znakach.',
            ]);

            try {
                $wynik = $decyzja->przywroc($request->user(), $model, $numer, $data['reason_code'], $data['user_message'] ?? null, $request->ip());
            } catch (BladDlaCzlowieka $blad) {
                return back()->withInput()->withErrors(['reason_code' => $blad->getMessage()]);
            }
        } else {
            $wynik = $ukrywanie->przywroc($request->user(), $model, $numer, $request->ip());
        }

        return $this->doHistorii($model, match ($wynik) {
            UkrywanieWersji::PRZYWROCONO => Komunikat::sukces("Wersja {$numer} jest znowu widoczna dla każdego, kto widzi przepis."),
            UkrywanieWersji::NIE_BYLA_UKRYTA => Komunikat::informacja("Wersja {$numer} nie jest ukryta — widzi ją każdy, kto widzi przepis."),
            UkrywanieWersji::UKRYTA_PRZEZ_DRUGA_STRONE => Komunikat::blad("Wersji {$numer} nie przywrócisz stąd: w międzyczasie ukryła ją druga strona. Odśwież historię zmian."),
            default => Komunikat::blad('Tej wersji już nie ma. Odśwież historię zmian.'),
        });
    }

    /**
     * Podstawa decyzji moderacji — te same pola i komunikaty co „Zdejmij
     * z urzędu” (`ZUrzeduController::store`). Uzasadnienie jest krótsze
     * o zdanie wskazujące wersję (`DecyzjaOWersjiPrzepisu::wskazanie`), żeby
     * całość zmieściła się w `moderation_actions.user_message` (2000).
     *
     * @return array{reason_code: string, user_message: string, note?: ?string}
     */
    private function podstawaUkrycia(Request $request, Recipe $recipe, int $numer): array
    {
        $limit = 2000 - mb_strlen(DecyzjaOWersjiPrzepisu::wskazanie($recipe, $numer)) - 1;

        /** @var array{reason_code: string, user_message: string, note?: ?string} */
        return $request->validate([
            'reason_code' => ['required', 'string', 'in:'.implode(',', array_keys(PodstawaDecyzji::PODSTAWY))],
            'user_message' => ['required', 'string', 'min:10', 'max:'.$limit],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'reason_code.required' => 'Wybierz podstawę decyzji — autor przepisu zobaczy ją w powiadomieniu.',
            'reason_code.in' => 'Wybierz podstawę decyzji z listy.',
            'user_message.required' => 'Napisz autorowi, dlaczego ukrywasz tę wersję. Bez tego nie ma od czego się odwołać.',
            'user_message.min' => 'Uzasadnienie jest za krótkie. Napisz jednym, dwoma zdaniami, co konkretnie w tej wersji narusza zasady.',
            'user_message.max' => "Uzasadnienie jest za długie. Zmieść się w {$limit} znakach.",
            'note.max' => 'Notatka jest za długa. Zmieść się w 2000 znakach.',
        ]);
    }

    /**
     * Które przyciski ukrycia pokazać — liczone RAZ na stronę, tą samą
     * `RecipeVersionPolicy`, która pilnuje zapisu. Odpowiedź zależy tylko od
     * przepisu, widza i tego, kto wersję ukrył, więc wystarczą trzy wersje
     * próbne zamiast pytania o każdą wersję listy (stała liczba zapytań).
     *
     * `przejmij` = moderacja widząca wersję ukrytą przez autora: ta sama
     * reguła `hide`, tylko strona widza to moderacja (nie autor-moderator).
     *
     * @return array{ukryj: bool, przejmij: bool, author: bool, moderator: bool}
     */
    private function uprawnieniaUkrycia(Request $request, Recipe $recipe): array
    {
        $widz = $request->user();
        if ($widz === null) {
            return ['ukryj' => false, 'przejmij' => false, RecipeVersion::UKRYL_AUTOR => false, RecipeVersion::UKRYLA_MODERACJA => false];
        }

        $proba = static fn (?string $kto): RecipeVersion => (new RecipeVersion)
            ->forceFill(['hidden_at' => $kto === null ? null : now(), 'hidden_by_role' => $kto])
            ->setRelation('recipe', $recipe);
        $gate = Gate::forUser($widz);

        $ukryj = $gate->allows('hide', $proba(null));

        return [
            'ukryj' => $ukryj,
            'przejmij' => $ukryj && UkrywanieWersji::strona($widz, $recipe) === RecipeVersion::UKRYLA_MODERACJA,
            RecipeVersion::UKRYL_AUTOR => $gate->allows('restore', $proba(RecipeVersion::UKRYL_AUTOR)),
            RecipeVersion::UKRYLA_MODERACJA => $gate->allows('restore', $proba(RecipeVersion::UKRYLA_MODERACJA)),
        ];
    }

    /** Wersja ukryta przez autora, a decyzję podejmuje moderacja: „Przejmij ukrycie”. */
    private function czyPrzejecie(RecipeVersion $wersja, string $strona): bool
    {
        return $wersja->czyUkryta()
            && $wersja->hidden_by_role === RecipeVersion::UKRYL_AUTOR
            && $strona === RecipeVersion::UKRYLA_MODERACJA;
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

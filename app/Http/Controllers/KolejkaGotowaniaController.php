<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Moderation\DziennikWgladu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Kolejka kilku przepisów z niezależnymi minutnikami (#2379).
 *
 * DECYZJA WŁAŚCICIELA (1 października 2026, D-333): kolejka żyje TYLKO
 * w przeglądarce (`localStorage`), bez migracji i bez konta, zgodnie z #2016
 * („minutniki lokalne”). Limit to 4 przepisy, kolejka wygasa po 24 h od
 * ostatniej zmiany, bez AI.
 *
 * DLACZEGO SERWER W OGÓLE WIE, CO JEST W KOLEJCE
 * Serwer nie przechowuje kolejki — dostaje ją w adresie
 * (`?p=zupa:2,schabowy:1&a=zupa`, slug i numer kroku). Dzięki temu ekran jest
 * zwykłą stroną z linkami (D-053): przełącznik potraw, „Następny krok”,
 * przesuwanie i usuwanie to odnośniki, które działają bez JavaScriptu.
 * Skrypt dokłada tylko to, czego bez niego zrobić się nie da: pamięta
 * kolejkę w przeglądarce, odtwarza adres po powrocie na `/gotuj-kilka`
 * i odlicza minutniki.
 *
 * AUTORYZACJA: UUID/slug w adresie to nie autoryzacja (AGENTS.md §7). Każdy
 * przepis przechodzi `RecipePolicy::view` — tę samą, co strona przepisu
 * i tryb gotowania. Przepis, którego widz już nie widzi (usunięty, ukryty,
 * wrócił do szkicu, konto zbanowane), wypada z kolejki z komunikatem;
 * przepis ze zmienionym adresem (`recipe_slug_redirects`) zostaje pod nowym slugiem;
 * treść przepisu nigdy nie jest kopiowana do kolejki, w przeglądarce
 * jest tylko slug i numer kroku.
 *
 * PODGLĄD SKŁADNIKÓW (#2469): relacja `ingredients` jest ładowana jednym
 * zapytaniem dla całej kolejki (bez zapytania na kartę), a widok rysuje ją
 * wyłącznie dla przepisu, który przeszedł `RecipePolicy::view` i jest aktywny.
 * Składniki nigdy nie trafiają do `data-kolejka-dane` ani do przeglądarki.
 */
class KolejkaGotowaniaController extends Controller
{
    /** Ile przepisów mieści kolejka (decyzja właściciela z 1.10.2026). */
    public const LIMIT = 4;

    private const WZORZEC_SLUGA = '/^[a-z0-9][a-z0-9-]{0,219}$/';

    public function show(Request $request): View
    {
        $zadane = $this->wczytajPozycje($request->query('p'));
        $przekroczonoLimit = count($zadane) > self::LIMIT;
        $zadane = array_slice($zadane, 0, self::LIMIT, true);

        $osoba = $request->user();
        $przepisy = Recipe::query()->whereIn('slug', array_keys($zadane))->with(['steps', 'author', 'ingredients'])->get()->keyBy('slug');

        // Przepis, który zmienił adres, zostaje w kolejce pod nowym slugiem
        // (z tym samym krokiem). Stary slug rozwiązujemy dopiero po `view`:
        // przepis niedostępny wypada jak każdy inny, bez śladu, że istniał.
        [$zadane, $przepisy, $zmienione] = $this->rozwiazStareAdresy($zadane, $przepisy, $osoba);

        /** @var list<array{recipe: Recipe, krok: int, total: int}> $pozycje */
        $pozycje = [];
        $niedostepne = 0;
        $bezKrokow = 0;

        foreach ($zadane as $slug => $krok) {
            $przepis = $przepisy->get($slug);

            // Brak przepisu i brak prawa do niego mówią to samo: „nie ma go
            // dla Ciebie” — nie zdradzamy, który z tych dwóch przypadków zaszedł.
            if ($przepis === null || ! Gate::forUser($osoba)->allows('view', $przepis)) {
                $niedostepne++;

                continue;
            }

            if ($przepis->steps->isEmpty()) {
                $bezKrokow++;

                continue;
            }

            // Wgląd z urzędu w przepis niewidoczny bez roli — jak w trybie
            // gotowania (D-333, `DziennikWgladu::przepis()`).
            app(DziennikWgladu::class)->przepis($przepis, $osoba, $request->ip());

            $total = $przepis->steps->count();
            $pozycje[] = ['recipe' => $przepis, 'krok' => max(1, min($krok, $total)), 'total' => $total];
        }

        $slugi = array_map(fn (array $p): string => $p['recipe']->slug, $pozycje);

        // Slugi z adresu, które na ekranie się nie znalazły (niedostępne, bez
        // kroków) albo zostały zamienione na nowy adres: skrypt wie dzięki
        // temu, że ich brak w kolejce jest zamierzony, a nie to samo co
        // nieaktualny adres z innej karty (kolejka z przeglądarki ma więcej).
        $pominiete = array_values(array_unique([
            ...array_values(array_diff(array_keys($zadane), $slugi)),
            ...array_keys($zmienione),
        ]));
        $usuniete = (string) $request->query('u', '');
        $usuniete = preg_match(self::WZORZEC_SLUGA, $usuniete) === 1 ? $usuniete : '';
        $aktywnySlug = (string) $request->query('a', '');
        $aktywnySlug = $zmienione[$aktywnySlug] ?? $aktywnySlug;
        $aktywnySlug = in_array($aktywnySlug, $slugi, true) ? $aktywnySlug : ($slugi[0] ?? null);

        return view('pages.recipes.kolejka-gotowania', [
            'pozycje' => $pozycje,
            'aktywnySlug' => $aktywnySlug,
            'niedostepne' => $niedostepne,
            'bezKrokow' => $bezKrokow,
            'przekroczonoLimit' => $przekroczonoLimit,
            'zAdresu' => $request->query->has('p'),
            'wygasla' => $request->boolean('wygasla'),
            'limit' => self::LIMIT,
            'pominiete' => $pominiete,
            'usuniete' => $usuniete,
        ]);
    }

    /**
     * Zamienia w zadanej kolejce stare slugi na aktualne. Zwraca nową kolejkę
     * (kolejność i kroki z adresu, powtórzenia po rozwiązaniu liczą się raz),
     * przepisy po aktualnym slugu i mapę stary → nowy slug.
     *
     * @param  array<string, int>  $zadane
     * @param  Collection<string, Recipe>  $przepisy
     * @return array{0: array<string, int>, 1: Collection<string, Recipe>, 2: array<string, string>}
     */
    private function rozwiazStareAdresy(array $zadane, Collection $przepisy, ?User $osoba): array
    {
        $nieznane = array_values(array_filter(array_keys($zadane), fn (string $slug): bool => ! $przepisy->has($slug)));

        if ($nieznane === []) {
            return [$zadane, $przepisy, []];
        }

        $przekierowania = DB::table('recipe_slug_redirects')->whereIn('slug', $nieznane)->pluck('recipe_id', 'slug');
        $cele = Recipe::query()->whereIn('id', $przekierowania->unique()->all())->with(['steps', 'author', 'ingredients'])->get()->keyBy('id');

        $wynik = [];
        $zmienione = [];

        foreach ($zadane as $slug => $krok) {
            $cel = $przepisy->has($slug) ? null : $cele->get($przekierowania->get($slug));

            if ($cel !== null && Gate::forUser($osoba)->allows('view', $cel)) {
                $zmienione[$slug] = $cel->slug;
                $przepisy->put($cel->slug, $cel);
                $slug = $cel->slug;
            }

            // Pierwsze wystąpienie wygrywa (jak przy powtórzonym slugu w adresie).
            $wynik[$slug] ??= $krok;
        }

        return [$wynik, $przepisy, $zmienione];
    }

    /**
     * `zupa:2,schabowy` → ['zupa' => 2, 'schabowy' => 1]. Kolejność z adresu
     * zostaje, powtórzony slug liczy się raz, śmieci są pomijane po cichu —
     * adres pisze skrypt, ale ktoś może go przerobić ręcznie.
     *
     * @return array<string, int>
     */
    private function wczytajPozycje(mixed $surowe): array
    {
        if (! is_string($surowe) || $surowe === '') {
            return [];
        }

        $wynik = [];

        foreach (explode(',', $surowe) as $element) {
            [$slug, $krok] = array_pad(explode(':', $element, 2), 2, '1');

            if (preg_match(self::WZORZEC_SLUGA, $slug) !== 1 || isset($wynik[$slug])) {
                continue;
            }

            $wynik[$slug] = ctype_digit($krok) && strlen($krok) <= 4 ? max(1, (int) $krok) : 1;
        }

        return $wynik;
    }
}

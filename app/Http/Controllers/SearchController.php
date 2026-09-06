<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Search\SearchQuery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wyszukiwanie. Parametr nazywa się `q`, ale zakładka po polsku: `sekcja`.
 *
 * Strona /search nigdy nie jest indeksowana (noindex) — to nie jest treść,
 * a niekończące się kombinacje zapytań to klasyczna pułapka SEO.
 */
class SearchController extends Controller
{
    /** Ile wyników pokazujemy na raz. */
    private const NA_STRONIE = 20;

    /**
     * Górna granica dla `?ile=`. Bez niej ktoś wpisze `?ile=1000000`
     * i zamieni wyszukiwarkę w narzędzie do obciążania bazy.
     */
    private const MAKS = 200;

    public function __construct(private readonly SearchQuery $search) {}

    public function index(Request $request): View
    {
        $phrase = trim((string) $request->query('q', ''));
        $section = $request->query('sekcja') === 'ludzie' ? 'ludzie' : 'przepisy';

        // ILE WYNIKÓW, I SKĄD SIĘ BIERZE „POKAŻ WIĘCEJ"
        //
        // Ekran mówił „Znaleziono 20 przepisów", licząc POBRANE, a nie
        // ZNALEZIONE. Przy dwustu dopasowaniach było to zdanie nieprawdziwe —
        // i to takie, na podstawie którego człowiek podejmuje decyzję
        // („nie ma tego, czego szukam, dodam własny"). Do tego nie było
        // żadnej drogi do dalszych wyników.
        //
        // Pobieramy o JEDEN więcej, niż pokazujemy. To wystarcza, żeby
        // uczciwie powiedzieć „jest ich więcej", i nie kosztuje drugiego
        // zapytania liczącego (`COUNT`) — a przy sortowaniu po podobieństwie
        // kursor z feedu tu nie zadziała.
        $ile = min(max((int) $request->query('ile', (string) self::NA_STRONIE), self::NA_STRONIE), self::MAKS);

        $przepisy = $section === 'przepisy'
            // Widz przekazywany po to, żeby wyszukiwarka respektowała blokady
            // (issue #41). Bez niego blokada kończyła się na widoku i liście.
            ? $this->search->recipes($phrase, $request->user(), $ile + 1)
            : collect();

        $jestWiecej = $przepisy->count() > $ile;

        return view('pages.search', [
            'phrase' => $phrase,
            'section' => $section,
            'recipes' => $przepisy->take($ile),
            'jestWiecej' => $jestWiecej,
            'nastepneIle' => min($ile + self::NA_STRONIE, self::MAKS),
            'people' => $section === 'ludzie' ? $this->search->people($phrase, $request->user()) : collect(),
        ]);
    }
}

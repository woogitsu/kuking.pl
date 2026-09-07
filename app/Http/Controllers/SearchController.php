<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\ZapiszSygnal;
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

    public function __construct(
        private readonly SearchQuery $search,
        private readonly ZapiszSygnal $sygnaly = new ZapiszSygnal,
    ) {}

    public function index(Request $request): View
    {
        $phrase = trim((string) $request->query('q', ''));

        // ZAKRESY WEDŁUG KITU (ekran 03): Wszystko / Przepisy / Ludzie / Do 30 minut.
        //
        // Domyślnie „wszystko" — człowiek, który wpisał „pierogi", nie wie
        // jeszcze, czy szuka przepisu, czy osoby, która je robi. Wymuszanie
        // tego wyboru PRZED wynikami to pytanie zadane za wcześnie.
        //
        // Czego tu NIE MA: chipa „Składniki" z makiety. Wyszukiwarka i tak
        // przeszukuje składniki wewnątrz przepisów (`recipe_ingredients`
        // w SearchQuery), więc osobny zakres sugerowałby, że gdzie indziej
        // ich nie szuka — a to nieprawda.
        $section = match ($request->query('sekcja')) {
            'ludzie' => 'ludzie',
            'przepisy' => 'przepisy',
            'szybkie' => 'szybkie',
            default => 'wszystko',
        };

        // „Do 30 minut" to zakres przepisów z dodatkowym warunkiem, nie
        // osobny rodzaj treści.
        $maksMinut = $section === 'szybkie' ? 30 : null;
        $szukaPrzepisow = in_array($section, ['wszystko', 'przepisy', 'szybkie'], true);
        $szukaLudzi = in_array($section, ['wszystko', 'ludzie'], true);

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

        // „ZA KRÓTKA" TO NIE „BEZ WYNIKÓW"
        //
        // SearchQuery::recipes()/people() pomija frazy krótsze niż 2 znaki —
        // nie szuka wcale, tylko od razu zwraca pustą kolekcję. Pokazanie
        // wtedy ekranu „Nic nie znaleźliśmy" mówiłoby: przeszukaliśmy bazę
        // i nie ma tam nic pasującego do „a" — a to nieprawda, bo baza w ogóle
        // nie została odpytana. To dokładnie ta sama klasa nieuczciwości co
        // „Znaleziono 20 przepisów" liczone z POBRANYCH wyżej w tym pliku.
        //
        // Próg 2 MUSI się zgadzać z SearchQuery — jeśli go tam zmienisz,
        // zmień i tutaj.
        $zaKrotka = $phrase !== '' && mb_strlen($phrase) < 2;

        $przepisy = $szukaPrzepisow
            // Widz przekazywany po to, żeby wyszukiwarka respektowała blokady
            // (issue #41). Bez niego blokada kończyła się na widoku i liście.
            ? $this->search->recipes($phrase, $request->user(), $ile + 1, $maksMinut)
            : collect();

        // Zakładka „Ludzie" liczy się DOKŁADNIE TAK SAMO, a nie „przy okazji".
        //
        // Wcześniej dostawała sztywne dwadzieścia wyników bez żadnej drogi
        // dalej. Nie kłamała wprost (nie było licznika), ale kończyła się
        // w miejscu, którego nie dało się rozpoznać: przy dwudziestu jeden
        // Basiach dwudziesta pierwsza po prostu nie istniała dla szukającego.
        $ludzie = $szukaLudzi
            ? $this->search->people($phrase, $request->user(), $ile + 1)
            : collect();

        // SYGNAŁ `search_performed` (issue #115) — PO POLICZENIU WYNIKÓW,
        // NIE PRZED. `query_text` NIGDY nie trafia do właściwości: fraza
        // wyszukiwania jest tekstem wpisanym przez człowieka, tej samej
        // natury co treść komentarza (AGENTS.md §7 — żadnych PII w danych
        // analitycznych), a `product_signals` ma nawet CHECK w bazie, który
        // odrzuci wiersz, gdyby ten kod kiedyś zaczął ją tam wysyłać. Zamiast
        // niej idzie wyłącznie DŁUGOŚĆ frazy i to, czy dała wynik.
        $this->sygnaly->handle($request->user(), ZapiszSygnal::SEARCH_PERFORMED, [
            'query_length' => mb_strlen($phrase),
            'has_results' => ($przepisy->count() + $ludzie->count()) > 0,
        ]);

        return view('pages.search', [
            'phrase' => $phrase,
            'section' => $section,
            'zaKrotka' => $zaKrotka,
            'szukaPrzepisow' => $szukaPrzepisow,
            'szukaLudzi' => $szukaLudzi,
            'recipes' => $przepisy->take($ile),
            'people' => $ludzie->take($ile),
            'jestWiecej' => $przepisy->count() > $ile,
            'jestWiecejOsob' => $ludzie->count() > $ile,
            'nastepneIle' => min($ile + self::NA_STRONIE, self::MAKS),
        ]);
    }
}

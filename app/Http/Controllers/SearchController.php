<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Feed\DailyBoard;
use App\Domain\Search\SearchQuery;
use App\Models\Tag;
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
        // Etap D kitu v2 (ekran 03) — prawa szyna obok wyników. Kit rysuje
        // tam podpowiedzi sezonowych tagów z liczbą przepisów i listę osób
        // z liczbą obserwujących; obu świadomie tu NIE MA (raport etapu D
        // tłumaczy dlaczego — liczba obserwujących jest anty-wzorcem
        // wprost zakazanym w AGENTS.md, a „sezonowość tagów" to funkcja,
        // której DECISIONS.md świadomie jeszcze nie zbudował).
        //
        // Zamiast tego szyna pokazuje TĘ SAMĄ tablicę „kuKINGi na dziś",
        // której już używają `/home` (prawa szyna) i `/odkryj` (główna
        // treść) — ten sam cel („coś do zobaczenia, kogoś do obserwowania"),
        // zero nowej logiki domenowej, zero nowych liczników.
        private readonly DailyBoard $dailyBoard,
        private readonly ZapiszSygnal $sygnaly = new ZapiszSygnal,
    ) {}

    public function index(Request $request): View
    {
        $phrase = trim((string) $request->query('q', ''));
        // GET renderuje błąd w miejscu, bez przekierowania na ten sam długi URL.
        $searchErrors = SearchQuery::phraseValidator($phrase)->errors();

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
        $odPrzepisu = $szukaPrzepisow ? $this->offset($request, 'od_przepisu') : 0;
        $odOsoby = $szukaLudzi ? $this->offset($request, 'od_osoby') : 0;
        $parametry = ['q' => $phrase, 'sekcja' => $section, 'ile' => $ile,
            'od_przepisu' => $odPrzepisu, 'od_osoby' => $odOsoby];
        $nastepnePrzepisy = $parametry;
        $nastepneOsoby = $parametry;
        if ($ile < self::MAKS) {
            $nastepnePrzepisy['ile'] = $nastepneOsoby['ile'] = min($ile + self::NA_STRONIE, self::MAKS);
        } else {
            $nastepnePrzepisy['od_przepisu'] += $ile;
            $nastepneOsoby['od_osoby'] += $ile;
        }

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
        $phraseForLength = $section === 'ludzie' ? SearchQuery::peoplePhrase($phrase) : $phrase;
        $zaKrotka = $phrase !== '' && mb_strlen($phraseForLength) < 2;

        $przepisy = $szukaPrzepisow && $searchErrors->isEmpty()
            // Widz przekazywany po to, żeby wyszukiwarka respektowała blokady
            // (issue #41). Bez niego blokada kończyła się na widoku i liście.
            ? $this->search->recipes($phrase, $request->user(), $ile + 1, $maksMinut, $odPrzepisu)
            : collect();

        // Zakładka „Ludzie" liczy się DOKŁADNIE TAK SAMO, a nie „przy okazji".
        //
        // Wcześniej dostawała sztywne dwadzieścia wyników bez żadnej drogi
        // dalej. Nie kłamała wprost (nie było licznika), ale kończyła się
        // w miejscu, którego nie dało się rozpoznać: przy dwudziestu jeden
        // Basiach dwudziesta pierwsza po prostu nie istniała dla szukającego.
        $ludzie = $szukaLudzi && $searchErrors->isEmpty()
            ? $this->search->people($phrase, $request->user(), $ile + 1, $odOsoby)
            : collect();

        // SYGNAŁ `search_performed` (issue #115) — PO POLICZENIU WYNIKÓW,
        // NIE PRZED. `query_text` NIGDY nie trafia do właściwości: fraza
        // wyszukiwania jest tekstem wpisanym przez człowieka, tej samej
        // natury co treść komentarza (AGENTS.md §7 — żadnych PII w danych
        // analitycznych), a `product_signals` ma nawet CHECK w bazie, który
        // odrzuci wiersz, gdyby ten kod kiedyś zaczął ją tam wysyłać. Zamiast
        // niej idzie wyłącznie DŁUGOŚĆ frazy i to, czy dała wynik.
        if ($searchErrors->isEmpty()) {
            $this->sygnaly->handle($request->user(), ZapiszSygnal::SEARCH_PERFORMED, [
                'query_length' => mb_strlen($phrase),
                'has_results' => ($przepisy->count() + $ludzie->count()) > 0,
            ]);
        }

        return view('pages.search', [
            'board' => $this->dailyBoard->forViewer($request->user()),
            'phrase' => $phrase,
            'searchErrors' => $searchErrors,
            'promowaneTagi' => $phrase === '' ? Tag::promowane()->get() : collect(),
            'section' => $section,
            'zaKrotka' => $zaKrotka,
            'szukaPrzepisow' => $szukaPrzepisow,
            'szukaLudzi' => $szukaLudzi,
            'recipes' => $przepisy->take($ile),
            'people' => $ludzie->take($ile),
            'jestWiecej' => $przepisy->count() > $ile,
            'jestWiecejOsob' => $ludzie->count() > $ile,
            'nastepneIle' => min($ile + self::NA_STRONIE, self::MAKS),
            'odPrzepisu' => $odPrzepisu,
            'odOsoby' => $odOsoby,
            'nastepnePrzepisy' => $nastepnePrzepisy,
            'nastepneOsoby' => $nastepneOsoby,
            'poczatekPrzepisow' => array_replace($parametry, ['od_przepisu' => 0]),
            'poczatekOsob' => array_replace($parametry, ['od_osoby' => 0]),
        ]);
    }

    private function offset(Request $request, string $key): int
    {
        $value = filter_var($request->query($key, 0), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX - self::MAKS],
        ]);

        return $value === false ? 0 : $value;
    }
}

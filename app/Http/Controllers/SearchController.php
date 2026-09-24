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
        // `q` MUSI być tekstem, zanim cokolwiek go rzutuje (issue #738).
        // `/szukaj?q[]=...` daje tablicę — bez tej straży `(string) $tablica`
        // wywala ostrzeżenie „Array to string conversion", które w tym
        // repo staje się wyjątkiem (błędy → wyjątki) i kończy się 500 na
        // publicznym, niezalogowanym endpoincie zamiast zwykłego pustego
        // ekranu wyszukiwania. Nie-tekstowe `q` jest więc traktowane
        // dokładnie tak samo jak brak `q`.
        $qSurowe = $request->query('q', '');
        $phrase = trim(is_string($qSurowe) ? $qSurowe : '');
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
        // DALSZE OKNO ZACZYNA SIĘ ZA OSTATNIM POKAZANYM REKORDEM, NIE ZA NUMEREM
        // (issue #1023). `od_*` zostaje do numeracji („przepisy 201–400")
        // i drogi powrotu; o tym, CO jest w oknie, decyduje kursor `po_*`
        // — klucz rankingu ostatniego rekordu poprzedniego okna. Uzasadnienie
        // w SearchQuery przy KURSOR_PRZEPISU. Kursor bez `od_*` nie ma sensu
        // (okno bez numeru i bez powrotu), więc wtedy jest ignorowany.
        $poPrzepisie = $odPrzepisu > 0 ? $this->kursor($request, 'po_przepisie') : null;
        $poOsobie = $odOsoby > 0 ? $this->kursor($request, 'po_osobie') : null;
        $parametry = array_filter(['q' => $phrase, 'sekcja' => $section, 'ile' => $ile,
            'od_przepisu' => $odPrzepisu, 'od_osoby' => $odOsoby,
            'po_przepisie' => $poPrzepisie, 'po_osobie' => $poOsobie], fn ($v) => $v !== null);

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
        //
        // Próg liczy się PO normalizacji (issue #1050) — tą samą
        // SearchQuery::doSzukania(), której używa domena. „🍲🍲" ma dwa znaki,
        // ale po transliteracji nic z niej nie zostaje: to nie jest wyszukiwanie
        // bez wyników, tylko fraza bez treści — osobny komunikat `$bezTresci`.
        $phraseForLength = $section === 'ludzie' ? SearchQuery::peoplePhrase($phrase) : $phrase;
        $bezTresci = SearchQuery::bezTresci($phraseForLength);
        $zaKrotka = $phrase !== '' && ! $bezTresci && SearchQuery::doSzukania($phraseForLength) === null;

        $przepisy = $szukaPrzepisow && $searchErrors->isEmpty()
            // Widz przekazywany po to, żeby wyszukiwarka respektowała blokady
            // (issue #41). Bez niego blokada kończyła się na widoku i liście.
            ? $this->search->recipes($phrase, $request->user(), $ile + 1, $maksMinut, $odPrzepisu, $poPrzepisie)
            : collect();

        // Zakładka „Ludzie" liczy się DOKŁADNIE TAK SAMO, a nie „przy okazji".
        //
        // Wcześniej dostawała sztywne dwadzieścia wyników bez żadnej drogi
        // dalej. Nie kłamała wprost (nie było licznika), ale kończyła się
        // w miejscu, którego nie dało się rozpoznać: przy dwudziestu jeden
        // Basiach dwudziesta pierwsza po prostu nie istniała dla szukającego.
        $ludzie = $szukaLudzi && $searchErrors->isEmpty()
            ? $this->search->people($phrase, $request->user(), $ile + 1, $odOsoby, $poOsobie)
            : collect();

        $nastepnePrzepisy = $parametry;
        $nastepneOsoby = $parametry;
        if ($ile < self::MAKS) {
            // Rosnąca lista od tego samego początku (tego samego kursora,
            // jeśli jest) — każde kliknięcie rysuje ją od nowa w całości.
            $nastepnePrzepisy['ile'] = $nastepneOsoby['ile'] = min($ile + self::NA_STRONIE, self::MAKS);
        } else {
            $nastepnePrzepisy['od_przepisu'] += $ile;
            $nastepneOsoby['od_osoby'] += $ile;
            if ($przepisy->count() > $ile) {
                $nastepnePrzepisy['po_przepisie'] = SearchQuery::kursorPrzepisu($przepisy[$ile - 1]);
            }
            if ($ludzie->count() > $ile) {
                $nastepneOsoby['po_osobie'] = SearchQuery::kursorOsoby($ludzie[$ile - 1]);
            }
        }

        // SYGNAŁ `search_performed` (issue #115) — PO POLICZENIU WYNIKÓW,
        // NIE PRZED. `query_text` NIGDY nie trafia do właściwości: fraza
        // wyszukiwania jest tekstem wpisanym przez człowieka, tej samej
        // natury co treść komentarza (AGENTS.md §7 — żadnych PII w danych
        // analitycznych), a `product_signals` ma nawet CHECK w bazie, który
        // odrzuci wiersz, gdyby ten kod kiedyś zaczął ją tam wysyłać. Zamiast
        // niej idzie wyłącznie DŁUGOŚĆ frazy i to, czy dała wynik.
        //
        // ZAPISUJEMY WYŁĄCZNIE, GDY FRAZA NAPRAWDĘ SZUKAŁA (issue #737).
        // Pusty ekran „Szukaj" (brak `q`) i fraza krótsza niż dwa znaki nie
        // odpytują bazy w ogóle — `SearchQuery::recipes()`/`::people()`
        // zwracają pustą kolekcję PRZED zapytaniem (ten sam próg co
        // `$zaKrotka` wyżej). Zapisanie tu sygnału policzyłoby otwarcie
        // pustego ekranu i „a" jako wyszukiwanie bez wyników, mimo że baza
        // w ogóle nie została odpytana — zatruwając miarę `has_results=false`.
        //
        // Fraza odrzucona przez `phraseValidator()` (za długa) też nie
        // odpytała bazy — `$przepisy`/`$ludzie` wyżej są wtedy puste — więc
        // z tego samego powodu nie ma czego zapisywać.
        if ($phrase !== '' && ! $zaKrotka && ! $bezTresci && $searchErrors->isEmpty()) {
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
            'bezTresci' => $bezTresci,
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
            'poczatekPrzepisow' => array_diff_key(array_replace($parametry, ['od_przepisu' => 0]), ['po_przepisie' => true]),
            'poczatekOsob' => array_diff_key(array_replace($parametry, ['od_osoby' => 0]), ['po_osobie' => true]),
        ]);
    }

    private function offset(Request $request, string $key): int
    {
        $value = filter_var($request->query($key, 0), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX - self::MAKS],
        ]);

        return $value === false ? 0 : $value;
    }

    /** Surowy tekst kursora; format sprawdza SearchQuery, zły kursor = zwykły offset. */
    private function kursor(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && strlen($value) <= 100 ? $value : null;
    }
}

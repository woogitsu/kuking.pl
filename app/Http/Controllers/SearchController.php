<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Feed\DailyBoard;
use App\Domain\Recipes\Alergeny\Alergen;
use App\Domain\Recipes\KosztPrzepisu;
use App\Domain\Search\SearchQuery;
use App\Http\Middleware\ParametryAdresuBezTablic;
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

    /**
     * Progi czasu całkowitego w minutach (`?czas=`, issue #1997). Brak
     * parametru = bez limitu. Czas liczy `Recipe::scopeGotoweWCiagu()` —
     * przepis bez podanego czasu nie trafia do żadnego progu.
     */
    public const PROGI_CZASU = [15, 30, 60];

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

        // ZAKRESY WEDŁUG KITU (ekran 03): Wszystko / Przepisy / Ludzie / Do 20 zł;
        // czas przygotowania jest osobnym wierszem progów (#1997, niżej).
        //
        // Domyślnie „wszystko" — człowiek, który wpisał „pierogi", nie wie
        // jeszcze, czy szuka przepisu, czy osoby, która je robi. Wymuszanie
        // tego wyboru PRZED wynikami to pytanie zadane za wcześnie.
        //
        // Czego tu NIE MA: chipa „Składniki" z makiety. Wyszukiwarka i tak
        // przeszukuje składniki wewnątrz przepisów (`recipe_ingredients`
        // w SearchQuery), więc osobny zakres sugerowałby, że gdzie indziej
        // ich nie szuka — a to nieprawda.
        $sekcjaSurowa = $request->query('sekcja');
        $section = match ($sekcjaSurowa) {
            'ludzie' => 'ludzie',
            // Stary zakres „Do 30 minut" (sprzed #1997) to dziś „Przepisy"
            // z `czas=30`. Adres zostaje ważny: linki w zakładkach, wysłane
            // komuś i propozycje pilota AI (`sekcja=szybkie`) dalej działają.
            'przepisy', 'szybkie' => 'przepisy',
            'tanie' => 'tanie',
            default => 'wszystko',
        };

        // CZAS PRZYGOTOWANIA (issue #1997): jawne progi zamiast jednego chipa.
        //
        // Czas to warunek NA PRZEPISY, nie osobny rodzaj treści — dlatego
        // stoi obok zakresu, a nie w nim. Wybrany próg zawęża „Przepisy"
        // i „Do 20 zł"; „Wszystko" z progiem staje się „Przepisy", bo
        // ludzie nie mają czasu przygotowania, a pokazanie ich obok
        // przefiltrowanych przepisów mówiłoby, że filtr ich też dotyczy.
        // Na „Ludziach" próg nie ma czego zawężać i jest pomijany.
        //
        // Nieznana wartość (`czas=45`, `czas=abc`, `czas[]=…`) NIE filtruje
        // po cichu najbliższym progiem ani nie daje 500: wyniki są bez
        // limitu, a ekran mówi to wprost (`$czasNieznany`).
        $czasSurowy = $request->query('czas');
        $maksMinut = is_string($czasSurowy) && in_array($czasSurowy, array_map('strval', self::PROGI_CZASU), true)
            ? (int) $czasSurowy
            : null;
        if ($maksMinut === null && $sekcjaSurowa === 'szybkie') {
            $maksMinut = 30;
        }
        // `czas[]=` usuwa z adresu `ParametryAdresuBezTablic` — ekran i tak
        // mówi, że wartości w adresie nie rozpoznał.
        $czasNieznany = $maksMinut === null
            && (($czasSurowy !== null && $czasSurowy !== '') || ParametryAdresuBezTablic::bylWAdresie($request, 'czas'));
        if ($section === 'ludzie') {
            $maksMinut = null;
            $czasNieznany = false;
        } elseif ($maksMinut !== null && $section === 'wszystko') {
            $section = 'przepisy';
        }
        // ALERGENY (#1902, D-333): filtr „Bez wskazanych alergenów (według
        // autorów)”, bezstanowy — wybór żyje wyłącznie w adresie (`bez[]=…`),
        // niczego nie zapisujemy o szukającym (D-299, art. 9 RODO). Tylko przy
        // włączonej fladze; to warunek NA PRZEPISY, jak czas: „Wszystko"
        // z filtrem staje się „Przepisy", a na „Ludziach" filtr jest pomijany.
        // Nieznane albo nie-tekstowe wartości są pomijane, a ekran mówi to
        // wprost (`$bezNieznane`) — nie filtrują po cichu niczym innym.
        $bezWejscie = [];
        if ((bool) config('kuking.alergeny.wlaczone') && $section !== 'ludzie') {
            $bezSurowe = $request->query('bez');
            $bezWejscie = is_array($bezSurowe)
                ? array_slice(array_values($bezSurowe), 0, 50)
                : (is_string($bezSurowe) && $bezSurowe !== '' ? [$bezSurowe] : []);
        }
        $bezAlergenow = Alergen::znormalizuj($bezWejscie);
        $bezNieznane = Alergen::nieznane($bezWejscie);
        if ($bezAlergenow !== [] && $section === 'wszystko') {
            $section = 'przepisy';
        }
        // „OD OSÓB, KTÓRE OBSERWUJĘ” (#2440, D-275): jawny wybór zalogowanej
        // osoby, w adresie tylko `obserwowani=1` (żadnych identyfikatorów ani
        // listy osób). Warunek NA PRZEPISY jak czas i alergeny: „Wszystko"
        // z wyborem staje się „Przepisy", a na „Ludziach" jest pomijany.
        // Gość nie ma kogo obserwować: wybór nie włącza żadnego zapytania
        // o konto, a ekran mówi to wprost (`$obserwowaniGosc`).
        $obserwowaniWAdresie = $request->query('obserwowani') === '1' && $section !== 'ludzie';
        $obserwowani = $obserwowaniWAdresie && $request->user() !== null;
        $obserwowaniGosc = $obserwowaniWAdresie && $request->user() === null;
        if ($obserwowani && $section === 'wszystko') {
            $section = 'przepisy';
        }
        // ZWYKŁY SKŁADNIK DO POMINIĘCIA (V2, #2526): jedno opcjonalne
        // kryterium `bez_skladnika=…`, bezstanowe jak alergeny — wybór żyje
        // wyłącznie w adresie. Warunek NA PRZEPISY: „Wszystko" z filtrem
        // staje się „Przepisy", a na „Ludziach" filtr jest pomijany.
        // Zła nazwa (za długa, bez liter) NIE filtruje po cichu: ekran mówi,
        // co poprawić, i pokazuje wyniki bez tego filtra.
        $bezSkladnika = null;
        $bladSkladnika = null;
        $skladnikWpisany = '';
        if ($section !== 'ludzie') {
            $surowySkladnik = $request->query('bez_skladnika');
            $skladnikWpisany = is_string($surowySkladnik) ? trim($surowySkladnik) : '';
            ['wartosc' => $bezSkladnika, 'blad' => $bladSkladnika] = SearchQuery::skladnikDoPominiecia($surowySkladnik);
            if ($bezSkladnika !== null && $section === 'wszystko') {
                $section = 'przepisy';
            }
        }
        // „Do 20 zł" to przepisy z kosztem wg autora (D-286).
        $maksKosztZl = $section === 'tanie' ? KosztPrzepisu::TANIE_DO : null;
        $szukaPrzepisow = in_array($section, ['wszystko', 'przepisy', 'tanie'], true);
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
        //
        // KAŻDA LISTA MA WŁASNY ROZMIAR OKNA (issue #984). Na zakładce
        // „Wszystko" oba przyciski sterowały jednym `ile`, więc „Pokaż więcej
        // przepisów" rozszerzało też listę osób — akcja robiła więcej, niż
        // obiecywał jej podpis. Teraz `ile_przepisow` i `ile_osob` są osobne;
        // wspólne `ile` zostaje wyłącznie jako wartość domyślna dla starych
        // adresów i nigdy nie trafia do nowych odnośników.
        $ile = $this->rozmiarOkna($request->query('ile'), self::NA_STRONIE);
        $ilePrzepisow = $this->rozmiarOkna($request->query('ile_przepisow'), $ile);
        $ileOsob = $this->rozmiarOkna($request->query('ile_osob'), $ile);
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
        $parametry = array_filter(['q' => $phrase, 'sekcja' => $section, 'czas' => $maksMinut,
            'bez' => $bezAlergenow === [] ? null : $bezAlergenow,
            'obserwowani' => $obserwowani ? 1 : null,
            'bez_skladnika' => $bezSkladnika,
            'ile_przepisow' => $ilePrzepisow, 'ile_osob' => $ileOsob,
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
        // `SearchQuery::jestPrzeszukiwalna()` MUSI się zgadzać z tym, co
        // robią `recipes()`/`people()` — jedna metoda liczy oba powody
        // odrzucenia (krócej niż 2 znaki i pusta po normalizacji, #1050),
        // żeby ten ekran i domena nigdy się nie rozjechały.
        $phraseForLength = $section === 'ludzie' ? SearchQuery::peoplePhrase($phrase) : $phrase;
        $zaKrotka = $phrase !== '' && ! SearchQuery::jestPrzeszukiwalna($phraseForLength);

        $przepisy = $szukaPrzepisow && $searchErrors->isEmpty()
            // Widz przekazywany po to, żeby wyszukiwarka respektowała blokady
            // (issue #41). Bez niego blokada kończyła się na widoku i liście.
            ? $this->search->recipes($phrase, $request->user(), $ilePrzepisow + 1, $maksMinut, $odPrzepisu, $maksKosztZl, $poPrzepisie, $bezAlergenow, $obserwowani, $bezSkladnika)
            : collect();

        // Zakładka „Ludzie" liczy się DOKŁADNIE TAK SAMO, a nie „przy okazji".
        //
        // Wcześniej dostawała sztywne dwadzieścia wyników bez żadnej drogi
        // dalej. Nie kłamała wprost (nie było licznika), ale kończyła się
        // w miejscu, którego nie dało się rozpoznać: przy dwudziestu jeden
        // Basiach dwudziesta pierwsza po prostu nie istniała dla szukającego.
        $ludzie = $szukaLudzi && $searchErrors->isEmpty()
            ? $this->search->people($phrase, $request->user(), $ileOsob + 1, $odOsoby, $poOsobie)
            : collect();

        // Każda lista rośnie niezależnie. Po osiągnięciu 200 kolejne okno
        // idzie za kluczem rankingu ostatniego rekordu tej listy (#1023),
        // a druga lista zachowuje własny rozmiar, offset i kursor (#984).
        // Odnośniki są nawigacją po wynikach, nie nowym wyszukiwaniem (#943).
        $nastepnePrzepisy = $parametry + ['nawigacja' => 1];
        $nastepneOsoby = $parametry + ['nawigacja' => 1];
        if ($ilePrzepisow < self::MAKS) {
            $nastepnePrzepisy['ile_przepisow'] = min($ilePrzepisow + self::NA_STRONIE, self::MAKS);
        } else {
            $nastepnePrzepisy['od_przepisu'] += $ilePrzepisow;
            if ($przepisy->count() > $ilePrzepisow) {
                $nastepnePrzepisy['po_przepisie'] = SearchQuery::kursorPrzepisu($przepisy[$ilePrzepisow - 1]);
            }
        }
        if ($ileOsob < self::MAKS) {
            $nastepneOsoby['ile_osob'] = min($ileOsob + self::NA_STRONIE, self::MAKS);
        } else {
            $nastepneOsoby['od_osoby'] += $ileOsob;
            if ($ludzie->count() > $ileOsob) {
                $nastepneOsoby['po_osobie'] = SearchQuery::kursorOsoby($ludzie[$ileOsob - 1]);
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
        //
        // NAWIGACJA PO WYNIKACH TO NIE NOWE WYSZUKANIE (issue #943).
        // `search_performed` liczy wysłanie frazy — formularzem na tej
        // stronie, w pasku u góry albo z odnośnika spoza wyników. „Pokaż
        // więcej", „Wróć do początku" i zakresy (Wszystko / Przepisy / Ludzie /
        // Do 20 zł) oraz progi czasu przeglądają wyniki JUŻ policzonej frazy i niosą
        // `nawigacja=1`. Bez tego jedno wyszukanie dawało kilka rekordów,
        // a puste dalsze okno zapisywało `has_results=false` dla frazy,
        // która w pierwszym oknie miała wyniki. Nie deduplikujemy po długości
        // frazy ani w sesji: kolejne wysłanie tej samej frazy to nowe
        // wyszukanie i liczy się ponownie.
        $nawigacja = $request->query('nawigacja') === '1';

        if ($phrase !== '' && ! $zaKrotka && $searchErrors->isEmpty() && ! $nawigacja) {
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
            'maksMinut' => $maksMinut,
            'czasNieznany' => $czasNieznany,
            'bezAlergenow' => $bezAlergenow,
            'bezSkladnika' => $bezSkladnika,
            'bladSkladnika' => $bladSkladnika,
            'skladnikWpisany' => $skladnikWpisany,
            'bezNieznane' => $bezNieznane,
            'obserwowani' => $obserwowani,
            'obserwowaniGosc' => $obserwowaniGosc,
            // Do pustego stanu: czy to widz w ogóle kogoś obserwuje. Jedno
            // proste `EXISTS`, tylko gdy wybór jest aktywny i nic nie znaleziono.
            'obserwujeKogos' => $obserwowani && $przepisy->isEmpty() && $request->user()->following()->exists(),
            'zaKrotka' => $zaKrotka,
            'szukaPrzepisow' => $szukaPrzepisow,
            'szukaLudzi' => $szukaLudzi,
            'recipes' => $przepisy->take($ilePrzepisow),
            'people' => $ludzie->take($ileOsob),
            'jestWiecej' => $przepisy->count() > $ilePrzepisow,
            'jestWiecejOsob' => $ludzie->count() > $ileOsob,
            'odPrzepisu' => $odPrzepisu,
            'odOsoby' => $odOsoby,
            'nastepnePrzepisy' => $nastepnePrzepisy,
            'nastepneOsoby' => $nastepneOsoby,
            'poczatekPrzepisow' => array_diff_key(array_replace($parametry, ['od_przepisu' => 0, 'nawigacja' => 1]), ['po_przepisie' => true]),
            'poczatekOsob' => array_diff_key(array_replace($parametry, ['od_osoby' => 0, 'nawigacja' => 1]), ['po_osobie' => true]),
        ]);
    }

    /** Rozmiar okna z adresu: od 20 do 200; brak albo śmieci = wartość domyślna. */
    private function rozmiarOkna(mixed $wartosc, int $domyslny): int
    {
        if (! is_string($wartosc) || $wartosc === '') {
            return $domyslny;
        }

        return min(max((int) $wartosc, self::NA_STRONIE), self::MAKS);
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

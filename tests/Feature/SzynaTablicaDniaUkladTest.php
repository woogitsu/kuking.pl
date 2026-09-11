<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyPick;
use App\Models\Media;
use App\Models\Post;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ZGŁOSZENIE WŁAŚCICIELA ZE ZRZUTU `/home` NA SZEROKIM MONITORZE (issue #272).
 *
 * 1. w sekcji OSOBY trzy miniatury stały JEDNA POD DRUGĄ, choć w szynie było
 *    miejsce na rząd;
 * 2. w sekcji DANIA zdjęcie wisiało samo, dociśnięte do lewej, a podpis leżał
 *    pod nim, nie obok;
 * 3. „w ogóle to się kupy nie trzyma".
 *
 * JEDNA PRZYCZYNA DLA OBU: elementy o stałej szerokości w poziomym `flex`
 * z `flex-wrap: wrap`, w kolumnie za wąskiej, żeby się zmieściły. Zmierzone
 * w Chromium przy oknie 1440 px, przed poprawką: szyna 352 px → wnętrze karty
 * tablicy 310 px → awatar 52 + odstęp 12 + blok tekstu 105 + odstęp 12 +
 * przycisk „Obserwuj" 129. Pasek miniatur siedział WEWNĄTRZ tego bloku
 * tekstu, więc miał 105 px na rząd potrzebujący 3 × 72 + 2 × 8 = 232 px.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO PILNUJE TEN PLIK, A CO `scripts/dostepnosc.mjs`
 * ══════════════════════════════════════════════════════════════════════
 *
 * Ten plik pilnuje dwóch rzeczy, które osobno nie znaczą nic:
 *   • że REGUŁA jest w arkuszu — i że nie „naprawiono" jej przez zdjęcie
 *     `flex-wrap: wrap`, bo zawijanie chroni przed przewijaniem strony
 *     w bok przy powiększonej czcionce (37 px przy bazie 32 px, pomiar
 *     w komentarzu w `app.css`);
 *   • że HTML stawia pasek TAM, GDZIE TA REGUŁA DZIAŁA — jako bezpośrednie
 *     dziecko rzędu `.kuking-board-person`. Wewnątrz bloku tekstu
 *     `flex-basis` nie robi nic, bo rodzicem jest tam zwykły blok, nie
 *     kontener `flex`. Reguła zostałaby w arkuszu, test arkusza świeciłby
 *     na zielono, a układ wróciłby do stanu ze zrzutu.
 *
 * Ten sam podział ma `tests/Feature/OdstepPodNaglowkiemStronyTest.php`
 * i `tests/Feature/OdstepyWFormularzachTest.php`, i z tego samego powodu.
 *
 * Czego ten plik NIE dowodzi: że miniatury naprawdę stanęły w jednym
 * wierszu, i że przy powiększonej czcionce strona się nie przewija. Jedno
 * i drugie wymaga ZMIERZENIA ułożonej strony w przeglądarce i robi to
 * `scripts/dostepnosc.mjs` — sekcja „Tablica dnia (rząd miniatur w szynie)"
 * dla rzędu i pomiar przepełnienia (issue #80) dla przewijania. Te dwa
 * pomiary łapią błędy w PRZECIWNE strony i dlatego muszą być oba.
 */
class SzynaTablicaDniaUkladTest extends TestCase
{
    use RefreshDatabase;

    /** Arkusz bez komentarzy — inaczej asercje trafiają w treść komentarza. */
    private function css(): string
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    /**
     * Wyjmuje ciało jednej reguły z arkusza. `\.klasa\s*\{` z dopełnieniem
     * do nawiasu zamykającego wystarcza, bo w tym arkuszu żadna z tych reguł
     * nie ma w środku zagnieżdżonego bloku.
     */
    private function regula(string $selektor): string
    {
        $trafil = preg_match(
            '/'.preg_quote($selektor, '/').'\s*\{([^}]*)\}/',
            $this->css(),
            $dopasowanie,
        );

        $this->assertSame(
            1,
            $trafil,
            "W `resources/css/app.css` nie ma reguły `{$selektor} { ... }`.",
        );

        return $dopasowanie[1];
    }

    #[Test]
    public function test_pasek_miniatur_zajmuje_caly_wiersz_karty_osoby(): void
    {
        $this->assertMatchesRegularExpression(
            '/flex-basis:\s*100%/',
            $this->regula('.kuking-board-preview'),
            '`.kuking-board-preview` nie ma `flex-basis: 100%`, więc pasek miniatur '.
            'znów dzieli wiersz z awatarem i przyciskiem „Obserwuj". W szynie zostaje '.
            'mu wtedy 105 px z 310, a rząd trzech miniatur potrzebuje 232 px — '.
            'zawija po jednej na wiersz, czyli wraca stan ze zrzutu z #272.',
        );
    }

    /**
     * KONTROLA ODWROTNA DO ZWYKŁEJ, I NAJWAŻNIEJSZA ASERCJA W TYM PLIKU.
     *
     * Najprostszym sposobem, żeby miniatury stanęły w rzędzie, jest zdjęcie
     * `flex-wrap: wrap`. Przechodzi wtedy i test wyżej, i pomiar rzędu
     * w przeglądarce — a strona wypycha się w bok o 37 px przy czcionce
     * przeglądarki podkręconej do 200 % (pomiar w komentarzu w `app.css`,
     * na `/`, `/odkryj` i `/szukaj`). Obrazek o stałej szerokości z `flex:
     * none` z definicji się nie skurczy, więc bez zawijania nie ma jak temu
     * zaradzić. To jest naruszenie WCAG 2.2 AA 1.4.10 (Reflow) i cofnięcie
     * cudzej naprawy.
     */
    #[Test]
    public function test_pasek_miniatur_nadal_zawija_przy_powiekszonej_czcionce(): void
    {
        $this->assertMatchesRegularExpression(
            '/flex-wrap:\s*wrap/',
            $this->regula('.kuking-board-preview'),
            '`.kuking-board-preview` stracił `flex-wrap: wrap`. Własny wiersz daje '.
            '310 px w szynie tylko przy czcionce 16 px; przy bazie 32 px wnętrze '.
            'karty ma 174 px, a rząd trzech miniatur potrzebuje wtedy 248 px. '.
            'Bez zawijania wraca przewijanie strony w bok (WCAG 2.2 AA 1.4.10).',
        );
    }

    #[Test]
    public function test_podpis_dania_ma_prog_dwoch_kolumn_liczony_w_rem(): void
    {
        $tresc = $this->regula('.kuking-board-post-body');

        $this->assertMatchesRegularExpression(
            '/flex:\s*1\s+1\s+[\d.]+rem/',
            $tresc,
            '`.kuking-board-post-body` musi mieć `flex: 1 1 <N>rem`. Bez rozmiaru '.
            'bazowego blok podpisu liczy się z TREŚCI, nie mieści się obok zdjęcia '.
            '96 px w szynie i spada pod nie — dokładnie stan ze zrzutu z #272.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/flex:\s*1\s+1\s+[\d.]+px/',
            $tresc,
            'Próg dwóch kolumn musi być w `rem`, nie w pikselach. W `rem` jedzie razem '.
            'z czcionką, więc przy tekście podkręconym w przeglądarce dwie kolumny same '.
            'się wyłączają, zamiast ściskać podpis do słupka kilku znaków na wiersz '.
            '(docs/UX_50_PLUS.md).',
        );
    }

    /**
     * Reguły wyżej nic nie robią, jeśli tablica w prawdziwym HTML-u nie
     * układa się tak, jak one zakładają. Bez tego sprawdzenia dałoby się
     * przenieść pasek z powrotem do bloku tekstu i zostawić w arkuszu
     * martwą regułę, a oba testy arkusza dalej świeciłyby na zielono.
     */
    #[Test]
    public function test_pasek_miniatur_jest_bezposrednim_dzieckiem_rzedu_osoby(): void
    {
        $dom = $this->tablicaNaStronieStartowej();
        $xpath = new \DOMXPath($dom);

        $paski = $xpath->query($this->klasa('div', 'kuking-board-preview'));

        $this->assertGreaterThan(
            0,
            $paski->length,
            'W tablicy nie ma ani jednego `.kuking-board-preview` — bez niego ten test '.
            'przeszedłby, nie sprawdzając niczego. Sprawdź dane w tym teście, '.
            'nie widok.',
        );

        foreach ($paski as $pasek) {
            $rodzic = $pasek->parentNode;

            $this->assertInstanceOf(\DOMElement::class, $rodzic);
            $this->assertStringContainsString(
                'kuking-board-person',
                (string) $rodzic->getAttribute('class'),
                'Pasek miniatur nie jest bezpośrednim dzieckiem `.kuking-board-person`, '.
                'a jego rodzicem jest `<'.$rodzic->nodeName.' class="'.
                $rodzic->getAttribute('class').'">`. `flex-basis: 100%` opisuje pasek '.
                'jako element RZĘDU karty osoby — wewnątrz bloku tekstu ta reguła jest '.
                'martwa i miniatury wracają do słupka po jednej na wiersz.',
            );
        }
    }

    /**
     * Druga połowa tego samego warunku: `flex: 1 1 10rem` działa tylko na
     * elemencie WIERSZA, a wierszem karty dania jest od 11 września 2026
     * odnośnik obejmujący całą kartę (`.kuking-board-post-link`), nie samo
     * `<li>`. I jednocześnie sprawdzenie, że klasa w ogóle jest w widoku —
     * reguła w arkuszu bez niej nie ma na czym zadziałać.
     */
    #[Test]
    public function test_podpis_dania_jest_bezposrednim_dzieckiem_wiersza_dania(): void
    {
        $dom = $this->tablicaNaStronieStartowej();
        $xpath = new \DOMXPath($dom);

        $podpisy = $xpath->query($this->klasa('span', 'kuking-board-post-body'));

        $this->assertGreaterThan(
            0,
            $podpisy->length,
            'W tablicy nie ma ani jednego `.kuking-board-post-body` — klasa, na której '.
            'wisi próg dwóch kolumn, zniknęła z widoku i reguła w `app.css` nie ma '.
            'na czym działać.',
        );

        foreach ($podpisy as $podpis) {
            $rodzic = $podpis->parentNode;

            $this->assertInstanceOf(\DOMElement::class, $rodzic);
            $this->assertStringContainsString(
                'kuking-board-post-link',
                (string) $rodzic->getAttribute('class'),
                'Blok podpisu dania nie jest bezpośrednim dzieckiem `.kuking-board-post-link`, '.
                'więc `flex: 1 1 10rem` na nim nic nie robi i podpis wraca pod zdjęcie.',
            );

            $wiersz = $rodzic->parentNode;

            $this->assertInstanceOf(\DOMElement::class, $wiersz);
            $this->assertStringContainsString(
                'kuking-board-post',
                (string) $wiersz->getAttribute('class'),
                'Odnośnik wiersza dania nie stoi bezpośrednio w `<li class="kuking-board-post">`. '.
                'Wcięcie i kreska siedzą na `<li>`, a układ na odnośniku — rozdzielenie ich '.
                'czymkolwiek pośrodku rozjeżdża rytm obu list.',
            );
        }
    }

    /**
     * CAŁY WIERSZ DANIA JEST JEDNYM ODNOŚNIKIEM, BEZ PRZYCISKU „ZOBACZ"
     * (zgłoszenie właściciela: „żadnej symetrii, byle jak").
     *
     * Do 11 września 2026 karta dania miała trzy cele kliknięcia prowadzące
     * w dwa miejsca: zdjęcie i przycisk „Zobacz" (oba do wpisu) oraz imię
     * autora (do profilu). Przycisk powtarzał się przy każdym daniu, nie
     * robiąc nic ponad to, co zdjęcie obok, i stał raz z wcięciem, raz bez —
     * zależnie od tego, czy danie miało zdjęcie.
     *
     * Ten test pilnuje obu połówek naraz: że odnośnik jest DOKŁADNIE jeden
     * (czyli nikt nie dołożył drugiego celu w środku pierwszego — odnośnik
     * w odnośniku nie istnieje w HTML-u) i że „Zobacz" nie wróciło.
     */
    #[Test]
    public function test_wiersz_dania_jest_jednym_odnosnikiem_bez_przycisku_zobacz(): void
    {
        $dom = $this->tablicaNaStronieStartowej();
        $xpath = new \DOMXPath($dom);

        $karty = $xpath->query($this->klasa('li', 'kuking-board-post'));

        $this->assertGreaterThan(0, $karty->length, 'W tablicy nie ma ani jednej karty dania.');

        foreach ($karty as $karta) {
            $odnosniki = $xpath->query('.//a', $karta);

            $this->assertSame(
                1,
                $odnosniki->length,
                'Karta dania ma '.$odnosniki->length.' odnośników zamiast jednego. Cały wiersz '.
                'jest celem kliknięcia; drugi odnośnik w środku albo nie da się kliknąć, albo '.
                'wraca stan sprzed poprawki — trzy cele prowadzące w dwa miejsca.',
            );

            $this->assertStringContainsString(
                'kuking-board-post-link',
                (string) $odnosniki->item(0)?->getAttribute('class'),
                'Jedyny odnośnik karty dania nie jest wierszem `.kuking-board-post-link`.',
            );

            $this->assertNotSame(
                'Zobacz',
                trim((string) $xpath->query('.//a[normalize-space()="Zobacz"]', $karta)->item(0)?->textContent),
                'Przy daniu wrócił przycisk „Zobacz" — powtórzony przy każdej karcie, choć cały '.
                'wiersz prowadzi pod ten sam adres.',
            );
        }
    }

    /**
     * OBIE LISTY MAJĄ JEDNĄ KOLUMNĘ ZDJĘĆ I JEDEN PRÓG DWÓCH KOLUMN.
     *
     * To jest cała symetria tej sekcji sprowadzona do dwóch liczb. Zmierzone
     * przed poprawką na `/` przy oknie 1512 px: tekst osoby zaczynał się przy
     * `x = 345`, podpis dania ZE zdjęciem przy `x = 389`, podpis dania BEZ
     * zdjęcia i pasek miniatur przy `x = 249`. Po poprawce obie listy zaczynają
     * tekst przy tym samym `x` (396 px) na każdej z czterech mierzonych
     * szerokości — ale tylko dopóki awatar i zdjęcie dania biorą szerokość
     * z JEDNEJ zmiennej, a oba bloki tekstu mają TEN SAM próg.
     */
    #[Test]
    public function test_obie_listy_maja_ta_sama_kolumne_zdjecia(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/--tablica-zdjecie:\s*\d+px/',
            $this->regula('.kuking-board'),
            '`.kuking-board` nie definiuje `--tablica-zdjecie` w pikselach. W `rem` ta liczba '.
            'podwoiłaby się przy czcionce przeglądarki 200 % (240 px) i samo zdjęcie nie '.
            'zmieściłoby się na ekranie 320 px (D-082, D-107).',
        );

        $this->assertMatchesRegularExpression(
            '/\.kuking-board-avatar,\s*\.kuking-board-post-photo\s*\{[^}]*width:\s*var\(--tablica-zdjecie\)/',
            $css,
            'Awatar osoby i zdjęcie dania nie biorą szerokości z jednej zmiennej. Gdy każda '.
            'lista ma własną liczbę, tekst zaczyna się w nich przy innym `x` — czyli wraca '.
            'stan, który właściciel nazwał „bez symetrii".',
        );

        foreach (['.kuking-board-person-body', '.kuking-board-post-body'] as $blok) {
            $this->assertMatchesRegularExpression(
                '/\.kuking-board-person-body,\s*\.kuking-board-post-body\s*\{[^}]*flex:\s*1\s+1\s+[\d.]+rem/',
                $css,
                "Blok `{$blok}` nie ma wspólnego progu `flex: 1 1 <N>rem` z drugą listą. ".
                'Dwa różne progi znaczą, że jedna lista zawija się na pion przy innej '.
                'szerokości niż druga, a to widać jako rozjazd między „Osobami" a „Daniami".',
            );
        }
    }

    /**
     * Danie BEZ gotowego zdjęcia nie zostawia w wierszu pustego miejsca po
     * zdjęciu. Element o zerowej szerokości w kontenerze `flex` zabiera swój
     * `gap` także wtedy, gdy nic nie zawiera — zmierzone przy bazie 32 px:
     * podpis takiego dania stał 24 px w prawo od krawędzi wszystkich
     * pozostałych kart w tablicy.
     *
     * Od 11 września 2026 miejscem na zdjęcie jest `<span>`, nie `<a>`:
     * odnośnikiem jest cały wiersz, a odnośnik w odnośniku nie istnieje
     * w HTML-u. Sprawdzenie dotyczy tego samego, co przedtem — pustego
     * elementu zabierającego `gap`.
     */
    #[Test]
    public function test_danie_bez_zdjecia_nie_zostawia_pustego_miejsca_po_zdjeciu(): void
    {
        $dom = $this->tablicaNaStronieStartowej();
        $xpath = new \DOMXPath($dom);

        $karty = $xpath->query($this->klasa('li', 'kuking-board-post'));

        $zeZdjeciem = 0;
        $bezZdjecia = 0;

        foreach ($karty as $karta) {
            $miejscaNaZdjecie = $xpath->query('.'.$this->klasa('span', 'kuking-board-post-photo'), $karta);
            $obrazki = $xpath->query('.//img', $karta);

            if ($obrazki->length > 0) {
                $zeZdjeciem++;

                $this->assertSame(
                    1,
                    $miejscaNaZdjecie->length,
                    'Danie ZE zdjęciem straciło miejsce na zdjęcie `.kuking-board-post-photo`.',
                );

                continue;
            }

            $bezZdjecia++;

            $this->assertSame(
                0,
                $miejscaNaZdjecie->length,
                'Danie bez gotowego zdjęcia renderuje pusty `<span class="kuking-board-post-photo">`. '.
                'Element o zerowej szerokości zabiera w kontenerze `flex` swój `gap`, więc podpis '.
                'stoi odsunięty od krawędzi, przy której licują wszystkie pozostałe karty tablicy.',
            );
        }

        // KONTROLA DODATNIA I UJEMNA W JEDNYM: bez dania ze zdjęciem
        // asercja wyżej nigdy by nie sprawdziła kształtu z odnośnikiem,
        // a bez dania bez zdjęcia — kształtu bez niego.
        $this->assertGreaterThan(0, $zeZdjeciem, 'Test nie miał ani jednego dania ZE zdjęciem.');
        $this->assertGreaterThan(0, $bezZdjecia, 'Test nie miał ani jednego dania BEZ zdjęcia.');
    }

    /**
     * TABLICA NA STRONIE POWITALNEJ NIE JEST KARTĄ — I NA POZOSTAŁYCH
     * EKRANACH DALEJ JEST (zgłoszenie właściciela).
     *
     * Na `/` hierarchię buduje tło PASA (`.pas` w `pages/landing.blade.php`),
     * a tablica była tam jedyną sekcją z własną kartą w środku pasa, czyli
     * kartą w karcie. W szynie i na `/odkryj` jest odwrotnie: tablica stoi
     * obok „Mojego zeszytu" i obok kart wpisów, więc bez własnego tła nie
     * wiadomo, gdzie się kończy.
     *
     * DWIE POŁOWY W JEDNYM TEŚCIE, BO OSOBNO NIE ZNACZĄ NIC. Samo „nie ma
     * `card` na `/`" przechodzi także wtedy, gdy ktoś zdejmie kartę wszędzie;
     * samo „jest `card` na `/odkryj`" nie zauważy, że na `/` wróciła.
     */
    #[Test]
    public function test_tablica_jest_karta_wszedzie_poza_strona_powitalna(): void
    {
        $this->wyborNaDzis();

        $powitalna = $this->sekcjaTablicy($this->tablicaZTrasy('landing'));

        $this->assertStringNotContainsString(
            ' card ',
            $powitalna,
            'Tablica na stronie powitalnej znów ma klasę `card`. W środku pasa (`.pas`) '.
            'to jest karta w karcie — jedyna taka sekcja na tym ekranie.',
        );

        $odkryj = $this->sekcjaTablicy($this->tablicaZTrasy('discover'));

        $this->assertStringContainsString(
            ' card ',
            $odkryj,
            'Tablica na `/odkryj` straciła kartę. Stoi tam nad listą kart wpisów i bez '.
            'własnego tła nie widać, gdzie się kończy — a to jest zmiana w drugą stronę, '.
            'nie naprawa strony powitalnej.',
        );
    }

    /** Atrybut `class` sekcji tablicy — jedno miejsce, żeby nie powtarzać XPatha. */
    private function sekcjaTablicy(\DOMDocument $dom): string
    {
        $xpath = new \DOMXPath($dom);
        $sekcja = $xpath->query($this->klasa('section', 'kuking-board'))->item(0);

        $this->assertInstanceOf(
            \DOMElement::class,
            $sekcja,
            'Na ekranie nie ma `<section class="… kuking-board">` — bez niej ten test '.
            'przeszedłby, nie sprawdzając niczego.',
        );

        return ' '.(string) $sekcja->getAttribute('class').' ';
    }

    /** XPath na element z klasą — porównanie po całym atrybucie łapie też `kuking-board-post-body`. */
    private function klasa(string $tag, string $klasa): string
    {
        return "//{$tag}[contains(concat(' ', normalize-space(@class), ' '), ' {$klasa} ')]";
    }

    /**
     * Tablica „kuKINGi na dziś" z DANYMI POD OBA KSZTAŁTY KARTY — osobą
     * z trzema gotowymi zdjęciami i dwoma daniami, jednym ze zdjęciem
     * i jednym bez.
     *
     * WYBÓR REDAKCYJNY (`daily_picks`), NIE AUTOMAT. Automatyczna gałąź
     * `DailyBoard` wyklucza osoby, które widz już obserwuje, i dobiera
     * maksymalnie jedno danie od osoby — czyli zawartość tablicy zależałaby
     * od rzeczy, o których ten test nic nie mówi. Wybór redakcyjny stawia
     * na tablicy dokładnie to, co tu wymieniono.
     *
     * `/odkryj` jako gość, nie `/home`: ten sam komponent
     * (`kuking-board.blade.php`) i ta sama struktura HTML, a bez logowania
     * i bez reszty strony startowej. Miejsce paska w drzewie nie zależy od
     * tego, w której kolumnie stoi tablica — o tym, czy rząd się mieści,
     * mówi pomiar w przeglądarce, nie ten plik.
     */
    private function tablicaNaStronieStartowej(string $trasa = 'discover'): \DOMDocument
    {
        $this->wyborNaDzis();

        return $this->tablicaZTrasy($trasa);
    }

    /** Ten sam wybór redakcyjny, ale wsiewany DOKŁADNIE RAZ — patrz `wyborNaDzis()`. */
    private function tablicaZTrasy(string $trasa): \DOMDocument
    {
        $html = $this->get(route($trasa))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return $dom;
    }

    /**
     * Wybór redakcyjny na dziś. OSOBNO OD RENDEROWANIA, bo test porównujący
     * dwa ekrany (`/` i `/odkryj`) musi wsiać te same dane raz: drugie
     * wywołanie przewracało się na `profiles_username_unique`, czyli na
     * własnych danych, a nie na sprawdzanej rzeczy.
     */
    private function wyborNaDzis(): void
    {
        $gospodarz = $this->moderator();
        $kucharka = $this->user('kucharka', ['display_name' => 'Ewa Kapica']);

        // Trzy wpisy z jednym gotowym zdjęciem każdy — widok składa pasek
        // ze zdjęć TRZECH ostatnich wpisów osoby, nie z jednego wpisu.
        for ($i = 0; $i < 3; $i++) {
            $wpis = Post::factory()->create([
                'author_id' => $kucharka->getKey(),
                'body' => 'Pyszne jagodzianki z mascarpone',
                'published_at' => now()->subMinutes($i + 1),
            ]);

            $wpis->media()->attach(
                Media::factory()->create(['owner_id' => $kucharka->getKey()])->getKey(),
                ['position' => 0],
            );
        }

        $zeZdjeciem = Post::factory()->create([
            'author_id' => $kucharka->getKey(),
            'body' => 'Chleb na zakwasie, pierwszy raz udany.',
            'published_at' => now()->subMinutes(10),
        ]);
        $zeZdjeciem->media()->attach(
            Media::factory()->create(['owner_id' => $kucharka->getKey()])->getKey(),
            ['position' => 0],
        );

        $bezZdjecia = Post::factory()->create([
            'author_id' => $kucharka->getKey(),
            'body' => 'Naleśniki po pracy. Dzieci zjadły wszystko.',
            'published_at' => now()->subMinutes(11),
        ]);

        $pozycja = 0;

        foreach ([
            [DailyPick::TYPE_USER, $kucharka->getKey()],
            [DailyPick::TYPE_POST, $zeZdjeciem->getKey()],
            [DailyPick::TYPE_POST, $bezZdjecia->getKey()],
        ] as [$typ, $id]) {
            DailyPick::create([
                'shown_on' => Czas::dzisiajData(),
                'subject_type' => $typ,
                'subject_id' => $id,
                'position' => $pozycja++,
                'curator_id' => $gospodarz->getKey(),
            ]);
        }
    }
}

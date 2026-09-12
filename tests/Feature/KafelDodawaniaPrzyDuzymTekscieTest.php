<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kafel „Dodaj zdjęcie tego, co ugotowałeś" przy bardzo dużym tekście.
 *
 * NA CZYM POLEGAŁ BŁĄD
 * `scripts/dostepnosc.mjs` meldował w linii podsumowania „focus częściowo
 * zasłonięty: 15". Dwa z tych ostrzeżeń dotyczyły kafla dodawania na tablicy
 * — przy 320 i przy 360 px, w wariancie „czcionka przeglądarki 200%".
 * Przyczyną nie było przewijanie: kontrolka była WYŻSZA NIŻ OKNO, a takiej
 * nie wyprowadzi spod belki żadne `scroll-padding` (D-082), bo nie ma
 * położenia strony, w którym widać ją w całości.
 *
 * ZMIERZONE PRZED POPRAWKĄ (`scripts/kafel-dodawania.mjs`, Chromium 141,
 * okno 740 px, zalogowany, `/home`, czcionka przeglądarki 200%):
 *
 *     okno     wysokość kafla   kolumna tekstu   tytuł        podpis
 *     320 px       2087,1 px            50 px    19 wierszy   17 wierszy
 *     360 px       1070,3 px            90 px     9 wierszy    9 wierszy
 *     390 px        896,8 px           120 px     7 wierszy    8 wierszy
 *     414 px        735,6 px           144 px     6 wierszy    6 wierszy
 *     768 px        289,2 px           498 px     2 wiersze    2 wiersze
 *
 * TO NIE BYŁA WYSOKOŚĆ TEKSTU, TYLKO SZEROKOŚĆ JEGO KOLUMNY. Przy 320 px
 * i korzeniu 32 px kafel ma 256 px. Obwódka (2 px) i wcięcie boczne
 * 2 × `--spacing-5` (2 × 40 px) zostawiają 174 px na rząd; awatar (48 px),
 * ikona (28 px) i dwa odstępy 2 × `--spacing-3` (2 × 24 px) biorą z tego
 * 124 px. Na tytuł pisany 40-piksełowym pismem zostaje 50 px — i reguła
 * bazowa `overflow-wrap: break-word` (`tokens.css`) łamie wyraz w środku,
 * bo inaczej wyszedłby poza ekran.
 *
 * POMIAR HISTORYCZNY PR #479 — przy podpisie 16 px. Issue #478 podnosi
 * podpis do 18 px; podane dalej wysokości nie są wynikiem nowego wariantu.
 * ZMIERZONE PO TAMTEJ POPRAWCE (ten sam skrypt, te same warianty):
 *
 *     okno     przed        po          kolumna tekstu
 *     320 px   2087,1 px    646,4 px    50 px  →  214 px
 *     360 px   1070,3 px    534,8 px    90 px  →  254 px
 *     390 px    896,8 px    472,8 px   120 px  →  284 px
 *     414 px    735,6 px    472,8 px   144 px  →  308 px
 *     768 px    289,2 px    289,2 px   498 px  →  498 px  (bez zmian)
 *
 * Wszystkie warianty bez powiększania i z naszym `data-text-scale="140"`
 * są po poprawce co do piksela takie same jak przed nią.
 *
 * DLACZEGO TEN TEST ISTNIEJE OBOK POMIARU W PRZEGLĄDARCE
 * Dokładnie z tego samego powodu co `BelkaPrzyDuzymTekscieTest`
 * i `RezerwaNadPaskiemTest`: wysokości kafla nie da się stwierdzić z CSS-a,
 * mierzy ją przeglądarka, a ten pomiar chodzi w CI WARUNKOWO (job
 * `dostepnosc` odpala się tylko przy zmianie w `resources/`, `public/`,
 * `scripts/dostepnosc.mjs` albo w plikach npm). Ten plik chodzi w jobie
 * `test`, czyli zawsze, i pilnuje tego, co widać w źródle:
 *
 *   1. że poprawka dalej jest w arkuszu i że stoi za PROGIEM „tekst jest
 *      duży w stosunku do ekranu", a nie za szerokością w pikselach,
 *   2. że nie zapłacono za nią ani pismem, ani żadnym słowem,
 *   3. że zwykły telefon ma kafel bez jednej zmiany,
 *   4. że liczba w pikselach nie rozjechała się z tokenem, z którego
 *      została wzięta.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Nie dowodzi, że kafel MIEŚCI SIĘ w oknie — na to trzeba przeglądarki
 * i do tego służy `scripts/kafel-dodawania.mjs`. Dowodzi tylko, że
 * mechanizm, który to załatwił, dalej stoi w arkuszu w tym kształcie,
 * w jakim został zmierzony.
 */
class KafelDodawaniaPrzyDuzymTekscieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Próg, za którym kafel zmienia układ. Ten sam co przy
     * `--rezerwa-pod-belka`, przy odpięciu `.topbar` i przy dolnej belce
     * (D-107) — porównuje okno z KORZENIEM, więc znaczy „tekst jest duży
     * w stosunku do ekranu", a nie „ekran jest wąski".
     */
    private const PROG = '@media (max-width: 15rem)';

    /**
     * Arkusz bez komentarzy.
     *
     * KOMENTARZE PRECZ, ZANIM COKOLWIEK SPRAWDZIMY — tak samo jak
     * w `BelkaPrzyDuzymTekscieTest`. Komentarz przy tej regule cytuje
     * dosłownie wszystko, czego test szuka („flex-basis: 100%", „20 px",
     * „display: none", nazwy tokenów), więc asercja szukająca tych ciągów
     * w surowym pliku trafiałaby we własne uzasadnienie — raz na czerwono
     * przy poprawnym kodzie, raz na zielono przy zepsutym
     * (`docs/PULAPKI_TESTOW.md`, pułapka 1).
     */
    private function css(string $plik = 'app.css'): string
    {
        $sciezka = resource_path('css/'.$plik);

        $this->assertFileExists($sciezka);

        $tresc = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($sciezka));

        $this->assertNotSame('', trim($tresc), "Arkusz {$plik} jest pusty — test sprawdzałby pustkę.");

        return $tresc;
    }

    /**
     * Wszystkie bloki progu z arkusza, z domknięciem liczonym po nawiasach
     * (blok zapytania medialnego zawiera w sobie reguły, więc „do pierwszego
     * `}`" urwałby go na pierwszej z nich).
     *
     * @return list<string>
     */
    private function blokiProgu(): array
    {
        $css = $this->css();
        $bloki = [];
        $od = 0;

        while (($start = strpos($css, self::PROG, $od)) !== false) {
            $otwarcie = strpos($css, '{', $start);

            $this->assertNotFalse($otwarcie, 'Blok progu `15rem` nie ma otwarcia.');

            $glebokosc = 0;
            $koniec = null;

            for ($i = $otwarcie, $n = strlen($css); $i < $n; $i++) {
                if ($css[$i] === '{') {
                    $glebokosc++;
                } elseif ($css[$i] === '}') {
                    $glebokosc--;

                    if ($glebokosc === 0) {
                        $koniec = $i;
                        break;
                    }
                }
            }

            $this->assertNotNull($koniec, 'Blok progu `15rem` nie jest domknięty.');

            $bloki[] = substr($css, $otwarcie, $koniec - $otwarcie);
            $od = $koniec;
        }

        /*
         * ASERCJA NA LICZBĘ ZNALEZIONYCH BLOKÓW — bez niej cały ten plik
         * przechodziłby na zielono w chwili, gdy próg zmieni zapis (choćby
         * na `@media (width <= 15rem)`) i `strpos` przestanie cokolwiek
         * znajdować. Zero trafień jest wtedy „sukcesem"
         * (`docs/PULAPKI_TESTOW.md`, pułapka 2).
         */
        $this->assertGreaterThanOrEqual(
            4,
            count($bloki),
            'W arkuszu nie ma już czterech bloków progu `'.self::PROG.'`. Albo próg '
            .'zmienił zapis, albo któraś z poprawek tego progu (pasek górny, rezerwa, '
            .'belka, kafel dodawania) zniknęła — a ten test nie ma wtedy czego sprawdzać.',
        );

        return $bloki;
    }

    /** Blok progu zawierający podany selektor. */
    private function blokProguZ(string $selektor): string
    {
        foreach ($this->blokiProgu() as $blok) {
            if (str_contains($blok, $selektor)) {
                return $blok;
            }
        }

        $this->fail(
            'Żaden blok `'.self::PROG."` nie zawiera już reguły `{$selektor}`. Kafel "
            .'dodawania wraca wtedy przy czcionce przeglądarki 200% do 2087,1 px '
            .'wysokości w oknie 740 px, czyli do kontrolki, której po Tabie nie widać '
            .'w całości (ostrzeżenie „focus częściowo zasłonięty" w scripts/dostepnosc.mjs).',
        );
    }

    /**
     * Reguła spoza wszystkich bloków progu — czyli to, co dostaje ZWYKŁY
     * telefon. Bez odcięcia bloków progu asercja „na zwykłym telefonie jest
     * tak jak było" trafiałaby równie dobrze w nadpisanie zza progu.
     */
    private function regulaBazowa(string $selektor): string
    {
        $css = $this->css();

        foreach ($this->blokiProgu() as $blok) {
            $css = str_replace($blok, '', $css);
        }

        $start = strpos($css, $selektor);

        $this->assertNotFalse($start, "Poza progiem nie ma już reguły `{$selektor}`.");

        $koniec = strpos($css, '}', $start);

        $this->assertNotFalse($koniec, "Reguła `{$selektor}` nie jest domknięta.");

        return substr($css, $start, $koniec - $start);
    }

    /**
     * Wartość jednej własności z bloku reguły.
     *
     * `(?<![-\w])` zamiast `\b` NIE JEST OZDOBĄ: granica słowa wypada też po
     * łączniku, więc wzorzec na `padding` łapie „padding-inline", a wzorzec
     * na `basis` — „flex-basis". Test czytałby wtedy inną własność niż ta,
     * o którą pyta, i mówił o niej w komunikacie błędu.
     */
    private function wartosc(string $blok, string $wlasnosc): string
    {
        $wzorzec = '~(?<![-\w])'.preg_quote($wlasnosc, '~').'\s*:\s*([^;}]+)~';

        $this->assertMatchesRegularExpression(
            $wzorzec,
            $blok,
            "W bloku nie ma już własności `{$wlasnosc}`.",
        );

        preg_match($wzorzec, $blok, $trafienie);

        return trim($trafienie[1]);
    }

    public function test_tekst_kafla_dostaje_wlasny_wiersz_przy_bardzo_duzym_tekscie(): void
    {
        $this->assertSame(
            'wrap',
            $this->wartosc($this->blokProguZ('.composer {'), 'flex-wrap'),
            'Kafel dodawania przestał zawijać przy bardzo dużym tekście. Bez zawijania '
            .'tytuł i podpis zostają w jednym rzędzie z awatarem i ikoną, a te zabierają '
            .'124 px ze 174 px rzędu — na tytuł pisany 40-piksełowym pismem zostaje 50 px.',
        );

        $this->assertSame(
            '100%',
            $this->wartosc($this->blokProguZ('.composer-copy {'), 'flex-basis'),
            'Tytuł i podpis kafla nie mają już własnego wiersza przy bardzo dużym tekście. '
            .'Zmierzone przy 320 px i czcionce przeglądarki 200%: kolumna tekstu wraca '
            .'z 214 px na 50 px, tytuł z 5 wierszy na 19, a cały kafel z 646,4 px na 2087,1 px '
            .'przy oknie 740 px.',
        );
    }

    public function test_ozdobna_ikona_schodzi_z_ukladu_zamiast_zajac_trzeci_wiersz(): void
    {
        $this->assertSame(
            'none',
            $this->wartosc($this->blokProguZ('.composer > .ikona'), 'display'),
            'Ozdobna ikona kafla wróciła do układu przy bardzo dużym tekście. Elementy '
            .'flexa układają się PO KOLEI, więc tekst z własnym wierszem spycha ją do '
            .'trzeciego wiersza, samą — za jej 28 px wysokości plus odstęp. Jest to ikona '
            .'`aria-hidden="true"`, więc jej zniknięcie nie zabiera nikomu ani jednego '
            .'słowa; opisem akcji jest tytuł kafla (AGENTS.md §5).',
        );
    }

    public function test_wciecie_boczne_kafla_nie_podwaja_sie_z_czcionka_przegladarki(): void
    {
        $wciecie = $this->wartosc($this->blokProguZ('.composer {'), 'padding-inline');

        $this->assertMatchesRegularExpression(
            '~^\d+(\.\d+)?px$~',
            $wciecie,
            "Wcięcie boczne kafla to znowu „{$wciecie}”, a nie wartość w pikselach ekranu. "
            .'Zapisane w `rem` podwaja się razem z korzeniem: przy 320 px i czcionce '
            .'przeglądarki 200% zabiera 80 z 256 px kafla, czyli 31% jego szerokości. '
            .'Szerokość ekranu od powiększenia czcionki nie rośnie, więc wcięcie liczone '
            .'od tej szerokości też nie ma powodu rosnąć (ta sama zasada co D-107).',
        );

        /*
         * ROZJAZD LICZBY Z TOKENEM — to jest jedyna cena zapisu w pikselach
         * i dlatego stoi tu asercja, a nie zdanie w komentarzu. Wartość ma
         * być DOKŁADNIE tym, czym `--spacing-5` jest na zwykłym telefonie,
         * żeby ta poprawka niczego nie zmniejszała: ma tylko nie pozwolić
         * wcięciu urosnąć.
         */
        preg_match('~--spacing-5:\s*([0-9.]+)rem~', $this->css('tokens.css'), $token);

        $this->assertNotEmpty(
            $token,
            'Token `--spacing-5` nie jest już zapisany w `rem` — liczba w `app.css` '
            .'nie ma z czym być porównana.',
        );

        $this->assertSame(
            (float) $token[1] * 16.0,
            (float) rtrim($wciecie, 'px'),
            "Wcięcie boczne kafla ({$wciecie}) rozjechało się z tokenem `--spacing-5` "
            .'('.$token[1].'rem przy korzeniu 16 px). Jedna z tych dwóch liczb została '
            .'zmieniona bez drugiej, więc przy bardzo dużym tekście kafel ma inne wcięcie '
            .'niż zakłada system projektowy — albo poprawka zaczęła je ZMNIEJSZAĆ, '
            .'zamiast tylko nie pozwalać mu rosnąć.',
        );
    }

    /**
     * KONTROLA DODATNIA POPRAWKI — i ona jest tu ważniejsza od asercji wyżej.
     *
     * Kafel da się obniżyć czterema zakazanymi drogami: zmniejszyć pismo
     * tytułu, zmniejszyć pismo podpisu, wyrzucić podpis albo rozłożyć kafel
     * na stałe (także tam, gdzie nikomu nie przeszkadzał). Każda z nich
     * obniżyłaby liczbę w `scripts/dostepnosc.mjs` i wyglądała jak naprawa.
     * Ten test pilnuje, że zapłacono za nią samym układem: ZWYKŁY telefon
     * ma kafel bez jednej zmiany.
     */
    public function test_zwykly_telefon_ma_kafel_bez_zmian(): void
    {
        $bazowa = $this->regulaBazowa('.composer {');

        $this->assertStringContainsString(
            'display: flex',
            $bazowa,
            'Kafel dodawania przestał być rzędem na zwykłym telefonie. Poprawka dotyczy '
            .'wyłącznie progu `'.self::PROG.'`, czyli bardzo dużego tekstu — przy zwykłej '
            .'czcionce kafel ma 201,4 px przy 320 px i mieści się bez żadnej zmiany.',
        );

        $this->assertStringContainsString(
            'align-items: center',
            $bazowa,
            'Awatar, tekst i ikona przestały stać w jednej linii na zwykłym telefonie.',
        );

        $this->assertSame(
            'var(--spacing-4) var(--spacing-5)',
            $this->wartosc($bazowa, 'padding'),
            'Wcięcie kafla na zwykłym telefonie przestało iść z tokenów. Poprawka ma '
            .'zatrzymać wzrost wcięcia BOCZNEGO za progiem, a nie zmieniać kafel, '
            .'który wszystkim mieści się na ekranie.',
        );
    }

    public function test_poprawka_nie_zostala_oplacona_pismem(): void
    {
        $this->assertSame(
            'var(--text-body-lg)',
            $this->wartosc($this->regulaBazowa('.composer-title {'), 'font-size'),
            'Tytuł kafla zszedł z `--text-body-lg` (20 px bazowo, 40 px przy czcionce '
            .'przeglądarki 200%). To jest zakazana droga do niskiego kafla: tytuł jest '
            .'opisem głównej akcji produktu, a minimum produktowe to 18 px '
            .'(docs/UX_50_PLUS.md).',
        );

        $this->assertSame(
            'var(--text-body)',
            $this->wartosc($this->regulaBazowa('.composer-help {'), 'font-size'),
            'Podpis kafla zszedł z minimum 18 px (`--text-body`, issue #478). Zmniejszanie pisma jest zakazaną '
            .'drogą do niskiego kafla — ta poprawka ma być zapłacona układem.',
        );

        foreach (['.composer-title', '.composer-help', '.composer-copy {'] as $selektor) {
            foreach ($this->blokiProgu() as $blok) {
                if (! str_contains($blok, $selektor)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '~(?<![-\w])font-size\s*:~',
                    $blok,
                    'Za progiem `'.self::PROG."` ktoś zmienia pismo w `{$selektor}`. "
                    .'Wysokość kafla ma spadać od układu, nie od tego, że tekst robi się '
                    .'mniejszy dokładnie u osoby, która powiększyła czcionkę.',
                );
            }
        }
    }

    /**
     * Druga połowa kontroli dodatniej: układ się zmienia, a TREŚĆ nie.
     *
     * Sprawdzamy na wyrenderowanej stronie, nie w pliku widoku — kafel jest
     * składany z komponentów, a test czytający Blade'a przechodziłby także
     * wtedy, gdyby `@if` w komponencie przestał cokolwiek wypuszczać.
     *
     * Wycinamy sam kafel, a nie cały dokument: tytuł „Dodaj zdjęcie tego, co
     * ugotowałeś" stoi na tablicy także w dolnej belce i w prawej szynie,
     * więc asercja na całym HTML-u przechodziłaby z cudzego miejsca
     * (`docs/PULAPKI_TESTOW.md`, pułapki 1 i 1b).
     */
    public function test_kafel_nie_stracil_ani_jednego_slowa(): void
    {
        $html = $this->actingAs($this->user('basia'))->get(route('home'))->assertOk()->getContent();

        $start = strpos($html, 'class="kafel-akcji composer"');

        $this->assertNotFalse($start, 'Na tablicy nie ma już kafla dodawania.');

        $koniec = strpos($html, '</a>', $start);

        $this->assertNotFalse($koniec, 'Kafel dodawania nie jest domknięty.');

        $kafel = substr($html, $start, $koniec - $start);

        $this->assertStringContainsString(
            'Dodaj zdjęcie tego, co ugotowałeś',
            $kafel,
            'Kafel stracił tytuł. Niska kontrolka okupiona zniknięciem jej opisu nie jest '
            .'naprawą — to jest główna akcja produktu (AGENTS.md §1).',
        );

        $this->assertStringContainsString(
            'Nie musi być ładne — ma być prawdziwe.',
            $kafel,
            'Kafel stracił podpis. Wysokość miała spaść od układu, nie od wyrzucenia '
            .'zdania, które mówi człowiekowi, że zdjęcie nie musi być idealne.',
        );

        /* Ikona zostaje W KODZIE STRONY — schodzi wyłącznie z układu, i to
           tylko za progiem. Usunięcie jej z widoku zabrałoby ją wszystkim,
           także na zwykłym telefonie, gdzie nikomu nie przeszkadza. */
        $this->assertStringContainsString(
            'class="ikona"',
            $kafel,
            'Ikona zniknęła z widoku, a miała schodzić tylko z UKŁADU i tylko za progiem '
            .self::PROG.'. Zwykły telefon traci ją wtedy bez powodu.',
        );
    }
}


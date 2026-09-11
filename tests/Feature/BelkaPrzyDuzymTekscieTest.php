<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dolna belka przy bardzo dużym tekście (issue #295).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Zmierzone na `/home` jako zalogowany, okno 320 × 740 px, czcionka
 * przeglądarki 200% (korzeń 32 px), Chromium 153: `.bottom-nav` ma
 * 376,2 px wysokości, czyli 51% ekranu telefonu. PR #269 dołożył rezerwę
 * `--rezerwa-pod-belka` i tym zamknął naruszenie 2.4.11 — treść da się
 * wyprowadzić spod belki, fokus nigdzie nie ginie — ale nie dotknął tego,
 * że nawigacja jest większa niż treść.
 *
 * TO NIE BYŁA WYSOKOŚĆ TEKSTU, A WŁAŚNIE TAK TO WYGLĄDAŁO
 * Pozycja belki miała tam 120 px, a jej zawartość — ikona 26 px, odstęp
 * 2 px, wiersz podpisu 43,2 px — potrzebuje 71,2 px. Różnicę robiły dwie
 * liczby zapisane w `rem`, które przy podwojonym korzeniu podwajają się
 * razem z nim, choć są FIZYCZNE, a nie typograficzne:
 *
 *     `min-height: var(--control-height-touch)`   3,75rem →  120 px
 *     `.bottom-nav-kolko`                            3rem  →   96 px
 *
 * Palec nie rośnie, gdy ktoś powiększy czcionkę w przeglądarce, a piksel CSS
 * przy powiększeniu SAMEJ czcionki zostaje tej samej wielkości (to nie jest
 * zoom strony). Do tego układ kolumnowy sumował w pionie ikonę i wiersz
 * tekstu, choć ikona w poziomie nic nie kosztowała.
 *
 * ZMIERZONE PO POPRAWCE: 181 px, czyli 24,5% ekranu zamiast 50,8%, przy
 * pięciu pozycjach, całych podpisach i piśmie podpisu nadal 36 px.
 *
 * DLACZEGO TEN TEST ISTNIEJE OBOK POMIARU W PRZEGLĄDARCE
 * Wysokości belki nie da się stwierdzić z CSS-a — to wynik ułożenia strony,
 * i mierzy ją `scripts/dostepnosc.mjs` (sekcja „Dolna belka”, próg 1/3 okna).
 * Ten pomiar chodzi w CI WARUNKOWO: job `dostepnosc` odpala się tylko przy
 * zmianie w `resources/`, `public/`, `scripts/dostepnosc.mjs` albo w plikach
 * npm. Ten plik chodzi w jobie `test`, czyli zawsze, i pilnuje tego, co widać
 * w źródle: że poprawka dalej jest w arkuszu, że nie zapłacono za nią pismem
 * ani liczbą pozycji, i że liczba w pikselach nie rozjechała się z tokenem,
 * z którego została wzięta.
 */
class BelkaPrzyDuzymTekscieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Próg, za którym belka zmienia układ. Ten sam co przy
     * `--rezerwa-pod-belka` i przy odpięciu `.topbar` — porównuje okno
     * z KORZENIEM, więc znaczy „tekst jest duży w stosunku do ekranu”,
     * a nie „ekran jest wąski”.
     */
    private const PROG = '@media (max-width: 15rem)';

    /**
     * Arkusz bez komentarzy.
     *
     * KOMENTARZE PRECZ, ZANIM COKOLWIEK SPRAWDZIMY. Ten arkusz cytuje
     * w komentarzach dokładnie to, czego w kodzie być nie może („flex-direction:
     * column" jako opis stanu sprzed poprawki, „96 px” jako opis usterki).
     * Asercja szukająca takiego ciągu w surowym pliku trafiałaby we własne
     * uzasadnienie — raz na czerwono przy poprawnym kodzie, raz na zielono
     * przy zepsutym (`docs/PULAPKI_TESTOW.md`, pułapka 1).
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
     * Wszystkie bloki progu `15rem` z arkusza, z domknięciem liczonym po
     * nawiasach — blok zapytania medialnego zawiera w sobie reguły, więc
     * „do pierwszego `}`” urwałoby go na pierwszej z nich.
     *
     * Zwracamy LISTĘ, bo tych bloków jest w arkuszu kilka (pasek górny,
     * rezerwa, belka wewnątrz warstwy i kółko poza nią) i żaden test nie ma
     * prawa zakładać, który jest który.
     *
     * @return list<string>
     */
    private function blokiProgu(): array
    {
        return array_map(
            static fn (array $blok): string => $blok['tresc'],
            $this->zakresyProgu(),
        );
    }

    /**
     * To samo, ale z położeniem w pliku — potrzebne, żeby dało się czytać
     * arkusz Z POMINIĘCIEM tych bloków i nie pomylić reguły bazowej
     * z jej nadpisaniem za progiem.
     *
     * @return list<array{tresc: string, od: int, do: int}>
     */
    private function zakresyProgu(): array
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

            $bloki[] = [
                'tresc' => substr($css, $otwarcie, $koniec - $otwarcie),
                'od' => $start,
                'do' => $koniec,
            ];
            $od = $koniec;
        }

        /*
         * ASERCJA NA LICZBĘ ZNALEZIONYCH BLOKÓW — bez niej cały ten plik
         * przechodziłby na zielono w chwili, gdy próg zmieni zapis (choćby
         * na `@media (width <= 15rem)`) i `strpos` przestanie cokolwiek
         * znajdować. Zero trafień jest wtedy „sukcesem”
         * (`docs/PULAPKI_TESTOW.md`, pułapka 2).
         */
        $this->assertGreaterThanOrEqual(
            3,
            count($bloki),
            'W arkuszu nie ma już trzech bloków progu `'.self::PROG.'`. '
            .'Albo próg zmienił zapis, albo poprawki (pasek górny, rezerwa, belka) '
            .'zniknęły — a ten test nie ma wtedy czego sprawdzać.',
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
            'Żaden blok `'.self::PROG."` nie zawiera już reguły `{$selektor}`. "
            .'Belka przy czcionce przeglądarki 200% wraca wtedy do 376,2 px, '
            .'czyli 51% ekranu telefonu (issue #295).',
        );
    }

    /**
     * Wartość jednej własności z bloku reguły.
     *
     * `(?<![-\w])` zamiast `\b` NIE JEST OZDOBĄ: granica słowa wypada też
     * po łączniku, więc wzorzec na `height` łapie „min-height”, a wzorzec
     * na `width` — „max-width”. Test czytałby wtedy inną własność niż ta,
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

    public function test_pozycja_belki_uklada_sie_poziomo_przy_bardzo_duzym_tekscie(): void
    {
        $blok = $this->blokProguZ('.bottom-nav-item {');

        $this->assertSame(
            'row',
            $this->wartosc($blok, 'flex-direction'),
            'Pozycja belki wróciła do układu kolumnowego przy bardzo dużym tekście. '
            .'Ikona nad podpisem dodaje wtedy swoje 26 px do wysokości KAŻDEGO '
            .'wiersza belki, choć w poziomie nic nie kosztuje — zmierzone: '
            .'278,6 px zamiast 181 px przy 320 px i czcionce przeglądarki 200%.',
        );
    }

    public function test_minimum_wysokosci_pozycji_nie_podwaja_sie_z_czcionka_przegladarki(): void
    {
        $minimum = $this->wartosc($this->blokProguZ('.bottom-nav-item {'), 'min-height');

        $this->assertMatchesRegularExpression(
            '~^\d+(\.\d+)?px$~',
            $minimum,
            "Minimum wysokości pozycji belki to znowu „{$minimum}”, a nie wartość "
            .'w pikselach ekranu. Zapisane w `rem` podwaja się razem z korzeniem: '
            .'zmierzone 361 px belki zamiast 181 px, bo trzy wiersze dostają po '
            .'120 px minimum na treść, która potrzebuje 71,2 px. Minimum celu dla '
            .'palca jest fizyczne — palec nie rośnie od powiększenia czcionki.',
        );

        /*
         * ROZJAZD LICZBY Z TOKENEM — to jest jedyna cena zapisu w pikselach
         * i dlatego stoi tu asercja, a nie zdanie w komentarzu. Wartość ma
         * być DOKŁADNIE tym, czym `--control-height-touch` jest na zwykłym
         * telefonie, żeby ta poprawka niczego nie zmniejszała: ma tylko nie
         * pozwolić minimum rosnąć.
         */
        preg_match('~--control-height-touch:\s*([0-9.]+)rem~', $this->css('tokens.css'), $token);

        $this->assertNotEmpty(
            $token,
            'Token `--control-height-touch` nie jest już zapisany w `rem` — liczba '
            .'w `app.css` nie ma z czym być porównana.',
        );

        $this->assertSame(
            (float) $token[1] * 16.0,
            (float) rtrim($minimum, 'px'),
            "Minimum w belce ({$minimum}) rozjechało się z tokenem "
            .'`--control-height-touch` ('.$token[1].'rem przy korzeniu 16 px). '
            .'Jedna z tych dwóch liczb została zmieniona bez drugiej, więc na '
            .'zwykłym telefonie belka wygląda inaczej niż zakłada system '
            .'projektowy — albo poprawka #295 zaczęła zmniejszać kontrolkę.',
        );
    }

    public function test_kolko_glownej_akcji_nie_rosnie_z_czcionka_przegladarki(): void
    {
        $blok = $this->blokProguZ('.bottom-nav-kolko {');

        foreach (['width', 'height'] as $wlasnosc) {
            $this->assertSame(
                '48px',
                $this->wartosc($blok, $wlasnosc),
                "Kółko przy „Dodaj” ma znowu `{$wlasnosc}` inne niż 48 px w pikselach "
                .'ekranu. Zapisane w `rem` rośnie do zmierzonych 96 px wokół ikony '
                .'o 26 px i samo robi z wiersza „Dodaj” 135 px. 48 px to minimum '
                .'celu dla palca z AGENTS.md §5, a cel kliknięcia jest większy: '
                .'odnośnikiem jest cała pozycja (zmierzone 160 × 60 px).',
            );
        }
    }

    public function test_glowna_akcja_dostaje_wlasny_wiersz_zamiast_lamac_slowo(): void
    {
        $this->assertSame(
            '100%',
            $this->wartosc($this->blokProguZ('.bottom-nav-item-glowna {'), 'flex-basis'),
            'Główna akcja nie ma już własnego wiersza przy bardzo dużym tekście. '
            .'Przy 320 px zostaje jej wtedy 96 px na podpis („Dodaj” potrzebuje 105), '
            .'a reguła bazowa `overflow-wrap: anywhere` łamie słowo gdziekolwiek — '
            .'na zrzucie stało „Doda / j”.',
        );
    }

    /**
     * KONTROLA DODATNIA POPRAWKI — i ona jest tu ważniejsza od czterech
     * asercji wyżej.
     *
     * Belkę da się obniżyć czterema zakazanymi drogami: zmniejszyć pismo
     * podpisów, zostawić ikony bez podpisów, wyrzucić pozycje albo zdjąć
     * zawijanie. Każda z nich obniżyłaby liczbę w `scripts/dostepnosc.mjs`
     * i wyglądała jak naprawa. Ten test pilnuje, że zapłacono za nią samym
     * układem: ZWYKŁY telefon ma belkę bez jednej zmiany.
     */
    public function test_zwykly_telefon_ma_belke_bez_zmian(): void
    {
        $bazowa = $this->regulaBazowa('.bottom-nav-item {');

        $this->assertStringContainsString(
            'flex-direction: column',
            $bazowa,
            'Podpis przestał stać POD ikoną na zwykłym telefonie. Poprawka #295 '
            .'dotyczy wyłącznie progu `15rem`, czyli bardzo dużego tekstu.',
        );

        $this->assertStringContainsString(
            'font-size: var(--text-body)',
            $bazowa,
            'Podpis w belce zszedł z `--text-body` (18 px). To jest zakazana droga '
            .'do niskiej belki: podpis pod ikoną jest CAŁĄ treścią tej pozycji, '
            .'nie dopiskiem, więc obowiązuje go minimum produktowe (issue #110).',
        );

        $this->assertStringContainsString(
            'flex-wrap: wrap',
            $this->regulaBazowa('.bottom-nav {'),
            'Belka przestała zawijać. Bez zawijania podpisy wychodzą ze swoich '
            .'kolumn i nachodzą na siebie już przy skali 150% na 320 px (issue #80).',
        );

        /*
         * ŻADEN blok progu nie ma prawa ruszać pisma — także ten, którego
         * ten plik jeszcze nie zna. Pętla po wszystkich blokach, nie po
         * jednym, bo „niska belka” jest najłatwiej osiągalna dopisaniem
         * `font-size` w dowolnym z nich.
         */
        foreach ($this->blokiProgu() as $blok) {
            $this->assertStringNotContainsString(
                'font-size',
                $blok,
                'W bloku progu `'.self::PROG.'` pojawił się `font-size`. Przy bardzo '
                .'dużym tekście NICZEGO nie zmniejszamy — to jest ta wielkość pisma, '
                .'o którą człowiek poprosił przeglądarkę.',
            );
        }
    }

    public function test_belka_ma_nadal_piec_pozycji_z_podpisem_przy_kazdej_ikonie(): void
    {
        $html = $this->actingAs($this->user('basia'))->get(route('home'))->assertOk()->getContent();

        $start = strpos((string) $html, '<nav class="bottom-nav"');

        $this->assertNotFalse($start, 'Na tablicy nie ma już dolnej belki.');

        $koniec = strpos((string) $html, '</nav>', $start);

        $this->assertNotFalse($koniec);

        $belka = substr((string) $html, $start, $koniec - $start);

        preg_match_all('~<a\b[^>]*class="bottom-nav-item[^"]*"[^>]*>(.*?)</a>~s', $belka, $pozycje, PREG_SET_ORDER);

        $this->assertCount(
            5,
            $pozycje,
            'Dolna belka nie ma już pięciu pozycji. Najprostsza droga do niskiej '
            .'belki to wyrzucenie z niej pozycji — i jest zamknięta: nawigacja '
            .'mobilna ma pięć pozycji (AGENTS.md §5), a na telefonie nie ma '
            .'nawigacji bocznej, z której dałoby się je odzyskać.',
        );

        foreach ($pozycje as $pozycja) {
            $podpis = trim(html_entity_decode(strip_tags((string) $pozycja[1])));

            $this->assertNotSame(
                '',
                $podpis,
                'Pozycja w dolnej belce została bez podpisu, z samą ikoną: '
                .$pozycja[0].'. Ikona nigdy nie jest jedynym opisem ważnej akcji '
                .'(AGENTS.md §5) — i jest to zakazana droga do niskiej belki.',
            );
        }
    }

    /**
     * Reguła spod podanego selektora — pierwsze wystąpienie POZA blokami
     * progu, czyli reguła bazowa dla zwykłego telefonu.
     */
    private function regulaBazowa(string $selektor): string
    {
        /*
         * CZYTAMY ARKUSZ Z WYCIĘTYMI BLOKAMI PROGU, a nie „pierwsze
         * trafienie". Reguła bazowa i jej nadpisanie za progiem mają ten sam
         * selektor, więc test szukający po nazwie trafiłby raz w jedną, raz
         * w drugą — zależnie od kolejności w pliku, czyli od czegoś, co
         * następna edycja może zmienić bez związku z tym testem.
         */
        $css = $this->css();

        foreach (array_reverse($this->zakresyProgu()) as $blok) {
            $css = substr_replace($css, '', $blok['od'], $blok['do'] - $blok['od'] + 1);
        }

        $start = strpos($css, $selektor);

        $this->assertNotFalse(
            $start,
            "Poza blokami progu nie ma już reguły `{$selektor}` — została tylko "
            .'wersja dla bardzo dużego tekstu, czyli zwykły telefon stracił swoją.',
        );

        $koniec = strpos($css, '}', $start);

        $this->assertNotFalse($koniec);

        return substr($css, $start, $koniec - $start);
    }
}

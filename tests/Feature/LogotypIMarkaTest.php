<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Logotyp „KuKing.pl" i znak „Uśmiech" (UI kit v2, decyzje D-015 i właściciela).
 *
 * PO CO TESTOWAĆ WYGLĄD
 * Nie testujemy tu tego, czy logo jest ładne — tego nie da się sprawdzić
 * asercją. Testujemy rzeczy, które CICHO SIĘ PSUJĄ i których nikt nie zauważy
 * przy przeglądaniu strony na jasnym motywie:
 *
 *  * znak wklejony wprost w HTML kontra znak w <img>. SVG załadowany przez
 *    <img> jest OSOBNYM dokumentem: nie dziedziczy `currentColor` ani zmiennych
 *    CSS strony. Pierwsza wersja tej zmiany renderowała się przez to na czarno
 *    w trybie ciemnym. „Wygląda dobrze u mnie" nie jest dowodem, bo nikt nie
 *    ogląda strony w obu motywach po każdej zmianie;
 *  * czytnik ekranu czytający nazwę dwa razy — znak i napis obok siebie;
 *  * font bez polskich znaków. Podzbiór `latin` kończy się na U+00FF, więc
 *    „ó" jest, a „ł ą ę ć ń ś ź ż" NIE. Strona wygląda wtedy poprawnie
 *    dopóki ktoś nie napisze „żurek".
 */
class LogotypIMarkaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Treść pliku BEZ komentarzy.
     *
     * Komentarze w tych plikach celowo cytują to, czego w kodzie być nie może
     * („kolory nie mogą być `currentColor`, bo…"). Asercja na surowej treści
     * trafia wtedy we własne uzasadnienie naprawy i oblewa. Pierwsza wersja
     * dwóch testów niżej robiła dokładnie to.
     */
    private function bezKomentarzy(string $tresc): string
    {
        // Kolejność ma znaczenie: najpierw komentarze HTML/SVG (mogą zawierać
        // ukośniki), potem blokowe CSS.
        $tresc = (string) preg_replace('~<!--.*?-->~s', '', $tresc);

        return (string) preg_replace('~/\*.*?\*/~s', '', $tresc);
    }

    public function test_belka_pokazuje_logotyp_kuking_pl(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        // Rozbicie na dwa elementy jest częścią logotypu: „.pl" ma kolor marki.
        $this->assertStringContainsString('KuKing<span class="wordmark-tld">.pl</span>', $html);
    }

    public function test_stary_zapis_wersalikami_zniknal(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        // Pytamy o KONKRETNY element logotypu, nie o słowo „KUKING" gdziekolwiek
        // na stronie — to drugie trafiałoby w teksty marketingowe i w tablicę
        // „kuKINGi na dziś", która ma zostać (D-009).
        $this->assertStringNotContainsString('wordmark-king', $html);
        $this->assertStringNotContainsString('KU<span', $html);
    }

    public function test_znak_jest_wklejony_wprost_a_nie_przez_img(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        // SEDNO: <svg> wewnątrz linku logotypu. Gdyby ktoś „uprościł" to
        // z powrotem do <img src="icons/kuking-mark.svg">, znak przestałby
        // dziedziczyć kolor i w trybie ciemnym byłby czarny na czarnym.
        $this->assertMatchesRegularExpression(
            '~<a class="wordmark".*?<svg class="kuking-mark"~s',
            $html,
            'Znak w belce nie jest wklejony wprost — w trybie ciemnym straci kolor.',
        );

        $this->assertStringContainsString('fill="currentColor"', $html);
    }

    public function test_znak_w_belce_jest_niemy_dla_czytnika_ekranu(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        // Znak stoi OBOK napisu „KuKing.pl". Gdyby miał własną etykietę,
        // czytnik przeczytałby nazwę serwisu dwa razy pod rząd.
        $this->assertMatchesRegularExpression(
            '~<svg class="kuking-mark"[^>]*aria-hidden="true"~',
            $html,
            'Znak w belce nie jest wyciszony — czytnik przeczyta nazwę dwa razy.',
        );

        // A cała nazwa jest podana czytnikowi RAZ, jako jeden napis.
        $this->assertStringContainsString('Kuking — strona główna', $html);
    }

    public function test_font_obejmuje_polskie_znaki(): void
    {
        $css = file_get_contents(resource_path('css/fonts.css'));

        // Bez podzbioru `latin-ext` przeglądarka podmienia same litery
        // z ogonkami na font zastępczy i słowo „żurek" ma trzy różne kroje.
        $this->assertStringContainsString('inter-latin-ext-wght-normal.woff2', $css);
        $this->assertStringContainsString('inter-latin-wght-normal.woff2', $css);

        $this->assertFileExists(resource_path('fonts/inter-latin-ext-wght-normal.woff2'));
        $this->assertFileExists(resource_path('fonts/inter-latin-wght-normal.woff2'));

        // Licencja SIL OFL wymaga, żeby tekst licencji szedł razem z plikami.
        $this->assertFileExists(resource_path('fonts/LICENSE-inter.txt'));
    }

    public function test_tekst_jest_widoczny_zanim_font_sie_pobierze(): void
    {
        $css = file_get_contents(resource_path('css/fonts.css'));
        $tokeny = file_get_contents(resource_path('css/tokens.css'));

        // `font-display: swap` to różnica między „strona się ładuje"
        // a „strona jest pusta" na wolnym łączu. Przy 133 kB fontu i grupie,
        // która bywa na LTE na wsi, to nie jest szczegół.
        $this->assertSame(
            2,
            substr_count($this->bezKomentarzy($css), 'font-display: swap'),
            'Któraś deklaracja @font-face nie ma font-display: swap.',
        );

        // Fallback musi być prawdziwym stosem systemowym, a nie samym
        // `sans-serif` — to jego widzi człowiek przez pierwsze sekundy.
        $this->assertStringContainsString('"Inter Variable", -apple-system', $tokeny);
    }

    public function test_samodzielny_znak_ma_kolory_wpisane_wprost(): void
    {
        $svg = $this->bezKomentarzy(file_get_contents(public_path('icons/kuking-mark.svg')));

        // Ten plik ładują favicon, service worker i zastępcze zdjęcie —
        // wszędzie tam SVG jest osobnym dokumentem i `currentColor` znaczy
        // czerń. Kolory MUSZĄ tu być wpisane wprost. To jest odwrotna zasada
        // niż w komponencie i łatwo je pomylić.
        $this->assertStringContainsString('#B3401F', $svg);
        $this->assertStringNotContainsString('currentColor', $svg);

        // Favicon w ciemnym motywie ma brać jaśniejszy odcień — `prefers-color-scheme`
        // jest preferencją systemu, więc dociera także do osobnego dokumentu SVG.
        $this->assertStringContainsString('prefers-color-scheme: dark', $svg);
    }

    public function test_favicon_ico_pokazuje_nowy_znak(): void
    {
        // `/favicon.ico` jest pobierany BEZWARUNKOWO i przez rzeczy, które
        // o naszym <head> nic nie wiedzą: wyniki wyszukiwania, czytniki
        // kanałów, podglądy w komunikatorach. Plik już istniał, więc po
        // zmianie znaku pokazywałby poprzednie logo w miejscach, w których
        // nikt by go nie szukał.
        $ico = file_get_contents(public_path('favicon.ico'));

        $this->assertNotFalse($ico);

        // Nagłówek ICO: 2 bajty zarezerwowane, 2 bajty typu (1 = ikona),
        // 2 bajty liczby obrazków.
        $naglowek = unpack('vzarezerwowane/vtyp/vile', substr($ico, 0, 6));

        $this->assertSame(1, $naglowek['typ'], 'To nie jest plik ikony.');
        $this->assertSame(2, $naglowek['ile'], 'Favikona nie ma obu rozmiarów, 16 i 32 px.');

        $rozmiary = [];

        for ($i = 0; $i < $naglowek['ile']; $i++) {
            $wpis = unpack('Cszerokosc/Cwysokosc', substr($ico, 6 + $i * 16, 2));
            $rozmiary[] = $wpis['szerokosc'];
        }

        $this->assertSame([16, 32], $rozmiary);
    }

    public function test_wariant_jednobarwny_nadaje_sie_na_wydruk(): void
    {
        $mono = $this->bezKomentarzy(file_get_contents(public_path('icons/kuking-mark-mono.svg')));

        // Tu `currentColor` jest POPRAWNY — odwrotnie niż w kuking-mark.svg.
        // Ten plik idzie do programów graficznych i na drukarkę, gdzie kolor
        // ustawia człowiek. Łatwo pomylić te dwie zasady, stąd osobny test.
        $this->assertStringContainsString('currentColor', $mono);
        $this->assertStringNotContainsString('#B3401F', $mono);

        // Bez połysku i uśmiechu: przy jednym kolorze te linie zlewają się
        // z tłem garnka i znak robi się plamą. Naklejka ma 20 mm.
        $this->assertStringNotContainsString('M24 43 C28 47 36 47 40 41', $mono);
    }

    public function test_karta_do_udostepniania_ma_wlasciwe_proporcje(): void
    {
        $png = public_path('icons/kuking-udostepnianie.png');

        $this->assertFileExists($png);

        [$szerokosc, $wysokosc] = getimagesize($png);

        // 1200×630 to format, na którym Facebook, WhatsApp i Signal pokazują
        // DUŻY podgląd. Przy innych proporcjach przycinają obrazek po swojemu
        // i napis wychodzi poza kadr.
        $this->assertSame(1200, $szerokosc);
        $this->assertSame(630, $wysokosc);
    }

    public function test_wszystkie_ikony_pokazuja_ten_sam_znak(): void
    {
        // Ikona PWA rozjechana ze znakiem na stronie to najbardziej
        // zawstydzający możliwy błąd: człowiek dodaje Kuking do ekranu
        // głównego i dostaje poprzednie logo. Wszystkie warianty wychodzą
        // z jednego generatora (scripts/generuj-ikony.mjs), więc pilnujemy
        // charakterystycznego fragmentu ścieżki korony.
        $korona = 'M18 22 L16 15 L24 19 L32 11 L40 19 L48 15 L46 22';

        foreach ([
            'kuking-mark.svg',
            'kuking-mark-mono.svg',
            'kuking-icon-any.svg',
            'kuking-icon-maskable.svg',
        ] as $plik) {
            $this->assertStringContainsString(
                $korona,
                file_get_contents(public_path('icons/'.$plik)),
                "Ikona {$plik} pokazuje inny znak niż reszta.",
            );
        }
    }
}

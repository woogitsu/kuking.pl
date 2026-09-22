<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gość dostaje prawą szynę OBOK treści, nie pod nią (D-122).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * `resources/css/app.css` zwijał układ niezalogowanego do JEDNEJ kolumny
 * 768 px na każdej szerokości (`--container-strona-solo`), a uzasadniał to
 * komentarzem „Gość nie ma nawigacji bocznej ANI SZYNY". Pierwsza połowa
 * jest prawdą; druga była prawdą jeden dzień. Komentarz powstał 6 września
 * 2026, a 7 września `/szukaj` dostało `<x-slot:rail>`; 10 września doszły
 * `/napisz-do-nas` i `/@nazwa` (#231).
 *
 * Skutek zgłosił właściciel: „niektóre podstrony jak napisz do nas jest
 * bardzo wąskie, gdzie po prawej i lewej można coś dodać na kompie". Osoba,
 * która pisze „nie mogę się zalogować" z komputera, miała blok „Nie możesz
 * się zalogować" POD CAŁYM formularzem, na stronie szerokiej na 768 px przy
 * monitorze 1920 px — czyli podpowiedź, po którą przyszła, leżała poza
 * pierwszym ekranem.
 *
 * CZEGO TEN TEST PILNUJE, A CZEGO NIE UMIE
 * Umie sprawdzić, że ekran gościa Z TREŚCIĄ w szynie dostaje klasę układu
 * dwukolumnowego, że ekran BEZ szyny jej nie dostaje, i że arkusz ma dla tej
 * klasy prawdziwe dwie kolumny z własnym sufitem szerokości. NIE UMIE
 * sprawdzić, że na ekranie naprawdę widać dwie kolumny — to mierzy dopiero
 * `scripts/dostepnosc.mjs` (sekcja „Wyrównanie belki do siatki treści"
 * i pomiar szerokości treści), bo HTML jest poprawny w obu przypadkach,
 * a psuje się wyłącznie obraz w przeglądarce (ta sama granica co przy D-091).
 *
 * NIEZMIENNIK, NIE LISTA ADRESÓW
 * Widoków publicznych z szyną jest dziś trzy. Czwarty dojdzie bez czytania
 * tego pliku, więc test nie wypisuje ich z ręki: bierze WSZYSTKIE widoki
 * z `<x-slot:rail>`, wybiera te, które otwierają się bez logowania, i żąda
 * od każdego tej samej reguły.
 */
class SzynaGosciaTest extends TestCase
{
    use RefreshDatabase;

    /** Klasa siatki dla gościa na ekranie z szyną. */
    private const KLASA_UKLADU = 'app-body-solo-z-szyna';

    /** Klasa na <body> — steruje szerokością belki i stopki. */
    private const KLASA_BELKI = 'uklad-solo-z-szyna';

    /** @return array<string, string> nazwa widoku => klasy z atrybutu `.app-body` */
    private function klasyUkladu(string $html): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new \DOMXPath($dom);
        $body = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' app-body ')]")->item(0);

        $this->assertNotNull($body, 'W dokumencie nie ma elementu .app-body — układ strony się zmienił.');

        return ' '.preg_replace('/\s+/', ' ', (string) $body->getAttribute('class')).' ';
    }

    private function klasyBelki(string $html): string
    {
        $this->assertSame(
            1,
            preg_match('/<body class="([^"]*)"/', $html, $trafienie),
            'W dokumencie nie ma <body class="...">.',
        );

        return ' '.preg_replace('/\s+/', ' ', $trafienie[1]).' ';
    }

    // --- SEDNO ZGŁOSZENIA ------------------------------------------------

    public function test_napisz_do_nas_daje_gosciowi_kolumne_na_szyne(): void
    {
        $html = (string) $this->get(route('kontakt'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'Nie możesz się zalogować',
            $html,
            'Zniknęła podpowiedź, dla której cała ta zmiana powstała.',
        );

        $this->assertStringContainsString(
            ' '.self::KLASA_UKLADU.' ',
            $this->klasyUkladu($html),
            'Gość na „Napisz do nas" nie dostaje kolumny na prawą szynę — blok '
            .'„Nie możesz się zalogować" ląduje pod całym formularzem, a strona '
            .'zostaje wąska na 768 px przy monitorze 1920 px (zgłoszenie właściciela).',
        );

        $this->assertStringContainsString(
            ' '.self::KLASA_BELKI.' ',
            $this->klasyBelki($html),
            'Belka i stopka zostały przy wąskim suficie — logotyp stanąłby '
            .'192 px na prawo od pierwszego słowa treści.',
        );
    }

    /**
     * TEN SAM ADRES DLA GOŚCIA I DLA ZALOGOWANEGO TO DWA RÓŻNE EKRANY
     * (D-099, D-106). Zalogowany ma tu nawigację boczną i trzy kolumny
     * z reguły `.app-body`, więc klas gościa dostać NIE MOŻE — inaczej
     * jego treść zjechałaby w lewo o szerokość menu.
     */
    public function test_zalogowany_nie_dostaje_klas_ukladu_goscia(): void
    {
        $html = (string) $this->actingAs($this->user('zalogowany_kontakt'))
            ->get(route('kontakt'))->assertOk()->getContent();

        $klasy = $this->klasyUkladu($html);

        $this->assertStringNotContainsString(' '.self::KLASA_UKLADU.' ', $klasy);
        $this->assertStringNotContainsString(' app-body-solo ', $klasy);
        $this->assertStringNotContainsString(' '.self::KLASA_BELKI.' ', $this->klasyBelki($html));
    }

    // --- NIEZMIENNIK NA WSZYSTKICH PUBLICZNYCH WIDOKACH Z SZYNĄ ----------

    /**
     * Publiczne widoki podające `<x-slot:rail>`, wyszukane w katalogu
     * widoków — nie wypisane z ręki, żeby czwarty taki ekran nie wypadł
     * z pomiaru w dniu, w którym powstanie.
     *
     * @return array<string, string> ścieżka pliku widoku => adres do otwarcia
     */
    private function publiczneEkranyZSzyna(): array
    {
        $adresy = [
            'pages/napisz-do-nas.blade.php' => '/napisz-do-nas',
            'pages/search.blade.php' => '/szukaj?q=zupa',
            'pages/profile/show.blade.php' => '/@zszyna',
        ];

        $znalezione = [];

        $pliki = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views/pages'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($pliki as $plik) {
            if (! str_ends_with($plik->getFilename(), '.blade.php')) {
                continue;
            }

            $zrodlo = (string) file_get_contents($plik->getPathname());
            if (! str_contains($zrodlo, '<x-slot:rail>') && ! str_contains($zrodlo, 'class="marka-profil-szyna"')) {
                continue;
            }

            $wzgledna = str_replace(resource_path('views').'/', '', $plik->getPathname());

            // Ekrany za logowaniem sprawdza `SzynaKolejneEkranyTest`; tutaj
            // chodzi o gościa. Rozstrzyga o tym próba wejścia bez konta.
            if ($this->get($adresy[$wzgledna] ?? '/__nie_ma__')->getStatusCode() !== 200) {
                continue;
            }

            $znalezione[$wzgledna] = $adresy[$wzgledna];
        }

        return $znalezione;
    }

    public function test_kazdy_publiczny_ekran_z_szyna_ma_kolumne_na_nia(): void
    {
        // Profil musi mieć czym wypełnić szynę — dla gościa są to tagi z wpisów
        // (bloku z liczbami gość nie dostaje, patrz `szyna-profilu`).
        $osoba = $this->user('zszyna');
        $wpis = Post::factory()->create(['author_id' => $osoba->getKey(), 'visibility' => 'public']);
        $wpis->tags()->attach(Tag::factory()->create(['name' => 'Zupa dnia']));

        $ekrany = $this->publiczneEkranyZSzyna();

        $this->assertGreaterThanOrEqual(
            3,
            count($ekrany),
            'Publicznych widoków z `<x-slot:rail>` miało być co najmniej trzy '
            .'(/szukaj, /napisz-do-nas, /@nazwa). Albo któryś zniknął, albo ten '
            .'test przestał je znajdować i nie sprawdza już nic.',
        );

        foreach ($ekrany as $widok => $adres) {
            $html = (string) $this->get($adres)->assertOk()->getContent();

            if ($widok === 'pages/profile/show.blade.php') {
                $this->assertStringContainsString(' app-body-tresc-z-szyna ', $this->klasyUkladu($html));
                $this->assertStringContainsString('marka-profil-dol-z-szyna', $html);
                $this->assertStringContainsString('<aside class="marka-profil-szyna"', $html);

                continue;
            }

            $this->assertStringContainsString(
                ' '.self::KLASA_UKLADU.' ',
                $this->klasyUkladu($html),
                "Widok {$widok} podaje szynę, a gość dostaje na nim jedną kolumnę — "
                .'szyna zjeżdża pod treść nawet przy 1920 px.',
            );
        }
    }

    /**
     * Widok publiczny BEZ szyny zostaje przy jednej, wyśrodkowanej kolumnie.
     *
     * To jest druga połowa decyzji D-122 i nie jest oczywista: zalogowany ma
     * trzecią kolumnę zarezerwowaną NAWET bez szyny (issue #294), żeby jego
     * nawigacja boczna nie przeskakiwała między podstronami. Gość nawigacji
     * bocznej nie ma, więc rezerwacja nie trzymałaby niczego w miejscu —
     * dołożyłaby tylko pustą kolumnę szeroką na 352 px, a pusta kolumna
     * wygląda na usterkę układu.
     *
     * `discover` WYPISAŁO SIĘ Z TEJ LISTY 11 WRZEŚNIA i to nie jest luka
     * w pokryciu. „Świeżo z Kuking" używa dziś kolumny szyny — nie przez
     * `<x-slot:rail>`, tylko od środka `<main>`, tak samo jak strona przepisu
     * (`OdkrywanieUzywaKolumnySzynyTest`, `.odkryj-uklad`). Zdanie „ten ekran
     * nie ma szyny" przestało więc być o nim prawdziwe, a lista, która
     * zostaje po zmianie produktu, tłumaczy regułę, której już nie uzasadnia
     * — dokładnie tak jak komentarz naprostowany przez D-122 wyżej.
     */
    public function test_ekran_goscia_bez_szyny_zostaje_przy_jednej_kolumnie(): void
    {
        foreach (['login', 'register', 'help'] as $trasa) {
            $html = (string) $this->get(route($trasa))->assertOk()->getContent();

            $klasy = $this->klasyUkladu($html);

            $this->assertStringContainsString(' app-body-solo ', $klasy, "Trasa „{$trasa}”.");
            $this->assertStringNotContainsString(
                ' '.self::KLASA_UKLADU.' ',
                $klasy,
                "Trasa „{$trasa}” nie ma szyny, a dostała na nią kolumnę — po prawej "
                .'stronie treści stoi 352 px pustki.',
            );
        }
    }

    /**
     * SLOT PODANY I PUSTY TO NADAL BRAK SZYNY.
     *
     * `x-szyna-profilu` na CUDZYM profilu oglądanym przez gościa potrafi nie
     * wypisać ani jednego bloku: blok z liczbami stoi pod `@auth`, a tagów
     * ani publicznych zeszytów ta osoba mieć nie musi. Gdyby o układzie
     * decydowało samo `isset($rail)`, gość dostałby wtedy pustą kolumnę.
     */
    public function test_pusta_szyna_nie_daje_pustej_kolumny(): void
    {
        $this->user('bezniczego');

        $html = (string) $this->get('/@bezniczego')->assertOk()->getContent();
        $this->assertStringNotContainsString('marka-profil-dol-z-szyna', $html);
        $this->assertStringNotContainsString('<aside class="marka-profil-szyna"', $html);

        $this->assertStringNotContainsString(
            ' '.self::KLASA_UKLADU.' ',
            $this->klasyUkladu($html),
            'Profil bez tagów i bez publicznych zeszytów nie ma czym wypełnić '
            .'szyny, a gość dostał na nią kolumnę.',
        );
    }

    // --- ARKUSZ ----------------------------------------------------------

    /**
     * Klasy w HTML-u bez reguł w arkuszu nie robią nic. Test czyta więc
     * `app.css` i `tokens.css` — tak samo jak `UkladGosciaTest` czyta z nich
     * listę układów jednokolumnowych.
     */
    public function test_arkusz_daje_tej_klasie_dwie_kolumny_i_wlasna_szerokosc(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $tokeny = (string) file_get_contents(resource_path('css/tokens.css'));

        $this->assertSame(
            1,
            preg_match('/\.'.self::KLASA_UKLADU.'\s*\{([^}]*)\}/', $css, $regula),
            'W `app.css` nie ma reguły dla `.'.self::KLASA_UKLADU.'` — klasa w HTML-u '
            .'jest wtedy samym napisem.',
        );

        $this->assertMatchesRegularExpression(
            '/grid-template-columns:\s*minmax\(\s*0\s*,\s*1fr\s*\)\s+var\(--container-rail\)\s*;/',
            $regula[1],
            'Układ gościa z szyną ma mieć DWIE kolumny: treść i szynę.',
        );

        $this->assertMatchesRegularExpression(
            '/max-width:\s*var\(--container-strona-solo-z-szyna\)\s*;/',
            $regula[1],
            'Bez własnego sufitu szerokości siatka zostaje przy 768 px i druga '
            .'kolumna nie ma z czego powstać.',
        );

        $this->assertMatchesRegularExpression(
            '/--container-strona-solo-z-szyna:\s*calc\(/',
            $tokeny,
            'Brakuje tokenu `--container-strona-solo-z-szyna` — reguła wyżej '
            .'odwołuje się wtedy do zmiennej, której nie ma.',
        );

        $this->assertMatchesRegularExpression(
            '/\.'.self::KLASA_BELKI.'\s+\.topbar-inner,\s*\.'.self::KLASA_BELKI.'\s+\.site-footer-inner\s*\{[^}]*--container-strona-solo-z-szyna/',
            $css,
            'Belka i stopka muszą wziąć tę samą szerokość co treść — dwie liczby '
            .'na jedną krawędź to rozjazd, którego nikt potem nie umie wytłumaczyć.',
        );
    }

    /**
     * KOLEJNOŚĆ REGUŁ, NIE WAGA SELEKTORA.
     *
     * `.app-body-solo` i `.app-body-solo-z-szyna` mają tę samą specyficzność
     * (0,1,0) i obie stoją w bloku `@media (min-width: 80rem)`. Gdyby ta
     * druga trafiła WYŻEJ, jedna kolumna z `.app-body-solo` wygrałaby
     * kolejnością i cała poprawka zniknęłaby bez śladu w HTML-u — czyli
     * dokładnie tak, że żaden test patrzący na dokument by tego nie zauważył.
     */
    public function test_regula_z_szyna_stoi_w_arkuszu_ponizej_reguly_bez_szyny(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $prog = strpos($css, '@media (min-width: 80rem)');
        $this->assertIsInt($prog, 'W arkuszu nie ma bloku `@media (min-width: 80rem)`.');

        $bezSzyny = strpos($css, '.app-body-solo {', $prog);
        $zSzyna = strpos($css, '.'.self::KLASA_UKLADU.' {', $prog);

        $this->assertIsInt($bezSzyny, 'Za progiem 80rem nie ma reguły `.app-body-solo`.');
        $this->assertIsInt($zSzyna, 'Za progiem 80rem nie ma reguły `.'.self::KLASA_UKLADU.'`.');

        $this->assertGreaterThan(
            $bezSzyny,
            $zSzyna,
            'Reguła układu z szyną stoi NAD regułą bez szyny — przy równej '
            .'specyficzności wygrywa ta niżej, więc gość znów dostałby jedną kolumnę.',
        );
    }
}

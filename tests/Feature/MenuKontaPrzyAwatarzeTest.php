<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CzytaWidoczneNapisy;
use Tests\TestCase;

/**
 * Menu konta przy awatarze w pasku górnym — issue #344.
 *
 * CO BYŁO ŹLE
 * Awatar w pasku górnym był zwykłym odnośnikiem na własny profil, z klasą
 * `topbar-desktop-only` i podpisem schowanym w `visually-hidden`. Na telefonie
 * nie było go więc wcale, a `.side-nav` — jedyne miejsce z „Ustawieniami"
 * i „Wyloguj się" — ma tam `display: none`. Pasek dolny niesie pięć pozycji
 * i szóstej mieć nie może (AGENTS.md §5). Obsługa konta stała na telefonie
 * wyłącznie na WŁASNYM PROFILU (D-168), czyli trzeba było wiedzieć, że się
 * tam chowa.
 *
 * CZEGO PILNUJE TEN PLIK
 *  1. Menu jest `<details>`, czyli otwiera się bez JavaScriptu — i nie jest
 *     schowane przed telefonem.
 *  2. Przycisk menu ma WIDOCZNY napis, nie sam awatar (AGENTS.md §5).
 *  3. Menu ma dokładnie trzy pozycje i każda prowadzi do istniejącej trasy
 *     (D-053).
 *  4. Wylogowanie zostaje POST-em z tokenem CSRF, także w menu.
 *  5. Gość nie widzi ani jednego śladu tego menu.
 *  6. Menu stoi na KAŻDYM ekranie, nie tylko na własnym profilu — to jest
 *     cała różnica względem stanu z D-168.
 *
 * DLACZEGO ASERCJE SĄ ZAWĘŻONE DO PASKA GÓRNEGO
 * „Ustawienia" i formularz wylogowania są w dokumencie także w `.side-nav`,
 * która renderuje się ZAWSZE, również na telefonie (chowa ją wyłącznie CSS),
 * a „Mój profil" — w pasku dolnym. Asercja po całym dokumencie przechodziłaby
 * więc nawet wtedy, gdyby menu nie istniało w ogóle (D-164, pułapka 1
 * z `docs/PULAPKI_TESTOW.md`). Wycinamy `<header class="topbar">`.
 */
class MenuKontaPrzyAwatarzeTest extends TestCase
{
    use CzytaWidoczneNapisy;
    use RefreshDatabase;

    public function test_menu_otwiera_sie_bez_javascriptu_i_nie_jest_schowane_przed_telefonem(): void
    {
        [$xpath, $pasek] = $this->pasekGorny($this->stronaZalogowanego('menu_bezjs'));

        $menu = $xpath->query(".//details[contains(concat(' ', normalize-space(@class), ' '), ' topbar-konto ')]", $pasek)->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $menu,
            'W pasku górnym nie ma `<details class="topbar-konto">`. Menu zbudowane na skrypcie '.
            'byłoby na telefonie bez zasięgu martwym przyciskiem — a to jedyne wejście '.
            'do Ustawień i do wylogowania (D-053).',
        );

        $this->assertSame(
            1,
            $xpath->query('.//summary', $menu)->length,
            'Menu nie ma `<summary>`, czyli nie ma czym się otworzyć bez skryptu.',
        );

        $this->assertStringNotContainsString(
            'topbar-desktop-only',
            $menu->getAttribute('class'),
            'Menu konta wróciło do `topbar-desktop-only`, czyli zniknęło z telefonu. '.
            'Tam `.side-nav` ma `display: none`, a pasek dolny jest pełny — nie zostaje '.
            'żadne wejście do obsługi konta.',
        );
    }

    public function test_przycisk_menu_ma_widoczny_napis_a_nie_sam_awatar(): void
    {
        [$xpath, $pasek] = $this->pasekGorny($this->stronaZalogowanego('menu_napis'));

        $summary = $xpath->query(".//details[contains(concat(' ', normalize-space(@class), ' '), ' topbar-konto ')]/summary", $pasek)->item(0);
        $this->assertInstanceOf(DOMElement::class, $summary);

        /*
         * `assertStringContainsString`, a NIE `assertSame`: przy koncie bez
         * zdjęcia awatar renderuje się jako sama litera w `<span class="avatar"
         * aria-hidden="true">`, więc widoczny tekst przycisku brzmi na przykład
         * „T Konto". Ta litera jest właśnie tym, co ZASTĘPUJE obrazek —
         * i dokładnie dlatego nie może być jedynym opisem przycisku.
         *
         * Asercja oblewa, gdy napis zniknie ALBO gdy ktoś schowa go
         * w `visually-hidden`: `widocznyNapis()` zdejmuje taki tekst, bo
         * „ikona nigdy nie jest sama" mówi o tym, co WIDAĆ, a nie o tym, co
         * słychać (AGENTS.md §5).
         */
        $this->assertStringContainsString(
            'Konto',
            $this->widocznyNapis($summary),
            'Przycisk menu nie ma widocznego napisu. Sam awatar — nawet z podpisem '.
            'w `visually-hidden` — jest ikoną bez opisu, a przy koncie bez zdjęcia w ogóle '.
            'samą literą (AGENTS.md §5).',
        );

        // KONTROLA DODATNIA: litera z awatara to za mało. Napis ma być osobnym
        // elementem, a nie przypadkowym tekstem, który akurat wpadł do środka.
        $this->assertSame(
            1,
            $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' topbar-konto-napis ')]", $summary)->length,
            'Napis „Moje konto" przestał być osobnym elementem przycisku.',
        );

        // WCAG 2.5.3 (Label in Name): nazwa dostępna ma ZAWIERAĆ to, co widać,
        // a nie zastępować to czymś innym.
        $this->assertStringContainsString(
            'Konto',
            $summary->getAttribute('aria-label'),
            'Etykieta dla czytnika ekranu nie zawiera widocznego napisu — osoba sterująca '.
            'głosem powie „Moje konto" i nic się nie stanie.',
        );
    }

    public function test_menu_ma_trzy_pozycje_i_zadna_nie_jest_martwa(): void
    {
        $czlowiek = $this->user('menu_trzy');

        [$xpath, $pasek] = $this->pasekGorny($this->stronaZalogowanego($czlowiek));

        $tresc = $xpath->query(".//ul[contains(concat(' ', normalize-space(@class), ' '), ' topbar-konto-tresc ')]", $pasek)->item(0);
        $this->assertInstanceOf(DOMElement::class, $tresc, 'Menu nie ma treści.');

        $this->assertSame(
            3,
            $xpath->query('./li', $tresc)->length,
            'Menu konta ma inną liczbę pozycji niż trzy. Zgłoszenie właściciela mówi '.
            'dokładnie o trzech: „mój profil, ustawienia i wyloguj się".',
        );

        $oczekiwane = [
            'Mój profil' => route('profile.show', $czlowiek->profile->username),
            'Ustawienia' => route('settings.index'),
        ];

        foreach ($oczekiwane as $napis => $adres) {
            $odnosniki = $this->odnosnikiPoWidocznymNapisie($xpath, $tresc, $napis);

            $this->assertCount(1, $odnosniki, "W menu konta nie ma widocznego napisu „{$napis}\".");
            $this->assertSame($adres, $odnosniki[0]->getAttribute('href'), "Pozycja „{$napis}\" prowadzi gdzie indziej.");

            // Nie martwy przycisk (D-053): wchodzimy pod adres Z GOTOWEJ
            // STRONY i dostajemy ekran, a nie 403 ani 404.
            $this->actingAs($czlowiek)->get($odnosniki[0]->getAttribute('href'))->assertOk();
        }

        $wyjscie = $xpath->query(".//button[normalize-space(.)='Wyloguj się']", $tresc)->item(0);
        $this->assertInstanceOf(
            DOMElement::class,
            $wyjscie,
            'W menu konta nie ma widocznego „Wyloguj się".',
        );
    }

    public function test_wylogowanie_w_menu_zostaje_postem_z_tokenem(): void
    {
        [$xpath, $pasek] = $this->pasekGorny($this->stronaZalogowanego('menu_post'));

        $tresc = $xpath->query(".//ul[contains(concat(' ', normalize-space(@class), ' '), ' topbar-konto-tresc ')]", $pasek)->item(0);
        $this->assertInstanceOf(DOMElement::class, $tresc);

        $formularz = $xpath->query(".//form[contains(@action, '/logout')]", $tresc)->item(0);
        $this->assertInstanceOf(DOMElement::class, $formularz, 'W menu konta nie ma formularza wylogowania.');

        $this->assertSame('POST', mb_strtoupper($formularz->getAttribute('method')));
        $this->assertSame(
            1,
            $xpath->query(".//input[@name='_token']", $formularz)->length,
            'Formularz wylogowania w menu stracił token CSRF.',
        );

        $this->assertSame(
            0,
            $xpath->query(".//a[contains(@href, '/logout')]", $pasek)->length,
            'Wylogowanie w pasku górnym stało się odnośnikiem GET — wyloguje człowieka podgląd '.
            'linku albo prefetch przeglądarki. W menu, obok dwóch zwykłych odnośników, jest to '.
            'najłatwiejsza zamiana „dla spójności wyglądu".',
        );
    }

    public function test_menu_stoi_na_kazdym_ekranie_a_nie_tylko_na_wlasnym_profilu(): void
    {
        $czlowiek = $this->user('menu_wszedzie');
        $ktos = $this->user('menu_ktos_inny');

        $ekrany = [
            'tablica' => route('home'),
            'cudzy profil' => route('profile.show', $ktos->profile->username),
            'ustawienia' => route('settings.index'),
        ];

        foreach ($ekrany as $nazwa => $adres) {
            $html = (string) $this->actingAs($czlowiek)->get($adres)->assertOk()->getContent();

            [$xpath, $pasek] = $this->pasekGorny($html);

            $this->assertSame(
                1,
                $xpath->query(".//details[contains(concat(' ', normalize-space(@class), ' '), ' topbar-konto ')]", $pasek)->length,
                "Ekran „{$nazwa}\" nie ma menu konta w pasku górnym. Na telefonie oznacza to ekran, ".
                'z którego nie da się wyjść z konta ani wejść w ustawienia — a to był cały '.
                'problem z D-168, tyle że przeniesiony gdzie indziej.',
            );
        }
    }

    /**
     * „POWIADOMIENIA" NIE UBYŁY — PRZESTAŁY SIĘ DUBLOWAĆ.
     *
     * Menu konta z widocznym napisem nie mieściło się w trzeciej kolumnie
     * belki: ma ona zmierzone 352 px i była zapełniona co do piksela
     * („Powiadomienia" 176 + „Dodaj" 95 + awatar 40 + odstępy). Kolumna rosła
     * kosztem środkowej i pole „Szukaj" przestawało stać nad tekstem, który
     * przeszukuje — zmierzone 739 zamiast 872 px przy 1280 px, czyli sprawdzenie
     * „Wyrównanie belki do siatki treści" ze `scripts/dostepnosc.mjs`.
     *
     * Miejsce wzięło się z jedynej rzeczy, która stała tam DRUGI RAZ: od 64rem
     * `.side-nav` niesie tę samą pozycję, z tym samym napisem, adresem
     * i licznikiem. Ten test pilnuje, żeby „drugi raz" nie zamieniło się
     * w „ani razu": na telefonie pozycja zostaje w belce, na komputerze —
     * w nawigacji bocznej. W HTML-u są oba egzemplarze (o tym, który widać,
     * decyduje wyłącznie CSS), więc sprawdzamy obecność każdego W SWOIM
     * MIEJSCU, a nie w całym dokumencie (D-164).
     */
    public function test_powiadomienia_stoja_i_w_belce_i_w_nawigacji_bocznej(): void
    {
        $html = $this->stronaZalogowanego('menu_powiadomienia');

        [$xpath, $pasek] = $this->pasekGorny($html);

        $wBelce = $this->odnosnikiPoWidocznymNapisie($xpath, $pasek, 'Powiadomienia');
        $this->assertCount(
            1,
            $wBelce,
            'Z paska górnego zniknęły „Powiadomienia". Poniżej 64rem nawigacji bocznej nie ma, '.
            'więc na telefonie nie zostałoby po nich nic.',
        );
        $this->assertStringContainsString(
            'topbar-mobile-only',
            $wBelce[0]->getAttribute('class'),
            'Egzemplarz w belce stracił `topbar-mobile-only` — na komputerze „Powiadomienia" '.
            'stoją znowu dwa razy, a trzecia kolumna belki nie ma na to 176 px.',
        );

        $boczna = $xpath->query("//nav[contains(concat(' ', normalize-space(@class), ' '), ' side-nav ')]")->item(0);
        $this->assertInstanceOf(DOMElement::class, $boczna, 'Nie znalazłem nawigacji bocznej.');
        $this->assertSame(
            1,
            $xpath->query(".//a[contains(@href, '/powiadomienia')]", $boczna)->length,
            'W nawigacji bocznej nie ma „Powiadomień" — a to na komputerze jedyne miejsce, '.
            'w którym zostały.',
        );
    }

    /**
     * Gość nie dostaje ani śladu menu konta.
     *
     * OSOBNY TEST, NIE DOPISEK: `actingAs()` utrzymuje logowanie w kolejnych
     * żądaniach tego samego testu, więc „sprawdzenie gościa" po wcześniejszym
     * `actingAs()` sprawdzałoby dalej zalogowanego (D-168).
     */
    public function test_gosc_nie_widzi_menu_konta(): void
    {
        $html = (string) $this->get(route('landing'))->assertOk()->getContent();

        [$xpath, $pasek] = $this->pasekGorny($html);

        // KONTROLA DODATNIA: to naprawdę pasek górny GOŚCIA, a nie pusta
        // strona — stoją w nim zaproszenia do logowania i rejestracji.
        $this->assertSame(
            1,
            $xpath->query(".//a[normalize-space(.)='Zaloguj się']", $pasek)->length,
            'W pasku górnym gościa nie ma „Zaloguj się" — wyciąłem nie ten fragment strony.',
        );

        $this->assertSame(
            0,
            $xpath->query(".//details[contains(concat(' ', normalize-space(@class), ' '), ' topbar-konto ')]", $pasek)->length,
            'Gość dostał menu konta, którego nie ma.',
        );

        // U gościa nie ma ani nawigacji bocznej, ani paska dolnego, więc tu
        // wolno zapytać o cały dokument — asercji „czegoś nie ma" się nie
        // zwęża (pułapka 1b z `docs/PULAPKI_TESTOW.md`).
        $this->assertStringNotContainsString('topbar-konto', $html);
        $this->assertStringNotContainsString('/logout', $html);
    }

    private function stronaZalogowanego(string|User $kto): string
    {
        $czlowiek = is_string($kto) ? $this->user($kto) : $kto;

        return (string) $this->actingAs($czlowiek)->get(route('home'))->assertOk()->getContent();
    }

    /**
     * Pasek górny — `<header class="topbar">`. Główka profilu to też
     * `<header>`, ale bez tej klasy, więc XPath jej nie złapie.
     *
     * @return array{0: DOMXPath, 1: DOMElement}
     */
    private function pasekGorny(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);
        $pasek = $xpath->query("//header[contains(concat(' ', normalize-space(@class), ' '), ' topbar ')]")->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $pasek,
            'Nie znalazłem paska górnego (`<header class="topbar">`) — strona wygląda na pustą.',
        );

        // Pusty kontener przechodzi każdą asercję „czegoś tu nie ma"
        // (pułapka 2 i 4 z `docs/PULAPKI_TESTOW.md`).
        $this->assertGreaterThan(
            200,
            mb_strlen((string) $dom->saveHTML($pasek)),
            'Pasek górny jest podejrzanie krótki — nie wyrenderował się.',
        );

        return [$xpath, $pasek];
    }
}

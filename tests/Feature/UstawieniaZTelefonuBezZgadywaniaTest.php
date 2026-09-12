<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CzytaWidoczneNapisy;
use Tests\TestCase;

/**
 * Wejście do Ustawień z telefonu — issue #344.
 *
 * CO BYŁO ŹLE
 * Na telefonie nawigacja to wyłącznie pasek dolny: Start, Szukaj, Dodaj,
 * Moje, Profil. `.side-nav` — jedyne miejsce z pozycją „Ustawienia" — ma
 * `display: none` poniżej 64rem (`resources/css/app.css:1173`), a awatar
 * w pasku górnym jest `topbar-desktop-only`
 * (`components/layout.blade.php:426`). Pasek dolny jest pełny i szóstej
 * pozycji mieć nie może (AGENTS.md §5).
 *
 * Słowa „Ustawienia" nie było więc na telefonie NIGDZIE. Do ekranów ustawień
 * dawało się dojść — przez „Zmień swój profil" na własnym profilu, bo
 * `/ustawienia/profil` niesie `x-ustawienia-nawigacja`, czyli spis wszystkich
 * dziewięciu ekranów. Ale trzeba było to ZGADNĄĆ. Człowiek, który szuka
 * hasła, prywatności albo usunięcia konta, szuka napisu „Ustawienia",
 * a nie napisu „Zmień swój profil".
 *
 * DLACZEGO ASERCJE SĄ ZAWĘŻONE DO GŁÓWKI PROFILU
 * Bo `.side-nav` renderuje się w HTML-u ZAWSZE, także wtedy, gdy CSS ją
 * chowa — jest w nim i pozycja „Ustawienia" z tym samym adresem, i formularz
 * wylogowania. Asercja po całym dokumencie przechodziłaby więc nad stroną,
 * na której tego wejścia w treści nie ma wcale, a nawet nad CUDZYM profilem
 * (pułapka 1 z `docs/PULAPKI_TESTOW.md`). Wycinamy `<header>` z nagłówkiem
 * `<h1>`, czyli główkę profilu, i pytamy wyłącznie o jej zawartość.
 *
 * CZEGO PILNUJE TEN PLIK
 *  1. Własny profil ma w główce WIDOCZNY napis „Ustawienia", prowadzący tam,
 *     gdzie ten sam napis na komputerze, i stojący w tym samym rzędzie akcji
 *     co „Wyloguj się".
 *  2. To NIE jest martwy przycisk (D-053): pod adresem z gotowej strony
 *     naprawdę stoi ekran ustawień ze spisem pozostałych.
 *  3. Cudzy profil i gość NIE dostają w główce ani tego wejścia, ani
 *     wylogowania — obie gałęzie, każda z kontrolą dodatnią.
 *  4. Wylogowanie zostaje POST-em z tokenem CSRF. Dokładanie sąsiada do tego
 *     rzędu jest najtańszym momentem, w którym ktoś zamieni formularz na
 *     zwykły odnośnik „dla spójności wyglądu".
 */
class UstawieniaZTelefonuBezZgadywaniaTest extends TestCase
{
    use CzytaWidoczneNapisy;
    use RefreshDatabase;

    public function test_wlasny_profil_ma_widoczne_wejscie_do_ustawien_przy_wylogowaniu(): void
    {
        $wlasciciel = $this->user('kciuk_wlasciciel', ['display_name' => 'Halina Kciukowa']);

        $html = $this->actingAs($wlasciciel)
            ->get(route('profile.show', 'kciuk_wlasciciel'))
            ->assertOk()
            ->getContent();

        [$xpath, $glowka] = $this->glowkaProfilu($html);

        // KONTROLA DODATNIA: to naprawdę główka profilu TEJ osoby, a nie
        // pusta strona, nie cudzy profil i nie pasek górny.
        $this->assertSame(
            'Halina Kciukowa',
            trim($xpath->query('.//h1', $glowka)->item(0)?->textContent ?? ''),
        );

        // KONTROLA DODATNIA RZĘDU: akcje właściciela, które stały tu przed
        // tą zmianą, dalej są — czyli wycinek obejmuje właściwy fragment.
        $this->assertSame(
            1,
            $xpath->query(".//a[normalize-space(.)='Zmień swój profil']", $glowka)->length,
            'W główce profilu nie ma „Zmień swój profil" — wyciąłem nie ten fragment strony.',
        );

        $wejscia = $this->odnosnikiPoWidocznymNapisie($xpath, $glowka, 'Ustawienia');

        $this->assertCount(
            1,
            $wejscia,
            'Na własnym profilu nie ma odnośnika z WIDOCZNYM napisem „Ustawienia". '
            .'Na telefonie to jedyna droga do ustawień: pasek dolny jest pełny, '
            .'a `.side-nav` ma poniżej 64rem `display: none`. '
            .'Podpis schowany w `visually-hidden` nie liczy się — ikona nigdy nie '
            .'jest jedynym opisem ważnej akcji (AGENTS.md §5).',
        );

        $wejscie = $wejscia[0];

        $this->assertSame(
            route('settings.index'),
            $wejscie->getAttribute('href'),
            'Napis „Ustawienia" ma prowadzić tam, gdzie prowadzi ten sam napis '
            .'w nawigacji bocznej na komputerze (`components/layout.blade.php`) '
            .'— od issue #344 jest to ekran-rozdroże `/ustawienia`, a nie '
            .'„Czytelność". Jeden napis, jeden cel, i ten cel nazywa się tak '
            .'samo jak napis.',
        );

        // Cel dotykowy: ta sama klasa przycisku co sąsiedzi, czyli 48 px
        // wysokości i 18 px tekstu z `.btn` w `app.css`. Gołego `<a>` bez
        // `.btn` nie da się trafić kciukiem.
        $this->assertStringContainsString('btn', $wejscie->getAttribute('class'));

        // Stoi w TYM SAMYM rzędzie co wylogowanie — tam, gdzie na telefonie
        // szuka się obsługi konta, a nie gdzieś na dole ekranu.
        $wylogowanie = $xpath->query(".//form[contains(@action, '/logout')]", $glowka)->item(0);
        $this->assertInstanceOf(DOMElement::class, $wylogowanie, 'W główce profilu nie ma wylogowania.');
        $this->assertSame(
            $wejscie->parentNode,
            $wylogowanie->parentNode,
            'Wejście do ustawień i wylogowanie stoją w różnych miejscach ekranu.',
        );
    }

    public function test_wejscie_do_ustawien_nie_jest_martwym_przyciskiem(): void
    {
        $wlasciciel = $this->user('kciuk_martwy', ['display_name' => 'Testowa Droga']);

        $html = $this->actingAs($wlasciciel)
            ->get(route('profile.show', 'kciuk_martwy'))
            ->assertOk()
            ->getContent();

        [$xpath, $glowka] = $this->glowkaProfilu($html);

        $wejscia = $this->odnosnikiPoWidocznymNapisie($xpath, $glowka, 'Ustawienia');
        $this->assertCount(1, $wejscia, 'Brak odnośnika z widocznym napisem „Ustawienia" — nie ma czego kliknąć.');
        $wejscie = $wejscia[0];

        // Idziemy dokładnie tam, gdzie prowadzi ATRYBUT z gotowej strony,
        // a nie tam, gdzie zdaniem testu powinien prowadzić. Inaczej test
        // sprawdzałby trasę, a nie odnośnik.
        $ekran = $this->actingAs($wlasciciel)
            ->get($wejscie->getAttribute('href'))
            ->assertOk();

        // KONTROLA DODATNIA: to jest ekran ustawień, a nie cokolwiek z 200.
        $ekran->assertSee('Wszystkie ustawienia');

        // …i nazywa się tak samo jak napis, w który człowiek nacisnął. To jest
        // cała rzecz, którą zdejmuje issue #344: do 12 września 2026 nagłówek
        // brzmiał tu „Czytelność" (koszt przyjęty świadomie w D-168).
        $ekran->assertSee('<h1>Ustawienia</h1>', false);

        // I nie jest ślepym zaułkiem — niesie spis pozostałych ekranów,
        // w tym te, których z telefonu nie dało się dotąd zobaczyć wcale.
        $ekran->assertSee('Prywatność');
        $ekran->assertSee('Bezpieczeństwo');
        $ekran->assertSee('Twoje dane');
    }

    public function test_wylogowanie_obok_ustawien_zostaje_postem_z_tokenem(): void
    {
        $wlasciciel = $this->user('kciuk_post', ['display_name' => 'Post Nie Get']);

        $html = $this->actingAs($wlasciciel)
            ->get(route('profile.show', 'kciuk_post'))
            ->assertOk()
            ->getContent();

        [$xpath, $glowka] = $this->glowkaProfilu($html);

        $formularz = $xpath->query(".//form[contains(@action, '/logout')]", $glowka)->item(0);
        $this->assertInstanceOf(DOMElement::class, $formularz);

        $this->assertSame('POST', mb_strtoupper($formularz->getAttribute('method')));
        $this->assertSame(
            1,
            $xpath->query(".//input[@name='_token']", $formularz)->length,
            'Formularz wylogowania stracił token CSRF.',
        );

        // Wylogowanie nie może być odnośnikiem GET — także takim, który
        // wygląda jak przycisk obok nowego wejścia do ustawień.
        $this->assertSame(
            0,
            $xpath->query(".//a[contains(@href, '/logout')]", $glowka)->length,
            'Wylogowanie stało się odnośnikiem GET — wyloguje człowieka podgląd linku albo prefetch przeglądarki.',
        );
    }

    public function test_obcy_nie_widzi_obslugi_konta_na_cudzym_profilu(): void
    {
        $this->user('kciuk_cel', ['display_name' => 'Cudza Kuchnia']);
        $obcy = $this->user('kciuk_obcy');

        $html = $this->actingAs($obcy)
            ->get(route('profile.show', 'kciuk_cel'))
            ->assertOk()
            ->getContent();

        [$xpath, $glowka] = $this->glowkaProfilu($html);

        // KONTROLA DODATNIA: obcy widzi TĘ SAMĄ główkę i ma w niej własną
        // akcję. Bez tego „nie widzi Ustawień" przeszłoby nad 404 albo nad
        // pustym ekranem (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
        $this->assertSame('Cudza Kuchnia', trim($xpath->query('.//h1', $glowka)->item(0)?->textContent ?? ''));
        $this->assertSame(1, $xpath->query(".//button[normalize-space(.)='Obserwuj']", $glowka)->length);

        $this->assertSame(
            0,
            $xpath->query(".//a[normalize-space(.)='Ustawienia']", $glowka)->length,
            'Obcy dostał na cudzym profilu wejście do SWOICH ustawień — to nie jest ekran do obsługi konta.',
        );
        $this->assertSame(
            0,
            $xpath->query(".//a[normalize-space(.)='Zmień swój profil']", $glowka)->length,
        );
        $this->assertSame(
            0,
            $xpath->query(".//form[contains(@action, '/logout')]", $glowka)->length,
            'Obcy dostał na cudzym profilu przycisk wylogowania.',
        );
    }

    public function test_gosc_nie_widzi_obslugi_konta_na_profilu(): void
    {
        $this->user('kciuk_goscinny', ['display_name' => 'Otwarta Kuchnia']);

        $html = $this->get(route('profile.show', 'kciuk_goscinny'))
            ->assertOk()
            ->getContent();

        [$xpath, $glowka] = $this->glowkaProfilu($html);

        // KONTROLA DODATNIA: strona się wyrenderowała i jest to widok GOŚCIA —
        // w pasku górnym stoi zaproszenie do logowania, a nie awatar konta.
        $this->assertSame('Otwarta Kuchnia', trim($xpath->query('.//h1', $glowka)->item(0)?->textContent ?? ''));
        $this->assertStringContainsString('Zaloguj się', $html);
        // `topbar-konto` to menu przy awatarze (issue #344). Do 12 września
        // 2026 stało tu `topbar-awatar` — klasa, której w serwisie już nie ma,
        // więc asercja byłaby spełniona zawsze i niczego by nie pilnowała.
        $this->assertStringNotContainsString('topbar-konto', $html);

        $this->assertSame(
            0,
            $xpath->query(".//a[normalize-space(.)='Ustawienia']", $glowka)->length,
            'Gość widzi wejście do ustawień, choć nie ma konta, którego miałyby dotyczyć.',
        );
        // U gościa nie ma nawet nawigacji bocznej z wylogowaniem, więc tu
        // wolno zapytać o cały dokument.
        $this->assertStringNotContainsString('/logout', $html);
    }

    /**
     * Główka profilu — jedyny `<header>` dokumentu, który niesie `<h1>`.
     * Pasek górny to też `<header>`, ale nagłówka pierwszego stopnia nie ma,
     * więc XPath go pomija.
     *
     * @return array{0: DOMXPath, 1: DOMElement}
     */
    private function glowkaProfilu(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        $glowka = $xpath->query('//header[.//h1]')->item(0);
        $this->assertInstanceOf(
            DOMElement::class,
            $glowka,
            'Nie znalazłem główki profilu (`<header>` z `<h1>`) — strona wygląda na pustą albo na inny ekran.',
        );

        return [$xpath, $glowka];
    }
}

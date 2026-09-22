<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CzytaWidoczneNapisy;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Ekran-rozdroże `/ustawienia` — issue #344, koszt zapisany w D-168.
 *
 * CO BYŁO ŹLE
 * Napis „Ustawienia" prowadził na ekran o nagłówku „Czytelność". Dotyczyło to
 * OBU miejsc, w których ten napis stoi: pozycji w nawigacji bocznej na
 * komputerze i przycisku w rzędzie akcji własnego profilu (dołożonego przy
 * D-168, na telefonie wtedy jedynego wejścia). D-168 przyjęło to świadomie
 * jako koszt — rozdroża nie było, a jeden napis o dwóch różnych celach byłby
 * gorszy niż jeden cel dziwny — i zapisało, że zdjęcie tego kosztu wymaga
 * osobnej decyzji. Ta decyzja zapadła.
 *
 * CZEGO PILNUJE TEN PLIK
 *  1. `/ustawienia` istnieje, jest za logowaniem i ma nagłówek „Ustawienia" —
 *     to samo słowo co napis, w który człowiek nacisnął.
 *  2. Wymienia WSZYSTKIE ekrany ustawień, których jest dziewięć, i każdy
 *     z nich WIDOCZNYM napisem — zębatka z podpisem dla czytnika ekranu nie
 *     liczy się (AGENTS.md §5).
 *  3. Żadna pozycja nie jest martwym przyciskiem (D-053): wchodzimy pod adres
 *     z gotowej strony i dostajemy 200.
 *  4. Spis stoi w `<main>` i TYLKO tam. Na pozostałych ekranach ustawień
 *     ta sama lista siedzi w prawej szynie (`UstawieniaDwieKolumnyTest`), bo
 *     towarzyszy formularzowi; tutaj jest treścią i drugi egzemplarz byłby
 *     dla czytnika ekranu podwójną nawigacją o tej samej nazwie.
 *  5. Oba napisy „Ustawienia" w serwisie prowadzą TUTAJ.
 *
 * DLACZEGO ASERCJE SĄ ZAWĘŻONE
 * `.side-nav` renderuje się w HTML-u ZAWSZE, także na telefonie (chowa ją
 * wyłącznie CSS), a od issue #344 w pasku górnym stoi jeszcze menu konta
 * z trzecią pozycją „Ustawienia". Pytanie o cały dokument przechodziłoby więc
 * nad KAŻDYM ekranem serwisu, łącznie z cudzym profilem (D-164, pułapka 1
 * z `docs/PULAPKI_TESTOW.md`). Wycinamy `<main>` i pytamy o jego zawartość.
 */
class RozdrozeUstawienTest extends TestCase
{
    use CzytaWidoczneNapisy;
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /**
     * Wszystkie ekrany ustawień — dziewięć. Ta sama lista co
     * w `UstawieniaNawigacjaTest`: rozdroże nie ma prawa pokazywać ich mniej,
     * bo nazywa się „Ustawienia" i człowiek wierzy, że widzi tam wszystko.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function ekrany(): array
    {
        return [
            ['Profil', 'settings.profile'],
            ['Zdjęcie profilowe', 'settings.avatar'],
            ['Czytelność', 'settings.accessibility'],
            ['Tagi', 'settings.tags'],
            ['Adres e-mail', 'settings.email'],
            ['Bezpieczeństwo', 'settings.security'],
            ['Weryfikacja dwuetapowa', 'settings.two_factor.edit'],
            ['Prywatność', 'settings.privacy'],
            ['Twoje dane', 'settings.data'],
        ];
    }

    public function test_rozdroze_nazywa_sie_tak_jak_napis_ktory_tu_prowadzi(): void
    {
        $html = $this->actingAs($this->user('rozdroze_nazwa'))
            ->get(route('settings.index'))
            ->assertOk()
            ->getContent();

        $tresc = $this->trescEkranu((string) $html);

        // Nagłówek EKRANU, nie `<title>` strony — ten sam napis stoi
        // w `<head>` cztery razy (pułapka 1b z `docs/PULAPKI_TESTOW.md`).
        $this->assertStringContainsString('<h1>Ustawienia</h1>', $tresc);
    }

    public function test_rozdroze_wymienia_widocznym_napisem_wszystkie_dziewiec_ekranow(): void
    {
        $html = (string) $this->actingAs($this->user('rozdroze_spis'))
            ->get(route('settings.index'))
            ->assertOk()
            ->getContent();

        [$xpath, $main] = $this->trescJakoWezel($html);

        foreach ($this->ekrany() as [$nazwa, $trasa]) {
            $odnosniki = $this->odnosnikiPoWidocznymNapisie($xpath, $main, $nazwa);

            $this->assertCount(
                1,
                $odnosniki,
                "Na rozdrożu nie ma DOKŁADNIE jednego odnośnika z widocznym napisem „{$nazwa}\". ".
                'Ekran nazywa się „Ustawienia" i ma być pełnym spisem — brakująca pozycja to '.
                'ekran, do którego z telefonu nie ma dojścia. Podpis schowany w `visually-hidden` '.
                'się nie liczy (AGENTS.md §5).',
            );

            $this->assertSame(
                route($trasa),
                $odnosniki[0]->getAttribute('href'),
                "Pozycja „{$nazwa}\" prowadzi gdzie indziej niż `{$trasa}`.",
            );
        }

        // KONTROLA DODATNIA LICZBY: spis ma dziewięć pozycji i ani jednej
        // więcej. Bez tego test przechodziłby też nad listą, do której ktoś
        // dopisał ekran nieistniejący albo powtórzony.
        $this->assertCount(
            9,
            $xpath->query(".//nav[@aria-label='Wszystkie ustawienia']//li", $main),
            'Rozdroże wymienia inną liczbę ekranów niż dziewięć — albo doszedł nowy '.
            'ekran ustawień i trzeba go dopisać także tutaj, albo lista się rozjechała.',
        );
    }

    public function test_zadna_pozycja_rozdroza_nie_jest_martwym_przyciskiem(): void
    {
        $czlowiek = $this->user('rozdroze_zywe');

        $html = (string) $this->actingAs($czlowiek)
            ->get(route('settings.index'))
            ->assertOk()
            ->getContent();

        [$xpath, $main] = $this->trescJakoWezel($html);

        foreach ($this->ekrany() as [$nazwa, $trasa]) {
            $odnosnik = $this->odnosnikiPoWidocznymNapisie($xpath, $main, $nazwa)[0] ?? null;
            $this->assertInstanceOf(DOMElement::class, $odnosnik, "Brak pozycji „{$nazwa}\".");

            // Idziemy pod adres Z GOTOWEJ STRONY, a nie pod ten, który zdaniem
            // testu powinien tam być — inaczej sprawdzalibyśmy trasę, a nie
            // odnośnik (D-053).
            $this->actingAs($czlowiek)
                ->get($odnosnik->getAttribute('href'))
                ->assertOk();
        }
    }

    public function test_spis_stoi_w_tresci_ekranu_i_nie_jest_zdublowany(): void
    {
        $html = (string) $this->actingAs($this->user('rozdroze_uklad'))
            ->get(route('settings.index'))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $this->assertSame(
            1,
            $xpath->query("//main[@id='tresc']//nav[@aria-label='Wszystkie ustawienia']")->length,
            'Spis ustawień nie stoi w treści rozdroża. Na tym jednym ekranie jest on CAŁĄ '.
            'treścią — w prawej szynie zostawiłby `<main>` z samym nagłówkiem, a na telefonie '.
            '(gdzie szyna ląduje pod treścią) człowiek zobaczyłby najpierw pusty ekran.',
        );

        $this->assertSame(
            1,
            $xpath->query("//nav[@aria-label='Wszystkie ustawienia']")->length,
            'Spis „Wszystkie ustawienia" jest na rozdrożu w dwóch egzemplarzach — dla czytnika '.
            'ekranu to dwie nawigacje o tej samej nazwie na jednej stronie.',
        );
    }

    public function test_oba_napisy_ustawienia_w_serwisie_prowadza_na_rozdroze(): void
    {
        $czlowiek = $this->user('rozdroze_wejscia', ['display_name' => 'Halina Rozdrożna']);

        $html = (string) $this->actingAs($czlowiek)
            ->get(route('profile.show', 'rozdroze_wejscia'))
            ->assertOk()
            ->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        // 1. Nawigacja boczna na komputerze. W HTML-u jest ZAWSZE — chowa ją
        //    wyłącznie CSS — więc wycinamy ją po klasie, a nie pytamy o cały
        //    dokument (D-164).
        $boczna = $xpath->query("//nav[contains(concat(' ', normalize-space(@class), ' '), ' side-nav ')]")->item(0);
        $this->assertInstanceOf(DOMElement::class, $boczna, 'Nie znalazłem nawigacji bocznej.');

        $wBocznej = $this->odnosnikiPoWidocznymNapisie($xpath, $boczna, 'Ustawienia');
        $this->assertCount(1, $wBocznej, 'W nawigacji bocznej nie ma widocznego napisu „Ustawienia".');
        $this->assertSame(
            route('settings.index'),
            $wBocznej[0]->getAttribute('href'),
            'Pozycja „Ustawienia" w nawigacji bocznej wróciła na „Czytelność" — napis i nagłówek '.
            'ekranu znowu mówią co innego (D-168).',
        );

        // 2. Rząd akcji własnego profilu — na telefonie do issue #344 jedyne
        //    wejście do obsługi konta. Zawężone do główki profilu, bo ten sam
        //    napis stoi teraz w dokumencie jeszcze dwa razy (nawigacja boczna
        //    i menu konta w pasku górnym).
        $glowka = $xpath->query('//header[.//h1]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $glowka, 'Nie znalazłem główki profilu.');
        $this->assertSame('Halina Rozdrożna', trim($xpath->query('.//h1', $glowka)->item(0)?->textContent ?? ''));

        $naProfilu = $this->odnosnikiPoWidocznymNapisie($xpath, $glowka, 'Ustawienia');
        $this->assertCount(1, $naProfilu, 'W główce własnego profilu nie ma widocznego napisu „Ustawienia".');
        $this->assertSame(
            route('settings.index'),
            $naProfilu[0]->getAttribute('href'),
            'Przycisk „Ustawienia" na własnym profilu wrócił na „Czytelność".',
        );
    }

    /**
     * Gość nie wchodzi na rozdroże.
     *
     * OSOBNY TEST, NIE DOPISEK DO POPRZEDNIEGO: `actingAs()` utrzymuje
     * logowanie w kolejnych żądaniach tego samego testu, więc „sprawdzenie
     * gościa" po wcześniejszym `actingAs()` sprawdzałoby dalej zalogowanego
     * (D-168).
     */
    public function test_gosc_nie_wchodzi_na_rozdroze(): void
    {
        $this->get(route('settings.index'))->assertRedirect(route('login'));
    }

    /**
     * `<main>` jako węzeł, żeby dało się pytać XPath-em o jego wnętrze.
     *
     * `trescEkranu()` z `WycinaObudoweEkranu` daje HTML i jest używane tam,
     * gdzie wystarczy porównanie napisów; tutaj potrzebny jest węzeł, bo
     * szukamy odnośników po WIDOCZNYM napisie.
     *
     * @return array{0: DOMXPath, 1: DOMElement}
     */
    private function trescJakoWezel(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);
        $main = $xpath->query("//main[@id='tresc']")->item(0);

        $this->assertInstanceOf(DOMElement::class, $main, 'Dokument nie ma `<main id="tresc">`.');

        return [$xpath, $main];
    }
}

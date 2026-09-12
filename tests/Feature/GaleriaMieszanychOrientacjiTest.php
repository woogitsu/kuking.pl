<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Galeria wpisu przy MIESZANYCH orientacjach zdjęć (issue #431).
 *
 * ZGŁOSZENIE WŁAŚCICIELA. „Jedno zdjęcie pionowe, drugie poziome, przez to
 * jest rozjazd i bierze całą wysokość najwyższego zdjęcia nawet jak nie jest
 * wyświetlane."
 *
 * To były dwie osobne usterki w dwóch różnych układach:
 *
 *   .photo-grid   dwie kolumny w jednym wierszu siatki mają wspólną wysokość,
 *                 więc pod zdjęciem poziomym zostawało puste pole. Zmierzone:
 *                 109,5 px przy 320 px, przy zdjęciu wysokim na 79,9 px —
 *                 czyli martwego miejsca było WIĘCEJ niż zdjęcia.
 *   .karuzela     taśma jest jednym rzędem elastycznym, więc jej wysokość to
 *                 wysokość NAJWYŻSZEGO slajdu, także gdy widać inny. Zmierzone:
 *                 220,5 px pustego pola pod zdjęciem poziomym przy 320 px.
 *
 * CZEGO TEN TEST NIE ROBI I ROBIĆ NIE MOŻE
 * ----------------------------------------
 * Nie mierzy pikseli. Wysokość pola siatki zna wyłącznie przeglądarka, która
 * ułożyła stronę — PHPUnit nie układa CSS-u. Pomiar jest osobnym narzędziem
 * (`scripts/galeria-orientacje.mjs`) i to on dał liczby wyżej.
 *
 * Ten test pilnuje czego innego, i to jest rzecz, której tamten pomiar
 * pilnować nie może: żeby REGUŁY, które te liczby zbiły do zera, nie zniknęły
 * po cichu z arkusza. Pomiar chodzi z ręki i nie ma go w `php artisan test`;
 * reguła skasowana w niepowiązanym PR-ze wróciłaby więc do serwisu bez jednej
 * czerwieni. Razem: TU jest to, że reguła istnieje, TAM — ile daje.
 *
 * DLACZEGO WYCINAMY BLOK REGUŁY, A NIE SZUKAMY W CAŁYM PLIKU
 * ----------------------------------------------------------
 * Bo `aspect-ratio: 1 / 1` stoi w `app.css` także przy
 * `.photo-placeholder-kwadrat`, a `object-fit: contain` przy `.kolaz-pole`.
 * Asercja na całym arkuszu przechodziłaby więc po skasowaniu reguły karuzeli
 * — łapiąc to samo słowo skądinąd. To jest pułapka 1 z
 * `docs/PULAPKI_TESTOW.md`, tylko w arkuszu stylów zamiast w HTML-u.
 *
 * @see scripts/galeria-orientacje.mjs — pomiar, z którego są liczby wyżej
 */
class GaleriaMieszanychOrientacjiTest extends TestCase
{
    private function arkusz(): string
    {
        $plik = base_path('resources/css/app.css');

        $this->assertFileExists($plik);

        $tresc = (string) file_get_contents($plik);

        // Pułapka 2: skan, który czyta pusty albo obcięty plik, nie znajduje
        // niczego — i uznaje to za sukces. Arkusz ma dziś ponad 5000 linijek.
        $this->assertGreaterThan(
            2000,
            substr_count($tresc, "\n"),
            'Arkusz jest podejrzanie krótki — czytamy ten plik, co trzeba?',
        );

        return $tresc;
    }

    /**
     * Zawartość klamry dla podanego selektora — czyli sama ta reguła,
     * bez reszty arkusza.
     */
    private function blok(string $arkusz, string $selektor): string
    {
        $poczatek = strpos($arkusz, $selektor);

        $this->assertNotFalse(
            $poczatek,
            "W arkuszu nie ma w ogóle selektora „{$selektor}”.",
        );

        $klamra = strpos($arkusz, '{', $poczatek);
        $this->assertNotFalse($klamra, "Selektor „{$selektor}” nie otwiera bloku.");

        $koniec = strpos($arkusz, '}', $klamra);
        $this->assertNotFalse($koniec, "Blok selektora „{$selektor}” się nie zamyka.");

        $blok = substr($arkusz, $klamra, $koniec - $klamra);

        /*
         * KOMENTARZE WYCINAMY — i to jest poprawka błędu w TYM pliku,
         * złapana przez kontrolę ujemną.
         *
         * Reguły w tym arkuszu mają obszerne komentarze W ŚRODKU bloku,
         * a komentarz przy `min-height` brzmi „`min-height: 0` NIE JEST
         * OZDOBĄ". Asercja na obecność napisu trafiała więc w KOMENTARZ
         * i przechodziła także po skasowaniu samej deklaracji. Zmierzone:
         * usunięcie `min-height: 0;` z arkusza nie oblało ani jednego
         * przypadku, dopóki tej linijki tu nie było.
         *
         * To jest pułapka 1 z `docs/PULAPKI_TESTOW.md` w najbardziej
         * zdradliwej odmianie: to samo słowo skądinąd, przy czym „skądinąd"
         * jest zdaniem, które sami napisaliśmy po to, żeby tę deklarację
         * obronić.
         */
        return (string) preg_replace('#/\*.*?\*/#su', '', $blok);
    }

    #[Test]
    public function test_siatka_zdjec_schodzi_na_telefonie_do_jednej_kolumny(): void
    {
        $arkusz = $this->arkusz();

        // Reguła mediów dla siatki — węższa niż 30rem, czyli telefon.
        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*30rem\)\s*\{\s*\.photo-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\)/u',
            $arkusz,
            'Siatka zdjęć nie schodzi na telefonie do jednej kolumny. W dwóch '.
            'kolumnach wiersz ma wysokość najwyższego zdjęcia, więc pod zdjęciem '.
            'poziomym wraca martwe pole (zmierzone: 109,5 px przy 320 px).',
        );
    }

    #[Test]
    public function test_pole_siatki_nie_rozciaga_sie_do_wysokosci_wiersza(): void
    {
        $blok = $this->blok($this->arkusz(), '.photo-grid {');

        $this->assertMatchesRegularExpression(
            '/align-items:\s*start\s*;/u',
            $blok,
            'Bez tego odnośnik „powiększ zdjęcie" rozciąga się na całą wysokość '.
            'wiersza, więc kliknięcie w puste miejsce POD zdjęciem poziomym '.
            'otwiera powiększenie zdjęcia, którego tam nie widać.',
        );
    }

    #[Test]
    public function test_slajdy_karuzeli_maja_te_sama_wysokosc(): void
    {
        $blok = $this->blok($this->arkusz(), '.karuzela-slajd .photo-zoom,');

        $this->assertMatchesRegularExpression(
            '/aspect-ratio:\s*1\s*\/\s*1/u',
            $blok,
            'Pole zdjęcia w karuzeli straciło stałą proporcję. Bez niej slajdy '.
            'mają różną wysokość, a taśma bierze wysokość NAJWYŻSZEGO — czyli '.
            'wraca dokładnie to, co zgłosił właściciel (zmierzone: 220,5 px '.
            'pustego pola przy 320 px).',
        );

        // `min-height: 0` jest tu warunkiem, nie ozdobą — i to jest ta część,
        // której brak wygląda w pomiarze prawie jak poprawka.
        $this->assertMatchesRegularExpression(
            '/min-height:\s*0\s*;/u',
            $blok,
            'Bez `min-height: 0` proporcja pola działa TYLKO na zdjęciach '.
            'poziomych: slajd jest elementem elastycznym, a jego automatyczne '.
            'minimum równa się własnej wysokości zdjęcia. Zmierzone bez tej '.
            'linijki przy 320 px: pole poziomego 214,5 px, pionowego 381,3 px '.
            '— czyli slajdy dalej różnej wysokości.',
        );
    }

    #[Test]
    public function test_karuzela_miesci_zdjecie_w_calosci_zamiast_je_kadrowac(): void
    {
        $arkusz = $this->arkusz();
        $blok = $this->blok($arkusz, '.karuzela-slajd .post-photo {');

        $this->assertMatchesRegularExpression(
            '/object-fit:\s*contain\s*;/u',
            $blok,
            'Zdjęcie w karuzeli ma mieścić się w polu W CAŁOŚCI.',
        );

        // KONTROLA DODATNIA DO ASERCJI WYŻEJ (pułapka 4).
        //
        // Sama obecność `contain` przeszłaby także wtedy, gdyby ktoś dopisał
        // obok `cover` w regule bardziej szczegółowej — a wtedy kadrowanie
        // wróciłoby, mimo zielonego testu. Pytamy więc osobno o to, czego
        // w galeriach wpisu nie ma prawa być.
        foreach (['.karuzela-slajd', '.kolaz-pole', '.photo-grid'] as $selektor) {
            $this->assertDoesNotMatchRegularExpression(
                '/'.preg_quote($selektor, '/').'[^{}]*\{[^}]*object-fit:\s*cover/u',
                $arkusz,
                "W „{$selektor}” pojawiło się `object-fit: cover`. Kadrowanie do pola ".
                'ucina to, co człowiek chciał pokazać, a zdjęcie jedzenia jest tu '.
                'samą treścią wpisu, nie tłem pod tekstem.',
            );
        }
    }

    #[Test]
    public function test_pomiar_orientacji_zostaje_w_repozytorium_razem_ze_swoimi_bramkami(): void
    {
        $plik = base_path('scripts/galeria-orientacje.mjs');

        $this->assertFileExists(
            $plik,
            'Zniknął pomiar, z którego są wszystkie liczby w tym pliku. Reguły '.
            'bez pomiaru zostają bez dowodu, że cokolwiek dają.',
        );

        $tresc = (string) file_get_contents($plik);

        // Bramki tego pomiaru są jego jedyną obroną przed fałszywą zielenią:
        // galeria bez mieszanych orientacji nie ma martwych pikseli z definicji.
        foreach ([
            'galerieMieszane === 0' => 'bramka „żadna galeria nie miała mieszanych orientacji"',
            'Page.setFontSizes' => 'pomiar przy czcionce przeglądarki 200%',
            'storageState' => 'jedno logowanie przenoszone ciasteczkiem (throttle na logowaniu)',
        ] as $fragment => $co) {
            $this->assertStringContainsString(
                $fragment,
                $tresc,
                "Z pomiaru zniknęła {$co}.",
            );
        }

        // Komplet szerokości z warunku właściciela — wszystkie cztery.
        foreach (['320', '360', '390', '414'] as $szerokosc) {
            $this->assertMatchesRegularExpression(
                '/SZEROKOSCI\s*=\s*\[[^\]]*\b'.$szerokosc.'\b/u',
                $tresc,
                "Pomiar przestał obejmować szerokość {$szerokosc} px.",
            );
        }
    }
}

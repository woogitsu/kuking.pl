<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;

/**
 * Podział stron przy wydruku przepisu z paczki (#492).
 *
 * `CZYTAJ-TO-NAJPIERW.txt` obiecuje wprost: „otwórz go z katalogu »przepisy«
 * i wciśnij Ctrl+P […] wydruk był czytelny". Zmierzone na PRAWDZIWYCH PDF-ach
 * A4 (Chromium 151.0.7922.34, margines 10 mm, `pdftotext`/`pdfimages` strona
 * po stronie) przed tą poprawką:
 *
 *  - „Rosół z kury": TEKST kroku 2 wychodził na stronie 2, a ZDJĘCIE tego
 *    samego kroku na stronie 3 — przy garnku człowiek miał instrukcję na
 *    jednej kartce, a obrazek do niej na drugiej;
 *  - „Pierogi ruskie": nagłówek „Składniki" kończył stronę 1, a pierwszy
 *    składnik zaczynał stronę 2; „Jak to zrobić" kończyło stronę 2, a krok 1
 *    zaczynał stronę 3 — dwa osierocone nagłówki w jednym przepisie;
 *  - skan z zeszytu (1200 × 1600) schodził do około 24 cm wysokości, przez co
 *    przepis rósł o całą kartkę, a jedna zostawała zapełniona w kilkunastu
 *    procentach.
 *
 * Czego ten test NIE dowodzi: nie drukuje i nie pagina. Pilnuje reguł, które
 * o podziale decydują, i tego, że one NAPRAWDĘ dotyczą tych elementów.
 * Sama paginacja jest zmierzona w dowodach (`output/492b-claude/druk.json`,
 * `output/492b-claude/pdf/`). To też NIE jest test fizycznej drukarki.
 */
class EksportDrukPodzialStronTest extends EksportWygladStylPaczki
{
    public function test_krok_przepisu_nie_rozpada_sie_na_dwie_kartki(): void
    {
        [$arkusz, $xpath] = $this->stronaPrzepisu();

        $kroki = $this->elementy($xpath, '//ol[contains(@class,"kroki")]/li');
        $this->assertGreaterThanOrEqual(2, count($kroki), 'Przepis w paczce nie ma kroków — nie ma czego mierzyć.');

        foreach ($kroki as $krok) {
            $this->assertSame('avoid', $this->wartoscDla($arkusz, $krok, 'break-inside'),
                'Krok „'.mb_substr(trim($krok->textContent), 0, 40).'" wolno przeciąć granicą kartki.');
        }

        $zdjecia = $this->elementy($xpath, '//img[contains(@class,"zdjecie")]');
        $this->assertNotEmpty($zdjecia, 'Przepis w paczce nie ma zdjęcia — nie ma czego mierzyć.');

        foreach ($zdjecia as $zdjecie) {
            $this->assertSame('avoid', $this->wartoscDla($arkusz, $zdjecie, 'break-inside'));
            $this->assertSame('16cm', $this->wartoscDla($arkusz, $zdjecie, 'max-height'),
                'Zdjęcie bez ograniczenia wysokości zajmuje na wydruku całą kartkę i spycha resztę przepisu.');
        }
    }

    public function test_naglowek_nie_zostaje_sam_na_dole_kartki(): void
    {
        [$arkusz, $xpath] = $this->stronaPrzepisu();

        $naglowki = $this->elementy($xpath, '//h2 | //h3');
        $this->assertGreaterThanOrEqual(2, count($naglowki), 'Przepis w paczce nie ma nagłówków — nie ma czego mierzyć.');

        foreach ($naglowki as $naglowek) {
            $this->assertSame('avoid', $this->wartoscDla($arkusz, $naglowek, 'break-after'),
                'Nagłówek „'.trim($naglowek->textContent).'" może zostać sam na dole kartki.');
        }
    }

    public function test_karta_z_komentarzem_i_ostrzezenie_zostaja_w_calosci(): void
    {
        [$arkusz, $xpath] = $this->stronaPrzepisu();

        $karty = $this->elementy($xpath, '//div[contains(@class,"karta")]');
        $this->assertNotEmpty($karty, 'Strona przepisu nie ma ani jednej karty — nie ma czego mierzyć.');

        foreach ($karty as $karta) {
            $this->assertSame('avoid', $this->wartoscDla($arkusz, $karta, 'break-inside'));
        }
    }

    /**
     * Kontrola, że dopasowywacz reguł ROZRÓŻNIA, a nie przepuszcza wszystkiego.
     *
     * Bez niej trzy testy wyżej przeszłyby także wtedy, gdyby ktoś napisał
     * `* { break-inside: avoid }` — czyli regułę, która zabrania dzielić
     * WSZYSTKIEGO, łącznie z akapitem tekstu, i psuje wydruk zamiast go
     * naprawiać (pułapka 4: sama asercja „coś jest" nie dowodzi, że mechanizm
     * pracował).
     */
    public function test_zwykly_akapit_dalej_wolno_przeniesc_na_nastepna_kartke(): void
    {
        [$arkusz, $xpath] = $this->stronaPrzepisu();

        $podpisy = $this->elementy($xpath, '//p[contains(@class,"podpis")]');
        $this->assertNotEmpty($podpisy, 'Strona przepisu nie ma podpisu — nie ma czego mierzyć.');

        $this->assertNull($this->wartoscDla($arkusz, $podpisy[0], 'break-inside'),
            'Reguła zakazu dzielenia jest za szeroka: łapie zwykły akapit.');
    }

    public function test_wydruk_nie_doklada_pustego_pasa_na_koncu(): void
    {
        /*
         * PUSTA OSTATNIA KARTKA — zmierzona, nie wydedukowana.
         *
         * `body` ma na ekranie 64 px dolnego wypełnienia i ten sam pas
         * jechał na papier. Gdy treść kończyła się blisko granicy kartki,
         * przepychał ją na następną — i wychodziła kartka bez ani jednego
         * znaku i bez ani jednego obrazu.
         *
         * Zmierzone na stronie przepisu z prawdziwej paczki (A4, margines
         * 10 mm, Chromium, `pdfinfo`/`pdftotext`/`pdfimages` strona po
         * stronie): przy 4 krokach 3 kartki, trzecia pusta; przy 14 krokach
         * 4 kartki, czwarta pusta. Po poprawce 2 i 3 kartki, obie
         * zapełnione; pozostałe trzynaście długości bez zmiany.
         *
         * Ten test NIE pagina i nie drukuje — pilnuje reguły, która o to
         * dba, i tego, że dotyczy ona `body`. Sama paginacja jest
         * w dowodach (`docs/design/evidence/eksport492/`).
         */
        [$arkusz, $xpath] = $this->stronaPrzepisu();

        $body = $this->elementy($xpath, '//body');
        $this->assertCount(1, $body, 'Strona przepisu nie ma `body` — nie ma czego mierzyć.');

        $this->assertSame('0', $this->wartoscDla($arkusz, $body[0], 'padding-bottom'),
            'Wydruk dokłada na końcu pas pustego miejsca, który potrafi urodzić pustą kartkę.');
    }

    public function test_dolne_wypelnienie_na_ekranie_zostaje(): void
    {
        /*
         * Kontrola DODATNIA do testu wyżej, nie ujemna — kontrolą ujemną jest
         * sabotaż z `evidence/eksport492/kontrola-ujemna.log`. Tu chodzi
         * o granicę poprawki: ma działać WYŁĄCZNIE w druku, bo na ekranie
         * te 64 px trzymają stopkę z dala od krawędzi okna.
         *
         * Asercja na literał skrótu `padding` jest świadomym kompromisem:
         * oblałaby się także po rozpisaniu skrótu na `padding-top` i resztę,
         * czyli przy niezmienionym zachowaniu. Pomocnik nie liczy kaskady
         * ani nie rozwija skrótów (patrz docblock `EksportWygladStylPaczki`),
         * a fałszywa czerwień jest tu tańsza od przeoczonej zmiany wyglądu.
         */
        [$html] = $this->stronaPrzepisuSurowa();

        $podstawowe = $this->regulyPodstawowe($this->arkusz($html));
        $body = $this->elementy($this->dokument($html), '//body');

        $this->assertCount(1, $body, 'Strona przepisu nie ma `body` — nie ma czego mierzyć.');

        $this->assertSame('24px 16px 64px', $this->wartoscDla($podstawowe, $body[0], 'padding'),
            'Z ekranu zniknęło dolne wypełnienie strony — to nie było przedmiotem poprawki wydruku.');
    }

    /**
     * Strona przepisu z prawdziwej paczki: arkusz `@media print` i DOM.
     *
     * @return array{0: string, 1: \DOMXPath}
     */
    private function stronaPrzepisu(): array
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        $zdjecieKroku = $this->zdjecieDla($basia, 'krok-drugi');
        $przepis = Recipe::factory()->for($basia, 'author')->family('od mamy, Haliny')->create([
            'title' => 'Rosół z kury na niedzielę',
            'hero_media_id' => $this->zdjecieDla($basia, 'rosol')->getKey(),
        ]);

        foreach (['1 kura zagrodowa', '3 marchewki', 'sól do smaku'] as $pozycja => $tekst) {
            RecipeIngredient::create([
                'recipe_id' => $przepis->getKey(),
                'ingredient_text' => $tekst,
                'position' => $pozycja,
                'no_amount' => true,
            ]);
        }

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Kurę opłucz i włóż do dużego garnka.',
        ]);
        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 1,
            'instruction' => 'Postaw na małym ogniu. Woda ma ledwo drgać.',
            'media_id' => $zdjecieKroku->getKey(),
            'timer_seconds' => 5400,
        ]);

        $export = $this->zbudujPaczke($basia);

        $pliki = array_values(array_filter(
            $this->plikiPaczki($export),
            static fn (string $plik): bool => str_starts_with($plik, 'przepisy/'),
        ));
        $this->assertCount(1, $pliki, 'Paczka nie zawiera strony przepisu.');

        $html = $this->zPaczki($export, $pliki[0]);

        return [$this->regulyWydruku($this->arkusz($html)), $this->dokument($html)];
    }

    /**
     * Ta sama scena, ale surowy HTML — dla asercji o regułach spoza `@media print`.
     *
     * @return array{0: string}
     */
    private function stronaPrzepisuSurowa(): array
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        Recipe::factory()->for($basia, 'author')->create([
            'title' => 'Rosół z kury na niedzielę',
            'hero_media_id' => $this->zdjecieDla($basia, 'rosol')->getKey(),
        ]);

        $export = $this->zbudujPaczke($basia);

        $pliki = array_values(array_filter(
            $this->plikiPaczki($export),
            static fn (string $plik): bool => str_starts_with($plik, 'przepisy/'),
        ));
        $this->assertCount(1, $pliki, 'Paczka nie zawiera strony przepisu.');

        return [$this->zPaczki($export, $pliki[0])];
    }
}

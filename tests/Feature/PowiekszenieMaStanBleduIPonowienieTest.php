<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Issue #743 — powiększanie zdjęć nie miało własnego stanu błędu ani
 * ponowienia po nieudanym pobraniu dużego wariantu. Dialog otwiera się
 * PRZED zakończeniem żądania (`resources/js/app.js`, blok „Powiększanie
 * zdjęć"); gdy ono nie dojdzie albo obraz się nie zdekoduje, człowiek miał
 * do dyspozycji tylko zepsuty `<img>` przeglądarki i przycisk „Zamknij”.
 *
 * CO TU JEST TESTOWANE, A CO NIE
 * Prawdziwe przerwanie żądania i wygląd nakładki po błędzie wymaga
 * przeglądarki — tej próby w tej sesji NIE wykonano (podobnie jak
 * `PowiekszanieZdjeciaTest` nie testuje samego działania `<dialog>`).
 * Test sprawdza to, co da się sprawdzić statycznie i co musi być prawdą,
 * żeby zachowanie w ogóle miało szansę zadziałać:
 *   1. w HTML jest region stanu (`role="status"`, `aria-live="polite"`)
 *      i przycisk „Spróbuj ponownie”, oba domyślnie ukryte (`hidden`);
 *   2. `resources/js/app.js` faktycznie nasłuchuje `load`/`error` na
 *      obrazie nakładki i steruje tymi elementami, z ochroną przed tym, że
 *      zdarzenie z POPRZEDNIEGO zdjęcia nadpisze stan NASTĘPNEGO
 *      (porównanie `obraz.src === biezacyAdres`);
 *   3. ponowienie faktycznie zmienia adres (nie jest no-opem).
 *
 * @bez-kontroli-dodatniej assertNotFalse na obu granicach bloku w app.js sprawia, że zniknięcie bloku daje czerwień, nie zieleń nad pustym wycinkiem.
 */
class PowiekszenieMaStanBleduIPonowienieTest extends TestCase
{
    public function test_dialog_ma_domyslnie_ukryty_region_bledu_i_przycisk_ponow(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $status = $xpath->query('//dialog[@id="powiekszenie"]//p[contains(concat(" ", normalize-space(@class), " "), " lightbox-status ")]');
        $this->assertCount(1, $status, 'Brak regionu stanu w nakładce powiększenia.');
        $this->assertSame('status', self::elementDom($status->item(0))->getAttribute('role'));
        $this->assertSame('polite', self::elementDom($status->item(0))->getAttribute('aria-live'));
        $this->assertTrue(self::elementDom($status->item(0))->hasAttribute('hidden'), 'Region stanu nie powinien być widoczny, dopóki nic się nie dzieje.');

        $ponow = $xpath->query('//dialog[@id="powiekszenie"]//button[contains(concat(" ", normalize-space(@class), " "), " lightbox-ponow ")]');
        $this->assertCount(1, $ponow, 'Brak przycisku „Spróbuj ponownie” w nakładce powiększenia.');
        $this->assertSame('button', self::elementDom($ponow->item(0))->getAttribute('type'), 'Przycisk ponowienia nie może być type="submit" formularza method="dialog" — zamknąłby dialog zamiast ponowić.');
        $this->assertTrue(self::elementDom($ponow->item(0))->hasAttribute('hidden'), 'Przycisk ponowienia nie powinien być widoczny, dopóki nie ma błędu.');
        $this->assertStringContainsString('Spróbuj ponownie', $ponow->item(0)->textContent);

        // „Zamknij” zostaje: przycisk ponowienia jest DODATKIEM, nie
        // zamiennikiem jedynej dotąd drogi wyjścia z dialogu.
        $zamknij = $xpath->query('//dialog[@id="powiekszenie"]//button[@type="submit"]');
        $this->assertCount(1, $zamknij);
        $this->assertStringContainsString('Zamknij', $zamknij->item(0)->textContent);
    }

    public function test_js_obsluguje_load_i_blad_obrazu_z_ochrona_przed_zdarzeniem_poprzedniego_zdjecia(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));
        $this->assertNotFalse($js);

        $poczatek = strpos($js, "const okno = document.getElementById('powiekszenie')");
        $this->assertNotFalse($poczatek, 'Nie znaleziono bloku obsługi dialogu powiększenia.');
        $koniec = strpos($js, '// --- Tryb gotowania', $poczatek);
        $this->assertNotFalse($koniec);
        $blok = substr($js, $poczatek, $koniec - $poczatek);

        $this->assertStringContainsString("addEventListener('load'", $blok, 'Brak obsługi udanego wczytania dużego wariantu.');
        $this->assertStringContainsString("addEventListener('error'", $blok, 'Brak obsługi błędu wczytania dużego wariantu.');
        $this->assertStringContainsString('Nie udało się wczytać zdjęcia', $blok);

        // Ochrona przed nadpisaniem stanu przez zdarzenie z innego zdjęcia:
        // każdy handler musi sprawdzić, że zdarzenie dotyczy AKTUALNIE
        // otwartego adresu, a nie jakiegoś wcześniejszego żądania.
        $this->assertStringContainsString('biezacyAdres', $blok);
        $this->assertMatchesRegularExpression('/obraz\.src\s*!==\s*biezacyAdres/', $blok, 'Handler load/error nie porównuje zdarzenia z aktualnie otwartym adresem.');

        // Ponowienie ma zmienić adres, nie tylko przypisać ten sam string
        // (przeglądarka potrafi to zignorować jako no-op).
        $this->assertStringContainsString('lightbox-ponow', $js);
        $ponowPoczatek = strpos($blok, "ponowPrzycisk.addEventListener('click'");
        $this->assertNotFalse($ponowPoczatek, 'Brak obsługi kliknięcia „Spróbuj ponownie”.');
        $ponowBlok = substr($blok, $ponowPoczatek, 900);
        $this->assertStringContainsString('Date.now()', $ponowBlok, 'Ponowienie powinno wymuszać nowe żądanie, nie liczyć na to, że ten sam adres przeładuje obraz.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Wycinanie TREŚCI ekranu (`<main>`) z całego dokumentu.
 *
 * PO CO TO JEST (pułapka 1 z `docs/PULAPKI_TESTOW.md`)
 * Asercja „strona zawiera napis X" puszczona na CAŁYM dokumencie przechodzi
 * także wtedy, gdy X stoi w `<title>`, w `<meta>`, w belce serwisu albo
 * w stopce — czyli nie mierzy tego, co miała mierzyć.
 *
 * ZMIERZONE, NIE ZAŁOŻONE. Na `/o-kuking` napis „O Kuking" stoi WYŁĄCZNIE
 * w `<title>` i w dwóch `<meta>`: po D-145 nagłówek tej strony brzmi
 * „O kuKING" i nazwa jest rozbita na znaczniki. Mimo to asercja
 * `assertStringContainsString('O Kuking', $html)` przechodziła — z powodu
 * nagłówka DOKUMENTU, nie z powodu treści ekranu.
 *
 * DLACZEGO NIE WYSTARCZA SAMO `bezStopki()`
 * Wzorzec `bezStopki()` (`TekstyWedlugCopyStyleTest`,
 * `ZobowiazaniaNaOKukingSaTwierdzeniamiTest`) zdejmuje stopkę i tylko
 * stopkę — `<head>`, belka i szyna zostają. Dla trafień, w których szukany
 * napis przychodzi z `<title>` albo z belki, jest więc za słaby. Ten
 * pomocnik idzie z drugiej strony: zamiast odejmować kolejne kawałki
 * obudowy, bierze wprost to, co jest treścią.
 *
 * DLACZEGO W `tests/Support`, A NIE PRYWATNIE W KAŻDYM PLIKU
 * Ten sam zabieg był potrzebny w kilku plikach naraz. Kolejna kopia
 * prywatnej metody to kolejne miejsce do poprawienia przy zmianie układu —
 * ten sam powód, dla którego wyodrębniono `CzytaJobDostepnosci`.
 *
 * CZEGO TEN POMOCNIK NIE ROBI
 * Nie zwęża do konkretnej sekcji `<main>` ani nie umie wyciąć prawej szyny
 * (`<aside class="app-rail">` — na to jest `SzynaKolejneEkranyTest::szyna()`).
 * Jeśli szukany napis pada w treści więcej niż raz i zależy Ci na KONKRETNYM
 * miejscu, wytnij sekcję — wzorce z tabeli w `docs/PULAPKI_TESTOW.md` §1.
 */
trait WycinaObudoweEkranu
{
    /**
     * Zawartość `<main>` jako HTML — bez `<head>`, belki, szyny i stopki.
     */
    protected function trescEkranu(string $html): string
    {
        return $this->wezelEkranu(
            $html,
            '//main',
            'Dokument nie ma znacznika <main> — test nie sprawdził treści, tylko obudowę.',
        );
    }

    private function wezelEkranu(string $html, string $zapytanie, string $komunikat): string
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $wezel = (new DOMXPath($dokument))->query($zapytanie)?->item(0);

        $this->assertInstanceOf(DOMElement::class, $wezel, $komunikat);

        $wycinek = (string) $dokument->saveHTML($wezel);

        // Pusty kontener przechodzi każdą asercję „czegoś tu nie ma"
        // (pułapka 2 i 4 z `docs/PULAPKI_TESTOW.md`). Sam znacznik to za mało.
        $this->assertGreaterThan(
            80,
            mb_strlen($wycinek),
            'Wycinek jest podejrzanie krótki — ekran nie wyrenderował treści.',
        );

        return $wycinek;
    }
}

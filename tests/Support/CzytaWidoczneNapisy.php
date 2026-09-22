<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Szukanie odnośników po WIDOCZNYM napisie, a nie po `textContent`.
 *
 * PO CO TO JEST (D-168, kontrola ujemna przy issue #344)
 * `normalize-space(.)` w XPath czyta `textContent`, a ten zlicza także tekst
 * schowany klasą `visually-hidden`. Test szukający odnośnika „Ustawienia"
 * przechodził więc również wtedy, gdy na ekranie stała sama zębatka
 * z podpisem dla czytnika ekranu — czyli dokładnie wtedy, gdy złamana jest
 * zasada „ikona nigdy nie jest jedynym opisem ważnej akcji" (AGENTS.md §5).
 * Sprawdzone sabotażem: wersja z `normalize-space(.)` NIE oblewała.
 *
 * DLACZEGO W `tests/Support`, A NIE PRYWATNIE W KAŻDYM PLIKU
 * Ten sam zabieg jest potrzebny wszędzie, gdzie pilnujemy, że napis jest
 * WIDOCZNY: przy rzędzie akcji profilu (`UstawieniaZTelefonuBezZgadywaniaTest`)
 * i przy menu konta w pasku górnym (`MenuKontaPrzyAwatarzeTest`). Druga kopia
 * prywatnej metody to drugie miejsce do poprawienia przy zmianie sposobu
 * chowania tekstu — ten sam powód, dla którego wyodrębniono
 * `WycinaObudoweEkranu`.
 *
 * CZEGO TEN POMOCNIK NIE ROBI
 * Nie zwęża dokumentu do żadnego fragmentu. Wycinek podaje wołający —
 * `.side-nav` renderuje się w HTML-u ZAWSZE, także na telefonie, więc
 * pytanie o cały dokument przechodzi nawet nad cudzym profilem (D-164,
 * pułapka 1 z `docs/PULAPKI_TESTOW.md`).
 */
trait CzytaWidoczneNapisy
{
    /**
     * Odnośniki w wycinku, których WIDOCZNY napis jest dokładnie taki jak
     * podany.
     *
     * @return list<DOMElement>
     */
    protected function odnosnikiPoWidocznymNapisie(DOMXPath $xpath, DOMElement $wycinek, string $napis): array
    {
        $znalezione = [];

        foreach ($xpath->query('.//a', $wycinek) as $odnosnik) {
            if ($odnosnik instanceof DOMElement && $this->widocznyNapis($odnosnik) === $napis) {
                $znalezione[] = $odnosnik;
            }
        }

        return $znalezione;
    }

    /**
     * Tekst elementu bez tego, co widzi wyłącznie czytnik ekranu.
     */
    protected function widocznyNapis(DOMElement $element): string
    {
        $dom = new DOMDocument;
        $dom->appendChild($dom->importNode($element->cloneNode(true), true));

        $xpath = new DOMXPath($dom);
        $schowane = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' visually-hidden ')]");

        foreach (iterator_to_array($schowane ?: []) as $wezel) {
            $wezel->parentNode?->removeChild($wezel);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $dom->textContent));
    }
}

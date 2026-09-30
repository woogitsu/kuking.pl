<?php

declare(strict_types=1);

namespace App\Domain\Kanaly;

use XMLWriter;

/**
 * Zapis kanału do Atom 1.0 (RFC 4287) — #2227.
 *
 * DLACZEGO `XMLWriter`, A NIE WIDOK BLADE
 * Treść pisze człowiek: `<`, `&`, cudzysłów i znaki sterujące wklejone
 * z edytora. `XMLWriter` ucieka znaki specjalne sam, w każdym atrybucie
 * i w każdym tekście, więc nie ma miejsca, w którym ktoś zapomni `e()`.
 * Szablon Blade z `<?xml` wybuchał też kiedyś na produkcji przy
 * `short_open_tag` (`MapaStronyTest`).
 *
 * ZNAKI, KTÓRYCH XML 1.0 NIE DOPUSZCZA (np. U+0001 wklejone z Worda),
 * `XMLWriter` przepuszcza bez słowa — i wtedy czytnik odrzuca CAŁY kanał.
 * `tekst()` je wycina. Pilnuje `KanalyAtomTest::test_znaki_specjalne_...`.
 *
 * JEDEN FORMAT. RSS 2.0 nie dokłada niczego, czego czytniki nie czytają
 * z Atomu, a drugi format to druga kopia tej samej granicy widoczności.
 */
final class ZapisAtom
{
    public const TYP = 'application/atom+xml; charset=utf-8';

    public function xml(Kanal $kanal): string
    {
        $x = new XMLWriter;
        $x->openMemory();
        $x->setIndent(true);
        $x->startDocument('1.0', 'UTF-8');

        $x->startElement('feed');
        $x->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $x->writeAttribute('xml:lang', 'pl');

        $x->writeElement('id', $this->tekst($kanal->id));
        $this->tekstowy($x, 'title', $kanal->tytul);
        $this->tekstowy($x, 'subtitle', $kanal->podtytul);
        $x->writeElement('updated', $kanal->zmieniono->toAtomString());
        $this->link($x, 'self', $kanal->adresKanalu, 'application/atom+xml');
        $this->link($x, 'alternate', $kanal->adresStrony, 'text/html');
        $this->autor($x, $kanal->autorNazwa, $kanal->autorAdres);
        $x->writeElement('generator', 'Kuking');

        foreach ($kanal->pozycje as $pozycja) {
            $x->startElement('entry');
            $x->writeElement('id', $this->tekst($pozycja->id));
            $this->tekstowy($x, 'title', $pozycja->tytul);
            $this->link($x, 'alternate', $pozycja->adres, 'text/html');
            if ($pozycja->opublikowano !== null) {
                $x->writeElement('published', $pozycja->opublikowano->toAtomString());
            }
            $x->writeElement('updated', $pozycja->zmieniono->toAtomString());
            $this->autor($x, $pozycja->autorNazwa, $pozycja->autorAdres);
            if ($pozycja->streszczenie !== null && $pozycja->streszczenie !== '') {
                $this->tekstowy($x, 'summary', $pozycja->streszczenie);
            }
            if ($pozycja->zdjecie !== null) {
                // Warianty zdjęć są w WebP (`Media::kluczPublicznegoWariantu()`).
                $this->link($x, 'enclosure', $pozycja->zdjecie, 'image/webp');
            }
            $x->endElement();
        }

        $x->endElement();
        $x->endDocument();

        return $x->outputMemory();
    }

    private function tekstowy(XMLWriter $x, string $nazwa, string $tresc): void
    {
        $x->startElement($nazwa);
        $x->writeAttribute('type', 'text');
        $x->text($this->tekst($tresc));
        $x->endElement();
    }

    private function link(XMLWriter $x, string $rel, string $adres, string $typ): void
    {
        $x->startElement('link');
        $x->writeAttribute('rel', $rel);
        $x->writeAttribute('type', $typ);
        $x->writeAttribute('href', $this->tekst($adres));
        $x->endElement();
    }

    private function autor(XMLWriter $x, string $nazwa, ?string $adres): void
    {
        $x->startElement('author');
        // `<name>` jest w Atom obowiązkowe i nie może być puste.
        $x->writeElement('name', $this->tekst($nazwa) !== '' ? $this->tekst($nazwa) : 'Kuking');
        if ($adres !== null) {
            $x->writeElement('uri', $this->tekst($adres));
        }
        $x->endElement();
    }

    /** Wycina znaki spoza XML 1.0 i nieprawidłowe UTF-8. */
    private function tekst(string $tresc): string
    {
        $utf8 = mb_scrub($tresc, 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $utf8);
    }
}

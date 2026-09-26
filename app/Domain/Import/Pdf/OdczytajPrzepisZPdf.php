<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\ParserTekstuPrzepisu;

/**
 * Plik PDF → odczytany przepis, w całości LOKALNIE (D-300): `pdftotext`
 * i prosty parser nagłówków. Bez modelu i bez kosztu.
 *
 * PDF bez warstwy tekstu (skan) kończy się `PDF_BEZ_TEKSTU` — to jest
 * jedyny przypadek PDF-a, w którym potrzebny byłby model (odczyt obrazu
 * stron ścieżką OCR z fundamentu importu).
 */
final class OdczytajPrzepisZPdf
{
    public function __construct(
        private readonly TekstZPdf $tekst,
        private readonly ParserTekstuPrzepisu $parser,
    ) {}

    /**
     * @throws ImportOdrzucony
     */
    public function handle(string $sciezka): OdczytanyPrzepis
    {
        $przepis = $this->parser->odczytaj($this->tekst->odczytaj($sciezka));

        if ($przepis === null) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_BEZ_TEKSTU);
        }

        return $przepis;
    }
}

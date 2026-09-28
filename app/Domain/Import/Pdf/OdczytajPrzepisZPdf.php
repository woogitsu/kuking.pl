<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\ParserTekstuPrzepisu;
use App\Models\User;

/**
 * PDF z tekstem jest odczytywany lokalnie; wyłącznie skan trafia do OCR
 * po zgodzie osoby i rezerwacji w globalnym budżecie AI.
 */
final class OdczytajPrzepisZPdf
{
    public function __construct(
        private readonly TekstZPdf $tekst,
        private readonly ParserTekstuPrzepisu $parser,
        private readonly OdczytajSkanPdf $skan,
    ) {}

    /**
     * @throws ImportOdrzucony
     */
    public function handle(string $sciezka, User $osoba, bool $chceZgody, string $probaId): OdczytanyPdf
    {
        try {
            $tekst = $this->tekst->odczytaj($sciezka);
        } catch (ImportOdrzucony $e) {
            if ($e->kod !== ImportOdrzucony::PDF_BEZ_TEKSTU) {
                throw $e;
            }

            return new OdczytanyPdf($this->skan->handle($sciezka, $osoba, $chceZgody, $probaId), 'ocr');
        }

        $przepis = $this->parser->odczytaj($tekst);

        if ($przepis === null) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_BRAK_PRZEPISU);
        }

        return new OdczytanyPdf($przepis, 'tekst_pdf');
    }
}

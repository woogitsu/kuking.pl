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
     * @param  list<int>|null  $wybraneStrony  tylko te strony (#2535); `null` = jak dotąd, pierwsze strony w limicie
     *
     * @throws ImportOdrzucony
     */
    public function handle(string $sciezka, User $osoba, bool $chceZgody, string $probaId, ?array $wybraneStrony = null): OdczytanyPdf
    {
        try {
            $tekst = $this->tekst->odczytaj($sciezka, $wybraneStrony);
        } catch (ImportOdrzucony $e) {
            if ($e->kod !== ImportOdrzucony::PDF_BEZ_TEKSTU) {
                throw $e;
            }

            return new OdczytanyPdf($this->skan->handle($sciezka, $osoba, $chceZgody, $probaId, $wybraneStrony), 'ocr');
        }

        $przepis = $this->parser->odczytaj($tekst);

        if ($przepis === null) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_BRAK_PRZEPISU);
        }

        return new OdczytanyPdf($przepis, 'tekst_pdf');
    }
}

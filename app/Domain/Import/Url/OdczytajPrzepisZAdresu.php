<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\TrybFragmentow;
use App\Domain\Import\WyznaczaczFragmentow;

/**
 * Adres strony → odczytany przepis (D-300).
 *
 *  1. `PobieraczStron` — SSRF, robots.txt, limity; zdjęć nie pobiera;
 *  2. `ParserJsonLdPrzepisu` — lokalnie, bez modelu i bez kosztu;
 *  3. dopiero gdy strona NIE MA danych `Recipe`: czysty tekst strony
 *     → `WyznaczaczFragmentow` (model zwraca same granice) → `TrybFragmentow`
 *     składa tekst z oryginału.
 *
 * Model nie dostaje adresu strony (nie ma czego otworzyć sam) ani HTML-a —
 * tylko ponumerowane wiersze czystego tekstu.
 */
final class OdczytajPrzepisZAdresu
{
    public function __construct(
        private readonly PobieraczStron $pobieracz,
        private readonly ParserJsonLdPrzepisu $jsonLd,
        private readonly WyznaczaczFragmentow $fragmenty,
        private readonly TrybFragmentow $tryb,
    ) {}

    /**
     * @throws ImportOdrzucony
     */
    public function handle(string $url): OdczytanaStrona
    {
        $strona = $this->pobieracz->pobierz($url);

        $przepis = $this->jsonLd->odczytaj($strona->html);

        if ($przepis !== null) {
            return new OdczytanaStrona($przepis, $strona->url, OdczytanaStrona::JSON_LD);
        }

        $wiersze = TekstStrony::wiersze($strona->html);

        if ($wiersze === []) {
            throw new ImportOdrzucony(ImportOdrzucony::BRAK_PRZEPISU);
        }

        $przepis = $this->tryb->zloz($wiersze, $this->fragmenty->fragmenty($wiersze));

        if ($przepis === null) {
            throw new ImportOdrzucony(ImportOdrzucony::BRAK_PRZEPISU);
        }

        return new OdczytanaStrona($przepis, $strona->url, OdczytanaStrona::FRAGMENTY);
    }
}

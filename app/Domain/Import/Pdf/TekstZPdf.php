<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf;

use App\Domain\Import\ImportOdrzucony;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/**
 * Warstwa tekstu z pliku PDF — narzędziami `poppler-utils` (`pdfinfo`,
 * `pdftotext`), LOKALNIE, bez modelu AI (D-300).
 *
 * `poppler-utils` to pakiet systemowy w obrazie Dockera (Dockerfile,
 * etap `runtime`), nie zależność Composera. Brak narzędzia = komunikat
 * „odczyt PDF chwilowo nie działa", nie błąd 500.
 *
 * Kolejność kontroli, od najtańszej:
 *  1. rozmiar w bajtach (`pdf_max_mb`),
 *  2. sygnatura `%PDF-` w pierwszym kilobajcie — nie ufamy rozszerzeniu ani
 *     typowi MIME od przeglądarki (AGENTS.md §7),
 *  3. `pdfinfo`: zaszyfrowany? uszkodzony? ile stron (`pdf_max_stron`)?
 *  4. `pdftotext -l <limit>` z limitem czasu — tylko dozwolone strony.
 *
 * Plik jest przekazywany jako argument procesu w tablicy (bez powłoki),
 * więc nazwa pliku nie może stać się poleceniem.
 */
final class TekstZPdf
{
    /** Tyle znaków „prawdziwego" tekstu musi być, żeby uznać warstwę tekstu za istniejącą. */
    public const MIN_ZNAKOW_TEKSTU = 20;

    /**
     * @throws ImportOdrzucony
     */
    public function odczytaj(string $sciezka): string
    {
        $maksMb = $this->maksMb();
        $rozmiar = @filesize($sciezka);

        if ($rozmiar === false || $rozmiar === 0) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
        }

        if ($rozmiar > $maksMb * 1024 * 1024) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_ZA_DUZY, ['mb' => $maksMb]);
        }

        $poczatek = (string) @file_get_contents($sciezka, false, null, 0, 1024);

        if (! str_contains($poczatek, '%PDF-')) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
        }

        $strony = $this->liczbaStron($sciezka);
        $maksStron = $this->maksStron();

        if ($strony > $maksStron) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_ZA_DUZO_STRON, ['strony' => $maksStron]);
        }

        try {
            $wynik = Process::timeout($this->limitCzasu())->run([
                'pdftotext', '-enc', 'UTF-8', '-f', '1', '-l', (string) $maksStron, '-q', $sciezka, '-',
            ]);
        } catch (ProcessTimedOutException) {
            // Plik, którego odczyt trwa dłużej niż limit, to w praktyce plik
            // spreparowany albo zepsuty — nie czekamy na niego dłużej.
            throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
        }

        if ($this->brakNarzedzia($wynik->exitCode())) {
            throw new ImportOdrzucony(ImportOdrzucony::NARZEDZIE_PDF_NIEDOSTEPNE);
        }

        if (! $wynik->successful()) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
        }

        $tekst = $wynik->output();

        if (! mb_check_encoding($tekst, 'UTF-8')) {
            $tekst = (string) mb_convert_encoding($tekst, 'UTF-8', 'UTF-8');
        }

        if (mb_strlen((string) preg_replace('/\s+/u', '', $tekst)) < self::MIN_ZNAKOW_TEKSTU) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_BEZ_TEKSTU);
        }

        return $tekst;
    }

    /**
     * @throws ImportOdrzucony
     */
    private function liczbaStron(string $sciezka): int
    {
        try {
            $wynik = Process::timeout($this->limitCzasu())->run(['pdfinfo', $sciezka]);
        } catch (ProcessTimedOutException) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
        }

        $wyjscie = $wynik->output()."\n".$wynik->errorOutput();

        if ($this->brakNarzedzia($wynik->exitCode())) {
            throw new ImportOdrzucony(ImportOdrzucony::NARZEDZIE_PDF_NIEDOSTEPNE);
        }

        if (stripos($wyjscie, 'Incorrect password') !== false || preg_match('/^Encrypted:\s+yes/mi', $wynik->output()) === 1) {
            // Plik z samym hasłem właściciela (bez hasła otwarcia) pdftotext
            // przeczyta; plik z hasłem otwarcia — nie. Rozróżnia to kod wyjścia.
            if (! $wynik->successful()) {
                throw new ImportOdrzucony(ImportOdrzucony::PDF_ZASZYFROWANY);
            }
        }

        if (! $wynik->successful()) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
        }

        if (preg_match('/^Pages:\s+(\d+)/mi', $wynik->output(), $m) !== 1) {
            throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
        }

        return (int) $m[1];
    }

    private function brakNarzedzia(?int $kod): bool
    {
        // Symfony Process uruchamia polecenie przez `exec` w sh — brak
        // programu to kod 127 („not found"), nie wyjątek.
        return $kod === 127;
    }

    private function maksMb(): int
    {
        return (int) config('kuking.import.pdf.max_mb', 10);
    }

    private function maksStron(): int
    {
        return (int) config('kuking.import.pdf.max_stron', 5);
    }

    private function limitCzasu(): int
    {
        return (int) config('kuking.import.pdf.limit_czasu', 20);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Import\Pdf;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\OdczytKartki;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\PlatnyOdczytImportu;
use App\Models\User;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;

/** Renderuje wyłącznie strony sprawdzonego PDF do ograniczonych obrazów bez metadanych. */
final class OdczytajSkanPdf
{
    public function __construct(private readonly PlatnyOdczytImportu $model) {}

    public function handle(string $sciezka, User $osoba, bool $chceZgody, string $probaId): OdczytanyPrzepis
    {
        $katalog = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kuking-pdf-'.bin2hex(random_bytes(12));
        if (! @mkdir($katalog, 0700)) {
            throw new ImportOdrzucony(ImportOdrzucony::NARZEDZIE_PDF_NIEDOSTEPNE);
        }

        try {
            $prefiks = $katalog.DIRECTORY_SEPARATOR.'strona';
            try {
                $wynik = Process::timeout(max(1, (int) config('kuking.import.pdf.limit_czasu', 20)))
                    ->run(['pdftoppm', '-f', '1', '-l', (string) $this->maksStron(),
                        '-jpeg', '-r', '120', '-scale-to', '1600', '-q', $sciezka, $prefiks]);
            } catch (ProcessTimedOutException) {
                throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
            }
            if ($wynik->exitCode() === 127) {
                throw new ImportOdrzucony(ImportOdrzucony::NARZEDZIE_PDF_NIEDOSTEPNE);
            }
            if (! $wynik->successful()) {
                throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
            }

            $pliki = glob($prefiks.'-*.jpg') ?: [];
            sort($pliki, SORT_NATURAL);
            $maks = $this->maksStron();
            if ($pliki === [] || count($pliki) > $maks) {
                throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
            }

            $tresc = [['type' => 'input_text', 'text' => 'Przepisz przepis z kolejnych stron skanu PDF. Zachowaj kolejność stron.']];
            foreach ($pliki as $plik) {
                $rozmiar = @filesize($plik);
                if ($rozmiar === false || $rozmiar < 1 || $rozmiar > 4 * 1024 * 1024) {
                    throw new ImportOdrzucony(ImportOdrzucony::PDF_USZKODZONY);
                }
                $tresc[] = ['type' => 'input_image', 'image_url' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($plik)), 'detail' => 'high'];
            }

            try {
                $dane = $this->model->odczytaj(
                    $osoba, $chceZgody, $probaId, KlientLuna::ZADANIE_OCR,
                    OdczytKartki::INSTRUKCJA, $tresc, OdczytKartki::NAZWA_SCHEMATU,
                    OdczytKartki::schemat(), count($pliki),
                );
            } catch (ImportOdrzucony $e) {
                if ($e->kod === ImportOdrzucony::BRAK_PRZEPISU) {
                    throw new ImportOdrzucony(ImportOdrzucony::PDF_BRAK_PRZEPISU);
                }
                throw $e;
            }
            $odczyt = OdczytKartki::wynik($dane);
            if ($odczyt === null) {
                throw new ImportOdrzucony(ImportOdrzucony::PDF_BRAK_PRZEPISU);
            }

            $porcje = trim((string) ($odczyt['porcje'] ?? ''));

            return new OdczytanyPrzepis(
                tytul: $odczyt['tytul'] ?? 'Przepis ze skanu PDF',
                opis: $odczyt['uwagi'],
                porcje: preg_match('/^\d{1,3}$/', $porcje) === 1 ? (float) $porcje : null,
                skladniki: array_map(static fn (array $wiersz): string => $wiersz['text'], $odczyt['skladniki']),
                kroki: array_map(static fn (array $wiersz): string => $wiersz['instruction'], $odczyt['kroki']),
            );
        } finally {
            foreach (glob($katalog.DIRECTORY_SEPARATOR.'*') ?: [] as $plik) {
                @unlink($plik);
            }
            @rmdir($katalog);
        }
    }

    private function maksStron(): int
    {
        return min(5, max(1, (int) config('kuking.import.pdf.max_stron', 5)));
    }
}

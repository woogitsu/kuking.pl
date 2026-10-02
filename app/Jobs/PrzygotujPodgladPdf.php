<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Pdf\TekstZPdf;
use App\Models\User;
use App\Support\Storage\PlikTymczasowyImportu;
use App\Support\Storage\PoczekalniaPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Podgląd stron PDF-a przed odczytem (#2535) — poza żądaniem WWW, bo Poppler
 * (`pdfinfo`, `pdftotext`, `pdftoppm`) działa tylko w procesie kolejki (#2293).
 *
 * BEZ MODELU AI I BEZ ZGODY: to wyłącznie lokalne narzędzia systemowe. Zadanie
 * sprawdza plik tymi samymi kontrolami co odczyt (rozmiar, sygnatura,
 * szyfrowanie, limit liczby WSZYSTKICH stron), robi małe miniatury każdej strony
 * i krótki fragment jej tekstu (podpis dla osób, które nie rozpoznają przepisu
 * po obrazku), po czym zapisuje opis `gotowy` w poczekalni. Powód odmowy trafia
 * do opisu jako zdanie po polsku. Nic nie opuszcza serwisu.
 *
 * JEDNA PRÓBA (`$tries = 1`): ponowienie to nowe wysłanie pliku.
 */
class PrzygotujPodgladPdf implements ShouldQueue
{
    use Queueable;

    /** Do 5 stron: pdfinfo + (pdftotext + pdftoppm) na stronę, po 20 s każdy, plus zapis miniatur. */
    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(public string $osobaId, public string $token)
    {
        $this->onQueue((string) config('kuking.import.kolejka', 'low'));
    }

    public function handle(PoczekalniaPdf $poczekalnia, TekstZPdf $tekst, PlikTymczasowyImportu $pliki): void
    {
        if (User::query()->whereKey($this->osobaId)->doesntExist()) {
            return;
        }

        $lokalna = $poczekalnia->plikLokalny($this->osobaId, $this->token);

        if ($lokalna === null) {
            // Pozycji nie ma (wygasła, odrzucona) — nie ma komu zapisywać opisu.
            return;
        }

        try {
            $liczba = $tekst->sprawdzDoWyboru($lokalna);
            $fragmenty = [];

            for ($strona = 1; $strona <= $liczba; $strona++) {
                $fragmenty[] = $this->fragment($tekst->tekstStrony($lokalna, $strona));
                $miniatura = $this->miniatura($lokalna, $strona);

                if ($miniatura !== null) {
                    $poczekalnia->zapiszMiniature($this->osobaId, $this->token, $strona, $miniatura);
                }
            }

            $poczekalnia->zapiszOpis($this->osobaId, $this->token, [
                'stan' => PoczekalniaPdf::STAN_GOTOWY,
                'strony' => $liczba,
                'fragmenty' => $fragmenty,
            ]);
        } catch (ImportOdrzucony $e) {
            $poczekalnia->zapiszOpis($this->osobaId, $this->token, [
                'stan' => PoczekalniaPdf::STAN_BLAD,
                'komunikat' => $e->getMessage(),
            ]);
        } finally {
            $pliki->usunLokalna($lokalna);
        }
    }

    /** Zadanie padło (timeout, wyjątek): człowiek dostaje zdanie, co zrobić, a nie wieczne „przygotowujemy”. */
    public function failed(?Throwable $blad): void
    {
        Log::warning('Podgląd stron PDF nie powiódł się.', ['stage' => 'import_pdf_podglad_failed']);

        app(PoczekalniaPdf::class)->zapiszOpis($this->osobaId, $this->token, [
            'stan' => PoczekalniaPdf::STAN_BLAD,
            'komunikat' => 'Nie udało się przygotować podglądu stron tego pliku. Wyślij plik jeszcze raz za chwilę albo wpisz przepis ręcznie.',
        ]);
    }

    private function fragment(string $tekst): string
    {
        $czysty = trim((string) preg_replace('/\s+/u', ' ', $tekst));

        return mb_strlen($czysty) > 90 ? rtrim(mb_substr($czysty, 0, 90)).'…' : $czysty;
    }

    /** Mała miniatura strony (JPEG) albo `null`, gdy się nie udała — strona zostaje wybieralna po numerze. */
    private function miniatura(string $sciezka, int $strona): ?string
    {
        $katalog = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kuking-pdf-m-'.bin2hex(random_bytes(12));

        if (! @mkdir($katalog, 0700)) {
            return null;
        }

        try {
            try {
                $wynik = Process::timeout(max(1, (int) config('kuking.import.pdf.limit_czasu', 20)))
                    ->run(['pdftoppm', '-f', (string) $strona, '-l', (string) $strona,
                        '-jpeg', '-r', '50', '-scale-to', '360', '-q', $sciezka, $katalog.DIRECTORY_SEPARATOR.'m']);
            } catch (ProcessTimedOutException) {
                return null;
            }

            if (! $wynik->successful()) {
                return null;
            }

            $pliki = glob($katalog.DIRECTORY_SEPARATOR.'m-*.jpg') ?: [];

            if (count($pliki) !== 1) {
                return null;
            }

            $rozmiar = @filesize($pliki[0]);

            if ($rozmiar === false || $rozmiar < 1 || $rozmiar > 1024 * 1024) {
                return null;
            }

            $tresc = file_get_contents($pliki[0]);

            return is_string($tresc) ? $tresc : null;
        } finally {
            foreach (glob($katalog.DIRECTORY_SEPARATOR.'*') ?: [] as $plik) {
                @unlink($plik);
            }
            @rmdir($katalog);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\Pdf\OdczytajPrzepisZPdf;
use App\Domain\Import\RozliczenieOdczytu;
use App\Domain\Posts\KontoNieMozePublikowac;
use App\Models\ImportPrzepisu;
use App\Models\PrzepisZImportu;
use App\Moderacja\ExceptionContext;
use App\Support\Storage\PlikTymczasowyImportu;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Import przepisu z wysłanego pliku PDF poza żądaniem WWW (V2, D-300, #28
 * etap 2) — bliźniak `ImportujPrzepisZAdresu`.
 *
 * KOLEJKA `low`, jak odczyt zdjęcia kartki i adresu. Wejście — plik — leży
 * na prywatnym dysku importu, a jego ścieżka w wierszu zlecenia
 * (`importy_przepisow.plik_tymczasowy`), więc zadanie nie zależy od niczego,
 * co żyło tylko w procesie WWW. Zgoda na wysłanie SKANU do modelu jedzie
 * w samym zadaniu (`$zgodaAi`): to zgoda z jednego formularza, nie z ustawień
 * konta (#2031). PDF z warstwą tekstu jest czytany lokalnie i do modelu nie
 * trafia w ogóle.
 *
 * JEDNA PRÓBA, ŚWIADOMIE (`$tries = 1`) — z tego samego powodu co przy
 * adresie: płatny odczyt skanu nie może pójść drugi raz dla tej samej
 * próby (klucz rezerwacji `(próba, 1)`). Zadanie zabite w środku wraca po
 * `retry_after`, widzi przekroczony limit prób i kończy się w `failed()`.
 *
 * PLIK ZNIKA W KAŻDYM STANIE KOŃCOWYM (#2051): sukces, odmowa, `failed()`;
 * a jeśli i to zawiedzie, `kuking:odzyskaj-importy` kasuje go po retencji.
 * Kopia robocza dla Popplera w katalogu tymczasowym systemu jest kasowana
 * w `finally`.
 */
class ImportujPrzepisZPdf implements ShouldQueue
{
    use Queueable;

    /** pdfinfo + pdftotext + pdftoppm po 20 s, jedno żądanie do modelu do 110 s, pobranie pliku i zapis. */
    public int $timeout = 200;

    public int $tries = 1;

    public function __construct(public string $importId, public bool $zgodaAi = false)
    {
        $this->onQueue((string) config('kuking.import.kolejka', 'low'));
    }

    public function handle(OdczytajPrzepisZPdf $odczyt, ZapiszSzkicZImportu $zapiszSzkic, PlikTymczasowyImportu $pliki): void
    {
        $zlecenie = ImportPrzepisu::query()->find($this->importId);

        if ($zlecenie === null || $zlecenie->jestKoncowy()) {
            // Stan końcowy już jest — plik, jeśli jeszcze został, nie jest potrzebny.
            if ($zlecenie !== null) {
                $this->zwolnijPlik($zlecenie, $pliki);
            }

            return;
        }

        $zlecenie->forceFill(['status' => ImportPrzepisu::STATUS_W_TOKU, 'rozpoczeto_at' => $zlecenie->rozpoczeto_at ?? now()])->save();

        $probaId = DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->value('id');
        $sciezka = $zlecenie->plik_tymczasowy;

        if ($sciezka === null || $sciezka === '' || $probaId === null || ! $zlecenie->zPdf()) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $pliki);

            return;
        }

        if (! (bool) config('kuking.import.pdf.wlaczony')) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_WYLACZONY, $pliki);

            return;
        }

        $lokalna = $pliki->pobierzDoLokalnego($sciezka);

        if ($lokalna === null) {
            // Pliku nie ma albo dysk nie odpowiada — człowiek dostaje „wyślij jeszcze raz”.
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $pliki);

            return;
        }

        try {
            $pdf = $odczyt->handle($lokalna, $zlecenie->user, $this->zgodaAi, (string) $probaId);
        } catch (ImportOdrzucony $e) {
            $this->zakoncz($zlecenie, self::kodZlecenia($e->kod), $pliki);

            return;
        } finally {
            $pliki->usunLokalna($lokalna);
        }

        $this->zapisz($zlecenie, $zapiszSzkic, $pdf->droga, $pdf->przepis, $pliki);
    }

    /** Zadanie padło (timeout, wyjątek, przekroczony limit prób) — plik i rezerwacja nie mogą wisieć. */
    public function failed(?Throwable $blad): void
    {
        $probaId = DB::table('proby_importu')->where('import_id', $this->importId)->value('id');

        if ($probaId !== null) {
            app(RozliczenieOdczytu::class)->zamknijPorzucone((string) $probaId);
        }

        $zlecenie = ImportPrzepisu::query()->find($this->importId);

        if ($zlecenie === null) {
            return;
        }

        $pliki = app(PlikTymczasowyImportu::class);

        if ($zlecenie->jestKoncowy()) {
            $this->zwolnijPlik($zlecenie, $pliki);

            return;
        }

        Log::warning('Import przepisu z pliku PDF nie powiódł się.', [
            'import_id' => $this->importId,
            ...($blad === null ? ['stage' => 'import_pdf_failed'] : ExceptionContext::forStage($blad, 'import_pdf_failed')),
        ]);

        $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $pliki);
    }

    /** Kod odmowy odczytu PDF-a → kod zlecenia (zamknięta lista w CHECK). */
    private static function kodZlecenia(string $kod): string
    {
        return match (true) {
            in_array($kod, ImportPrzepisu::KODY_PDF, true) => $kod,
            $kod === ImportOdrzucony::BRAK_ZGODY_AI => ImportPrzepisu::KOD_BRAK_ZGODY,
            $kod === ImportOdrzucony::BUDZET_AI => ImportPrzepisu::KOD_BUDZET_DZIENNY,
            $kod === ImportOdrzucony::MODEL_NIEDOSTEPNY => ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY,
            default => ImportPrzepisu::KOD_BLAD_WEWNETRZNY,
        };
    }

    private function zapisz(
        ImportPrzepisu $zlecenie,
        ZapiszSzkicZImportu $zapiszSzkic,
        string $droga,
        OdczytanyPrzepis $przepis,
        PlikTymczasowyImportu $pliki,
    ): void {
        try {
            DB::transaction(function () use ($zlecenie, $zapiszSzkic, $droga, $przepis): void {
                $biezace = ImportPrzepisu::query()->whereKey($zlecenie->getKey())->lockForUpdate()->first();

                // Domknięte w międzyczasie (odzyskiwanie po czasie) — stan końcowy już jest.
                if ($biezace === null || $biezace->jestKoncowy()) {
                    return;
                }

                $recipe = $zapiszSzkic->handle(
                    autor: $zlecenie->user,
                    zrodlo: PrzepisZImportu::ZRODLO_PDF,
                    droga: $droga,
                    przepis: $przepis,
                );

                $zlecenie->forceFill([
                    'recipe_id' => $recipe->getKey(),
                    'status' => ImportPrzepisu::STATUS_GOTOWY,
                    'kod_bledu' => null,
                    'zakonczono_at' => now(),
                ])->save();

                DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->update([
                    'status' => 'gotowy',
                    'recipe_id' => $recipe->getKey(),
                    'updated_at' => now(),
                ]);
            });
        } catch (KontoNieMozePublikowac) {
            // #2189: zawieszone konto nie dopisuje treści. Transakcja wróciła w całości.
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $pliki);

            return;
        }

        $this->zwolnijPlik($zlecenie->refresh(), $pliki);

        Log::info('Import przepisu zakończony szkicem.', [
            'stage' => 'import_przepisu',
            'zrodlo' => 'pdf',
            'droga' => $droga,
            'kod' => null,
        ]);
    }

    private function zakoncz(ImportPrzepisu $zlecenie, string $kod, PlikTymczasowyImportu $pliki): void
    {
        $zlecenie->forceFill([
            'status' => ImportPrzepisu::STATUS_NIEUDANY,
            'kod_bledu' => $kod,
            'zakonczono_at' => now(),
        ])->save();

        DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->update([
            'status' => 'nieudany',
            'updated_at' => now(),
        ]);

        $this->zwolnijPlik($zlecenie, $pliki);
    }

    /**
     * Kasuje plik z dysku, a dopiero potem zeruje ścieżkę w wierszu — gdy dysk
     * odmówi, ścieżka zostaje i `kuking:odzyskaj-importy` ponowi kasowanie.
     */
    private function zwolnijPlik(ImportPrzepisu $zlecenie, PlikTymczasowyImportu $pliki): void
    {
        if ($zlecenie->plik_tymczasowy === null) {
            return;
        }

        if ($pliki->skasuj($zlecenie->plik_tymczasowy)) {
            $zlecenie->forceFill(['plik_tymczasowy' => null])->save();
        }
    }
}

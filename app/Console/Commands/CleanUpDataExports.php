<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DataExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Kasowanie wygasłych paczek z danymi.
 *
 * Paczka to kopia CAŁEGO konta — z e-mailem, wszystkimi treściami i zdjęciami.
 * Trzymanie jej w storage bez końca to zbieranie danych „na zapas”, czyli
 * dokładnie to, czego zasada minimalizacji z RODO zabrania. Do tego każdy
 * dzień życia takiego pliku to jeden dzień więcej ryzyka wycieku.
 *
 * Komenda jest bezpieczna do wielokrotnego uruchomienia: kasuje tylko paczki
 * po `expires_at` i nigdy nie rusza samego rekordu (historia „poprosiłam
 * o eksport 3 marca” zostaje, znika tylko plik).
 */
class CleanUpDataExports extends Command
{
    protected $signature = 'kuking:sprzataj-eksporty
                            {--dry-run : Pokaż, co zostałoby usunięte, i nic nie kasuj}';

    protected $description = 'Kasuje wygasłe paczki z danymi użytkowników i oznacza je jako wygasłe';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $expired = DataExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereIn('status', [DataExport::STATUS_READY, DataExport::STATUS_EXPIRED])
            ->orderBy('expires_at')
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Nie ma wygasłych paczek do usunięcia.');

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($expired as $export) {
            $label = $export->getKey().' (wygasła '.$export->expires_at->format('Y-m-d H:i').')';

            if ($dryRun) {
                $this->line('Do usunięcia: '.$label);

                continue;
            }

            if ($export->disk !== null && $export->object_key !== null) {
                try {
                    Storage::disk($export->disk)->delete($export->object_key);
                } catch (Throwable $e) {
                    // Brak pliku nie może zablokować sprzątania reszty —
                    // inaczej jedna zepsuta paczka trzyma w storage sto innych.
                    Log::warning('Nie udało się usunąć wygasłej paczki z danymi', [
                        'data_export_id' => $export->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $export->update([
                'status' => DataExport::STATUS_EXPIRED,
                'disk' => null,
                'object_key' => null,
                'bytes' => null,
            ]);

            $removed++;
            $this->line('Usunięto: '.$label);
        }

        $this->info($dryRun
            ? 'Tryb podglądu: znaleziono '.$this->paczki($expired->count()).'.'
            : 'Gotowe. Usunięto '.$this->paczki($removed).'.',
        );

        return self::SUCCESS;
    }

    /** Polska odmiana: „1 wygasłą paczkę”, „2 wygasłe paczki”, „5 wygasłych paczek”. */
    private function paczki(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($n === 1) {
            return '1 wygasłą paczkę';
        }

        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) {
            return $n.' wygasłe paczki';
        }

        return $n.' wygasłych paczek';
    }
}

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
            ->where(function ($query): void {
                $query->where('status', DataExport::STATUS_READY)
                    ->orWhere(function ($query): void {
                        $query->where('status', DataExport::STATUS_EXPIRED)
                            ->whereNotNull('disk')
                            ->whereNotNull('object_key');
                    });
            })
            // UUID jest stabilnym kursorem. Nie używamy offsetu: każdy udany
            // przebieg zmienia rekord tak, że wypada on ze zbioru retry.
            // `lazyById` trzyma w pamięci najwyżej jedną partię, a nie całą
            // historię eksportów.
            ->lazyById(100);

        $removed = 0;
        $nieudane = 0;
        $znalezione = 0;

        foreach ($expired as $export) {
            $znalezione++;
            $label = $export->getKey().' (wygasła '.$export->expires_at->format('Y-m-d H:i').')';

            if ($dryRun) {
                $this->line('Do usunięcia: '.$label);

                continue;
            }

            $skasowano = $this->skasujPlik($export);

            // STATUS ZMIENIAMY ZAWSZE — plik ma przestać być do pobrania
            // niezależnie od tego, czy udało się go usunąć ze storage.
            //
            // ADRESU NIE KASUJEMY, DOPÓKI PLIKU NAPRAWDĘ NIE MA (audyt W3-02).
            // Wcześniej `disk` i `object_key` znikały bezwarunkowo, także po
            // nieudanym kasowaniu — i to jest gorsze niż sama nieudana próba.
            // Ta paczka to kopia CAŁEGO konta: e-mail, wszystkie treści,
            // wszystkie zdjęcia. Wyczyszczenie adresu zamieniało odwracalną
            // awarię sprzątania w plik, o którym nikt już nie wie, gdzie leży
            // — czyli w bezterminowe przechowywanie danych osobowych, wbrew
            // zasadzie minimalizacji, na którą powołuje się ta komenda.
            //
            // Z zachowanym adresem następne uruchomienie spróbuje ponownie:
            // zapytanie wyżej obejmuje też paczki w stanie `expired`.
            $export->update($skasowano
                ? [
                    'status' => DataExport::STATUS_EXPIRED,
                    'disk' => null,
                    'object_key' => null,
                    'bytes' => null,
                ]
                : ['status' => DataExport::STATUS_EXPIRED]);

            if ($skasowano) {
                $removed++;
                $this->line('Usunięto: '.$label);
            } else {
                $nieudane++;
                $this->line('NIE UDAŁO SIĘ usunąć pliku (adres zachowany, spróbuję ponownie): '.$label);
            }
        }

        if ($znalezione === 0) {
            $this->info('Nie ma wygasłych paczek do usunięcia.');

            return self::SUCCESS;
        }

        if ($nieudane > 0) {
            // CZĘŚCIOWA PORAŻKA TO PORAŻKA (#1534, wzorem #1342). Paczka,
            // której nie udało się usunąć, leży dalej w storage — to kopia
            // całego konta. Kod ≠ 0 jest jedynym sygnałem, który widzi
            // harmonogram: `Harmonogram::artisan()` zamienia go w wyjątek.
            $this->error('Nie udało się usunąć '.$this->paczki($nieudane)
                .' (kandydatów: '.$znalezione.', usunięto: '.$removed.', błędy: '.$nieudane.').'
                .' Adresy zachowane — następne uruchomienie spróbuje ponownie. Szczegóły w logu.');

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Tryb podglądu: znaleziono '.$this->paczki($znalezione).'.'
            : 'Gotowe. Usunięto '.$this->paczki($removed).'.',
        );

        return self::SUCCESS;
    }

    /** Polska odmiana: „1 wygasłą paczkę”, „2 wygasłe paczki”, „5 wygasłych paczek”. */
    /**
     * Kasuje plik paczki i SPRAWDZA, czy naprawdę zniknął.
     *
     * Samo `delete()` nie wystarcza jako dowód. Dysk `local` ma
     * `throw => false`, więc zwraca `false` zamiast rzucić wyjątek — a stary
     * kod nie patrzył ani na wyjątek, ani na wynik. Nieudane kasowanie
     * wyglądało dokładnie tak samo jak udane.
     *
     * Stąd `exists()` po fakcie: to jedyna odpowiedź, która nie zależy od
     * tego, jak skonfigurowany jest dysk.
     */
    private function skasujPlik(DataExport $export): bool
    {
        if ($export->disk === null && $export->object_key === null) {
            // Nie ma czego kasować — plik zniknął przy wcześniejszym przebiegu
            // albo nigdy nie powstał. To jest sukces, nie awaria.
            return true;
        }

        if ($export->disk === null || $export->object_key === null) {
            // Połowa adresu nie pozwala ani znaleźć pliku, ani uczciwie
            // stwierdzić, że go nie ma. Zachowujemy to, co zostało, i
            // zostawiamy ślad operatorowi zamiast udawać udane kasowanie.
            Log::error('Paczka z danymi ma niepełny adres pliku', [
                'data_export_id' => $export->getKey(),
                'disk' => $export->disk,
                'object_key' => $export->object_key,
            ]);

            return false;
        }

        try {
            $dysk = Storage::disk($export->disk);
            $dysk->delete($export->object_key);

            if ($dysk->exists($export->object_key)) {
                Log::error('Paczka z danymi nadal istnieje po próbie usunięcia', [
                    'data_export_id' => $export->getKey(),
                    'disk' => $export->disk,
                    'object_key' => $export->object_key,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            // Brak pliku nie może zablokować sprzątania reszty — inaczej jedna
            // zepsuta paczka trzyma w storage sto innych. Ale MUSI zostawić
            // ślad z adresem: bez niego nie da się tego dokończyć ręcznie.
            Log::error('Nie udało się usunąć wygasłej paczki z danymi', [
                'data_export_id' => $export->getKey(),
                'disk' => $export->disk,
                'object_key' => $export->object_key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

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

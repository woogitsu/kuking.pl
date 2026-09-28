<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\DataExport;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WSPÓLNE PRZEJĘCIE REKORDU EKSPORTU (issue #2073).
 *
 * Dwie strony sięgają po ten sam obiekt w magazynie pod tym samym,
 * deterministycznym kluczem (`ExportFileNames::objectKey()`):
 *
 *  - nocne `kuking:sprzataj-eksporty` KASUJE go przy rekordzie `failed`
 *    bez `object_key` (osierocona paczka po zabitej próbie),
 *  - worker `GenerateUserExport` po `queue:retry` ZAPISUJE go od nowa
 *    i ustawia `ready`.
 *
 * Do 28 września 2026 sprzątanie kasowało plik na podstawie listy
 * kandydatów przeczytanej wcześniej, bez ponownego sprawdzenia i bez żadnej
 * synchronizacji z workerem. Przeplot „sprzątanie wybrało → worker zapisał
 * i zakończył → sprzątanie skasowało” zostawiał `ready` z działającym
 * linkiem do pliku, którego nie ma.
 *
 * Mechanizm to BLOKADA WIERSZA `data_exports`, nie nowy stan ani kolumna:
 *
 *  - sprzątanie bierze `FOR UPDATE` na wierszu, POD BLOKADĄ sprawdza jeszcze
 *    raz, że rekord nadal jest niedokończony, i TRZYMA blokadę przez całe
 *    `delete()` — obiekt znika, zanim ktokolwiek inny zmieni stan rekordu;
 *  - worker zmienia stan na `processing` także pod `FOR UPDATE`, więc gdy
 *    sprzątanie trzyma wiersz, próba CZEKA, a nie zapisuje paczki. Rusza po
 *    zatwierdzeniu sprzątania, od zera — jej plik powstaje PO `delete()`,
 *    więc `ready` wskazuje obiekt, który istnieje. Gdy worker był pierwszy,
 *    rewalidacja sprzątania widzi `processing`/`ready` i pliku nie rusza.
 *
 * Blokada obejmuje jedno kasowanie jednego obiektu (jedno żądanie do
 * magazynu), a nie budowanie paczki. Kolejność blokad się nie zmienia:
 * obie strony biorą tu wyłącznie wiersz `data_exports`; `finalize()`
 * (`users` → `data_exports`) i `EraseAccountData` nie są w tym miejscu
 * w konflikcie.
 */
final class PrzejecieEksportu
{
    /** Karencja przed sprzątaniem nieudanej próby — patrz `CleanUpDataExports::skasujNiedokonczone()`. */
    public const KARENCJA_GODZIN = 1;

    /**
     * Kandydaci do sprzątania osieroconego obiektu. Jedno źródło prawdy dla
     * listy (#1840, także `--dry-run`) i dla rewalidacji pod blokadą.
     *
     * @return Builder<DataExport>
     */
    public static function niedokonczone(): Builder
    {
        return DataExport::query()
            ->where('status', DataExport::STATUS_FAILED)
            ->whereNull('object_key')
            ->where('updated_at', '<', now()->subHours(self::KARENCJA_GODZIN));
    }

    /**
     * Przejęcie przez sprzątanie: `$operacja` (kasowanie obiektu) wykonuje
     * się WYŁĄCZNIE, gdy rekord pod blokadą nadal jest niedokończony, i pod
     * tą blokadą.
     *
     * @param  Closure(DataExport): void  $operacja
     * @return bool czy rekord był nadal niedokończony (false = pominięty, bo
     *              w międzyczasie przejął go worker albo zniknął)
     */
    public static function dlaSprzatania(string $dataExportId, Closure $operacja): bool
    {
        return DB::transaction(function () use ($dataExportId, $operacja): bool {
            $aktualny = self::niedokonczone()->whereKey($dataExportId)->lockForUpdate()->first();

            if ($aktualny === null) {
                return false;
            }

            $operacja($aktualny);

            return true;
        });
    }

    /**
     * Przejęcie przez próbę workera: przejście w `processing` pod blokadą
     * wiersza. Czeka na sprzątanie, które właśnie kasuje obiekt tego eksportu.
     *
     * Model `$export` dostaje stan z bazy przeczytany POD BLOKADĄ, bo ten
     * sprzed czekania mógł być nieaktualny.
     *
     * @return bool false = rekordu nie ma albo jest już `ready` — próba nie
     *              rusza (bez drugiej paczki i drugiego e-maila)
     */
    public static function dlaProby(DataExport $export): bool
    {
        return DB::transaction(function () use ($export): bool {
            $aktualny = DataExport::query()->whereKey($export->getKey())->lockForUpdate()->first();

            if ($aktualny === null || $aktualny->status === DataExport::STATUS_READY) {
                return false;
            }

            $export->setRawAttributes($aktualny->getAttributes(), sync: true);
            $export->update(['status' => DataExport::STATUS_PROCESSING]);

            return true;
        });
    }
}

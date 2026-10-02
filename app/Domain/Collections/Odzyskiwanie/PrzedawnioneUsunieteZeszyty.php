<?php

declare(strict_types=1);

namespace App\Domain\Collections\Odzyskiwanie;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nocne sprzątanie kopii odzyskania usuniętych zeszytów (#2567).
 *
 * Po `kuking.usuniete_tresci.retention_days` dniach od `deleted_at` kopia
 * (nazwa, opis i własne dopiski) jest kasowana na stałe — to samo okno i ta
 * sama komenda (`kuking:sprzataj-usuniete-tresci`) co dla przepisów, wpisów
 * i komentarzy (ADR retencji §5.7). Wymazanie konta kasuje kopie wcześniej
 * (`EraseAccountData`).
 *
 * ODZYSKANIE ŚCIGA SIĘ ZE SPRZĄTANIEM
 * Kandydatów wczytujemy bez blokady, a każdą kopię kasujemy w osobnej
 * transakcji, czytając jej wiersz JESZCZE RAZ pod `FOR UPDATE`
 * (`OdzyskajUsunietyZeszyt` bierze ten sam wiersz). Kopia, z której zeszyt
 * właśnie wrócił, już nie istnieje i zostaje pominięta; kopia, którą
 * sprzątanie zdążyło skasować, daje odzyskaniu uczciwą odmowę.
 *
 * BUDŻET: najwyżej `BUDZET_PRZEBIEGU` kopii na noc, najstarsze pierwsze. Błąd
 * jednej kopii zostawia ją na następną noc i nie blokuje reszty.
 */
final class PrzedawnioneUsunieteZeszyty
{
    public const BUDZET_PRZEBIEGU = 500;

    /** @return int ile kopii skasowano (przy `$naSucho` — ile by skasowano) */
    public function posprzataj(int $dni, bool $naSucho = false): int
    {
        $prog = now()->subDays(max(1, $dni));

        $kandydaci = DB::table('deleted_collections')
            ->where('deleted_at', '<', $prog)
            ->orderBy('deleted_at')
            ->orderBy('id')
            ->limit(self::BUDZET_PRZEBIEGU)
            ->pluck('id');

        if ($naSucho) {
            return $kandydaci->count();
        }

        $skasowano = 0;

        foreach ($kandydaci as $id) {
            try {
                $skasowano += DB::transaction(function () use ($id, $prog): int {
                    // Ponowny odczyt pod blokadą: odzyskanie, które wygrało,
                    // usunęło wiersz — wtedy nie ma czego kasować.
                    $swieza = DB::table('deleted_collections')
                        ->where('id', $id)
                        ->where('deleted_at', '<', $prog)
                        ->lockForUpdate()
                        ->first(['id']);

                    if ($swieza === null) {
                        return 0;
                    }

                    return DB::table('deleted_collections')->where('id', $id)->delete();
                });
            } catch (Throwable $e) {
                Log::warning('Sprzątanie kopii usuniętego zeszytu nie powiodło się; spróbujemy następnej nocy.', [
                    'id' => (string) $id,
                    'wyjatek' => $e::class,
                ]);
            }
        }

        return $skasowano;
    }
}

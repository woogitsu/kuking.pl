<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Zgoda na mail z życzeniami urodzinowymi (issue #1755, etap c).
 *
 * Ten sam kształt co `PrzestawZgodeNaDigest` (D-072) i z tych samych powodów:
 *   - udzielenie zgody i jej dowód w dzienniku idą w JEDNEJ transakcji —
 *     bez dowodu nie ma zgody;
 *   - wycofanie zapisuje się ZAWSZE, nawet gdy dowód wycofania się nie
 *     zapisze (wtedy tylko log) — nieudany dziennik nie może zatrzymać
 *     człowieka, który prosi, żeby do niego nie pisać;
 *   - brak zmiany = brak wiersza w dzienniku.
 */
final class PrzestawZgodeNaZyczeniaMailem
{
    /** Zwraca `true`, gdy stan zgody naprawdę się zmienił. */
    public function handle(User $osoba, bool $chce, string $zrodlo): bool
    {
        return DB::transaction(function () use ($osoba, $chce, $zrodlo): bool {
            $current = User::query()->lockForUpdate()->findOrFail($osoba->getKey());
            $zmiana = $this->zastosuj($current, $chce, $zrodlo);
            $osoba->setRawAttributes($current->getAttributes(), true);

            return $zmiana;
        });
    }

    private function zastosuj(User $osoba, bool $chce, string $zrodlo): bool
    {
        if ((bool) $osoba->wants_birthday_email === $chce) {
            return false;
        }

        if ($chce) {
            DB::transaction(function () use ($osoba, $zrodlo): void {
                $osoba->forceFill(['wants_birthday_email' => true])->save();
                $this->zapisz($osoba, WpisZgody::UDZIELONA, $zrodlo);
            });

            return true;
        }

        $osoba->forceFill(['wants_birthday_email' => false])->save();

        try {
            DB::transaction(fn (): WpisZgody => $this->zapisz($osoba, WpisZgody::WYCOFANA, $zrodlo));
        } catch (Throwable $awaria) {
            Log::error('Nie udało się zapisać wycofania zgody na mail urodzinowy.', [
                'user_id' => (string) $osoba->getKey(),
                'zrodlo' => $zrodlo,
                'wyjatek' => $awaria::class,
                'sqlstate' => $awaria instanceof QueryException ? (string) $awaria->getCode() : null,
            ]);
        }

        return true;
    }

    private function zapisz(User $osoba, string $czynnosc, string $zrodlo): WpisZgody
    {
        return WpisZgody::create([
            'user_id' => $osoba->getKey(),
            'cel' => WpisZgody::CEL_ZYCZENIA_URODZINOWE,
            'czynnosc' => $czynnosc,
            'zrodlo' => $zrodlo,
            'wystapilo_at' => now(),
            'wersja_polityki' => (string) config('kuking.zgody.wersja_polityki'),
        ]);
    }
}

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
 * Zgoda na sobotnie przypomnienie o produktach do zużycia (#1903, D-333).
 *
 * Ten sam kształt co `PrzestawZgodeNaDigest` (D-072) i z tych samych powodów:
 *   - udzielenie zgody i jej dowód w dzienniku idą w JEDNEJ transakcji —
 *     bez dowodu nie ma zgody;
 *   - wycofanie zapisuje się ZAWSZE, nawet gdy dowód wycofania się nie
 *     zapisze (wtedy tylko log) — nieudany dziennik nie może zatrzymać
 *     człowieka, który prosi, żeby do niego nie pisać;
 *   - brak zmiany = brak wiersza w dzienniku.
 */
final class PrzestawZgodeNaPrzypomnienieSpizarni
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
        if ((bool) $osoba->wants_pantry_reminder === $chce) {
            return false;
        }

        if ($chce) {
            DB::transaction(function () use ($osoba, $zrodlo): void {
                $osoba->forceFill(['wants_pantry_reminder' => true])->save();
                $this->zapisz($osoba, WpisZgody::UDZIELONA, $zrodlo);
            });

            return true;
        }

        $osoba->forceFill(['wants_pantry_reminder' => false])->save();

        try {
            DB::transaction(fn (): WpisZgody => $this->zapisz($osoba, WpisZgody::WYCOFANA, $zrodlo));
        } catch (Throwable $awaria) {
            Log::error('Nie udało się zapisać wycofania zgody na sobotnie przypomnienie o produktach.', [
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
            'cel' => WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI,
            'czynnosc' => $czynnosc,
            'zrodlo' => $zrodlo,
            'wystapilo_at' => now(),
            // Wersja OBOWIĄZUJĄCA w chwili zgody, nie ostatnio opublikowana:
            // w okresie przejściowym zmiany istotnej obowiązuje jeszcze
            // poprzednia (D-327).
            'wersja_polityki' => WersjaDokumentu::polityka()->obowiazujaca(),
        ]);
    }
}

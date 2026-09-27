<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Punkt przyjęcia uprzywilejowanej operacji względem zmiany roli konta.
 *
 * Najpierw wspólny zamek ostatniego administratora, potem wiersz aktora:
 * taką samą kolejność stosuje ChangeUserRole. Blokada trwa przez zapis
 * skutku, więc degradacja albo zatwierdzi się pierwsza i Policy zobaczy
 * nową rolę, albo zaczeka na zatwierdzenie operacji.
 */
final class ZamekUprzywilejowanegoAktora
{
    /**
     * @template T
     *
     * @param  Closure(User): T  $operacja  sprawdza Policy na świeżym aktorze i zapisuje skutek
     * @return T
     */
    public static function wykonaj(User $aktor, Closure $operacja): mixed
    {
        return DB::transaction(static function () use ($aktor, $operacja): mixed {
            OstatniAdministrator::zablokuj();

            $swiezy = User::query()->whereKey($aktor->getKey())->lockForUpdate()->first();
            if ($swiezy === null) {
                throw new AuthorizationException;
            }

            return $operacja($swiezy);
        });
    }
}

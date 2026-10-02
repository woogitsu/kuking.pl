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
 *
 * KONTA DODATKOWE (#2352). Operacja, która dotyka też innych kont niż aktor
 * (ukrycie wskazówki: kucharz i autor przepisu), musi brać wszystkie wiersze
 * `users` w tej samej kolejności co akcje obywatelskie (`ZamekPary`: rosnąco po
 * `id`). Aktor brany zawsze pierwszy odwracał kolejność, gdy sam był autorem
 * przepisu o niższym `id` niż kucharz — zakleszczenie z „Wycofaj zgodę”
 * (40P01). `$dodatkoweKonta` dokłada je do jednego, posortowanego przebiegu
 * (po zamku ról, bo ten stoi przed wierszami kont we wszystkich ścieżkach).
 */
final class ZamekUprzywilejowanegoAktora
{
    /**
     * @template T
     *
     * @param  Closure(User): T  $operacja  sprawdza Policy na świeżym aktorze i zapisuje skutek
     * @param  list<string>  $dodatkoweKonta  identyfikatory kont, które operacja też zablokuje
     * @return T
     */
    public static function wykonaj(User $aktor, Closure $operacja, array $dodatkoweKonta = []): mixed
    {
        return DB::transaction(static function () use ($aktor, $operacja, $dodatkoweKonta): mixed {
            OstatniAdministrator::zablokuj();

            $aktorId = (string) $aktor->getKey();
            $doZablokowania = array_values(array_unique([$aktorId, ...array_map('strval', $dodatkoweKonta)]));
            sort($doZablokowania, SORT_STRING);

            $swiezy = null;
            foreach ($doZablokowania as $id) {
                $konto = User::query()->whereKey($id)->lockForUpdate()->first();

                if ($id === $aktorId) {
                    $swiezy = $konto;
                }
            }

            if ($swiezy === null) {
                throw new AuthorizationException;
            }

            return $operacja($swiezy);
        });
    }
}

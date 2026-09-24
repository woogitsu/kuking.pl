<?php

declare(strict_types=1);

namespace App\Domain\Users;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * INVARIANT: po zatwierdzeniu każdej zmiany istnieje co najmniej jedno konto
 * z `role = admin` i `status = active` (#1016, D-039).
 *
 * Tylko administrator rozstrzyga odwołania (`UserPolicy::resolveAppeals()`).
 * Rola na koncie zawieszonym, zablokowanym albo czekającym na usunięcie nie
 * wpuszcza do panelu, więc liczy się wyłącznie czynny administrator.
 *
 * JEDEN WSPÓLNY ZAMEK, NIE ZAMEK KONTA. Blokada wiersza zmienianego konta nie
 * serializuje dwóch przejść dotyczących DWÓCH różnych administratorów: oba
 * widzą „tego drugiego" i oba przechodzą. Dlatego każde przejście, które może
 * zmniejszyć liczbę czynnych administratorów — degradacja (`ChangeUserRole`),
 * `suspend()`, `ban()`, `markForDeletion()` — bierze najpierw transakcyjną
 * blokadę doradczą (1016, 1), dopiero potem wiersz konta, i liczy
 * administratorów już pod nią.
 *
 * Kolejność: zamek wspólny → wiersz konta. Odwrotna zakleszcza się z
 * `ChangeUserRole`, dlatego wejście spod `ZamekKonta` jest odrzucane.
 * Uwaga także na klucze obce: INSERT wskazujący na `users` bierze
 * `FOR KEY SHARE` na tym wierszu, więc transakcja, która najpierw coś
 * wstawia, a potem woła strażnika, musi wziąć `zablokuj()` wcześniej
 * (tak robi `ModerationController::decide()`).
 */
final class OstatniAdministrator
{
    public const KOMUNIKAT = 'To jest ostatnie czynne konto administratora. '
        .'Bez niego nikt nie rozstrzygnie odwołania od decyzji moderacyjnej (DSA art. 20). '
        .'Najpierw nadaj rolę administratora innemu czynnemu kontu (kuking:nadaj-role).';

    /** Wspólny zamek wszystkich przejść. Zwalnia się sam z końcem transakcji. */
    public static function zablokuj(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Zamek ostatniego administratora działa tylko w transakcji.');
        }
        if (ZamekKonta::trzymanyWTymProcesie()) {
            throw new LogicException('Zamek ostatniego administratora bierz przed blokadą konta.');
        }

        DB::select('SELECT pg_advisory_xact_lock(1016, 1)');
    }

    /**
     * Czy `$user` jest jedynym czynnym administratorem — odczyt z bazy,
     * nie z modelu. Wołać wyłącznie pod `zablokuj()`.
     */
    public static function jestJedynym(User $user): bool
    {
        $stan = User::query()->whereKey($user->getKey())->first(['role', 'status']);
        if ($stan === null || $stan->role !== User::ROLE_ADMIN || $stan->status !== User::STATUS_ACTIVE) {
            return false;
        }

        return ! User::query()->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_ACTIVE)->whereKeyNot($user->getKey())->exists();
    }

    /**
     * Wykonuje `$zapis` odbierający kontu aktywność albo rolę — pod wspólnym
     * zamkiem i pod blokadą wiersza, po ponownym policzeniu administratorów.
     * Odmowa rzuca wyjątek i wycofuje całą transakcję wołającego.
     *
     * @throws OdmowaOstatniegoAdministratora gdy to ostatni czynny administrator
     */
    public static function odbierzAktywnosc(User $user, Closure $zapis): void
    {
        DB::transaction(static function () use ($user, $zapis): void {
            self::zablokuj();
            User::query()->whereKey($user->getKey())->lockForUpdate()->first(['id']);

            if (self::jestJedynym($user)) {
                throw new OdmowaOstatniegoAdministratora;
            }

            $zapis();
        });
    }
}

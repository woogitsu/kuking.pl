<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skrót do jednego WŁASNEGO zeszytu na ekranie „Moje" (#2542).
 *
 * Skrót nie zmienia zeszytu domyślnego, widoczności ani praw do zawartości:
 * to jedno pole przy koncie. Pole nie jest w `$fillable` — zmienia je tylko
 * ta akcja, po sprawdzeniu, że zeszyt należy do osoby.
 */
final class UstawSkrotDoZeszytu
{
    /**
     * @throws BladDlaCzlowieka gdy zeszyt nie istnieje już albo nie jest własny
     */
    public function ustaw(User $user, Collection $collection): void
    {
        DB::transaction(function () use ($user, $collection): void {
            // Blokada współdzielona: równoległe usunięcie zeszytu (inna karta)
            // poczeka, a my nie zapiszemy odnośnika do zeszytu, którego już nie ma.
            $swiezy = Collection::query()
                ->whereKey($collection->getKey())
                ->where('owner_id', $user->getKey())
                ->sharedLock()
                ->first();

            if ($swiezy === null) {
                throw new BladDlaCzlowieka('Tego zeszytu już nie ma albo nie jest Twój, więc nie można ustawić do niego skrótu. Wróć do listy zeszytów i wybierz inny.');
            }

            $user->forceFill(['ulubiony_zeszyt_id' => $swiezy->getKey()])->save();
        });
    }

    /**
     * Usuwa skrót, ale tylko wtedy, gdy wskazuje właśnie ten zeszyt — stary
     * przycisk z drugiej karty nie skasuje skrótu, który ktoś już zmienił.
     * Zeszyt i zapisy zostają.
     */
    public function usun(User $user, Collection $collection): void
    {
        User::query()
            ->whereKey($user->getKey())
            ->where('ulubiony_zeszyt_id', $collection->getKey())
            ->update(['ulubiony_zeszyt_id' => null]);

        $user->refresh();
    }
}

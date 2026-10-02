<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DeletedCollection;
use App\Models\User;

/**
 * Kopia usuniętego zeszytu jest PRYWATNA (#2567): lista i odzyskanie tylko dla
 * właściciela z AKTYWNYM kontem (odzyskanie to pisanie, a zawieszenie odcina
 * od pisania). Moderator, obce konto i gość nie mają żadnej furtki. Czy
 * konkretny zeszyt wolno odzyskać (termin, sprawa moderacyjna, nazwa), rozstrzyga
 * `OdzyskajUsunietyZeszyt` pod blokadą.
 */
class DeletedCollectionPolicy
{
    public function lista(User $user): bool
    {
        return $user->isActive();
    }

    public function odzyskaj(User $user, DeletedCollection $kopia): bool
    {
        return $user->isActive() && $user->getKey() === $kopia->owner_id;
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportPrzepisu;
use App\Models\User;

/**
 * Zlecenie odczytu przepisu widzi i ponawia WYŁĄCZNIE jego właściciel (D-298).
 * UUID w adresie `/import/{import}` nie jest autoryzacją (AGENTS.md §7) —
 * moderator też nie ma tu czego szukać: to prywatny szkic ze zdjęciem kartki.
 */
class ImportPrzepisuPolicy
{
    public function view(User $user, ImportPrzepisu $import): bool
    {
        return (string) $user->getKey() === (string) $import->user_id;
    }

    public function update(User $user, ImportPrzepisu $import): bool
    {
        return $this->view($user, $import);
    }
}

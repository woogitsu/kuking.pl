<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Kto może wczytać treści z własnej paczki eksportu (#1985).
 *
 * Wczytanie TWORZY przepisy, wpisy i zeszyty, więc wolno je tylko komuś, kto
 * może dziś tworzyć treści: konto czynne. Zawieszone (tylko odczyt), zablokowane
 * i zgłoszone do usunięcia — nie. Własność treści nie jest tu pytaniem:
 * importer tworzy wyłącznie treści zalogowanej osoby, nigdy cudze.
 */
final class WczytanaZPaczkiPolicy
{
    public function create(User $user): bool
    {
        return $user->isActive();
    }
}

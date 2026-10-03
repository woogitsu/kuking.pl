<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Świeże poświadczenie i generacja TEGO żądania, sprawdzane pod ZamekKonta. */
final class PotwierdzSesjePrzedZmianaBezpieczenstwa
{
    public function sprawdz(User $swiezy, #[\SensitiveParameter] string $obecneHaslo, int $generacjaSesji): void
    {
        if ($generacjaSesji !== (int) $swiezy->session_generation
            || ! Hash::check($obecneHaslo, (string) $swiezy->password)) {
            throw new ZmianaHaslaWymagaPonownegoLogowania('Konto zmieniło się w innej sesji. Zaloguj się ponownie, a potem spróbuj jeszcze raz.');
        }
    }
}

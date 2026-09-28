<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\User;

/**
 * Wyznaczacz fragmentów, gdy model jest niedostępny: zawsze „nie wiemy".
 *
 * Strona bez JSON-LD `Recipe` kończy się wtedy komunikatem „na tej stronie
 * nie znaleźliśmy przepisu" i szkicem z samym adresem źródła — bez żadnego
 * żądania do OpenAI.
 */
final class BezModeluFragmentow implements WyznaczaczFragmentow
{
    public function fragmenty(array $wiersze, ?User $osoba = null, bool $chceZgody = false, ?string $probaId = null): ?array
    {
        return null;
    }
}

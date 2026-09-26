<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Wyznaczacz fragmentów, gdy model jest niedostępny: zawsze „nie wiemy".
 *
 * Strona bez JSON-LD `Recipe` kończy się wtedy komunikatem „na tej stronie
 * nie znaleźliśmy przepisu" i szkicem z samym adresem źródła — bez żadnego
 * żądania do OpenAI.
 */
final class BezModeluFragmentow implements WyznaczaczFragmentow
{
    public function fragmenty(array $wiersze): ?array
    {
        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Collections;

use App\Models\Collection;

/**
 * Wybór zeszytu przy WYJMOWANIU przepisu lub wpisu (`collections.remove`,
 * `collections.remove-post`). Brak wyboru oznacza „wszystkie moje" (`null`).
 * Osobno od zapisu, bo zdania w błędach są inne: tam mowa o zapisaniu,
 * tu o wyjęciu. Zakres i tak przypina dostęp osoby, więc identyfikator
 * w adresie niczego nie otwiera (AGENTS.md §7).
 */
final class WyjecieZZeszytuRequest extends WyborZeszytuRequest
{
    protected function zdanieBleduWyboru(): string
    {
        return 'Odśwież stronę i ponownie wskaż zeszyt, z którego wyjmujemy.';
    }

    public function zeszytDoWyjecia(): ?Collection
    {
        $id = $this->idWybranegoZeszytu();

        return $id !== null
            ? Collection::query()->dostepneDoZapisuDla($this->user())->find($id)
            : null;
    }
}

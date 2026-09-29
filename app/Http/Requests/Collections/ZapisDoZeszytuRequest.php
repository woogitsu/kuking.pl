<?php

declare(strict_types=1);

namespace App\Http\Requests\Collections;

use App\Models\Collection;

/**
 * Wybór zeszytu przy ZAPISIE przepisu lub wpisu (`collections.save`,
 * `collections.save-post`). Brak wyboru oznacza zeszyt domyślny (`null`).
 * Zeszyt spoza zakresu daje `ValidationException`; zniknięcie zeszytu
 * między walidacją a zapytaniem — 404, jak w kontrolerze przed #970.
 */
final class ZapisDoZeszytuRequest extends WyborZeszytuRequest
{
    protected function zdanieBleduWyboru(): string
    {
        return 'Odśwież stronę i ponownie wybierz zeszyt do zapisania.';
    }

    public function zeszytDoZapisu(): ?Collection
    {
        $id = $this->idWybranegoZeszytu();

        return $id !== null
            ? Collection::query()->dostepneDoZapisuDla($this->user())->findOrFail($id)
            : null;
    }
}

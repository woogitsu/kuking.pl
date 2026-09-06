<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Collection;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nazwa zeszytu zajęta u tej samej osoby — bez rozróżniania wielkości liter
 * (issue #43).
 *
 * CO SIĘ DZIAŁO
 * Nic nie broniło założyć dwóch zeszytów „Obiady”. Lista zeszytów pokazuje
 * nazwę, liczbę przepisów i widoczność — dwa wiersze wyglądają identycznie
 * i trzeba wejść do obu, żeby sprawdzić, w którym jest szukany przepis.
 * Najczęstsza droga do tego stanu to podwójne wysłanie formularza.
 *
 * DLACZEGO OSOBNA REGUŁA, A NIE `Rule::unique(...)`
 * Ten sam powód co w `UsernameNotTaken`: `Rule::unique` zawsze dokłada
 * warunek `name = :wartosc` i tylko DOKŁADA do niego kolejne, więc nie da
 * się zamienić porównania na `lower(name) = lower(:wartosc)`.
 *
 * TO NIE JEST GWARANCJA, TYLKO UPRZEJMOŚĆ
 * Między tym `SELECT`-em a `INSERT`-em jest okno, w które wchodzi drugie
 * kliknięcie. Gwarancją jest unikalny indeks `collections_owner_name_lower_unique`
 * (AGENTS.md §6); ta reguła istnieje po to, żeby człowiek zobaczył zdanie
 * po polsku zamiast błędu 500.
 */
class CollectionNameNotTaken implements ValidationRule
{
    /**
     * @param  string  $ownerId  Nazwy są zajęte tylko w obrębie jednej osoby —
     *                           dwie różne osoby mogą mieć zeszyt „Obiady”.
     * @param  string|null  $ignoreCollectionId  Zeszyt, którego własna nazwa
     *                                           nie jest dla niego „zajęta”
     *                                           przy zapisie bez zmiany nazwy.
     */
    public function __construct(
        private readonly string $ownerId,
        private readonly ?string $ignoreCollectionId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $zajeta = Collection::query()
            ->where('owner_id', $this->ownerId)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
            ->when(
                $this->ignoreCollectionId !== null,
                fn ($query) => $query->where('id', '!=', $this->ignoreCollectionId),
            )
            ->exists();

        if ($zajeta) {
            $fail('Masz już zeszyt o tej nazwie. Wybierz inną.');
        }
    }
}

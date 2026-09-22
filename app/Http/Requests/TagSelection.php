<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TagSelection
{
    /** Oddzielne fazy: rozmiar, format, jedno zapytanie o unikalne UUID. */
    public function validate(Request $request, int $limit): array
    {
        $request->validate(['tags' => ['nullable', 'array']], [
            'tags.array' => 'Wybierz tagi z listy i zapisz ponownie.',
        ]);
        $tags = $request->input('tags') ?? [];
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                throw ValidationException::withMessages(['tags' => 'Wybierz tagi z listy i zapisz ponownie.']);
            }
        }
        $tags = array_values(array_unique(array_map('strtolower', $tags)));
        Validator::make(['tags' => $tags], ['tags' => ['array', 'max:'.$limit]], [
            'tags.max' => 'Wybierz najwyżej :max tagów z listy i zapisz ponownie.',
        ])->validate();
        $format = Validator::make(['tags' => $tags], ['tags.*' => ['bail', 'required', 'string', 'uuid']]);
        if ($format->fails()) {
            throw ValidationException::withMessages(['tags' => 'Wybierz tagi z listy i zapisz ponownie.']);
        }
        // Laravel dla exists na tablicy wykonuje jeden getMultiCount.
        Validator::make(['tags' => $tags], ['tags' => ['array', 'exists:tags,id']], [
            'tags.exists' => 'Jeden z wybranych tagów nie jest już dostępny. Sprawdź pozostałe zaznaczenia i zapisz ponownie.',
        ])->validate();

        return $tags;
    }
}

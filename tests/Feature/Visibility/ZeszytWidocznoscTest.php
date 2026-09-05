<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Zeszyt (kolekcja) — tylko `public` i `private` (CHECK w bazie).
 *
 * Różnica wobec pozostałych typów: trasa `/zeszyt/{collection}` żyje w grupie
 * `auth`, więc gość nie zobaczy NAWET zeszytu publicznego — dostaje
 * przekierowanie do logowania. To jest świadoma decyzja produktowa, nie luka,
 * ale musi być zapisana w tabeli prawdy, bo inaczej macierz kłamie.
 */
class ZeszytWidocznoscTest extends WidocznoscTestCase
{
    protected function widocznosci(): array
    {
        return ['public', 'private'];
    }

    protected function tabelaPrawdy(): array
    {
        return [
            'autor' => ['public' => true, 'private' => true],
            'obserwujący' => ['public' => true, 'private' => false],
            'obcy' => ['public' => true, 'private' => false],
            'zablokowany' => ['public' => false, 'private' => false],
            'niezalogowany' => ['public' => false, 'private' => false],
        ];
    }

    protected function utworz(string $widocznosc): Model
    {
        return Collection::create([
            'owner_id' => $this->autor->getKey(),
            'name' => 'Zeszyt '.$widocznosc,
            'visibility' => $widocznosc,
            'is_default' => false,
        ]);
    }

    protected function adres(Model $tresc): string
    {
        return route('collections.show', $tresc);
    }

    /**
     * Gość nie zobaczy nawet zeszytu publicznego, więc test bazowy (który
     * zakłada 403 dla blokującego) trzeba dopasować: tu blokujący JEST
     * zalogowany, więc 403 jest poprawnym oczekiwaniem — zostawiamy wersję
     * z klasy bazowej bez zmian.
     */
}

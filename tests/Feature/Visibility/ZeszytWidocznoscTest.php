<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Models\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Zeszyt (kolekcja) — tylko `public` i `private` (CHECK w bazie).
 *
 * Do issue #965 trasa `/zeszyt/{collection}` żyła w grupie `auth` i gość nie
 * widział NAWET zeszytu publicznego. To przeczyło etykiecie „Wszyscy"
 * z formularza i `CollectionPolicy::view(?User)`, więc odczyt wyszedł spod
 * `auth`: gość widzi zeszyt publiczny, prywatny dostaje odmowę.
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
            'niezalogowany' => ['public' => true, 'private' => false],
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
}

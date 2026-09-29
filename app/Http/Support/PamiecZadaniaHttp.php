<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\PamiecZadania;
use Illuminate\Http\Request;

/** Adapter HTTP: pamięć w atrybutach bieżącego `Request` (#970). */
final readonly class PamiecZadaniaHttp implements PamiecZadania
{
    public function __construct(private Request $request) {}

    public function pobierz(string $klucz): mixed
    {
        return $this->request->attributes->get($klucz);
    }

    public function zapisz(string $klucz, mixed $wartosc): void
    {
        $this->request->attributes->set($klucz, $wartosc);
    }
}

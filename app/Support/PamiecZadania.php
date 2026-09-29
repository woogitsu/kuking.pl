<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pamięć jednego żądania: wartość liczona raz i współdzielona przez karty
 * na tej samej stronie (#970). Każde żądanie zaczyna od pustej. Domena nie
 * zna `Request` — implementacja HTTP to `App\Http\Support\PamiecZadaniaHttp`.
 */
interface PamiecZadania
{
    public function pobierz(string $klucz): mixed;

    public function zapisz(string $klucz, mixed $wartosc): void;
}

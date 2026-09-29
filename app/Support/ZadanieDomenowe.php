<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Wszystko, czego domena potrzebuje od bieżącego żądania: sesja, adres IP
 * i pola formularza (#970). Domena nie zna `Illuminate\Http\Request` —
 * implementacja HTTP to `App\Http\Support\ZadanieHttp`.
 */
interface ZadanieDomenowe
{
    public function sesja(): Session;

    public function ip(): ?string;

    /** Pole formularza albo zapytania (jak `Request::input()`). */
    public function pole(string $nazwa, mixed $domyslnie = null): mixed;

    /** @return array<string, mixed> Całe wejście żądania (jak `Request::all()`). */
    public function wszystkie(): array;

    /** Dopisuje pola do wejścia żądania (jak `Request::merge()`), by trafiły do starych wartości formularza. */
    public function scal(array $pola): void;
}

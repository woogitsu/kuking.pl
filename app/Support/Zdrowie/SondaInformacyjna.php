<?php

declare(strict_types=1);

namespace App\Support\Zdrowie;

/**
 * Odczyt tylko do WGLĄDU operatora w `/health` (#2218).
 *
 * Różnica wobec `Sonda` jest w typie, nie w konwencji: `Sonda` mierzy i rzuca,
 * a jej porażka zmienia `status` na `degraded`. Ten odczyt NIE MA jak zmienić
 * `status` — nie rzuca, nie ma pola `ok`, nie wchodzi do `checks`, z którego
 * kontroler liczy `status` i kod HTTP. Trafia do osobnej sekcji `informacje`,
 * widocznej tylko z tokenem (jak `checks`, audyt A5-05).
 *
 * Po co: stan, który wymaga człowieka, ale nie jest awarią serwisu ani
 * powodem, żeby monitoring świecił na czerwono (sprawa z sufitem prób
 * potwierdzenia DSA stoi tygodniami, do czasu ręcznej decyzji). Alarm idzie
 * osobno, przez `EpizodAlarmu`.
 *
 * Odpowiedź czyta każdy, kto zna token: same liczby całkowite, bez numerów
 * spraw, adresów i nazw.
 */
interface SondaInformacyjna
{
    /** Klucz w sekcji `informacje` odpowiedzi. */
    public function nazwa(): string;

    /**
     * @return array<string, int>
     */
    public function odczytaj(): array;
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * ZDJĘCIE PROFILOWE NIE WYCHODZI DO OPENAI — ZADANIE ZOSTAJE PUSTE (D-239).
 *
 * Do tej zmiany to zadanie (issue #237, D-061) wysyłało miniaturę awatara do
 * oceny modelem. Decyzja właściciela z 22.09.2026: awatar bez potwierdzonej
 * zgody nie wychodzi. Serwis nie ma żadnego mechanizmu, w którym człowiek
 * potwierdzałby zgodę na ocenę swojego zdjęcia profilowego przez zewnętrzny
 * model (`dziennik_zgod` zna jeden cel: tygodniowy list), więc dziś nie ma
 * takiej zgody u nikogo — i nic nie wychodzi.
 *
 * DLACZEGO KLASA ZOSTAJE, SKORO NIC NIE ROBI
 * W kolejce `low` na produkcji mogą czekać zadania zlecone przed wdrożeniem.
 * Bez tej klasy każde skończyłoby się błędem deserializacji w `failed_jobs`;
 * z nią kończy się bez żadnego skutku i bez żądania do dostawcy. Nikt jej
 * już nie zleca (`AvatarSettingsController::update`). Do usunięcia, gdy
 * kolejka sprzed wdrożenia będzie pusta.
 *
 * Przywrócenie oceny awatarów wymaga osobnej decyzji: celu zgody
 * w `dziennik_zgod`, ekranu, na którym człowiek ją daje i wycofuje,
 * i sprawdzenia tej zgody tutaj, przed każdą wysyłką.
 */
class PrzeanalizujAwatar implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $mediaId) {}

    public function handle(): void
    {
        // Celowo pusto — patrz opis klasy.
    }
}

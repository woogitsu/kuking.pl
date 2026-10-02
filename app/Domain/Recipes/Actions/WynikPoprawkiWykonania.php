<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Models\CookedEvent;

/**
 * Wynik `PoprawWykonanie`: świeży wiersz, nazwy pól faktycznie zapisanych
 * i pola pominięte z powodem (`PolaKorekty`).
 */
final class WynikPoprawkiWykonania
{
    /**
     * @param  list<string>  $zmienione
     * @param  array<string, string>  $pominiete
     */
    public function __construct(
        public readonly CookedEvent $wykonanie,
        public readonly array $zmienione,
        public readonly array $pominiete,
    ) {}
}

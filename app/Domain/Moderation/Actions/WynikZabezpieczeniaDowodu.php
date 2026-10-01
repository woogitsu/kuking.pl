<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

/**
 * Co naprawdę zrobiła akcja „CSAM — natychmiast ukryj i zabezpiecz”.
 *
 * Ekran wyniku mówi to moderatorowi wprost, bez zgadywania — zwłaszcza
 * czego NIE udało się zrobić (blokada konta wyższej roli, zdjęcie, które
 * już było kasowane).
 */
final readonly class WynikZabezpieczeniaDowodu
{
    public function __construct(
        public string $zabezpieczenieId,
        public int $zdjecZabezpieczonych,
        public int $zdjecPominietych,
        public bool $kontoZablokowane,
        public bool $kontoJuzZablokowane,
        public ?string $powodBrakuBlokady,
        public bool $zamknietoZgloszenie,
    ) {}
}

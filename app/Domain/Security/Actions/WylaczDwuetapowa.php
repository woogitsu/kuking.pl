<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Users\Actions\PotwierdzSesjePrzedZmianaBezpieczenstwa;
use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\User;

/** Wyłączenie 2FA i odwołanie innych sesji pod tą samą blokadą konta. */
final class WylaczDwuetapowa
{
    public function __construct(private readonly PotwierdzSesjePrzedZmianaBezpieczenstwa $potwierdzSesje) {}

    public function handle(User $user, #[\SensitiveParameter] string $haslo, int $generacjaSesji, string $zachowajSesje, ?string $ip): void
    {
        $byloWlaczone = ZamekKonta::zablokuj($user, function (?User $swiezy) use ($haslo, $generacjaSesji, $zachowajSesje, $user): bool {
            if ($swiezy === null) {
                throw new BladDlaCzlowieka('Tego konta już nie ma. Zaloguj się ponownie.');
            }

            $this->potwierdzSesje->sprawdz($swiezy, $haslo, $generacjaSesji);
            $byloWlaczone = $swiezy->hasTwoFactorConfirmed();
            $swiezy->disableTwoFactor();
            $swiezy->invalidateSessions($zachowajSesje);
            $user->setRawAttributes($swiezy->getAttributes(), sync: true);

            return $byloWlaczone;
        });

        if ($byloWlaczone) {
            AuditLogEntry::recordBezWywracania('account.two_factor_disabled', $user, $user, ip: $ip);
        }
    }
}

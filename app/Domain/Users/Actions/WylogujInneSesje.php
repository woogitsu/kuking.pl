<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\User;

final class WylogujInneSesje
{
    public function __construct(private readonly PotwierdzSesjePrzedZmianaBezpieczenstwa $potwierdzSesje) {}

    public function handle(
        User $user,
        #[\SensitiveParameter] string $obecneHaslo,
        int $generacjaSesji,
        string $zachowajSesje,
        ?string $ip = null,
    ): void {
        ZamekKonta::zablokuj($user, function (?User $swiezy) use ($user, $obecneHaslo, $generacjaSesji, $zachowajSesje, $ip): void {
            if ($swiezy === null) {
                throw new BladDlaCzlowieka('Tego konta już nie ma. Zaloguj się ponownie.');
            }

            $this->potwierdzSesje->sprawdz($swiezy, $obecneHaslo, $generacjaSesji);
            $swiezy->invalidateSessions($zachowajSesje);
            AuditLogEntry::record('account.sessions_logged_out_others', $swiezy, $swiezy, ip: $ip);
            $user->setRawAttributes($swiezy->getAttributes(), sync: true);
        });
    }
}

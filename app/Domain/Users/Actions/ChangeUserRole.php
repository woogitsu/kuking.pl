<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Domain\Users\OstatniAdministrator;
use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Zmiana roli z powłoki: wspólny zamek → konto → walidacja → zapis i audyt. */
final class ChangeUserRole
{
    /** @return array{user: User, previous: string, changed: bool} */
    public function handle(User $user, string $role): array
    {
        if (! in_array($role, [User::ROLE_USER, User::ROLE_MODERATOR, User::ROLE_ADMIN], true)) {
            throw new DomainException('Nieznana rola: '.$role.'. Dozwolone: user, moderator, admin.');
        }

        // Nie odwracamy kolejności konto → wspólny zamek (D-093).
        if (ZamekKonta::trzymanyWTymProcesie()) {
            throw new LogicException('Zmianę roli wywołaj przed założeniem blokady konta.');
        }

        return DB::transaction(static function () use ($user, $role): array {
            // Wspólny z zawieszeniem, banem i usunięciem konta (#1016).
            // Sam zamek konta nie serializuje degradacji DWÓCH różnych kont.
            OstatniAdministrator::zablokuj();

            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($fresh === null) {
                throw new DomainException('Nie znaleziono konta o takim loginie.');
            }
            if (! $fresh->isActive()) {
                throw new DomainException("Konto ma status „{$fresh->status}\", nie „active\" — najpierw przywróć konto, potem nadaj rolę.");
            }

            $previous = (string) $fresh->role;
            if ($previous === $role) {
                return ['user' => $fresh, 'previous' => $previous, 'changed' => false];
            }

            if ($role !== User::ROLE_ADMIN && OstatniAdministrator::jestJedynym($fresh)) {
                throw new OdmowaOstatniegoAdministratora;
            }

            $fresh->promoteTo($role);
            // Rola to uprawnienie, nie wygląd (#1315): sesja otwarta przed
            // awansem weszłaby do panelu bez ponownego logowania i 2FA,
            // a po degradacji trzymałaby w pamięci stan sprzed zmiany.
            // Komenda nie ma własnej sesji, więc padają wszystkie.
            $fresh->invalidateSessions();
            AuditLogEntry::record(
                action: 'user.role_changed',
                subject: $fresh,
                metadata: ['from' => $previous, 'to' => $role, 'source' => 'console:kuking:nadaj-role'],
            );

            return ['user' => $fresh, 'previous' => $previous, 'changed' => true];
        });
    }
}

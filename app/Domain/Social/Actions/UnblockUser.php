<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\User;

/**
 * Odblokowanie. Świadomie NIE przywracamy relacji follow — jeśli ktoś chce
 * znów obserwować, klika "Obserwuj". Automatyczne przywrócenie byłoby
 * niespodzianką, a niespodzianki w prywatności są złe.
 */
final class UnblockUser
{
    public function handle(User $blocker, User $target, ?string $ip = null): void
    {
        Block::query()
            ->where('blocker_id', $blocker->getKey())
            ->where('blocked_id', $target->getKey())
            ->delete();

        AuditLogEntry::record(
            action: 'user.unblocked',
            actor: $blocker,
            subject: $target,
            ip: $ip,
        );
    }
}

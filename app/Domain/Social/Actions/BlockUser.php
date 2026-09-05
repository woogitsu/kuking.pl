<?php

declare(strict_types=1);

namespace App\Domain\Social\Actions;

use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Zablokowanie kogoś.
 *
 * Blokada kasuje obserwowanie W OBIE STRONY. Gdyby zostawić follow, osoba
 * zablokowana wciąż widziałaby treści w swoim feedzie — a to znaczy, że
 * blokada nie działa. To jest reguła domenowa, nie detal implementacji.
 */
final class BlockUser
{
    public function handle(User $blocker, User $target, ?string $ip = null): void
    {
        if ($blocker->getKey() === $target->getKey()) {
            throw new RuntimeException('Nie można zablokować samego siebie.');
        }

        DB::transaction(function () use ($blocker, $target): void {
            Block::query()->firstOrCreate([
                'blocker_id' => $blocker->getKey(),
                'blocked_id' => $target->getKey(),
            ], ['created_at' => now()]);

            $blocker->following()->detach($target->getKey());
            $target->following()->detach($blocker->getKey());
        });

        AuditLogEntry::record(
            action: 'user.blocked',
            actor: $blocker,
            subject: $target,
            ip: $ip,
        );
    }
}

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
 *
 * DZIENNIK AUDYTU — `recordBezWywracania()`, nie `record()` (D-249, klasa 2;
 * #1896), tak samo jak w `BlockUser`. Autorytatywny ślad odblokowania to
 * usunięty wiersz `blocks` — usuwamy go PRZED wpisem, więc w chwili zapisu
 * odblokowanie już się wykonało. Rzucający `record()` zamieniał tu wykonaną
 * zmianę w HTTP 500 bez żadnej drogi ponowienia (blokady, którą miałby
 * odtworzyć retry, już nie ma). Awaria dziennika idzie do `report()`
 * z nazwą brakującego wpisu, a odpowiedź zostaje odpowiedzią sukcesu.
 */
final class UnblockUser
{
    public function handle(User $blocker, User $target, ?string $ip = null): void
    {
        Block::query()
            ->where('blocker_id', $blocker->getKey())
            ->where('blocked_id', $target->getKey())
            ->delete();

        AuditLogEntry::recordBezWywracania(
            action: 'user.unblocked',
            actor: $blocker,
            subject: $target,
            ip: $ip,
        );
    }
}

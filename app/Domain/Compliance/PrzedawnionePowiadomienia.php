<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\Notification;

/**
 * Retencja `notifications` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.2).
 *
 * Domyślnie `config('kuking.notifications.retention_months')` miesięcy od
 * `created_at`, NIEZALEŻNIE od `read_at` — jeden wiek dla wszystkich
 * powiadomień (wariant A z ADR §6). Żadna kategoria nie jest wyjątkiem:
 * `notifications` nie ma charakteru audytowego, w odróżnieniu od `audit_log`.
 *
 * Zwykły masowy `DELETE` (wzorzec B) — wiersz nie ma odpowiednika w storage.
 */
final class PrzedawnionePowiadomienia
{
    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): int
    {
        $zapytanie = Notification::query()->where('created_at', '<', now()->subMonths($miesiecyKarencji));

        return $naSucho ? $zapytanie->count() : $zapytanie->delete();
    }
}

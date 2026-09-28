<?php

declare(strict_types=1);

namespace App\Domain\Api\Actions;

use App\Models\AuditLogEntry;
use App\Models\PersonalAccessToken;
use App\Models\User;

/**
 * Odwołanie tokenu aplikacji mobilnej (D-270) — z aplikacji („Wyloguj")
 * albo z WWW (ekran „Urządzenia z dostępem").
 *
 * Kto może odwołać który token, rozstrzyga Policy
 * (`PersonalAccessTokenPolicy::delete`) PRZED wywołaniem; ta akcja zakłada,
 * że pytanie już padło. Identyfikator tokenu w adresie nie jest autoryzacją
 * (AGENTS.md §7).
 */
final class OdwolajTokenAplikacji
{
    public function handle(User $kto, PersonalAccessToken $token, ?string $ip): void
    {
        $token->delete();

        AuditLogEntry::recordBezWywracania('account.api_token_revoked', $kto, $token, ip: $ip);
    }

    /** „Odwołaj wszystkie" z WWW — jeden wpis w dzienniku, nie N. */
    public function wszystkie(User $kto, ?string $ip): int
    {
        $ile = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $kto->getKey())
            ->delete();

        if ($ile > 0) {
            AuditLogEntry::recordBezWywracania('account.api_tokens_revoked_all', $kto, $kto, ['ile' => $ile], $ip);
        }

        return $ile;
    }
}

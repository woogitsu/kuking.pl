<?php

declare(strict_types=1);

namespace App\Domain\Pwa;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/** Kontekst wyświetlenia chroni nowe konto przed żądaniem ze starej karty. */
final class InstallPromptContext
{
    public function issue(User $user, string $sessionId): string
    {
        return Crypt::encryptString(json_encode([
            'user' => (string) $user->getKey(),
            'session' => hash('sha256', $sessionId),
            'expires' => now()->addHour()->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public function valid(string $token, User $user, string $sessionId): bool
    {
        try {
            $context = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return false;
        }

        return is_array($context)
            && is_string($context['user'] ?? null)
            && hash_equals((string) $user->getKey(), $context['user'])
            && is_string($context['session'] ?? null)
            && hash_equals(hash('sha256', $sessionId), $context['session'])
            && is_int($context['expires'] ?? null)
            && $context['expires'] > now()->getTimestamp();
    }
}

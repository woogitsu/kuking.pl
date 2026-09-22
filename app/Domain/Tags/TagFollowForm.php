<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/** Stan konkretnego formularza, związany z kontem i chroniony przed podmianą. */
final class TagFollowForm
{
    public function encode(User $user, array $shown, array $followed): string
    {
        return Crypt::encryptString(json_encode([
            'user' => $user->getKey(),
            'shown' => $shown,
            'followed' => $followed,
        ], JSON_THROW_ON_ERROR));
    }

    public function decode(User $user, mixed $token): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }
        try {
            $scope = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        return is_array($scope) && ($scope['user'] ?? null) === $user->getKey()
            && is_array($scope['shown'] ?? null) && is_array($scope['followed'] ?? null)
            ? $scope : null;
    }
}

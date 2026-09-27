<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Skrot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class FacebookConnectionProof extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'facebook_connection_proofs';

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $token): string
    {
        return Skrot::hmac($token);
    }

    public static function validShape(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1;
    }
}

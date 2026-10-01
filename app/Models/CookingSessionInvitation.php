<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jednorazowy link do wspólnego gotowania (#2385).
 *
 * W bazie leży wyłącznie SHA-256 tokenu (`token_hash`) i tylko dopóki link
 * czeka; przyjęcie i odwołanie kasują skrót. `$fillable` PUSTE: `status` jest
 * polem sterującym, `token_hash` poświadczeniem, a klucze osób ustawiają
 * wyłącznie nazwane akcje w `App\Domain\Recipes\Gotowanie\Wspolne`.
 *
 * @property string $id
 * @property string $session_id
 * @property string|null $token_hash
 * @property string $status
 */
class CookingSessionInvitation extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'cooking_session_invitations';

    protected $fillable = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CookingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CookingSession::class, 'session_id');
    }

    /** Czy na ten link da się jeszcze wejść. */
    public function czeka(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    public static function skrotTokenu(string $token): string
    {
        return hash('sha256', $token);
    }
}

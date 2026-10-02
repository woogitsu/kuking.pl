<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wielorazowy link do wspólnego gotowania (#2385): jeden żywy link wpuszcza
 * kolejne osoby, aż sesja ma komplet pomocników.
 *
 * W bazie leży wyłącznie SHA-256 tokenu (`token_hash`) i tylko dopóki link
 * jest żywy; odwołanie kasuje skrót. Status `accepted` i `accepted_by_id` to
 * pozostałość pierwotnego projektu „jeden link = jedna osoba”: kod ich już
 * nie ustawia (przyjęcie niczego w zaproszeniu nie zmienia). `$fillable` PUSTE: `status` jest
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

    /**
     * Odwołuje żywy link sesji (kasuje skrót tokenu). Wołać POD BLOKADĄ wiersza
     * sesji, żeby nie mijać się z przyjęciem.
     */
    public static function uniewaznijZywe(string $idSesji): void
    {
        self::query()
            ->where('session_id', $idSesji)
            ->where('status', self::STATUS_PENDING)
            ->update([
                'status' => self::STATUS_REVOKED,
                'token_hash' => null,
                'responded_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public static function skrotTokenu(string $token): string
    {
        return hash('sha256', $token);
    }
}

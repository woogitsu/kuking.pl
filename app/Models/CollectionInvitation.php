<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zaproszenie do wspólnego zeszytu (#1743, D-302).
 *
 * Dwie drogi, jeden wiersz:
 *  - po nazwie konta — `invitee_id` znany od początku;
 *  - linkiem — `token_hash` (SHA-256 tokenu z adresu), `invitee_id` pusty,
 *    dopóki ktoś nie przyjmie. Token znika z wiersza przy odpowiedzi, więc
 *    link działa raz.
 *
 * `$fillable` jest PUSTE i to celowo. `status` to pole sterujące (jak `status`
 * konta, AGENTS.md §7), `token_hash` to poświadczenie, a klucze osób
 * ustawiają wyłącznie nazwane akcje w `App\Domain\Collections\Wspoldzielenie`.
 */
class CollectionInvitation extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'via_link' => 'boolean',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    public function jestLinkiem(): bool
    {
        return (bool) $this->via_link;
    }

    /** Czy na to zaproszenie da się jeszcze odpowiedzieć. */
    public function czeka(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    public static function skrotTokenu(string $token): string
    {
        return hash('sha256', $token);
    }
}

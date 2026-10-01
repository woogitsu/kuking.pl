<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Wspólne gotowanie jednego przepisu: gospodarz i pomocnik (#2385).
 *
 * `$fillable` jest PUSTE i to celowo: `status` jest polem sterującym,
 * `host_id` i `revision` należą do akcji, a wiersz powstaje i zmienia się
 * wyłącznie przez `App\Domain\Recipes\Gotowanie\Wspolne\*`. Zasady dostępu:
 * `CookingSessionPolicy` i `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
 *
 * @property string $id
 * @property string $recipe_id
 * @property string $host_id
 * @property string $status
 * @property int $revision
 * @property CarbonImmutable $expires_at
 */
class CookingSession extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Sesja trwa: aktywna i przed terminem. Wygasła jest dla serwisu nieistniejąca. */
    public function trwa(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->expires_at->isFuture();
    }

    public function maGospodarza(User $osoba): bool
    {
        return $this->host_id === $osoba->getKey();
    }

    public function maPomocnika(User $osoba): bool
    {
        return $this->pomocnicy()->whereKey($osoba->getKey())->exists();
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function pomocnicy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cooking_session_participants', 'session_id', 'user_id')
            ->withPivot(['role', 'joined_at']);
    }

    /** @return HasMany<CookingSessionInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(CookingSessionInvitation::class, 'session_id');
    }
}

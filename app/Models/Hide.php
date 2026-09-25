<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prywatne ukrycie wpisu albo osoby przez jednego widza (issue #1810, D-278).
 *
 * `$fillable` PUSTE: każda kolumna to albo klucz właściciela (`user_id`), albo
 * obiekt, albo termin — wszystkie ustawia jawna akcja domenowa
 * (`App\Domain\Ukrycia\Actions\*`), nigdy dane z żądania wprost.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $post_id
 * @property string|null $hidden_user_id
 * @property \Illuminate\Support\Carbon|null $hidden_until
 */
class Hide extends Model
{
    use HasUuids;

    protected $table = 'hides';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'hidden_until' => 'datetime',
        ];
    }

    /**
     * Ukrycie, które jeszcze działa: na stałe albo z terminem w przyszłości.
     *
     * @param  Builder<Hide>  $query
     */
    public function scopeAktywne(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('hides.hidden_until')->orWhere('hides.hidden_until', '>', now()));
    }

    public function jestAktywne(): bool
    {
        return $this->hidden_until === null || $this->hidden_until->isFuture();
    }

    public function naStale(): bool
    {
        return $this->hidden_until === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function hiddenUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hidden_user_id');
    }
}

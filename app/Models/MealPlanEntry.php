<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Jedna pozycja planera tygodnia (#27, D-310): dzień + przepis ALBO własny
 * wpis („obiad u mamy”). Plan jest prywatny — widzi go wyłącznie właściciel.
 *
 * `user_id` celowo poza `$fillable` (AGENTS.md §7): właściciela ustawia
 * akcja domenowa jawnym przypisaniem, nie żądanie.
 *
 * `done_at` (#2593) to prywatne „Zrobione” — także poza `$fillable`: stan
 * ustawia wyłącznie akcja `OznaczPozycjePlanu`, nie żądanie.
 *
 * `note` (#2549) to krótki prywatny dopisek przy pozycji z przepisem —
 * także poza `$fillable`; ustawia go wyłącznie akcja `ZapiszDopisekPlanu`.
 *
 * Pozycja bez przepisu i bez tekstu jest możliwa — zostaje po TWARDYM
 * usunięciu przepisu (`ON DELETE SET NULL`), patrz migracja.
 */
class MealPlanEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'day',
        'recipe_id',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'date',
            'done_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}

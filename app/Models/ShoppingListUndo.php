<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Migawka ostatniego usunięcia z listy zakupów, do krótkiego cofnięcia
 * (#2630, rozszerzenie D-333). Jedna na osobę; żyje `kuking.zakupy.cofniecie_minut`.
 *
 * Nic tu nie jest `$fillable`: właściciela, zakres, migawkę i termin ustawia
 * wyłącznie akcja domenowa `ListaZakupow` (AGENTS.md §7).
 */
class ShoppingListUndo extends Model
{
    use HasUuids;

    public const SCOPE_SINGLE = 'single';

    public const SCOPE_CHECKED = 'checked';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'items_count' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function wygasla(): bool
    {
        return $this->expires_at->isPast();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

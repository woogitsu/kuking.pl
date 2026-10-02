<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dodatkowa, nazwana prywatna lista zakupów (#2528, V2, D-333 — paczka E).
 *
 * Lista domyślna („Na co dzień”) NIE jest wierszem tej tabeli — to pozycje
 * z `list_id = NULL`. Wiersz oznacza wyłącznie listę, którą osoba sama
 * założyła i nazwała (np. „Święta”).
 *
 * W `$fillable` stoi wyłącznie `name`; właściciela (`user_id`) ustawia akcja
 * domenowa `ListaZakupow` jawnym przypisaniem, nigdy żądanie (AGENTS.md §7).
 */
class ShoppingList extends Model
{
    use HasUuids;

    /** Nazwa listy domyślnej — stała etykieta, nie dana osobowa. */
    public const NAZWA_DOMYSLNEJ = 'Na co dzień';

    protected $fillable = [
        'name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class, 'list_id');
    }
}

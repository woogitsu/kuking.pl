<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Temat — zamknięta pozycja słownika redakcyjnego (issue #31, SOUL.md 4.7).
 *
 * Świadomie NIE ma tu `$fillable` z `slug`: slug siedzi w adresie strony
 * tematu, więc jego zmiana to zmiana adresu, którą ktoś mógł już zapisać.
 * Nowy temat zakłada redakcja przez seeder, nie formularz.
 *
 * `is_active` TEŻ NIE JEST W `$fillable`, i to nie jest przeoczenie: seeder
 * chodzi ponownie na istniejącej bazie, a temat wycofany ręcznie ma zostać
 * wycofany. Brak tej kolumny w `$fillable` jest tym, co to egzekwuje —
 * `updateOrCreate` nie ma jak jej po cichu przywrócić.
 */
class Topic extends Model
{
    /**
     * KLUCZ MUSI POWSTAWAĆ W PHP, NIE W BAZIE.
     *
     * Migracja daje kolumnie `DEFAULT gen_random_uuid()`, więc wiersz i tak
     * dostanie identyfikator — ale model po `Topic::create()` NIE ZNA tej
     * wartości i `getKey()` oddaje `null`. Kod, który zaraz potem pisze
     * `where('topic_id', $temat->getKey())`, dostaje wtedy `topic_id IS NULL`,
     * bo builder zamienia `null` na `whereNull`. Zapytanie nie wybucha,
     * tylko po cichu odpowiada na inne pytanie niż zadane.
     *
     * Kosztowało to pięć zielonych-z-niewłaściwego-powodu testów w tym samym
     * pliku, zanim ktokolwiek zobaczył stronę tematu. Domyślnie w bazie
     * zostaje jako zabezpieczenie dla wstawek z psql.
     */
    use HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** Adres strony tematu. */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'topic_follows')
            ->withTimestamps();
    }

    /**
     * Tematy do wyboru — bez wycofanych, w kolejności redakcyjnej.
     *
     * Temat wycofany znika z WYBORU, ale nie znika z wpisów, które już go
     * mają: inaczej czyjś wpis straciłby przypisanie przy decyzji redakcyjnej,
     * o której ta osoba nic nie wie.
     */
    public function scopeDoWyboru(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Zamrożony obraz przepisu w chwili istotnej zmiany.
 *
 * Po co: ktoś ugotował z wersji z 2027 roku i zostawił komentarz "wyszło
 * idealnie". Jeśli autor w 2029 zmieni proporcje, ten komentarz przestanie
 * mieć sens bez dostępu do starej wersji.
 *
 * Wersji NIGDY się nie nadpisuje (decyzja właściciela z 24.09.2026, issue
 * #1316). Poprawka treści to zawsze nowa wersja; próba `update()`/`save()`
 * istniejącej kończy się wyjątkiem, zanim cokolwiek trafi do bazy.
 */
class RecipeVersion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'recipe_id',
        'editor_id',
        'version_number',
        'snapshot',
        'change_note',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Wersji przepisu nie wolno zmieniać — zapisz nową wersję (issue #1316).');
        });
    }

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version_number' => 'integer',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}

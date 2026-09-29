<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ślad treści wczytanej z własnej paczki eksportu (#1985, etap 2): skrót
 * (`odcisk`) i wskaźnik na utworzoną treść, bez samej treści.
 *
 * Bez `$fillable`: wiersz zapisuje wyłącznie `WczytajPaczke`, a żadne pole nie
 * pochodzi z żądania ani z pliku.
 *
 * @property string $id
 * @property string $user_id
 * @property string $rodzaj
 * @property string $odcisk
 * @property ?string $recipe_id
 * @property ?string $post_id
 * @property ?string $collection_id
 * @property ?Carbon $created_at
 */
class WczytanaZPaczki extends Model
{
    use HasUuids;

    public const RODZAJ_PRZEPIS = 'przepis';

    public const RODZAJ_WPIS = 'wpis';

    public const RODZAJ_ZESZYT = 'zeszyt';

    protected $table = 'wczytane_z_paczki';

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

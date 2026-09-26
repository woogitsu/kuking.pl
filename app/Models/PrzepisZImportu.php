<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pochodzenie szkicu przepisu z importu (D-300, `docs/DATABASE.md`).
 *
 * Bez `$fillable`: wiersz zapisuje wyłącznie `ZapiszSzkicZImportu`, a znacznik
 * sprawdzenia — `StrazImportu::poPublikacji()`. Żadne pole nie pochodzi
 * wprost z żądania.
 *
 * @property string $recipe_id
 * @property string $user_id
 * @property string $zrodlo
 * @property string $droga
 * @property ?string $source_url
 * @property ?string $tekst_zrodla
 * @property ?Carbon $sprawdzone_at
 */
class PrzepisZImportu extends Model
{
    public const ZRODLO_URL = 'url';

    public const ZRODLO_PDF = 'pdf';

    public const ZRODLO_ZDJECIE = 'zdjecie';

    protected $table = 'przepisy_z_importu';

    protected $primaryKey = 'recipe_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['sprawdzone_at' => 'datetime'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sprawdzony(): bool
    {
        return $this->sprawdzone_at !== null;
    }
}

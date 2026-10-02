<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Import\PominieteWImporcie;
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
 * @property ?array{skladniki: int, kroki: int, obciete: list<string>} $pominiete liczby i nazwy pól, bez treści (#2521)
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
        return ['sprawdzone_at' => 'datetime', 'pominiete' => 'array'];
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

    /**
     * Czy publikacja wymaga „Sprawdziłem odczytany tekst” (D-300). Szkic ze
     * zdjęcia ma własną bramkę (`BramkaPublikacjiOdczytu`); jego wiersz tutaj
     * istnieje tylko po to, by pamiętać o pominiętych wierszach (#2521).
     */
    public function wymagaPotwierdzeniaOdczytu(): bool
    {
        return $this->zrodlo !== self::ZRODLO_ZDJECIE && ! $this->sprawdzony();
    }

    /** Co import pominął lub uciął (#2521); `null` = kompletny. */
    public function pominieteWImporcie(): ?PominieteWImporcie
    {
        return PominieteWImporcie::zTablicy($this->pominiete);
    }
}

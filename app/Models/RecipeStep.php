<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Odmiana;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeStep extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'recipe_id',
        'position',
        'instruction',
        'media_id',
        'timer_seconds',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'timer_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * Czas minutnika po polsku; po „na” używa biernika: „1 minutę i 1 sekundę”.
     *
     * Metoda siedzi na modelu, nie w widoku trybu gotowania (issue #24):
     * ten sam tekst czyta zarówno widoczny akapit („ustaw sobie minutnik
     * na…"), jak i etykieta przy przycisku uruchamiającym minutnik w JS —
     * dwa miejsca liczące to samo osobno to dwie okazje, żeby się rozjechały
     * (ten sam powód co `Recipe::servingsLabel()` wyżej w kodzie bazy).
     */
    public function timerLabel(bool $afterNa = false): ?string
    {
        if ($this->timer_seconds === null || $this->timer_seconds <= 0) {
            return null;
        }

        $minuty = intdiv($this->timer_seconds, 60);
        $sekundy = $this->timer_seconds % 60;

        $czesci = [];

        if ($minuty > 0) {
            $czesci[] = $minuty.' '.Odmiana::rzeczownik($minuty, $afterNa ? 'minutę' : 'minuta', 'minuty', 'minut');
        }

        if ($sekundy > 0) {
            $czesci[] = $sekundy.' '.Odmiana::rzeczownik($sekundy, $afterNa ? 'sekundę' : 'sekunda', 'sekundy', 'sekund');
        }

        return implode(' i ', $czesci);
    }
}

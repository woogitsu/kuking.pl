<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Czas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tag tygodnia — wyróżnienie zwykłego tagu na określone dni (issue #18).
 * Kształt tabeli i powód osobnej tabeli: migracja
 * `2026_09_25_100000_create_tag_highlights_table`.
 *
 * Całość stoi za flagą `kuking.tag_tygodnia.wlaczony` (domyślnie wyłączona).
 */
class TagHighlight extends Model
{
    use HasUuids;

    protected $fillable = [
        'tag_id',
        'starts_on',
        'ends_on',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }

    /**
     * Wyróżnienie trwające dziś — dzień liczony w strefie człowieka, nie UTC.
     * Bazowy `EXCLUDE` gwarantuje, że pasuje najwyżej jedno.
     *
     * @param  Builder<TagHighlight>  $query
     */
    public function scopeBiezace(Builder $query): void
    {
        $dzis = Czas::lokalnie(now())->toDateString();

        $query->whereDate('starts_on', '<=', $dzis)->whereDate('ends_on', '>=', $dzis);
    }

    /**
     * Bieżący tag tygodnia do pokazania na `/home` — albo nic.
     *
     * Tag ukryty albo scalony nie dostaje bloku: zaproszenie do tagu, którego
     * formularz dodawania i tak nie zaznaczy (`PostController::create` bierze
     * tylko aktywny), byłoby martwym przyciskiem.
     */
    public static function doPokazania(): ?self
    {
        if (! config('kuking.tag_tygodnia.wlaczony', false)) {
            return null;
        }

        $wyroznienie = self::query()->biezace()->with('tag')->first();

        return $wyroznienie?->tag?->isActive() ? $wyroznienie : null;
    }
}

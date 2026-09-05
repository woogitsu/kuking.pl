<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_REMOVED = 'removed';

    public const SOURCE_OWN = 'own';

    public const SOURCE_FAMILY = 'family';

    public const SOURCE_ADAPTATION = 'adaptation';

    public const SOURCE_EXTERNAL = 'external';

    /** Etykiety pochodzenia przepisu — używane w widokach i w formularzu. */
    public const SOURCE_LABELS = [
        self::SOURCE_OWN => 'Mój własny',
        self::SOURCE_FAMILY => 'Rodzinny',
        self::SOURCE_ADAPTATION => 'Moja wersja czyjegoś przepisu',
        self::SOURCE_EXTERNAL => 'Z książki, bloga lub telewizji',
    ];

    public const DIFFICULTY_LABELS = [
        'easy' => 'Łatwy',
        'medium' => 'Średni',
        'hard' => 'Wymagający',
    ];

    protected $fillable = [
        'author_id',
        'title',
        'slug',
        'summary',
        'servings',
        'prep_minutes',
        'cook_minutes',
        'difficulty',
        'visibility',
        'status',
        'hero_media_id',
        'source_type',
        'source_url',
        'source_person',
        'source_note',
        'family_since_year',
        'source_scan_media_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'servings' => 'float',
            'prep_minutes' => 'integer',
            'cook_minutes' => 'integer',
            'family_since_year' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    // ---------------------------------------------------------------------
    // Relacje
    // ---------------------------------------------------------------------

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function heroMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'hero_media_id');
    }

    public function sourceScan(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'source_scan_media_id');
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class)->orderBy('position');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RecipeStep::class)->orderBy('position');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class)->orderByDesc('version_number');
    }

    public function cookedEvents(): HasMany
    {
        return $this->hasMany(CookedEvent::class)->latest('cooked_at');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->where('status', Comment::STATUS_PUBLISHED)
            ->oldest();
    }

    // ---------------------------------------------------------------------
    // Zakresy i pytania
    // ---------------------------------------------------------------------

    /** @param  Builder<Recipe>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at');
    }

    /** @param  Builder<Recipe>  $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->published()->where('visibility', 'public');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at !== null;
    }

    public function totalMinutes(): ?int
    {
        if ($this->prep_minutes === null && $this->cook_minutes === null) {
            return null;
        }

        return (int) $this->prep_minutes + (int) $this->cook_minutes;
    }

    /** Czas w formacie ISO 8601 dla structured data (np. PT1H30M). */
    public function totalTimeIso(): ?string
    {
        $minutes = $this->totalMinutes();

        if ($minutes === null || $minutes <= 0) {
            return null;
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return 'PT'.($hours > 0 ? $hours.'H' : '').($rest > 0 ? $rest.'M' : '');
    }

    public function difficultyLabel(): ?string
    {
        return $this->difficulty === null ? null : (self::DIFFICULTY_LABELS[$this->difficulty] ?? null);
    }

    /**
     * Kto jest prawdziwym autorem przepisu — użytkownik czy osoba, po której
     * przepis został odziedziczony. To jest jedno z serc produktu: przepis
     * "po Halinie" ma być podpisany Haliną.
     */
    public function attributionLine(): string
    {
        $author = $this->author->displayName();

        if ($this->source_person !== null && $this->source_person !== '') {
            return "przepis {$this->source_person}, spisany przez {$author}";
        }

        return "przepis {$author}";
    }

    public function url(): string
    {
        return route('recipes.show', ['recipe' => $this->slug]);
    }
}

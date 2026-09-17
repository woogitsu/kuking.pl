<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Tag — otwarta taksonomia użytkowników, zastępująca Tematy (D-021).
 *
 * ŚWIADOMIE BEZ `status` I `merged_into_tag_id` W `$fillable`.
 * Zmiana stanu tagu (ukrycie, scalenie) jest zawsze jawną, nazwaną operacją
 * (`App\Domain\Tags\Actions\MergeTags`, nie `Tag::create()`/`update()` wprost)
 * — ten sam powód, dla którego `User::$fillable` nie ma `status` ani `role`
 * (AGENTS.md §7). Masowy zapis z formularza nie ma jak po cichu scalić
 * albo ukryć tagu.
 */
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_MERGED = 'merged';

    protected $fillable = [
        'name',
        'normalized_name',
        'slug',
        'is_seeded',
        'internal_category',
    ];

    protected function casts(): array
    {
        return [
            'is_seeded' => 'boolean',
        ];
    }

    /** Adres strony tagu (/tag/{slug}). */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * NAJWAŻNIEJSZA METODA W TYM PLIKU — normalizacja do UNIKALNOŚCI
     * (poprawka techniczna z D-021, `docs/DECISIONS.md`).
     *
     * BEZ `unaccent`. Istniejąca `kuking_normalize()` (funkcja SQL z migracji
     * `2026_09_05_001300_fix_search_indexes`) usuwa polskie znaki
     * diakrytyczne i służy WYŁĄCZNIE do wyszukiwania/podpowiadania
     * (`App\Domain\Tags\TagSuggester`) — użycie jej też tutaj złamałoby
     * wymóg, że `zurek` i `żurek` to DWA różne tagi: oba znormalizowałyby
     * się do identycznego ciągu i drugi z nich nigdy nie powstałby jako
     * osobny wiersz (`UNIQUE(normalized_name)` odrzuciłby zapis).
     *
     * Co robi:
     *   1. `trim()` — białe znaki na początku/końcu.
     *   2. redukcja wielokrotnych białych znaków do jednej spacji.
     *   3. `mb_strtolower()` — wielkość liter nie tworzy nowego tagu.
     *   4. `Normalizer::normalize(..., NFC)` — ta sama litera zapisana dwoma
     *      różnymi sekwencjami Unicode (znak złożony kontra litera + akcent
     *      jako osobny znak łączący) ma stać się TYM SAMYM ciągiem bajtów,
     *      inaczej `UNIQUE` na kolumnie widziałoby dwa różne teksty, mimo że
     *      ekran pokazuje identyczny napis.
     *
     * Diakrytyki ZOSTAJĄ — to jest cały sens tej metody.
     */
    public static function znormalizujNazwe(string $surowa): string
    {
        $przycieta = trim($surowa);
        $pojedynczeSpacje = preg_replace('/\s+/u', ' ', $przycieta) ?? $przycieta;
        $malymiLiterami = mb_strtolower($pojedynczeSpacje);

        return Normalizer::normalize($malymiLiterami, Normalizer::FORM_C) ?: $malymiLiterami;
    }

    /**
     * Slug publicznej strony tagu — liczony OSOBNO od nazwy wyświetlanej
     * (SPEC §1.2), bo slug nie może zawierać spacji ani polskich znaków.
     *
     * `Str::slug()` transliteruje diakrytyki (ż → z), więc `żurek` i `zurek`
     * MOGĄ dać ten sam slug — to nie jest sprzeczne z tym, że są dwoma
     * różnymi tagami: ten drugi po prostu dostanie slug z numerem, tak samo
     * jak `RecipeController` robi to dziś dla tytułów przepisów.
     */
    public static function slugDlaNazwy(string $nazwa): string
    {
        // `Str::ascii` + `Str::slug`, dokładnie jak `GenerateRecipeSlug` dla
        // tytułów przepisów — jedna konwencja transliteracji w całym repo.
        $slug = Str::slug(Str::ascii($nazwa));

        return $slug === '' ? 'tag' : $slug;
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tags')
            ->using(PostTag::class)
            ->withPivot('position', 'dodany_recznie')
            ->orderBy('post_tags.position');
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tag_follows')
            ->withPivot('created_at');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    /** Tag kanoniczny, pod którym ten tag żyje po scaleniu (SPEC §1.8). */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_tag_id');
    }

    /** Tagi scalone POD tym tagiem — istnienie choćby jednego blokuje jego usunięcie w bazie. */
    public function mergedFrom(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_tag_id');
    }

    public function promotion(): HasOne
    {
        return $this->hasOne(TagPromotion::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isMerged(): bool
    {
        return $this->status === self::STATUS_MERGED;
    }

    /**
     * Tag, pod którym ten tag NAPRAWDĘ dziś żyje — sam siebie, jeśli nie
     * jest scalony, albo cel scalenia. Używane przez `TagController::show`
     * do przekierowania ze starej strony na kanoniczną (SPEC §1.8 pkt 6) —
     * bez osobnej tabeli przekierowań, bo scalony tag zostaje w `tags`
     * ze swoim slugiem (patrz R1 §1.8: `recipe_slug_redirects` tu niepotrzebne).
     */
    public function tagKanoniczny(): self
    {
        if (! $this->isMerged() || $this->mergedInto === null) {
            return $this;
        }

        return $this->mergedInto;
    }

    /** @param  Builder<Tag>  $query */
    public function scopeAktywne(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Tagi promowane — lista gospodarza (D-021, „tag promowany — lista
     * gospodarza"), w kolejności redakcyjnej z `tag_promotions.position`.
     *
     * TO JEST TEN „DOPISEK, NIE PRZEBUDOWA", O KTÓRY CHODZIŁO.
     * Zanim właściciel potwierdził tę część D-021, ten sam ekran onboardingu
     * musiałby wybierać skądś tagi startowe — a jedyna zmiana, jakiej to
     * potrzebowało, to DODANIE tej metody i tabeli `tag_promotions`. Żaden
     * kod, który już czyta `Tag`, `post_tags` czy `tag_follows`, nie musiał
     * się zmienić.
     *
     * Podzapytanie do sortowania (`orderBy` z buildera zamiast `JOIN`)
     * unika kolizji nazw kolumn między `tags` i `tag_promotions` — Eloquent
     * hydratuje czyste modele `Tag`, bez sztucznych aliasów w SELECT-cie.
     *
     * @param  Builder<Tag>  $query
     */
    public function scopePromowane(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE)
            ->whereHas('promotion')
            ->with('promotion')
            ->orderBy(
                TagPromotion::query()
                    ->select('position')
                    ->whereColumn('tag_promotions.tag_id', 'tags.id'),
            );
    }
}

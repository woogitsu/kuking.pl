<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Odmiana;
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

    /**
     * Przepisy, które wolno pokazać temu widzowi — niezależnie od tego, KTO
     * jest ich autorem.
     *
     * DLACZEGO TO JEST SCOPE NA MODELU, A NIE HELPER W KONTROLERZE
     * `ProfileController` ma własny, prywatny filtr — i to mu wystarcza,
     * bo tam wszystkie przepisy należą do JEDNEGO właściciela profilu.
     *
     * W zeszycie tak nie jest: leżą tam przepisy wielu różnych autorów, każdy
     * z własną widocznością i własnymi blokadami. Filtr musi więc pytać
     * o relację widz ↔ autor osobno dla każdego wiersza, a nie raz dla całej
     * listy.
     *
     * Kanoniczna tabela prawdy jest w `Tests\Feature\Visibility\WidocznoscTestCase`:
     *
     *   widz            | public | followers | private
     *   ----------------|--------|-----------|--------
     *   autor           |   ✓    |     ✓     |    ✓
     *   obserwujący     |   ✓    |     ✓     |    ✗
     *   obcy            |   ✓    |     ✗     |    ✗
     *   zablokowany     |   ✗    |     ✗     |    ✗
     *   niezalogowany   |   ✓    |     ✗     |    ✗
     *
     * Blokada ma pierwszeństwo przed wszystkim innym i działa w OBIE strony
     * (`AGENTS.md` §4) — nieważne, kto kogo zablokował.
     *
     * @param  Builder<Recipe>  $query
     */
    public function scopeWidoczneDla(Builder $query, ?User $widz): void
    {
        if ($widz === null) {
            $query->published()->where('visibility', 'public');

            return;
        }

        $widzId = $widz->getKey();

        // 1. Blokada — pierwsza i bezwarunkowa, w obie strony.
        $query->whereNotExists(function ($sub) use ($widzId): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function ($w) use ($widzId): void {
                    $w->where('blocks.blocker_id', $widzId)
                        ->whereColumn('blocks.blocked_id', 'recipes.author_id');
                })
                ->orWhere(function ($w) use ($widzId): void {
                    $w->whereColumn('blocks.blocker_id', 'recipes.author_id')
                        ->where('blocks.blocked_id', $widzId);
                });
        });

        // 2. Widoczność, liczona per autor wiersza.
        //
        // `published()` celowo NIE obejmuje własnych przepisów widza. Autor ma
        // widzieć swój szkic w swoim zeszycie — „poprawne dane nigdy nie
        // znikają" (AGENTS.md, UX 50+). Gdyby filtr publikacji obowiązywał
        // wszystkich, ta naprawa prywatności zabrałaby ludziom dostęp do
        // własnych, niedokończonych przepisów.
        $query->where(function ($w) use ($widzId): void {
            $w->where('recipes.author_id', $widzId)
                ->orWhere(function ($cudze) use ($widzId): void {
                    $cudze->published()
                        ->where(function ($widok) use ($widzId): void {
                            $widok->where('visibility', 'public')
                                ->orWhere(function ($obs) use ($widzId): void {
                                    $obs->where('visibility', 'followers')
                                        ->whereExists(function ($sub) use ($widzId): void {
                                            $sub->selectRaw('1')
                                                ->from('follows')
                                                ->where('follows.follower_id', $widzId)
                                                ->whereColumn('follows.followed_id', 'recipes.author_id');
                                        });
                                });
                        });
                });
        });
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

    /**
     * Liczba porcji gotowa do pokazania człowiekowi (audyt A28).
     *
     * DLACZEGO TO NIE JEST `(int) $recipe->servings` W WIDOKU
     * Kolumna `servings` to `decimal(6,2)`, a formularz dopuszcza `step=0.5`
     * i `min=0.5`. Rzutowanie na `int` w widoku dawało:
     *
     *     w bazie 0.5  → „0 porcji"
     *     w bazie 1.5  → „1 porcji"
     *
     * czyli przepis mówiący nieprawdę o samym sobie — a że ta sama wartość szła
     * do JSON-LD jako `recipeYield`, nieprawda trafiała również do Google.
     *
     * Druga połowa to polszczyzna (AGENTS.md §11, docs/UX_50_PLUS.md).
     * „1 porcji" i „2 porcji" to nie jest polski. Liczebnik rządzi
     * rzeczownikiem, a ułamek zawsze bierze dopełniacz liczby mnogiej:
     *
     *     1        → porcja
     *     2, 3, 4  → porcje    (ale 12, 13, 14 → porcji)
     *     5 i dalej→ porcji
     *     1,5      → porcji
     *
     * Separator dziesiętny po polsku to przecinek, nie kropka.
     *
     * Metoda siedzi na modelu, a nie w widoku, bo tę samą odpowiedź czyta
     * i znaczek na stronie, i structured data — dwa różne teksty dla jednej
     * liczby byłyby dwiema okazjami do pomyłki.
     */
    public function servingsLabel(): ?string
    {
        if ($this->servings === null) {
            return null;
        }

        // decimal(6,2) — po zaokrągleniu do dwóch miejsc nie ma już „ogona"
        // po arytmetyce zmiennoprzecinkowej.
        $liczba = round((float) $this->servings, 2);

        if ($liczba <= 0) {
            return null;
        }

        $calkowita = abs($liczba - round($liczba)) < 0.005;

        if ($calkowita) {
            $ile = (int) round($liczba);

            return $ile.' '.self::odmianaPorcji($ile);
        }

        // 1.5 → „1,5", 0.75 → „0,75". Zbędne zera z prawej znikają.
        $tekst = rtrim(rtrim(number_format($liczba, 2, ',', ''), '0'), ',');

        return $tekst.' porcji';
    }

    /**
     * Odmiana rzeczownika „porcja" przez liczebnik główny.
     *
     * Pułapka jest w drugim warunku: 12, 13 i 14 mają końcówkę 2–4, ale idą
     * jak „pięć". Bez `% 100` wychodzi „12 porcje".
     */
    /**
     * Reguła odmiany mieszka w `App\\Support\\Odmiana` — ta sama, której
     * używa wyszukiwarka. Wcześniej były dwie: pełna, trzystanowa tutaj
     * i dwustanowa w widoku wyszukiwania, przez którą przy trzech wynikach
     * pisało „Znaleziono 3 przepisów".
     */
    private static function odmianaPorcji(int $ile): string
    {
        return Odmiana::rzeczownik($ile, 'porcja', 'porcje', 'porcji');
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

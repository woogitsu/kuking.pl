<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wpis: zdjęcie + kilka słów. Główna jednostka treści w Kuking.
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_REMOVED = 'removed';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_FOLLOWERS = 'followers';

    public const VISIBILITY_PRIVATE = 'private';

    /** Zdjęcia jedno pod drugim — jedyny wariant do issue #92 i domyślny. */
    public const DISPLAY_NORMAL = 'normal';

    /** Jedno zdjęcie naraz, przewijane w bok we własnym kontenerze. */
    public const DISPLAY_CAROUSEL = 'carousel';

    /** Siatka: wszystkie zdjęcia na jednym ekranie. */
    public const DISPLAY_COLLAGE = 'collage';

    protected $fillable = [
        'author_id',
        'body',
        'visibility',
        'status',
        'display_mode',
        'recipe_id',
        'topic_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'hide_as_memory' => 'boolean',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Temat wpisu — opcjonalny (issue #31).
     *
     * Wpis bez tematu jest w pełni poprawny i tak zostaje: wymuszanie wyboru
     * dokładałoby decyzję w momencie, w którym chcemy, żeby człowiek po prostu
     * wrzucił zdjęcie. Cel produktowy to poniżej 60 sekund od wejścia
     * do opublikowania.
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    /**
     * Zeszyty, w których ten wpis został odłożony (UI kit v2, ekran 01).
     *
     * @return BelongsToMany<Collection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_items');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->withPivot('position')
            ->orderBy('post_media.position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->where('status', Comment::STATUS_PUBLISHED)
            ->oldest();
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // ---------------------------------------------------------------------
    // Zakresy
    // ---------------------------------------------------------------------

    /** @param  Builder<Post>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at');
    }

    /** @param  Builder<Post>  $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->published()->where('visibility', self::VISIBILITY_PUBLIC);
    }

    /**
     * Wpisy autorów, których konto jest dostępne — bez zawieszonych,
     * zablokowanych i zgłoszonych do usunięcia.
     *
     * ODDZIELNIE OD `widoczneDla`, I TO NIE JEST PRZEOCZENIE
     * `widoczneDla` odpowiada na pytanie „czy TEN widz ma prawo to zobaczyć"
     * — dotyczy relacji między dwiema osobami. Ten zakres odpowiada na inne
     * pytanie: „czy ta treść ma prawo być POLECANA nieznajomym". Pierwsze
     * obowiązuje wszędzie, drugie tylko tam, gdzie serwis sam podsuwa treść:
     * „Świeżo z Kuking", wyszukiwarka, tablica na dziś, feed tematów.
     *
     * Rozdzielenie ma konkretny skutek: zawieszony autor dalej widzi własne
     * wpisy i dalej działa bezpośredni link, ale serwis przestaje je
     * podsuwać. Sklejenie obu warunków w jeden odcięłoby autora od własnych
     * treści — a „poprawne dane nigdy nie znikają" obowiązuje także wtedy,
     * gdy ktoś jest ukarany.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeTylkoOdDostepnychAutorow(Builder $query): void
    {
        $query->whereHas('author', fn ($autor) => $autor->where('status', User::STATUS_ACTIVE));
    }

    /**
     * Wpisy, które MOŻE zobaczyć konkretna osoba — licząc per autor wiersza.
     *
     * DLACZEGO TO MUSI BYĆ ZAKRES NA MODELU, A NIE POMOCNIK W KONTROLERZE
     * `ProfileController` ma własny filtr widoczności, ale liczy go dla JEDNEGO
     * właściciela profilu: „czy widz obserwuje TĘ osobę". Na stronie tematu
     * wpisy pochodzą od wielu autorów naraz, więc pytanie brzmi inaczej —
     * dla każdego wiersza osobno. Skopiowanie tamtego pomocnika dałoby filtr,
     * który przepuszcza wpisy „tylko dla obserwujących" od osób, których widz
     * nie obserwuje.
     *
     * Kolejność ma znaczenie: NAJPIERW blokada, bezwarunkowo i w obie strony.
     * Blokada, która działa „w większości miejsc", nie działa — a temat jest
     * dokładnie tym miejscem, w którym ktoś odcięty wypłynąłby z powrotem.
     *
     * Wzorzec identyczny jak `Recipe::scopeWidoczneDla` (audyt A04). Dwie
     * kopie tej samej logiki to dwie okazje do rozjazdu, ale zapytania
     * dotyczą różnych tabel i kolumn — połączenie ich wymagałoby warstwy
     * abstrakcji droższej niż problem, który rozwiązuje.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeWidoczneDla(Builder $query, ?User $widz): void
    {
        if ($widz === null) {
            $query->published()->where('visibility', self::VISIBILITY_PUBLIC);

            return;
        }

        $widzId = $widz->getKey();

        $query->whereNotExists(function ($sub) use ($widzId): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function ($w) use ($widzId): void {
                    $w->where('blocks.blocker_id', $widzId)
                        ->whereColumn('blocks.blocked_id', 'posts.author_id');
                })
                ->orWhere(function ($w) use ($widzId): void {
                    $w->whereColumn('blocks.blocker_id', 'posts.author_id')
                        ->where('blocks.blocked_id', $widzId);
                });
        });

        // Własne wpisy widz widzi zawsze — także prywatne. „Poprawne dane
        // nigdy nie znikają": własne archiwum ma być dostępne dla autora.
        $query->where(function ($w) use ($widzId): void {
            $w->where('posts.author_id', $widzId)
                ->orWhere(function ($cudze) use ($widzId): void {
                    $cudze->published()
                        ->where(function ($widok) use ($widzId): void {
                            $widok->where('visibility', self::VISIBILITY_PUBLIC)
                                ->orWhere(function ($obs) use ($widzId): void {
                                    $obs->where('visibility', self::VISIBILITY_FOLLOWERS)
                                        ->whereExists(function ($sub) use ($widzId): void {
                                            $sub->selectRaw('1')
                                                ->from('follows')
                                                ->where('follows.follower_id', $widzId)
                                                ->whereColumn('follows.followed_id', 'posts.author_id');
                                        });
                                });
                        });
                });
        });
    }

    /**
     * Tryby wyświetlania zdjęć, które baza w ogóle przyjmie (issue #92).
     *
     * Ta lista jest ODBICIEM ograniczenia CHECK z migracji
     * `2026_09_06_120000_add_display_mode_to_posts`, nie drugim źródłem
     * prawdy: gdy się rozjadą, baza odrzuci zapis, a nie zapisze cicho
     * tryb, którego widok nie umie narysować.
     *
     * @return list<string>
     */
    public static function dozwoloneTrybyWyswietlania(): array
    {
        return [self::DISPLAY_NORMAL, self::DISPLAY_CAROUSEL, self::DISPLAY_COLLAGE];
    }

    /**
     * Tryb, w którym zdjęcia tego wpisu MAJĄ SIĘ NAPRAWDĘ pokazać.
     *
     * DLACZEGO NIE WYSTARCZY SAMA KOLUMNA
     * Karuzela z jednym zdjęciem to przyciski „poprzednie/następne", które
     * nie mają dokąd prowadzić, a kolaż z jednym zdjęciem to siatka z jednym
     * polem. Wpis może stracić zdjęcia (moderacja, usunięcie pliku) długo po
     * tym, jak autor wybrał tryb — i wtedy zapisana wartość przestaje mieć
     * sens. Pytanie „co narysować" ma więc jedną odpowiedź, liczoną w jednym
     * miejscu, zamiast trzech warunków rozsianych po widokach.
     */
    public function trybWyswietlaniaZdjec(): string
    {
        if ($this->media->count() < 2) {
            return self::DISPLAY_NORMAL;
        }

        return in_array($this->display_mode, self::dozwoloneTrybyWyswietlania(), true)
            ? (string) $this->display_mode
            : self::DISPLAY_NORMAL;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at !== null;
    }

    public function url(): string
    {
        return route('posts.show', ['post' => $this->getKey()]);
    }
}

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
 *
 * Kolumny z migracji SQL add_kind_and_title_to_posts (Larastan nie odczytuje ALTER TABLE).
 *
 * @property string $kind
 * @property string|null $title
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const KIND_DISH = 'dish';

    public const KIND_QUESTION = 'question';

    protected $attributes = [
        'kind' => self::KIND_DISH,
        'title' => null,
    ];

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
        'kind',
        'title',
        'author_id',
        'body',
        'visibility',
        'status',
        'display_mode',
        'recipe_id',
        // Tożsamość JEDNEGO wysłania formularza „Opublikuj" — nie treść i nie
        // stan wpisu. Częściowy indeks UNIQUE `posts_one_per_klucz_wyslania`
        // na parze (autor, klucz) sprawia, że drugie kliknięcie nie tworzy
        // drugiego wpisu (ADR `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`).
        'klucz_wyslania',
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

    /**
     * Tagi wpisu (D-021) — maksymalnie 5, w kolejności, w jakiej autor je
     * dodał. Limit i tworzenie nowych tagów pilnuje
     * `App\Domain\Tags\Actions\ResolveTagsForPost`, nie ten model.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tags')
            ->using(PostTag::class)
            ->withPivot('position', 'dodany_recznie')
            ->orderBy('post_tags.position');
    }

    public function comments(): HasMany
    {
        // `->orderBy('id')` rozstrzyga remisy `created_at` (sekundowa
        // dokładność `timestampsTz()`). Bez tego paginacja komentarzy potrafi
        // pokazać ten sam wpis na dwóch stronach i nie pokazać innego nigdzie.
        // Pełne uzasadnienie: `Recipe::cookedEvents()`.
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->where('status', Comment::STATUS_PUBLISHED)
            ->oldest()
            ->orderBy('id');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // ---------------------------------------------------------------------
    // Zakresy
    // ---------------------------------------------------------------------

    /** Licznik kart: ślad usunięcia zachowuje rozmowę, ale nie jest odpowiedzią.
     * @param  Builder<Post>  $query
     */
    public function scopeWithVisibleCommentCount(Builder $query, ?User $viewer): void
    {
        $query->withCount(['comments' => fn (Builder $comments) => $comments
            ->widoczneDla($viewer)
            ->where(fn (Builder $counted) => $counted
                ->whereNull('comments.body_removed_at')
                ->orWhere('posts.kind', self::KIND_DISH))]);
    }

    /** @param  Builder<Post>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at');
    }

    /** @param  Builder<Post>  $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->enabledKinds()->published()->where('visibility', self::VISIBILITY_PUBLIC);
    }

    /** Flaga publikacji działu nie usuwa danych ani nie filtruje operacji utrzymaniowych.
     * @param  Builder<Post>  $query
     */
    public function scopeEnabledKinds(Builder $query): void
    {
        if (! config('kuking.questions.enabled', false)) {
            $query->where('posts.kind', self::KIND_DISH);
        }
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
     * „Świeżo z Kuking", wyszukiwarka, tablica na dziś, feed tagów.
     *
     * Rozdzielenie ma konkretny skutek: zawieszony autor dalej widzi własne
     * wpisy i dalej działa bezpośredni link, ale serwis przestaje je
     * podsuwać. Sklejenie obu warunków w jeden odcięłoby autora od własnych
     * treści — a „poprawne dane nigdy nie znikają" obowiązuje także wtedy,
     * gdy ktoś jest ukarany.
     *
     * UWAGA NA NAZWĘ (audyt W5-08). Ten scope znaczy „tylko konta AKTYWNE"
     * i jest WĘŻSZY niż granica z polityk, która dopuszcza też konto
     * zawieszone (`User::jestDostepnyJakoAutor()`). Nazywał się wcześniej
     * `tylkoOdDostepnychAutorow` i przez to czytał się jak ta druga reguła —
     * a wtedy łatwo użyć go tam, gdzie potrzebna jest granica polityki,
     * albo odwrotnie.
     *
     * Tu wąsko jest CELOWO: te zapytania polecają treści nieznajomym.
     * Zawieszenie jest karą za pisanie i nie kasuje tego, co ktoś już
     * napisał — ale nie jest też powodem, żeby akurat teraz go promować.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeTylkoOdAktywnychAutorow(Builder $query): void
    {
        $query->whereHas('author', fn ($autor) => $autor->where('status', User::STATUS_ACTIVE));
    }

    /**
     * Wpisy, których PRZEPIS wolno dziś pokazać temu widzowi — czyli wpisy
     * bez przepisu (zwykłe „co dziś ugotowałem") ORAZ wpisy wskazujące
     * przepis, który jest opublikowany, nieusunięty i widoczny dla widza.
     *
     * PO CO TO JEST (issue #368)
     * Opublikowany przepis dostaje od `PublishRecipe` wpis wskazujący go
     * przez `posts.recipe_id` — z `body = null` i BEZ własnych zdjęć. Wpis
     * niczego z przepisu NIE KOPIUJE: tytuł i zdjęcie karta bierze z relacji
     * `$post->recipe`. Gdyby kopiował, usunięcie przepisu, ukrycie go przez
     * moderację, zmiana widoczności i zmiana tytułu byłyby CZTEREMA
     * miejscami do rozjechania się i czterema hakami „przenieś zmianę
     * na wpis".
     *
     * Ten zakres jest ceną za tę decyzję i jednocześnie całą jej obsługą:
     * jeden warunek w zapytaniu załatwia usunięcie (także miękkie), ukrycie
     * przez moderację ORAZ zawężenie widoczności naraz. Wiersz `posts`
     * zostaje w bazie nietknięty — po prostu przestaje wychodzić ze
     * strumienia, dokładnie tak, jak przestaje być widoczny przepis.
     *
     * DLACZEGO `whereHas`, A NIE `whereExists` NA SUROWYM `recipes`
     * Relacja `recipe()` prowadzi do modelu z `SoftDeletes`, więc zapytanie
     * relacji samo dokłada `recipes.deleted_at is null`. Ręczny `whereExists`
     * na tabeli wymagałby pamiętania o tym warunku — a to jest dokładnie ten
     * rodzaj rzeczy, który się zapomina przy drugiej kopii.
     *
     * DLACZEGO NIE REUŻYWAMY `Notification::wierszTresciWidoczny()`
     * Tamten pomocnik odpowiada na to samo pytanie, ale jest prywatny,
     * zbudowany na surowym `Query\Builder` z aliasem tabeli i wymaga
     * NIEPUSTEGO widza — a strumienie („Świeżo z Kuking", tablica dnia,
     * strona powitalna) pytają także za gościa, czyli z `?User = null`.
     * Kanonicznym odpowiednikiem w warstwie Eloquenta jest
     * `Recipe::scopeWidoczneDla()` — ta sama tabela prawdy, przypięta
     * testami `Tests\Feature\Visibility\WidocznoscTestCase` — i to jej
     * używamy, zamiast zakładać trzecią kopię tej samej reguły.
     *
     * JEDEN ŚWIADOMY WYJĄTEK: AUTOR WIDZI SWOJE. `widoczneDla()` przepuszcza
     * autorowi własną treść niezależnie od widoczności („poprawne dane nigdy
     * nie znikają", AGENTS.md §5), więc autor zobaczy w swoim feedzie wpis
     * do własnego przepisu „tylko dla obserwujących", a nawet „tylko dla
     * mnie". Nikt inny go nie zobaczy. Wybór jest świadomy: własna kopia
     * reguły widoczności bez tej furtki byłaby czwartym miejscem, w którym
     * ta sama tabela prawdy może się rozjechać.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeZWidocznymPrzepisem(Builder $query, ?User $widz): void
    {
        $query->where(function (Builder $w) use ($widz): void {
            $w->whereNull('posts.recipe_id')
                ->orWhereHas('recipe', function ($przepis) use ($widz): void {
                    $przepis->published()->widoczneDla($widz);
                });
        });
    }

    /**
     * Wpisy, które MOŻE zobaczyć konkretna osoba — licząc per autor wiersza.
     *
     * DLACZEGO TO MUSI BYĆ ZAKRES NA MODELU, A NIE POMOCNIK W KONTROLERZE
     * `ProfileController` ma własny filtr widoczności, ale liczy go dla JEDNEGO
     * właściciela profilu: „czy widz obserwuje TĘ osobę". Na stronie tagu
     * wpisy pochodzą od wielu autorów naraz, więc pytanie brzmi inaczej —
     * dla każdego wiersza osobno. Skopiowanie tamtego pomocnika dałoby filtr,
     * który przepuszcza wpisy „tylko dla obserwujących" od osób, których widz
     * nie obserwuje.
     *
     * Kolejność ma znaczenie: NAJPIERW blokada, bezwarunkowo i w obie strony.
     * Blokada, która działa „w większości miejsc", nie działa — a strona tagu jest
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
        $query->enabledKinds();

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
        return route($this->kind === self::KIND_QUESTION ? 'questions.show' : 'posts.show', ['post' => $this->getKey()]);
    }

    /**
     * Czy ten wpis nie ma NIC własnego — jest wyłącznie wskazaniem przepisu.
     *
     * Takie wpisy zakłada `kuking:dopisz-wpisy-przepisow` (#368), żeby przepis
     * w ogóle pojawił się w strumieniu: bez treści, bez własnych zdjęć,
     * ze zdjęciem branym z przepisu. Ich strona (`posts.show`) to nagłówek,
     * pasek „Z przepisu” i komentarze — czyli ekran, na którym nie ma nic,
     * czego nie ma na stronie przepisu, a strona przepisu ma WŁASNE komentarze
     * (`recipes.comment`).
     *
     * KOMENTARZE SĄ CZĘŚCIĄ WARUNKU, I TO NIE JEST DROBIAZG.
     * Wpis bez treści, ale Z komentarzem, ma już coś własnego — rozmowę ludzi.
     * Gdyby warunek jej nie pytał, przekierowanie zostawiłoby tę rozmowę pod
     * adresem, do którego nic nie prowadzi. Dlatego wpis z komentarzem
     * zachowuje swoją stronę — i dlatego ta strona musi umieć pokazać
     * zdjęcie przepisu (issue #447).
     *
     * `comments_count` jest używane, GDY JEST POLICZONE. Strumień liczy je
     * w jednym zapytaniu (`withCount`), więc karta nie dokłada zapytań na
     * sztukę; pojedynczy ekran wpisu może sobie pozwolić na jedno.
     */
    public function jestSamymPrzepisem(): bool
    {
        if ($this->recipe === null || filled($this->body) || $this->media->isNotEmpty()) {
            return false;
        }

        $komentarzy = $this->comments_count ?? $this->comments()->count();

        return (int) $komentarzy === 0;
    }

    /**
     * Adres, pod którym stoi TREŚĆ tego wpisu — dla karty w strumieniu.
     *
     * Dla zwykłego wpisu to jego własna strona. Dla wpisu, który jest samym
     * wskazaniem przepisu — strona przepisu, bo tam jest wszystko: zdjęcie,
     * składniki, kroki i komentarze. Zgłoszenie właściciela z 12 września:
     * „Muszę szukać i klikać w bigos z cukinii żeby przejść do przepisu…
     * To nie ma sensu”.
     *
     * TO NIE JEST TO SAMO CO `url()` I NIE WOLNO ICH ZAMIENIĆ. `url()` zostaje
     * KANONICZNYM adresem wpisu — tym, który idzie do udostępniania, do
     * `<link rel="canonical">` i do danych strukturalnych. Adres wysłany
     * komuś w wiadomości ma działać po latach, także wtedy, gdy wpis
     * przestał być „samym przepisem”.
     */
    public function adresTresci(): string
    {
        return $this->jestSamymPrzepisem() && $this->recipe !== null
            ? route('recipes.show', $this->recipe->slug)
            : $this->url();
    }
}

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

    /**
     * `kind` NIE JEST TU CELOWO — patrz `oznaczJakoPytanie()` niżej.
     *
     * `title` ZOSTAJE, i to też jest decyzja, a nie przeoczenie: tytuł jest
     * TREŚCIĄ, którą pisze autor, a nie polem sterującym. Sam z siebie nie
     * otwiera żadnej furtki, bo CHECK `posts_kind_title_check` nie przyjmie
     * tytułu przy daniu — a `kind = 'question'` nie da się już podrzucić
     * hurtem. Tytuł podstawiony do `Post::create()` daniu odbija się więc
     * o bazę, zamiast po cichu wejść.
     */
    protected $fillable = [
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
     * Wpisy z WŁASNĄ treścią — niepustym tekstem albo choć jednym zdjęciem.
     * SQL-owa strona `czyJestZapowiedziaPrzepisu()`: wpis, który ją spełnia,
     * nie jest zapowiedzią, więc `PostPolicy::view()` nie bramkuje go
     * przepisem (issue #1377).
     *
     * @param  Builder<Post>  $query
     */
    public function scopeZWlasnaTrescia(Builder $query): void
    {
        $query->where(function (Builder $w): void {
            // `~ '\S'` = `filled()` z PHP: sam biały znak to brak treści.
            $w->whereRaw("posts.body ~ '\\S'")
                ->orWhereExists(function ($sub): void {
                    $sub->selectRaw('1')->from('post_media')->whereColumn('post_media.post_id', 'posts.id');
                });
        });
    }

    /**
     * Zapowiedź przepisu wychodzi na listy tylko z widocznym przepisem
     * (`zWidocznymPrzepisem()`, #368/#941), a wpis z własną treścią — według
     * WŁASNEJ widoczności, jak na swojej stronie (`PostPolicy::view()`,
     * issue #1377). Lista, która go pokazuje, musi przed kartą zdjąć
     * niedostępną relację: `ukryjNiedostepnePrzepisy()`.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeZWidocznymPrzepisemAlboWlasnaTrescia(Builder $query, ?User $widz): void
    {
        $query->where(function (Builder $w) use ($widz): void {
            $w->where(fn (Builder $tresc) => $tresc->zWlasnaTrescia())
                ->orWhere(fn (Builder $zapowiedz) => $zapowiedz->zWidocznymPrzepisem($widz));
        });
    }

    /**
     * Zdejmuje z wpisów relację przepisu, którego widz nie może zobaczyć —
     * to samo `setRelation('recipe', null)` co `PostController::show()`,
     * tylko jednym zapytaniem na stronę listy (issue #1377). Karta czyta
     * z relacji tytuł, slug, zdjęcie, plakietkę i przycisk „Ugotowałem”;
     * bez relacji pokazuje sam wpis z jego własną widocznością.
     *
     * Reguły `RecipePolicy::view()` w SQL: `Recipe::widoczneDla()` (własne
     * zawsze, cudze opublikowane, widoczność, blokada) plus dostępny autor
     * cudzego przepisu. Bez furtki moderatora — ostrzej, nigdy luźniej.
     *
     * @param  iterable<Post>  $wpisy
     */
    public static function ukryjNiedostepnePrzepisy(iterable $wpisy, ?User $widz): void
    {
        $zPrzepisem = collect($wpisy)->filter(
            fn (Post $wpis): bool => $wpis->relationLoaded('recipe') && $wpis->recipe !== null,
        );

        if ($zPrzepisem->isEmpty()) {
            return;
        }

        $widoczne = Recipe::query()
            ->whereIn('recipes.id', $zPrzepisem->pluck('recipe_id')->unique()->values())
            ->widoczneDla($widz)
            ->where(function (Builder $autor) use ($widz): void {
                $autor->whereHas('author', fn ($a) => $a->dostepnyJakoAutor());
                if ($widz !== null) {
                    $autor->orWhere('recipes.author_id', $widz->getKey());
                }
            })
            ->pluck('recipes.id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($zPrzepisem as $wpis) {
            if (! in_array((string) $wpis->recipe_id, $widoczne, true)) {
                $wpis->setRelation('recipe', null);
            }
        }
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
     * Zapisane wpisy, które widz może OTWORZYĆ — jedna reguła dla wnętrza
     * zeszytu, licznika na jego karcie i szyny „Ostatnio zapisane" (#1319).
     *
     * Cztery granice, wszystkie obowiązkowe: widoczność wpisu, bramka
     * przepisu (`zWidocznymPrzepisem()`, #368), status konta autora wpisu
     * i status konta autora PRZEPISU (W5-08) — ten ostatni osobno, bo
     * zapowiedź przepisu może należeć do kogo innego niż przepis.
     *
     * Wcześniej tylko `CollectionController::show()` miał komplet; karta
     * zeszytu i „Ostatnio zapisane" miały tylko pierwszą i trzecią.
     * Zapowiedź schowanego przepisu znikała z wnętrza
     * zeszytu, a karta dalej mówiła „1 wpis", szyna zaś dawała odnośnik,
     * który `PostPolicy::view()` kończy odmową.
     *
     * Gałąź `recipe_id IS NULL` przepuszcza zwykłe wpisy bez przepisu.
     *
     * OBIE BRAMKI PRZEPISU DOTYCZĄ TYLKO CZYSTEJ ZAPOWIEDZI (#1377, komentarz
     * w #1319 z 23.09). Wpis z własnym tekstem albo zdjęciem, który wskazuje
     * przepis, `PostPolicy::view()` wpuszcza według WŁASNEJ widoczności —
     * więc zostaje we wnętrzu, na karcie i w „Ostatnio zapisane", a nie
     * wpada do „niedostępnych". Wnętrze zeszytu zdejmuje mu wtedy przepis
     * z karty (`ukryjNiedostepnePrzepisy()` w `CollectionController::show()`).
     *
     * @param  Builder<Post>  $query
     */
    public function scopeWidoczneWZeszycieDla(Builder $query, ?User $widz): void
    {
        $query->widoczneDla($widz)
            ->zWidocznymPrzepisemAlboWlasnaTrescia($widz)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->where(fn ($w) => $w->whereNull('posts.recipe_id')
                ->orWhere(fn ($tresc) => $tresc->zWlasnaTrescia())
                ->orWhereHas('recipe.author', fn ($autor) => $autor->dostepnyJakoAutor()));
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

    /**
     * Uczyń z tego wpisu PYTANIE do działu „Poradźcie".
     *
     * DLACZEGO TA METODA ISTNIEJE (a `kind` nie ma go w `$fillable`)
     * `kind` nie jest treścią — jest polem STERUJĄCYM. Rozstrzyga, do
     * których strumieni wpis w ogóle trafia (`scopeEnabledKinds`), pod jakim
     * adresem stoi (`url()`) i czy `PostPolicy` dziś go przepuści. To ta sama
     * rodzina co `users.status` i `users.role`, których AGENTS.md §7 zabrania
     * w `$fillable`, i ten sam wzorzec co `ContactMessage::oznaczJako()`:
     * stan ustawia jawna, nazwana metoda, nigdy pole z żądania.
     *
     * Walidacja w `PostController` już dziś odrzuca podrzucone `kind` i ma
     * tak zostać — ale walidacja broni JEDNEJ drogi i trzyma się wyłącznie
     * na dyscyplinie: pierwsze `Post::create($request->all())` napisane
     * kiedykolwiek w przyszłości przewraca ją bez śladu. Dział „Poradźcie"
     * jest od 19 września włączony na produkcji
     * (`KUKING_QUESTIONS_ENABLED`), więc „danie zamienione w pytanie" to nie
     * jest już hipoteza o martwym kodzie: taki wpis wypada z feedu dań,
     * wchodzi do kolejki nieodpowiedzianych pytań i zmienia swój adres.
     *
     * `title` USTAWIA SIĘ TU RAZEM Z `kind`, bo baza nie przyjmuje ich
     * osobno: CHECK `posts_kind_title_check` wiąże je w jedną wartość
     * (danie bez tytułu, pytanie z tytułem 10–180 znaków po obcięciu).
     * Rozdzielenie na dwa kroki dałoby stan pośredni, którego wiersz i tak
     * nie umie mieć.
     *
     * NIE ZAPISUJE — inaczej niż `ContactMessage::oznaczJako()`, bo tam stan
     * zmienia się na wierszu, który już istnieje. Tutaj `kind` jest
     * ustawiany przy NARODZINACH wpisu: `PublishPost` robi jeden `save()`
     * wewnątrz transakcji i na tym jednym zapisie stoi idempotencja
     * wysłania formularza (`posts_one_per_klucz_wyslania`). Zapis w tej
     * metodzie byłby drugim, wcześniejszym `INSERT`-em.
     */
    public function oznaczJakoPytanie(string $title): static
    {
        return $this->forceFill([
            'kind' => self::KIND_QUESTION,
            'title' => $title,
        ]);
    }

    /**
     * Cała treść, którą autor napisał — do lokalnych sygnałów i oceny modelem (#831).
     *
     * PYTANIE MOŻE NIE MIEĆ OPISU: tytuł jest wtedy jedyną wypowiedzią,
     * więc analiza samego `body` przepuszczałaby je bez żadnego spojrzenia.
     * Dla dania zostaje `body`, jak dotąd. Niczego nie zapisuje — `body`
     * i `title` zostają osobnymi danymi.
     */
    public function tekstDoOceny(): string
    {
        $body = trim((string) $this->body);

        if ($this->kind !== self::KIND_QUESTION) {
            return $body;
        }

        return trim(trim((string) $this->title)."\n\n".$body);
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
        if (! $this->czyJestZapowiedziaPrzepisu()) {
            return false;
        }

        $komentarzy = $this->comments_count ?? $this->comments()->count();

        return (int) $komentarzy === 0;
    }

    /**
     * Czy ten wpis jest ZAPOWIEDZIĄ przepisu — czyli nie ma własnej treści
     * ani własnych zdjęć, a jedynym, co niesie, jest wskazanie przepisu.
     *
     * TO NIE JEST TO SAMO CO `jestSamymPrzepisem()` I NIE WOLNO ICH SKLEIĆ.
     * Tamta metoda pyta dodatkowo o komentarze, bo odpowiada na pytanie
     * „czy ta strona ma jeszcze po co istnieć" — rozmowa pod wpisem jest
     * treścią własną i sama w sobie wystarcza, żeby strony nie zwijać
     * przekierowaniem.
     *
     * Tu pytanie jest inne: „skąd ten wpis bierze swoją widoczność".
     * Odpowiedź — z przepisu, bo `WpisWskazujacyPrzepis::dopisz()` zapisuje
     * `visibility = 'public'` NIE jako decyzję o jawności, tylko jako brak
     * własnego zawężenia; bramką ma być przepis
     * (`scopeZWidocznymPrzepisem()`). Dopisanie tu warunku o komentarzach
     * znaczyłoby, że KTOKOLWIEK odblokowuje cudzy ukryty przepis, pisząc
     * pod jego zapowiedzią jedno zdanie. Dokładnie tak wyciekał tytuł
     * przepisu „tylko dla obserwujących" pod bezpośrednim adresem wpisu:
     * komentarz kasował przekierowanie, a strona wypisywała tytuł, slug
     * i zdjęcie główne gościowi.
     */
    public function czyJestZapowiedziaPrzepisu(): bool
    {
        if ($this->recipe_id === null || filled($this->body)) {
            return false;
        }

        // `media` bywa tu niezaładowane: ta metoda jest wołana także
        // z `PostPolicy::view()`, czyli PRZED `load()` w kontrolerze.
        // `exists()` zamiast pobrania wierszy — potrzebna jest odpowiedź
        // „czy jest choć jedno", nie same zdjęcia.
        return $this->relationLoaded('media')
            ? $this->media->isEmpty()
            : ! $this->media()->exists();
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

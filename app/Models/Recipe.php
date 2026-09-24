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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * Ile najwyżej kroków i składników przyjmuje jeden przepis.
     *
     * Te dwie liczby stały wpisane na sztywno w regułach walidacji
     * `RecipeController` (`'steps' => ['array', 'max:60']`) i nigdzie więcej.
     * Wyszły na stałe klasy, bo formularz jednostronicowy renderuje „tyle
     * wierszy, ile jest, plus jeden" BEZ SUFITU — czyli przy pełnym przepisie
     * rysował wiersz sześćdziesiąty pierwszy i odbijał własny POST
     * komunikatem o zbyt wielu krokach. Sufit musi być tą samą liczbą co
     * granica walidacji, a więc musi być JEDNĄ liczbą.
     */
    public const MAX_STEPS = 60;

    public const MAX_INGREDIENTS = 120;

    /**
     * Relacje, które czyta `<x-recipe-card>`: `attributionLine()` sięga po
     * `author->profile`, plakietka „konto przykładowe" po `author`,
     * miniatura po `heroMedia` (#1374). Każda lista kart — profil,
     * wyszukiwarka, zeszyt — ładuje je z góry tą jedną stałą, żeby karta
     * nie dociągała autora osobnym zapytaniem na każdy przepis.
     */
    public const RELACJE_KARTY = ['author.profile', 'heroMedia'];

    protected $fillable = [
        'author_id',
        // Tożsamość JEDNEGO wysłania formularza „Opublikuj" — nie treść
        // i nie stan przepisu. Częściowy indeks UNIQUE
        // `recipes_one_per_klucz_wyslania` na parze (autor, klucz) sprawia,
        // że drugie kliknięcie nie zakłada drugiego przepisu (ADR
        // `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`). Wypełniane tylko
        // przy ZAKŁADANIU przepisu; edycja tej kolumny nie dotyka.
        'klucz_wyslania',
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

    /**
     * Zeszyty, w których ten przepis został odłożony.
     *
     * Odwrotna strona `Collection::recipes()`. Potrzebna po to, żeby zapytać
     * „co TA OSOBA ma w swoich zeszytach" jednym zapytaniem, zamiast pobierać
     * wszystkie jej zeszyty i sklejać ich zawartość w PHP.
     *
     * @return BelongsToMany<Collection, $this>
     */
    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_items');
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
        // DRUGI KLUCZ SORTOWANIA NIE JEST OZDOBĄ — TO WARUNEK POPRAWNEJ
        // PAGINACJI.
        //
        // `cooked_at`, `created_at` i `published_at` są w tym schemacie typu
        // `timestamptz(0)`, czyli z dokładnością do SEKUNDY (`timestampsTz()`
        // w migracjach). Dwa wykonania dodane w tej samej sekundzie mają
        // identyczny klucz, a przy `ORDER BY` po samym nim PostgreSQL może
        // oddać je w dowolnej kolejności — i w KAŻDYM zapytaniu w innej.
        //
        // Paginacja to dwa osobne zapytania z `LIMIT`/`OFFSET`. Gdy kolejność
        // remisów zmieni się między nimi, ten sam wiersz pokazuje się na
        // dwóch stronach, a inny NIE POKAZUJE SIĘ NIGDZIE. To jest dokładnie
        // to, czego zakazuje UX_50_PLUS.md: poprawne dane nie mają prawa
        // zniknąć.
        //
        // `id` jest UUID-em v7 (`HasUuids` w Laravelu 12+), więc rośnie
        // z czasem — jako drugi klucz nie tylko rozstrzyga remis, ale
        // rozstrzyga go CHRONOLOGICZNIE. Ten sam wzorzec stoi już
        // w `DiscoverFeed` i `TagController`; tutaj go brakowało.
        return $this->hasMany(CookedEvent::class)
            ->latest('cooked_at')
            ->latest('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->where('status', Comment::STATUS_PUBLISHED)
            // `id` NIE jest ozdobą przy `oldest()` — patrz komentarz przy
            // `cookedEvents()` wyżej. `created_at` ma dokładność do sekundy,
            // więc bez tego dwa komentarze z tej samej sekundy potrafią
            // przeskoczyć między stronami.
            ->oldest()
            ->orderBy('id');
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

    /**
     * CZAS CAŁKOWITY JEST ZNANY TYLKO WTEDY, GDY PODANO OBA CZASY (#1090).
     *
     * Puste pole znaczy „nie wiem", a nie „zero": przy 10 min przygotowania
     * i pustym gotowaniu nikt nie zmierzył, ile to zajmie razem. Jawne `0`
     * znaczy „tego etapu nie ma" (np. surówka bez gotowania), więc 10 + 0
     * to znane 10 minut. Suma 0 (0 + 0) też jest „nie wiem" — obiecywanie
     * dania w zero minut byłoby tak samo nieprawdą.
     *
     * Tę samą regułę stosują: strona przepisu, `totalTime` w JSON-LD,
     * podgląd w kreatorze i filtr „Do 30 minut" (`scopeGotoweWCiagu()`
     * niżej). Przedtem strona pokazywała „Około 10 min", a filtr ten sam
     * przepis pomijał.
     */
    public function totalMinutes(): ?int
    {
        if ($this->prep_minutes === null || $this->cook_minutes === null) {
            return null;
        }

        $suma = $this->prep_minutes + $this->cook_minutes;

        return $suma > 0 ? $suma : null;
    }

    /**
     * Przepisy, których czas całkowity jest ZNANY (reguła z `totalMinutes()`)
     * i mieści się w `$maksMinut`. SQL-owy odpowiednik tamtej metody — obie
     * muszą zmieniać się razem.
     */
    public function scopeGotoweWCiagu(Builder $query, int $maksMinut): void
    {
        $query
            ->whereNotNull('recipes.prep_minutes')
            ->whereNotNull('recipes.cook_minutes')
            ->whereRaw('(recipes.prep_minutes + recipes.cook_minutes) > 0')
            ->whereRaw('(recipes.prep_minutes + recipes.cook_minutes) <= ?', [$maksMinut]);
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
     * Kolumna `servings` to `decimal(6,2)`, a formularz dopuszcza `step=0.01`
     * (setne — decyzja właściciela z 20.09.2026, issue #750) i `min=0.5`.
     * Rzutowanie na `int` w widoku dawało:
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
     * Podpis przepisu: kto go tu zapisał i skąd go ma.
     *
     * NIGDY NIE DOKLEJAJ PRZYIMKA ANI SŁOWA NIOSĄCEGO PRZYPADEK DO TEKSTU
     * WPISANEGO PRZEZ CZŁOWIEKA ANI DO NAZWY KONTA. Polskiej odmiany nie da
     * się policzyć z dowolnego ciągu znaków, a każda próba kończy się zdaniem,
     * które wygląda na zepsute oprogramowanie.
     *
     * Do 11 września 2026 stało tu `"przepis {$source_person}, spisany przez
     * {$author}"` i miało dwa błędy odmiany naraz: tekst użytkownika wchodził
     * w miejsce dopełniacza („przepis Nasze smaki"), a nazwa konta w miejsce
     * biernika („spisany przez Krzysztof"). Wariant bez źródła był zepsuty tak
     * samo: „przepis Krzysztof".
     *
     * Dlatego obie wartości stoją tu w MIANOWNIKU, w osobnych członach:
     * nazwa konta jako podpis (tak samo jak w wierszu z awatarem), a wartość
     * pola po dwukropku — dwukropek zdejmuje wymaganie przypadku i działa
     * dla „od mamy", „Nasze smaki" i „z gazety Przyjaciółka" jednakowo.
     * Wartość idzie dosłownie: po dwukropku mała litera jest poprawna,
     * a zmiana wielkości liter należy do widoku, nie do podpisu.
     */
    public function attributionLine(): string
    {
        $author = $this->author->displayName();

        if ($this->source_person !== null && $this->source_person !== '') {
            return "{$author} · skąd ten przepis: {$this->source_person}";
        }

        return $author;
    }

    public function url(): string
    {
        return route('recipes.show', ['recipe' => $this->slug]);
    }
}

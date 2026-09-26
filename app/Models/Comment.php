<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Komentarz. Dotyczy dokładnie jednego obiektu — pilnuje tego CHECK w bazie.
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_REMOVED = 'removed';

    /**
     * Komentarze, które WOLNO pokazać temu widzowi (issue #41, audyt komentarzy).
     *
     * Komentarz nie ma własnej widoczności — renderuje się wewnątrz strony
     * rodzica i dziedziczy jego ochronę NIEJAWNIE. To wystarcza, dopóki pytanie
     * brzmi „czy wolno mi zobaczyć ten wpis". Nie wystarcza, gdy wpis jest
     * publiczny, a nieprzyjemna jest konkretna OSOBA pod nim.
     *
     * TRZY GRANICE, NIE JEDNA. Kolejność w kodzie to kolejność ważności.
     *
     * 1. BLOKADA — pierwsza, bezwarunkowa, w OBIE strony (`AGENTS.md` §4).
     *    Bez niej blokada znaczyła „nie zobaczę jej wpisów, ale nadal będę
     *    czytać jej zaczepki pod cudzymi" — czyli nie chroniła przed dokładnie
     *    tym, po co ludzie jej używają. Dla gościa nie ma tu czego filtrować:
     *    blokada jest relacją między dwoma kontami.
     *
     * 2. STATUS KONTA AUTORA KOMENTARZA — `banned` i `pending_delete` odpadają,
     *    `suspended` ZOSTAJE (kara za pisanie nie kasuje tego, co ktoś już
     *    napisał). Ta sama granica co `User::jestDostepnyJakoAutor()`,
     *    `UserPolicy::viewProfile()` i `RecipePolicy::view()`.
     *
     *    `erased` TEŻ ZOSTAJE (D-022) — i to jest ta jedna rzecz, przez którą
     *    ta granica przestała być tożsama z „czy to konto może czytać
     *    serwis". Komentarz osoby, która usunęła konto i nie poprosiła
     *    o usunięcie treści, zostaje w cudzej rozmowie pod podpisem
     *    „Użytkownik usunięty". Wcześniej znikał, bo status konta po
     *    anonimizacji zostawał na `pending_delete` —
     *    `KomentarzeGranicaStatusuAutoraTest` ma teraz osobny wiersz danych
     *    na każdy z tych dwóch stanów.
     *
     *    TEGO TU NIE BYŁO I TO BYŁA LUKA — zmierzona przed poprawką.
     *    Komentarz osoby zbanowanej stał pod publicznym wpisem z jej nazwą,
     *    awatarem i linkiem do profilu, a ten link dawał 403. To dokładnie
     *    „obietnica bez pokrycia w drugą stronę" z audytu A5, tylko w warstwie
     *    komentarzy. Licznik `withCount(['comments' => …widoczneDla])` liczył
     *    ten komentarz razem z resztą, więc karta wpisu obiecywała
     *    „Komentarze (2)", a po wejściu widać było jeden — ta sama usterka co
     *    licznik obserwujących pokazujący 2 zamiast 1 i co wpis zbanowanego
     *    autora w `FollowingFeed`.
     *
     *    Granica jest tu POLITYCZNA (`dostepnyJakoAutor`), nie promocyjna
     *    (`Post::scopeTylkoOdAktywnychAutorow`, która wycina też zawieszonych).
     *    Powód: komentarz nie jest treścią POLECANĄ nieznajomym — jest częścią
     *    rozmowy pod treścią, na którą widz już wszedł. Wycięcie zawieszonych
     *    wyrywałoby wypowiedzi ze środka rozmowy za karę dotyczącą pisania.
     *
     *    Bez wyjątku dla samego autora: konto `banned`/`pending_delete` jest
     *    wylogowywane przy pierwszym żądaniu (`EnsureAccountIsActive`), więc
     *    taki widz nie istnieje. Bez wyjątku dla moderatora — tym samym
     *    świadomym uproszczeniem co w `Notification::widoczneDla()`: pominięcie
     *    furtki jest OSTRZEJSZE, a moderator ma do pracy panel moderacji.
     *
     * 3. STATUS KOMENTARZA — ukryty przez moderację nie wraca listą.
     *    Każda relacja `comments()` (Post, Recipe, CookedEvent) filtruje to
     *    dziś sama, więc NIE jest to naprawa zmierzonego wycieku, tylko
     *    domknięcie zakresu: `CommentPolicy::view()` tę regułę od teraz ma,
     *    a zakres bez niej byłby tą drugą warstwą, która „implementuje regułę
     *    inaczej albo wcale". Zakres nie ma prawa zależeć od tego, że każdy
     *    jego wywołujący pamiętał o statusie.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeWidoczneDla($query, ?User $widz): void
    {
        if ($widz !== null) {
            $query->whereNotExists(function ($sub) use ($widz): void {
                $sub->selectRaw('1')
                    ->from('blocks')
                    ->where(function ($w) use ($widz): void {
                        $w->where('blocks.blocker_id', $widz->getKey())
                            ->whereColumn('blocks.blocked_id', 'comments.author_id');
                    })
                    ->orWhere(function ($w) use ($widz): void {
                        $w->whereColumn('blocks.blocker_id', 'comments.author_id')
                            ->where('blocks.blocked_id', $widz->getKey());
                    });
            });
        }

        // Reguły 2 i 3 NIE zależą od tego, kto patrzy — więc obowiązują także
        // gościa. Wcześniej cała metoda kończyła się na `return` przy
        // `$widz === null` i gość nie przechodził przez żaden filtr.
        $query->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->where('comments.status', self::STATUS_PUBLISHED);
    }

    protected $fillable = [
        'author_id',
        'post_id',
        'recipe_id',
        'cooked_event_id',
        'parent_id',
        'body',
        'status',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->where('status', self::STATUS_PUBLISHED)
            ->oldest();
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<CookedEvent, $this>
     */
    public function cookedEvent(): BelongsTo
    {
        return $this->belongsTo(CookedEvent::class);
    }

    /** Obiekt, którego dotyczy komentarz — dokładnie jeden z trzech. */
    public function subject(): Post|Recipe|CookedEvent|null
    {
        return $this->post ?? $this->recipe ?? $this->cookedEvent;
    }

    /** Kto powinien dostać powiadomienie o tym komentarzu. */
    public function notifiableUserId(): ?string
    {
        $subject = $this->subject();

        return match (true) {
            $subject instanceof Post => $subject->author_id,
            $subject instanceof Recipe => $subject->author_id,
            $subject instanceof CookedEvent => $subject->user_id,
            default => null,
        };
    }
}

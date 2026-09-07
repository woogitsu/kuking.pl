<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CookedEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Ugotowałem" — realne wykonanie czyjegoś przepisu.
 *
 * Nie ma tu unikalności (user_id, recipe_id) i nigdy jej nie dodawaj:
 * ta sama osoba może gotować ten sam przepis dziesiątki razy i każde
 * wykonanie jest osobnym wydarzeniem.
 */
class CookedEvent extends Model
{
    /** @use HasFactory<CookedEventFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'recipe_id',
        'note',
        'would_make_again',
        'perceived_difficulty',
        'actual_minutes',
        'changes_note',
        'cooked_at',
    ];

    protected function casts(): array
    {
        return [
            'cooked_at' => 'datetime',
            'would_make_again' => 'boolean',
            'actual_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'cooked_event_media')
            ->withPivot('position')
            ->orderBy('cooked_event_media.position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->where('status', Comment::STATUS_PUBLISHED)
            ->oldest();
    }

    public function url(): string
    {
        return route('cooked.show', ['cookedEvent' => $this->getKey()]);
    }

    // ---------------------------------------------------------------------
    // Zakresy
    // ---------------------------------------------------------------------

    /**
     * Wykonania, które WOLNO pokazać temu widzowi na liście — np. w galerii
     * „Komu wyszło" pod przepisem (audyt A4).
     *
     * DLACZEGO TO JEST SCOPE, A NIE FILTR W BLADE
     * `RecipeController::show` ładował do dwunastu wykonań jedną instrukcją
     * SQL i renderował je w pętli bez pytania, kto je zrobił. Zablokowana
     * osoba wracała więc oglądającemu przez CUDZY przepis — miejsce, na które
     * `CookedEventPolicy::view` w ogóle nie ma wpływu, bo tam nikt nie klika
     * pojedynczego wykonania, tylko przegląda galerię. Filtrowanie w pętli
     * Blade byłoby też zapytaniem `hasBlockRelationWith()` per wiersz, czyli
     * N+1 na stronie przepisu — jednej z najczęściej odwiedzanych.
     *
     * Wzorzec identyczny jak `Post::scopeWidoczneDla` i `Recipe::scopeWidoczneDla`:
     * blokada pierwsza, bezwarunkowa, w OBIE strony. Wykonanie samo w sobie
     * nie ma widoczności (public/followers/private) — idzie za przepisem,
     * a widoczność przepisu jest już rozstrzygnięta wcześniej, jednym
     * wywołaniem `RecipePolicy::view` na całą stronę. Tu liczy się wyłącznie
     * to, KTO ugotował, bo to ta osoba (nie autor przepisu) może być
     * zablokowana przez widza albo odwrotnie.
     *
     * DRUGA GRANICA: STATUS KONTA KUCHARZA (audyt komentarzy i „Ugotowałem").
     * Blokada była tu jedyną regułą i to była luka, zmierzona przed poprawką:
     * wykonanie osoby ZBANOWANEJ stało w galerii „Komu wyszło" pod publicznym
     * przepisem — ze zdjęciem, notatką, nazwą i awatarem — a link do jej
     * profilu dawał 403. Licznik `cookedCount` nad galerią pokazywał 2, kiedy
     * kafelków było widać jeden. To ta sama usterka co przepis zbanowanego
     * autora w cudzym zeszycie (W5-08) i wpis zbanowanego autora
     * w `FollowingFeed`, w kolejnym miejscu, do którego tamte poprawki
     * nie dotarły.
     *
     * `banned` i `pending_delete` odpadają, `suspended` ZOSTAJE — ta sama
     * granica co `User::jestDostepnyJakoAutor()`.
     *
     * DLACZEGO TUTAJ, A NIE W `CookedEventPolicy::view()`
     * Bo to są dwa różne pytania i tak też są rozstrzygnięte w tym repo.
     * `view()` odpowiada „czy wolno mi wejść na TO wykonanie pod jego
     * adresem" i statusu kucharza CELOWO nie liczy — decyzja jest przypięta
     * testem (`KomusWyszloWidocznoscTest::
     * test_wykonanie_zbanowanego_kucharza_nie_dostaje_celebracji`, wprost:
     * „treść zostaje, znika tylko wyróżnienie"). Ten zakres odpowiada na
     * pytanie „czy serwis ma SAM Z SIEBIE podsunąć to wykonanie komuś, kto
     * przyszedł pod cudzy przepis" — a tam zbanowanych nie podsuwamy, tym
     * samym wzorcem co `Post::scopeTylkoOdAktywnychAutorow` dla treści
     * polecanych nieznajomym i co `CookedEventPolicy::celebrate()`.
     * Rozjazd jest więc świadomy i w OSTRZEJSZĄ stronę: lista pokazuje
     * mniej niż bezpośredni adres, nigdy odwrotnie.
     *
     * Reguła NIE zależy od tego, kto patrzy, więc obowiązuje także gościa —
     * dlatego jest poza gałęzią `$widz !== null`. Wcześniej metoda kończyła
     * się na `return` dla gościa i galeria pokazywała mu wszystko.
     *
     * @param  Builder<CookedEvent>  $query
     */
    public function scopeWidoczneDla(Builder $query, ?User $widz): void
    {
        if ($widz !== null) {
            $widzId = $widz->getKey();

            $query->whereNotExists(function ($sub) use ($widzId): void {
                $sub->selectRaw('1')
                    ->from('blocks')
                    ->where(function ($w) use ($widzId): void {
                        $w->where('blocks.blocker_id', $widzId)
                            ->whereColumn('blocks.blocked_id', 'cooked_events.user_id');
                    })
                    ->orWhere(function ($w) use ($widzId): void {
                        $w->whereColumn('blocks.blocker_id', 'cooked_events.user_id')
                            ->where('blocks.blocked_id', $widzId);
                    });
            });
        }

        $query->whereHas('user', fn ($kucharz) => $kucharz->dostepnyJakoAutor());
    }
}

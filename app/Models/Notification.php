<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Powiadomienie w aplikacji.
 *
 * `type` jest stabilnym łańcuchem (np. `cooked_event.created`), nie nazwą klasy
 * PHP — po to, żeby refaktor kodu nie unieważnił historii powiadomień ani
 * eksportu danych użytkownika.
 */
class Notification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Ktoś ugotował z Twojego przepisu. Najważniejsze powiadomienie w Kuking. */
    public const TYPE_COOKED = 'cooked_event.created';

    public const TYPE_COMMENT = 'comment.created';

    public const TYPE_REPLY = 'comment.replied';

    public const TYPE_FOLLOW = 'follow.created';

    public const TYPE_SAVED = 'recipe.saved';

    public const TYPE_MODERATION = 'moderation.decision';

    public const TYPE_WELCOME = 'account.welcome';

    /**
     * Pierwszy wpis nowej osoby — powiadomienie dla GOSPODARZA, nie dla
     * autora (issue #6).
     *
     * 55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to wyłącznie
     * odbiorcy treści. Kto opublikuje pierwszy raz, robi to wbrew własnemu
     * nawykowi — i jeśli nikt nie odpowie, drugi raz już nie spróbuje.
     * Gospodarz musi się o tym dowiedzieć NATYCHMIAST, a nie przy najbliższym
     * zajrzeniu do panelu.
     */
    public const TYPE_FIRST_POST = 'post.first';

    protected $fillable = [
        'user_id',
        'actor_id',
        'type',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Powiadomienia, które ta osoba ma prawo zobaczyć — bez tych od osób,
     * z którymi łączy ją blokada.
     *
     * DLACZEGO FILTR PRZY ODCZYCIE, A NIE KASOWANIE PRZY BLOKADZIE
     *
     * `NotifyUser` od początku odmawiał tworzenia NOWYCH powiadomień, gdy
     * między osobami jest blokada. Nie robił jednak nic z tymi, które już
     * leżały na liście — a ludzie blokują właśnie PO nieprzyjemnym zdarzeniu,
     * czyli wtedy, gdy powiadomienie o nim już istnieje. Blokada zostawiała
     * więc na liście nazwisko i zdjęcie osoby, od której człowiek się odciął.
     *
     * Kasowanie wierszy przy blokadzie byłoby nieodwracalne: odblokowanie
     * kogoś ma przywrócić stan sprzed blokady, a nie zostawić dziurę
     * w historii (i w eksporcie danych — RODO art. 15). Dlatego filtrujemy
     * przy odczycie.
     *
     * Blokada liczy się W OBIE STRONY, tak samo jak w
     * `User::hasBlockRelationWith()` — inaczej byłaby ochroną połowiczną.
     *
     * Powiadomienia bez autora (`actor_id IS NULL` — powitanie, wiadomość
     * od moderacji) zostają zawsze: `NOT EXISTS` nie ma wtedy do czego
     * przyrównać `blocked_id` i nie znajduje żadnego wiersza.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        $query->whereNotExists(function (QueryBuilder $sub) use ($viewer): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where(function (QueryBuilder $warunek) use ($viewer): void {
                    $warunek
                        ->where(function (QueryBuilder $ja) use ($viewer): void {
                            $ja->where('blocks.blocker_id', $viewer->getKey())
                                ->whereColumn('blocks.blocked_id', 'notifications.actor_id');
                        })
                        ->orWhere(function (QueryBuilder $on) use ($viewer): void {
                            $on->whereColumn('blocks.blocker_id', 'notifications.actor_id')
                                ->where('blocks.blocked_id', $viewer->getKey());
                        });
                });
        });

        /*
         * SPRAWCA ZDARZENIA ZBANOWANY ALBO OZNACZONY DO USUNIĘCIA PO FAKCIE.
         *
         * `UserPolicy::viewProfile()` daje w tym stanie 403 wszystkim poza
         * moderatorem (`User::jestDostepnyJakoAutor()`) — ta reguła w ogóle
         * nie miała odpowiednika tutaj. Nazwa i awatar osoby zbanowanej albo
         * czekającej na usunięcie konta wisiały więc na liście dalej, choć
         * kliknięcie w jej profil kończyło się ścianą. Zawieszenie
         * (`suspended`) CELOWO tu nie wchodzi — to kara za pisanie, a nie za
         * bycie widzianym, i `jestDostepnyJakoAutor()` też ją pomija.
         *
         * Od D-022 lista statusów jest JEDNĄ STAŁĄ
         * (`User::STATUSY_UKRYWAJACE_TRESC`), a nie czwartą kopią tego
         * samego `whereIn`. Powód jest zmierzony: `erased` powstał właśnie
         * dlatego, że dołożenie stanu do jednej warstwy nie dołożyło go do
         * pozostałych. `erased` w tej stałej NIE JEST — powiadomienie
         * o wykonaniu, którego autor wymazał konto, ma zostać widoczne
         * dokładnie tak samo jak samo wykonanie.
         *
         * Powiadomienia bez sprawcy (`actor_id IS NULL`) przechodzą zawsze,
         * z tego samego powodu co przy blokadzie wyżej.
         */
        $query->whereNotExists(function (QueryBuilder $sub): void {
            $sub->selectRaw('1')
                ->from('users as sprawcy')
                ->whereColumn('sprawcy.id', 'notifications.actor_id')
                ->whereIn('sprawcy.status', User::STATUSY_UKRYWAJACE_TRESC);
        });

        /*
         * POWIADOMIENIE O KOMENTARZU, KTÓREGO TREŚĆ ZNIKŁA ALBO DO KTÓREJ
         * ODBIORCA STRACIŁ DOSTĘP.
         *
         * `comment.created`/`comment.replied` niosą własną kopię fragmentu
         * (`data.excerpt`) — dlatego SAME W SOBIE nie znikają, kiedy znika
         * komentarz albo treść, pod którą stał: autor mógł go skasować,
         * moderacja mogła go ukryć, a wpis/przepis mógł w międzyczasie zmienić
         * widoczność na węższą (audyt: dokładnie ta usterka, co wpis
         * zbanowanego autora w `FollowingFeed`, tylko na powiadomieniach).
         *
         * Odbiorca takiego powiadomienia NIE musi być właścicielem treści —
         * przy odpowiedzi w cudzym wątku (`PublishComment::handle()`) idzie
         * też do autora komentarza-rodzica. Dlatego widoczność treści liczymy
         * dla KONKRETNEGO odbiorcy ($viewer), tymi samymi regułami co
         * `PostPolicy::view()` / `RecipePolicy::view()` / `CookedEventPolicy::view()`
         * — właściciel treści widzi zawsze własne, obcy tylko opublikowane,
         * z widocznością public/followers/private i blokadą w obie strony.
         *
         * Inne typy powiadomień (ugotowanie, zapis do zeszytu, obserwowanie...)
         * ten warunek pomija: ich odbiorcą jest zawsze właściciel treści,
         * który widzi własne rzeczy niezależnie od stanu publikacji — dodanie
         * tu tej samej reguły nic by nie zmieniło, a tylko powielałoby kod.
         *
         * ŚWIADOME UPROSZCZENIE: pomijamy furtkę dla moderatora, którą mają
         * Policy (`isModerator()`). Odbiorcą powiadomienia prawie nigdy nie
         * jest moderator, a pominięcie furtki jest OSTRZEJSZE, nie luźniejsze
         * — najwyżej moderator nie zobaczy własnego powiadomienia o cudzym
         * komentarzu na liście (ma do tego panel moderacji), nigdy odwrotnie.
         */
        // UWAGA NA TYP: to jedyne miejsce w tej metodzie, gdzie `where()` woła
        // się WPROST na $query (Eloquent\Builder), a nie w zagnieżdżeniu
        // `whereExists`/`whereNotExists`. `Eloquent\Builder::where(Closure)`
        // ma własne nadpisanie i przekazuje do closure NOWY `Eloquent\Builder`
        // (`$this->model->newQueryWithoutRelationships()`), nie surowy
        // `Illuminate\Database\Query\Builder` — inaczej niż każde inne miejsce
        // w tym pliku. Zły typ tutaj to `TypeError` w runtime, nie błąd SQL.
        $query->where(function (Builder $tylkoIstniejaceTresci) use ($viewer): void {
            $tylkoIstniejaceTresci
                ->whereNotIn('notifications.type', [self::TYPE_COMMENT, self::TYPE_REPLY])
                ->orWhereExists(function (QueryBuilder $sub) use ($viewer): void {
                    $sub->selectRaw('1')
                        ->from('comments as pc')
                        ->whereRaw("pc.id = (notifications.data->>'comment_id')::uuid")
                        ->where('pc.status', Comment::STATUS_PUBLISHED)
                        ->whereNull('pc.deleted_at')
                        ->where(function (QueryBuilder $tresc) use ($viewer): void {
                            $tresc
                                ->where(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => $this->wierszTresciWidoczny($s, 'posts', 'pc.post_id', $viewer),
                                ))
                                ->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => $this->wierszTresciWidoczny($s, 'recipes', 'pc.recipe_id', $viewer),
                                ))
                                ->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => $this->wierszWykonaniaWidoczny($s, $viewer),
                                ));
                        });
                });
        });

        return $query;
    }

    /**
     * EXISTS potwierdzający, że wiersz `posts`/`recipes` wskazywany przez
     * $fk (np. `pc.post_id`) jest w tej chwili widoczny dla $widz — tymi
     * samymi regułami co `PostPolicy::view()` / `RecipePolicy::view()`
     * (obie tabele mają identyczny kształt: `author_id`, `status`,
     * `published_at`, `visibility`, `deleted_at`).
     */
    private function wierszTresciWidoczny(QueryBuilder $sub, string $tabela, string $fk, User $widz): void
    {
        $widzId = $widz->getKey();

        $sub->selectRaw('1')
            ->from("{$tabela} as tw")
            ->whereColumn('tw.id', $fk)
            // Skasowana (soft delete) treść nie wraca do nikogo, nawet do autora.
            ->whereNull('tw.deleted_at')
            ->where(function (QueryBuilder $w) use ($widzId): void {
                // Właściciel widzi zawsze własną treść — szkic, ukrytą przez
                // moderację, prywatną. „Poprawne dane nigdy nie znikają."
                $w->where('tw.author_id', $widzId)
                    ->orWhere(function (QueryBuilder $obce) use ($widzId): void {
                        $obce->where('tw.status', self::STATUS_TRESCI_OPUBLIKOWANA)
                            ->whereNotNull('tw.published_at')
                            // Autor treści zbanowany/do usunięcia odcina WSZYSTKICH
                            // poza sobą — już obsłużonym w gałęzi wyżej.
                            ->whereNotExists(function (QueryBuilder $autor): void {
                                $autor->selectRaw('1')
                                    ->from('users as autorzy_tresci')
                                    ->whereColumn('autorzy_tresci.id', 'tw.author_id')
                                    ->whereIn('autorzy_tresci.status', User::STATUSY_UKRYWAJACE_TRESC);
                            })
                            // Blokada między ODBIORCĄ a AUTOREM TREŚCI — może
                            // być inna osoba niż sprawca zdarzenia (odpowiedź
                            // w cudzym wątku).
                            ->whereNotExists(function (QueryBuilder $blok) use ($widzId): void {
                                $blok->selectRaw('1')
                                    ->from('blocks')
                                    ->where(function (QueryBuilder $w2) use ($widzId): void {
                                        $w2->where('blocks.blocker_id', $widzId)
                                            ->whereColumn('blocks.blocked_id', 'tw.author_id');
                                    })
                                    ->orWhere(function (QueryBuilder $w2) use ($widzId): void {
                                        $w2->whereColumn('blocks.blocker_id', 'tw.author_id')
                                            ->where('blocks.blocked_id', $widzId);
                                    });
                            })
                            ->where(function (QueryBuilder $widocznosc) use ($widzId): void {
                                $widocznosc->where('tw.visibility', 'public')
                                    ->orWhere(function (QueryBuilder $obserwujacy) use ($widzId): void {
                                        $obserwujacy->where('tw.visibility', 'followers')
                                            ->whereExists(function (QueryBuilder $f) use ($widzId): void {
                                                $f->selectRaw('1')
                                                    ->from('follows')
                                                    ->where('follows.follower_id', $widzId)
                                                    ->whereColumn('follows.followed_id', 'tw.author_id');
                                            });
                                    });
                            });
                    });
            });
    }

    /**
     * To samo dla komentarza pod „Ugotowałem" (`CookedEventPolicy::view()`):
     * wykonanie nie ma własnej widoczności, idzie za przepisem, a osobno
     * liczy się blokada widz↔kucharz. Brak przepisu (skasowany) zostawia
     * dostęp wyłącznie właścicielowi wykonania.
     */
    private function wierszWykonaniaWidoczny(QueryBuilder $sub, User $widz): void
    {
        $widzId = $widz->getKey();

        $sub->selectRaw('1')
            ->from('cooked_events as ce')
            ->whereColumn('ce.id', 'pc.cooked_event_id')
            ->whereNotExists(function (QueryBuilder $blok) use ($widzId): void {
                $blok->selectRaw('1')
                    ->from('blocks')
                    ->where(function (QueryBuilder $w) use ($widzId): void {
                        $w->where('blocks.blocker_id', $widzId)->whereColumn('blocks.blocked_id', 'ce.user_id');
                    })
                    ->orWhere(function (QueryBuilder $w) use ($widzId): void {
                        $w->whereColumn('blocks.blocker_id', 'ce.user_id')->where('blocks.blocked_id', $widzId);
                    });
            })
            ->where(function (QueryBuilder $w) use ($widzId, $widz): void {
                $w->where(function (QueryBuilder $bezPrzepisu) use ($widzId): void {
                    $bezPrzepisu->whereNull('ce.recipe_id')->where('ce.user_id', $widzId);
                })->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                    fn (QueryBuilder $s) => $this->wierszTresciWidoczny($s, 'recipes', 'ce.recipe_id', $widz),
                ));
            });
    }

    /**
     * Ten sam literał co `Post::STATUS_PUBLISHED` i `Recipe::STATUS_PUBLISHED`
     * — nazwana stała zamiast magicznego stringa powtórzonego w SQL wyżej.
     */
    private const STATUS_TRESCI_OPUBLIKOWANA = 'published';
}

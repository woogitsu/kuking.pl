<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Treści czekające na odzew widoczny dla ich autora (issue #579). */
final class UnansweredContent
{
    /** @return Builder<Post> */
    public function eligiblePosts(User $host): Builder
    {
        return Post::query()
            ->published()
            ->whereIn('visibility', ['public', 'followers'])
            ->widoczneDla($host)
            ->where('author_id', '!=', $host->getKey())
            ->whereHas('author', fn (Builder $author) => $author->widocznyJakoOsoba());
    }

    /**
     * Tylko dania. Pytania mają własną zakładkę (`questions()`) i własną
     * definicję odzewu — bez tego warunku pytanie bez odpowiedzi stało
     * w obu kolejkach naraz (#372).
     *
     * @return Builder<Post>
     */
    public function posts(User $host): Builder
    {
        return $this->withoutResponse($this->eligiblePosts($host)->where('posts.kind', Post::KIND_DISH), 'post_id', 'posts', 'author_id');
    }

    /**
     * Utrwalony nośnik pierwszego wkładu, tylko jeśli odbiorca ma do niego dostęp.
     * Odpowiedź lub usunięcie nośnika nie promuje kolejnego wpisu.
     *
     * @param  list<string>  $authors
     * @return array<string, string>
     */
    public function firstPostIds(User $host, array $authors): array
    {
        if ($authors === []) {
            return [];
        }

        return $this->eligiblePosts($host)
            ->whereIn('author_id', $authors)
            ->whereExists(fn (QueryBuilder $events) => $events->selectRaw('1')->from('first_post_events')
                ->whereColumn('first_post_events.author_id', 'posts.author_id')
                ->whereColumn('first_post_events.post_id', 'posts.id'))
            ->pluck('id', 'author_id')
            ->all();
    }

    /** Pytanie czeka na główną odpowiedź innej osoby, widoczną dla pytającego.
     * @return Builder<Post>
     */
    public function questions(User $host): Builder
    {
        return $this->eligiblePosts($host)->where('posts.kind', Post::KIND_QUESTION)
            ->whereNotExists($this->answers());
    }

    /** @return Builder<Recipe> */
    public function recipes(User $host): Builder
    {
        $recipes = Recipe::query()
            ->published()
            ->whereIn('visibility', ['public', 'followers'])
            ->widoczneDla($host)
            ->where('author_id', '!=', $host->getKey())
            ->whereHas('author', fn (Builder $author) => $author->widocznyJakoOsoba());

        return $this->withoutResponse($recipes, 'recipe_id', 'recipes', 'author_id');
    }

    /** @return Builder<CookedEvent> */
    public function cooked(User $host): Builder
    {
        $events = CookedEvent::query()
            ->widoczneDla($host)
            ->where('user_id', '!=', $host->getKey())
            ->whereHas('user', fn (Builder $user) => $user->widocznyJakoOsoba())
            ->whereHas('recipe', fn (Builder $recipe) => $recipe
                ->published()
                ->whereIn('visibility', ['public', 'followers'])
                ->widoczneDla($host)
                ->whereHas('author', fn (Builder $author) => $author->widocznyJakoOsoba()));

        return $this->withoutResponse($events, 'cooked_event_id', 'cooked_events', 'user_id');
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $content
     * @return Builder<TModel>
     */
    private function withoutResponse(Builder $content, string $foreignKey, string $table, string $ownerKey): Builder
    {
        return $content->whereNotExists($this->responses($foreignKey, $table, $ownerKey));
    }

    /**
     * Mediana czasu do pierwszego odzewu — tylko dania (zakładka „Wpisy”).
     *
     * Pytania mają osobną medianę (`medianQuestionResponseHours()`): inna
     * definicja odzewu (tylko główna odpowiedź) i inne tempo. Wspólna liczba
     * mieszała oba rodzaje, więc szybko obsłużone pytania zaniżały medianę
     * wpisów, a zakładka „Pytania” nie miała żadnej (#372).
     */
    public function medianPostResponseHours(User $host): ?float
    {
        return $this->medianResponseHours($host, Post::KIND_DISH, $this->responses('post_id', 'posts', 'author_id'));
    }

    /** Mediana czasu do pierwszej głównej odpowiedzi innej osoby na pytanie (#372). */
    public function medianQuestionResponseHours(User $host): ?float
    {
        return $this->medianResponseHours($host, Post::KIND_QUESTION, $this->answers());
    }

    private function medianResponseHours(User $host, string $kind, QueryBuilder $responses): ?float
    {
        $first = $responses->select(DB::raw('MIN(queue_comments.created_at)'));
        $posts = $this->eligiblePosts($host)
            ->where('posts.kind', $kind)
            ->where('published_at', '>=', now()->subDays(30))
            ->select('published_at')->selectSub($first, 'first_response');
        $value = DB::query()->fromSub($posts, 'answered_posts')
            ->whereNotNull('first_response')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (first_response - published_at)) / 3600) AS median')
            ->value('median');

        return $value === null ? null : round((float) $value, 1);
    }

    /** Odpowiedź na pytanie = widoczny komentarz najwyższego poziomu innej osoby z treścią. */
    private function answers(): QueryBuilder
    {
        return $this->responses('post_id', 'posts', 'author_id')
            ->whereNull('queue_comments.parent_id')->whereNull('queue_comments.body_removed_at');
    }

    private function responses(string $foreignKey, string $table, string $ownerKey): QueryBuilder
    {
        $recipient = $table.'.'.$ownerKey;

        return $this->visibleComments('queue_comments', $recipient)
            ->whereColumn('queue_comments.'.$foreignKey, $table.'.id')
            ->whereColumn('queue_comments.author_id', '!=', $recipient)
            ->where(function (QueryBuilder $thread) use ($recipient, $foreignKey): void {
                $thread->whereNull('queue_comments.parent_id')
                    ->orWhereExists($this->visibleComments('queue_parents', $recipient)
                        ->whereColumn('queue_parents.id', 'queue_comments.parent_id')
                        ->whereColumn('queue_parents.'.$foreignKey, 'queue_comments.'.$foreignKey)
                        ->whereNull('queue_parents.parent_id'));
            });
    }

    /** Widoczność odzewu liczymy dla odbiorcy, nie dla gospodarza. */
    private function visibleComments(string $alias, string $recipient): QueryBuilder
    {
        // Surowe zapytanie wymaga jawnego odpowiednika Comment::SoftDeletes.
        return DB::table('comments as '.$alias)
            ->selectRaw('1')
            ->where($alias.'.status', Comment::STATUS_PUBLISHED)
            ->whereNull($alias.'.deleted_at')
            ->whereExists(User::query()->selectRaw('1')
                ->dostepnyJakoAutor()
                ->whereColumn('users.id', $alias.'.author_id')->toBase())
            ->whereNotExists(function (QueryBuilder $blocks) use ($alias, $recipient): void {
                $blocks->selectRaw('1')->from('blocks')
                    ->where(function (QueryBuilder $direction) use ($alias, $recipient): void {
                        $direction->whereColumn('blocks.blocker_id', $recipient)
                            ->whereColumn('blocks.blocked_id', $alias.'.author_id');
                    })
                    ->orWhere(function (QueryBuilder $direction) use ($alias, $recipient): void {
                        $direction->whereColumn('blocks.blocker_id', $alias.'.author_id')
                            ->whereColumn('blocks.blocked_id', $recipient);
                    });
            });
    }
}

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

    /** @return Builder<Post> */
    public function posts(User $host): Builder
    {
        return $this->withoutResponse($this->eligiblePosts($host), 'post_id', 'posts', 'author_id');
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

    public function medianPostResponseHours(User $host): ?float
    {
        $first = $this->responses('post_id', 'posts', 'author_id')
            ->select(DB::raw('MIN(queue_comments.created_at)'));
        $posts = $this->eligiblePosts($host)
            ->where('published_at', '>=', now()->subDays(30))
            ->select('published_at')->selectSub($first, 'first_response');
        $value = DB::query()->fromSub($posts, 'answered_posts')
            ->whereNotNull('first_response')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (first_response - published_at)) / 3600) AS median')
            ->value('median');

        return $value === null ? null : round((float) $value, 1);
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

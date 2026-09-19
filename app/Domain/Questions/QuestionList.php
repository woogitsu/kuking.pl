<?php

declare(strict_types=1);

namespace App\Domain\Questions;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class QuestionList
{
    /** @return Builder<Post> */
    public function query(?User $viewer, bool $unanswered = false, ?string $tag = null): Builder
    {
        $answers = fn (Builder $query) => $this->visibleAnswers($query, $viewer);
        $query = Post::query()->where('posts.kind', Post::KIND_QUESTION)
            ->published()->widoczneDla($viewer)->tylkoOdAktywnychAutorow()
            ->withCount(['allComments as answer_count' => $answers]);

        if ($unanswered) {
            $query->whereDoesntHave('allComments', $answers);
        }
        if ($tag !== null) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->where('slug', $tag)->where('status', 'active'));
        }

        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    /** @param Builder<Comment> $query */
    public function visibleAnswers(Builder $query, ?User $viewer): void
    {
        $query->whereNull('comments.parent_id')->whereNull('comments.body_removed_at')->widoczneDla($viewer);
    }
}

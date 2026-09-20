<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Policies\CookedEventPolicy;
use App\Policies\PostPolicy;
use App\Policies\RecipePolicy;

/** Granica automatu bez uprawnień właściciela ani moderatora (#827). */
final class AutomaticAnalysisAccess
{
    public function allows(Post|Comment $content): bool
    {
        $current = $content->fresh();

        if ($current instanceof Comment) {
            return $current->status === Comment::STATUS_PUBLISHED
                && $current->author?->jestDostepnyJakoAutor()
                && $this->subjectAllows($current->subject());
        }

        return $this->subjectAllows($current);
    }

    private function subjectAllows(Post|Recipe|CookedEvent|null $subject): bool
    {
        if ($subject === null) {
            return false;
        }

        // D-055 obejmuje także obserwujących. Kopia służy wyłącznie pytaniu
        // Policy o pozostałe warunki dostępu gościa; nigdy jej nie zapisujemy.
        // Nie podszywamy się pod autora, bo obszedłby status i prywatność.
        $candidate = clone $subject;

        if ($candidate instanceof CookedEvent) {
            if (! $this->subjectAllows($candidate->recipe)) {
                return false;
            }

            $recipe = clone $candidate->recipe;
            $recipe->visibility = Post::VISIBILITY_PUBLIC;
            $candidate->setRelation('recipe', $recipe);

            return app(CookedEventPolicy::class)->view(null, $candidate);
        }

        if (! in_array($candidate->visibility, [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS], true)) {
            return false;
        }

        $candidate->visibility = Post::VISIBILITY_PUBLIC;

        return $candidate instanceof Post
            ? app(PostPolicy::class)->view(null, $candidate)
            : app(RecipePolicy::class)->view(null, $candidate);
    }
}

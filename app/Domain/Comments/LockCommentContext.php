<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Domain\Users\ZamekKonta;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

/** Kolejność: konta → obserwowania → przepis wykonania → cel → komentarze. */
final class LockCommentContext
{
    public const UNAVAILABLE = 'Tu nie da się teraz dodać komentarza. '
        .'Odśwież stronę — zobaczysz, co jest w tym miejscu dostępne.';

    /** @param Closure(User, Post|Recipe|CookedEvent, ?Comment): Comment $publish */
    public function handle(User $author, Post|Recipe|CookedEvent $subject, ?Comment $parent, Closure $publish): Comment
    {
        if (ZamekKonta::trzymanyWTymProcesie()) {
            throw new LogicException('Publikację komentarza wywołaj poza zamkiem pojedynczego konta.');
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $before = $this->discover($author, $subject, $parent);

            try {
                return DB::transaction(function () use ($author, $subject, $parent, $before, $publish): Comment {
                    $users = [];
                    foreach ($before['users'] as $id) {
                        $users[$id] = User::query()->whereKey($id)->lock('FOR NO KEY UPDATE')->first();
                        if ($users[$id] === null) {
                            $this->deny();
                        }
                    }
                    $freshAuthor = $users[$author->getKey()];

                    // Unfollow nie bierze zamków kont. Istniejący wiersz relacji
                    // musi pozostać do końca decyzji; brak relacji nie daje dostępu.
                    foreach ($before['users'] as $id) {
                        DB::table('follows')->where('follower_id', $freshAuthor->getKey())
                            ->where('followed_id', $id)->lock('FOR SHARE')->first();
                    }

                    $recipe = null;
                    if ($before['recipe'] !== null) {
                        $recipe = Recipe::withTrashed()->whereKey($before['recipe'])->lock('FOR NO KEY UPDATE')->first();
                    }
                    $freshSubject = $subject->newQuery()->whereKey($subject->getKey())->lock('FOR NO KEY UPDATE')->first();
                    if ($freshSubject === null) {
                        $this->deny();
                    }
                    foreach ($before['comments'] as $id) {
                        Comment::query()->whereKey($id)->lock('FOR NO KEY UPDATE')->first();
                    }

                    // Po czekaniu nie wolno dobierać nowego konta poza kolejnością.
                    // Wyjątek wycofuje także zamki savepointu przed kolejną próbą.
                    if ($this->discover($author, $freshSubject, $parent) !== $before) {
                        throw new ChangedCommentDependencies;
                    }

                    $ownerId = $freshSubject instanceof CookedEvent ? $freshSubject->user_id : $freshSubject->author_id;
                    $freshSubject->setRelation($freshSubject instanceof CookedEvent ? 'user' : 'author', $users[$ownerId]);
                    if ($freshSubject instanceof CookedEvent) {
                        if ($recipe !== null) {
                            $recipe->setRelation('author', $users[$recipe->author_id]);
                        }
                        $freshSubject->setRelation('recipe', $recipe !== null && ! $recipe->trashed() ? $recipe : null);
                    }

                    // Wpis i wykonanie mają osobną zdolność `comment` (wykonanie
                    // zbanowanego kucharza da się skomentować — D-261); przepis — `view`.
                    $ability = $freshSubject instanceof Recipe ? 'view' : 'comment';
                    if (! $freshAuthor->isActive()
                        || ! Gate::forUser($freshAuthor)->allows($ability, $freshSubject)
                        || $freshAuthor->hasBlockRelationWith($users[$ownerId])) {
                        $this->deny();
                    }

                    $freshParent = null;
                    foreach ($before['comments'] as $id) {
                        $comment = Comment::query()->whereKey($id)->widoczneDla($freshAuthor)->first();
                        if ($comment === null || ! $this->belongsTo($comment, $freshSubject)) {
                            $this->deny();
                        }
                        $comment->setRelation('author', $users[$comment->author_id]);
                        if ($id === $parent?->getKey()) {
                            $freshParent = $comment;
                        }
                    }

                    return $publish($freshAuthor, $freshSubject, $freshParent);
                });
            } catch (ChangedCommentDependencies) {
                // Tylko zmiana identyfikatorów zależności. Timeout i deadlock
                // wychodzą bez ponawiania, żeby nie ukrywać błędnego protokołu.
            }
        }

        $this->deny();
    }

    /** @return array{users: list<string>, comments: list<string>, recipe: ?string, identity: array<mixed>} */
    private function discover(User $author, Post|Recipe|CookedEvent $subject, ?Comment $parent): array
    {
        $fresh = $subject->newQuery()->find($subject->getKey());
        if ($fresh === null) {
            $this->deny();
        }
        $ownerId = $fresh instanceof CookedEvent ? $fresh->user_id : $fresh->author_id;
        $ids = [(string) $author->getKey(), (string) $ownerId];
        $recipeId = $fresh instanceof CookedEvent ? $fresh->recipe_id : null;
        $recipe = $recipeId !== null ? Recipe::withTrashed()->find($recipeId) : null;
        if ($recipe !== null) {
            $ids[] = (string) $recipe->author_id;
        }
        $comments = [];
        $identity = [$ownerId, $recipeId, $recipe?->author_id];
        if ($parent !== null) {
            $selected = Comment::query()->find($parent->getKey());
            if ($selected === null || ! $this->belongsTo($selected, $fresh)) {
                $this->deny();
            }
            $root = $selected->parent_id !== null ? Comment::query()->find($selected->parent_id) : $selected;
            if ($root === null || $root->parent_id !== null || ! $this->belongsTo($root, $fresh)) {
                $this->deny();
            }
            foreach ([$selected, $root] as $comment) {
                $ids[] = (string) $comment->author_id;
                $comments[] = (string) $comment->getKey();
                $identity[] = [$comment->getKey(), $comment->author_id, $comment->parent_id];
            }
        }
        $ids = array_values(array_unique($ids));
        $comments = array_values(array_unique($comments));
        sort($ids, SORT_STRING);
        sort($comments, SORT_STRING);

        return ['users' => $ids, 'comments' => $comments, 'recipe' => $recipeId, 'identity' => $identity];
    }

    private function belongsTo(Comment $comment, Post|Recipe|CookedEvent $subject): bool
    {
        return match (true) {
            $subject instanceof Post => $comment->post_id === $subject->getKey(),
            $subject instanceof Recipe => $comment->recipe_id === $subject->getKey(),
            $subject instanceof CookedEvent => $comment->cooked_event_id === $subject->getKey(),
        };
    }

    private function deny(): never
    {
        throw new BladDlaCzlowieka(self::UNAVAILABLE);
    }
}

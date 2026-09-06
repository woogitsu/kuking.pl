<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;

class CommentPolicy
{
    /**
     * Komentarz nie ma własnej widoczności (`Comment::scopeWidoczneDla`) —
     * dziedziczy ją po rodzicu, dokładnie tak jak `CookedEventPolicy::view()`
     * deleguje do `RecipePolicy`. Rodzic jest dokładnie jeden z trzech
     * (CHECK w bazie, `Comment::subject()`), więc `null` nie powinno się
     * zdarzyć — a jeśli się zdarzy, odmawiamy, zamiast zgadywać.
     */
    public function view(?User $user, Comment $comment): bool
    {
        $subject = $comment->subject();

        return match (true) {
            $subject instanceof Post => app(PostPolicy::class)->view($user, $subject),
            $subject instanceof Recipe => app(RecipePolicy::class)->view($user, $subject),
            $subject instanceof CookedEvent => app(CookedEventPolicy::class)->view($user, $subject),
            default => false,
        };
    }

    public function update(User $user, Comment $comment): bool
    {
        // Edycja komentarza tylko przez 15 minut od publikacji. Krótkie okno
        // wystarcza na poprawienie literówki, a nie pozwala zmienić sensu
        // rozmowy po tym, jak ktoś już odpowiedział.
        return $user->getKey() === $comment->author_id
            && $comment->created_at?->diffInMinutes(now()) < 15;
    }

    public function delete(User $user, Comment $comment): bool
    {
        if ($user->isModerator()) {
            return true;
        }

        if ($user->getKey() === $comment->author_id) {
            return true;
        }

        // Autor treści może usunąć komentarz pod swoim wpisem — to jego kuchnia.
        return $user->getKey() === $comment->notifiableUserId();
    }
}

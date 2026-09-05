<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Comment;
use App\Models\User;

class CommentPolicy
{
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

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

/**
 * Kto może zobaczyć i zmienić wpis.
 *
 * UUID w adresie NIE JEST autoryzacją. Każde wejście na wpis przechodzi
 * przez tę politykę — inaczej mamy IDOR-a i wystarczy zgadnąć albo znaleźć
 * identyfikator, żeby czytać treści prywatne.
 */
class PostPolicy
{
    public function view(?User $user, Post $post): bool
    {
        if ($post->kind === Post::KIND_QUESTION && ! config('kuking.questions.enabled', false)) {
            return false;
        }

        if (! $post->isPublished()) {
            return $user !== null && $user->getKey() === $post->author_id;
        }

        $isOwnerOrModerator = $user !== null
            && ($user->getKey() === $post->author_id || $user->isModerator());

        // Konto autora zbanowane albo oznaczone do usunięcia — ta sama granica
        // co `UserPolicy::viewProfile` (audyt A5). Bez tego wpis zostawał
        // dostępny pod bezpośrednim adresem, mimo że link „zobacz profil" pod
        // nim dawał 403 — obietnica bez pokrycia w drugą stronę. Zawieszenie
        // NIE wchodzi tutaj: to kara czasowa i tylko na publikowanie
        // („dostęp tylko do ODCZYTU" — `EnsureAccountIsActive`), więc treść
        // zawieszonej osoby zostaje widoczna tak jak jej profil.
        if (! $isOwnerOrModerator && ! $post->author->jestDostepnyJakoAutor()) {
            return false;
        }

        // Blokada działa w obie strony i ma pierwszeństwo przed wszystkim innym.
        if ($user !== null && $user->hasBlockRelationWith($post->author)) {
            return false;
        }

        return match ($post->visibility) {
            Post::VISIBILITY_PUBLIC => true,
            Post::VISIBILITY_FOLLOWERS => $user !== null
                && ($user->getKey() === $post->author_id || $user->isFollowing($post->author)),
            Post::VISIBILITY_PRIVATE => $user !== null && $user->getKey() === $post->author_id,
            default => false,
        };
    }

    public function update(User $user, Post $post): bool
    {
        return $user->getKey() === $post->author_id;
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->getKey() === $post->author_id || $user->isModerator();
    }

    public function comment(User $user, Post $post): bool
    {
        return $this->view($user, $post) && $user->isActive();
    }
}

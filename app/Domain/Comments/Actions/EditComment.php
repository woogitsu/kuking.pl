<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Poprawka własnego komentarza (issue #1337).
 *
 * Sprawdzenie w kontrolerze to za mało: odpowiedź mogła zostać zatwierdzona
 * między `authorize()` a zapisem i poprawka trafiłaby pod cudzą odpowiedź.
 * `LockCommentContext` przy publikacji odpowiedzi trzyma wiersz korzenia pod
 * `FOR NO KEY UPDATE` aż do zatwierdzenia — tu bierzemy ten sam zamek i dopiero
 * pod nim pytamy `CommentPolicy::update()` jeszcze raz. Zamek jest jeden,
 * więc nie powstaje nowa kolejność blokad.
 *
 * Zwraca `null`, gdy pod zamkiem poprawka nie jest już dozwolona; kontroler
 * wybiera wtedy komunikat na podstawie świeżego stanu.
 */
final class EditComment
{
    public function handle(User $author, Comment $comment, string $body): ?Comment
    {
        return DB::transaction(function () use ($author, $comment, $body): ?Comment {
            $fresh = Comment::query()->whereKey($comment->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($fresh === null || Gate::forUser($author)->denies('update', $fresh)) {
                return null;
            }

            $fresh->update(['body' => $body]);

            return $fresh;
        });
    }
}

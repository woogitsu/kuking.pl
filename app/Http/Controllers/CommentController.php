<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\DeleteComment;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Edycja i usunięcie komentarza.
 *
 * Reguły KTO MOŻE CO żyją w CommentPolicy (edycja — autor, 15 minut od
 * publikacji; usunięcie — autor komentarza, autor treści albo moderator).
 * Kontroler autoryzuje i waliduje żądanie. DeleteComment pilnuje spójności
 * wątku oraz atomowego powiadomienia z powodem usunięcia.
 */
class CommentController extends Controller
{
    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Napisz coś, zanim zapiszesz poprawkę.',
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ]);

        $comment->fill(['body' => trim($data['body'])]);
        if ($comment->isDirty('body')) {
            $comment->save();
            PrzeanalizujTresc::dlaKomentarza($comment)->afterCommit();
        }

        return back()->with('status', 'Komentarz poprawiony.');
    }

    public function destroy(Request $request, Comment $comment, DeleteComment $delete): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $actor = $request->user();

        $isSelfDelete = $actor->getKey() === $comment->author_id;
        $isContentOwnerRemovingOthers = ! $isSelfDelete
            && $actor->getKey() === $comment->notifiableUserId();

        $data = $request->validate([
            'reason' => [$isContentOwnerRemovingOthers ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'reason.required' => 'Napisz krótko, dlaczego usuwasz ten komentarz — autor to zobaczy. Bez powodu wygląda to na ukrywanie głosu innej osoby.',
            'reason.max' => 'Powód jest za długi. Zmieść się w 500 znakach.',
        ]);

        $delete->handle($actor, $comment, $data['reason'] ?? null);

        return back()->with('status', 'Komentarz usunięty.');
    }
}

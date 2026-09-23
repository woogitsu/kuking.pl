<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\DeleteComment;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Edycja i usunięcie komentarza.
 *
 * Reguły KTO MOŻE CO żyją w CommentPolicy (edycja — autor, 15 minut od
 * publikacji; usunięcie — autor komentarza albo autor treści; moderator
 * zdejmuje cudzy komentarz wyłącznie z panelu moderacji, issue #932).
 * Kontroler woła Policy i waliduje dane. Akcja DeleteComment pilnuje dwóch
 * rzeczy, których Policy świadomie nie robi:
 *  - wątek nie może się rozsypać, gdy usunięty komentarz ma odpowiedzi,
 *  - gdy autor treści usuwa CUDZY komentarz, autor komentarza dostaje
 *    powiadomienie z powodem — inaczej wygląda to na cichą cenzurę.
 */
class CommentController extends Controller
{
    public function __construct(private readonly DeleteComment $deleteComment) {}

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        // Issue #937: autor widzi własny ukryty komentarz, ale nie może go
        // poprawić (CommentPolicy::update). Zamiast gołego 403 mówimy mu,
        // co może zrobić — o istnieniu komentarza wie, bo to jego tekst.
        if ($request->user()->getKey() === $comment->author_id
            && $comment->status !== Comment::STATUS_PUBLISHED) {
            return back()->withInput()->withErrors([
                'body' => 'Moderacja ukryła ten komentarz, więc nie da się go już poprawić. '
                    .'Jeśli uważasz, że to pomyłka, odwołaj się od decyzji — znajdziesz ją w powiadomieniach.',
            ]);
        }

        $this->authorize('update', $comment);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Napisz coś, zanim zapiszesz poprawkę.',
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ]);

        $comment->update(['body' => trim($data['body'])]);

        return back()->with('status', 'Komentarz poprawiony.');
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
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

        $this->deleteComment->handle($actor, $comment, $data['reason'] ?? null);

        return back()->with('status', 'Komentarz usunięty.');
    }
}

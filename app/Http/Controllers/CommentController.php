<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\DeleteComment;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    private const ALREADY_DELETED = 'Ten komentarz był już usunięty. Nic więcej nie trzeba robić.';

    public function __construct(private readonly DeleteComment $deleteComment) {}

    public function update(Request $request, Comment $comment): RedirectResponse|Response
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

        // Po 15 minutach autor nie poprawi komentarza, ale nie traci tekstu,
        // który właśnie wpisał — dopiero PO kontroli moderacji wyżej.
        if ($request->user()->can('recoverExpiredEdit', $comment)) {
            return response()->view('pages.comments.expired-edit', [
                'body' => is_string($request->input('body')) ? $request->input('body') : '',
                'returnUrl' => $comment->subject()->url(),
            ], 403);
        }

        $this->authorize('update', $comment);

        // Po walidacji przekierowanie może dotrzeć już po zamknięciu okna.
        // Identyfikator pochodzi z autoryzowanego modelu, nie z pola formularza.
        $request->session()->flash('comment_edit_recovery', $comment->getKey());
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ], [
            'body.required' => 'Napisz coś, zanim zapiszesz poprawkę.',
            'body.max' => 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.',
        ]);

        $comment->update(['body' => trim($data['body'])]);
        $request->session()->forget('comment_edit_recovery');

        return back()->with('status', 'Komentarz poprawiony.');
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $actor = $request->user();

        // Issue #911: stary formularz z drugiej karty albo ponowione wysłanie.
        // Przed walidacją powodu — nie każemy uzasadniać czegoś, co już się stało.
        // Komentarz bez odpowiedzi jest miękko usunięty (trasa ma withTrashed).
        if ($comment->trashed() || $comment->body_removed_at !== null) {
            return back()->with('status', self::ALREADY_DELETED);
        }

        $isSelfDelete = $actor->getKey() === $comment->author_id;
        $isContentOwnerRemovingOthers = ! $isSelfDelete
            && $actor->getKey() === $comment->notifiableUserId();

        $data = $request->validate([
            'reason' => [$isContentOwnerRemovingOthers ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'reason.required' => 'Napisz krótko, dlaczego usuwasz ten komentarz — autor to zobaczy. Bez powodu wygląda to na ukrywanie głosu innej osoby.',
            'reason.max' => 'Powód jest za długi. Zmieść się w 500 znakach.',
        ]);

        $deleted = $this->deleteComment->handle($actor, $comment, $data['reason'] ?? null);

        return back()->with('status', $deleted ? 'Komentarz usunięty.' : self::ALREADY_DELETED);
    }
}

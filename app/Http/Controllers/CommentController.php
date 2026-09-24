<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\EditComment;
use App\Models\Comment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Edycja i usunięcie komentarza.
 *
 * Reguły KTO MOŻE CO żyją w CommentPolicy (edycja — autor, 15 minut od
 * publikacji i tylko do pierwszej odpowiedzi, #1337; usunięcie — autor
 * komentarza albo autor treści; moderator zdejmuje cudzy komentarz wyłącznie
 * z panelu moderacji, issue #932).
 * Kontroler woła Policy i waliduje dane. Akcja DeleteComment pilnuje dwóch
 * rzeczy, których Policy świadomie nie robi:
 *  - wątek nie może się rozsypać, gdy usunięty komentarz ma odpowiedzi,
 *  - gdy autor treści usuwa CUDZY komentarz, autor komentarza dostaje
 *    powiadomienie z powodem — inaczej wygląda to na cichą cenzurę.
 */
class CommentController extends Controller
{
    public function __construct(
        private readonly DeleteComment $deleteComment,
        private readonly EditComment $editComment,
    ) {}

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

        // Po 15 minutach albo po pierwszej odpowiedzi (#1337) autor nie
        // poprawi komentarza, ale nie traci tekstu, który właśnie wpisał —
        // dopiero PO kontroli moderacji wyżej.
        if ($odmowa = $this->odmowaZTekstem($request, $comment)) {
            return $odmowa;
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

        // Pod zamkiem korzenia `EditComment` pyta Policy jeszcze raz: odpowiedź
        // zatwierdzona po `authorize()` wyżej zamyka poprawkę (#1337).
        if ($this->editComment->handle($request->user(), $comment, trim($data['body'])) === null) {
            $request->session()->forget('comment_edit_recovery');

            return $this->odmowaZTekstem($request, $comment->fresh() ?? $comment) ?? abort(403);
        }
        $request->session()->forget('comment_edit_recovery');

        return back()->with('status', 'Komentarz poprawiony.');
    }

    private function odmowaZTekstem(Request $request, Comment $comment): ?Response
    {
        $powod = match (true) {
            $request->user()->can('recoverExpiredEdit', $comment) => ['pages.comments.expired-edit', 403],
            $request->user()->can('recoverAnsweredEdit', $comment) => ['pages.comments.answered-edit', 409],
            default => null,
        };

        if ($powod === null) {
            return null;
        }

        return response()->view($powod[0], [
            'body' => is_string($request->input('body')) ? $request->input('body') : '',
            'returnUrl' => $comment->subject()->url(),
            'commentId' => $comment->getKey(),
        ], $powod[1]);
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        // Issue #937: jak przy edycji — autor wie o swoim ukrytym komentarzu,
        // więc zamiast gołego 403 mówimy mu, co może zrobić.
        if ($request->user()->getKey() === $comment->author_id
            && $comment->status !== Comment::STATUS_PUBLISHED) {
            return back()->withErrors([
                'comment' => 'Moderacja ukryła ten komentarz, więc nie da się go już usunąć. '
                    .'Jeśli uważasz, że to pomyłka, odwołaj się od decyzji — znajdziesz ją w powiadomieniach.',
            ]);
        }

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

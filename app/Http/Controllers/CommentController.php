<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Comment;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Edycja i usunięcie komentarza.
 *
 * Reguły KTO MOŻE CO żyją w CommentPolicy (edycja — autor, 15 minut od
 * publikacji; usunięcie — autor komentarza, autor treści albo moderator).
 * Ten kontroler tylko woła Policy i pilnuje dwóch rzeczy, których Policy
 * świadomie nie robi:
 *  - wątek nie może się rozsypać, gdy usunięty komentarz ma odpowiedzi,
 *  - gdy autor treści usuwa CUDZY komentarz, autor komentarza dostaje
 *    powiadomienie z powodem — inaczej wygląda to na cichą cenzurę.
 */
class CommentController extends Controller
{
    /** Tekst zostawiany zamiast treści, żeby wątek odpowiedzi się nie rozsypał. */
    private const DELETED_PLACEHOLDER = 'Komentarz usunięty.';

    public function __construct(private readonly NotifyUser $notify) {}

    public function update(Request $request, Comment $comment): RedirectResponse
    {
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
        $author = $comment->author;

        $isSelfDelete = $actor->getKey() === $comment->author_id;
        $isContentOwnerRemovingOthers = ! $isSelfDelete
            && $actor->getKey() === $comment->notifiableUserId();

        $data = $request->validate([
            'reason' => [$isContentOwnerRemovingOthers ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'reason.required' => 'Napisz krótko, dlaczego usuwasz ten komentarz — autor to zobaczy. Bez powodu wygląda to na ukrywanie głosu innej osoby.',
            'reason.max' => 'Powód jest za długi. Zmieść się w 500 znakach.',
        ]);

        $originalBody = $comment->body;
        $hasReplies = $comment->replies()->exists();

        if ($hasReplies) {
            // Nie kasujemy wiersza — jego dzieci (odpowiedzi) by "zawisły"
            // bez rodzica w widoku. Zostawiamy widoczny ślad zamiast tego.
            $comment->forceFill(['body' => self::DELETED_PLACEHOLDER, 'body_removed_at' => now()])->save();
        } else {
            $comment->delete();
        }

        if ($isContentOwnerRemovingOthers) {
            $subject = $comment->subject();

            $this->notify->handle(
                recipient: $author,
                type: Notification::TYPE_MODERATION,
                actor: $actor,
                data: [
                    'message' => 'Twój komentarz „'.mb_substr($originalBody, 0, 120).'” został usunięty przez autora treści. Powód: '.$data['reason'],
                    'url' => $subject?->url(),
                ],
            );
        }

        return back()->with('status', 'Komentarz usunięty.');
    }
}

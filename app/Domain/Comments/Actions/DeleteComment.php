<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Usunięcie, znacznik wykonania i powiadomienie zatwierdzają się razem. */
final class DeleteComment
{
    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(User $actor, Comment $comment, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $comment, $reason): void {
            $current = Comment::withTrashed()->lockForUpdate()->findOrFail($comment->getKey());
            Gate::forUser($actor)->authorize('delete', $current);

            // Stan pod blokadą jest trwałym znacznikiem wykonania, również
            // po retencji powiadomienia. Stara karta nie tworzy drugiego pingu.
            if ($current->trashed() || $current->body_removed_at !== null) {
                return;
            }

            $originalBody = $current->body;
            $isOwnerRemovingOthers = $actor->getKey() !== $current->author_id
                && $actor->getKey() === $current->notifiableUserId();

            if ($isOwnerRemovingOthers && trim((string) $reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'Napisz krótko, dlaczego usuwasz ten komentarz — autor to zobaczy.',
                ]);
            }

            if ($current->replies()->exists()) {
                $current->forceFill(['body' => 'Komentarz usunięty.', 'body_removed_at' => now()])->save();
            } else {
                $current->delete();
            }

            if ($isOwnerRemovingOthers) {
                $this->notify->handle(
                    recipient: $current->author,
                    type: Notification::TYPE_MODERATION,
                    actor: $actor,
                    data: [
                        'message' => 'Twój komentarz „'.mb_substr($originalBody, 0, 120).'” został usunięty przez autora treści. Powód: '.$reason,
                        'url' => $current->subject()?->url(),
                    ],
                );
            }
        });
    }
}

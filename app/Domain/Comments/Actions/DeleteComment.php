<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteComment
{
    public const DELETED_PLACEHOLDER = 'Komentarz usunięty.';

    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(User $actor, Comment $comment, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $comment, $reason): void {
            $fresh = Comment::query()->whereKey($comment->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $fresh);
            $originalBody = $fresh->body;

            // Decyzja dopiero POD zamkiem wspólnym z publikacją odpowiedzi.
            //
            // Liczy się KAŻDA żywa odpowiedź, nie tylko opublikowana (#1317).
            // `replies()` filtruje `status = published`, więc odpowiedź ukryta
            // przez moderację nie chroniła korzenia: szedł do kosza, a po
            // przywróceniu odpowiedź wisiała pod niewidocznym rodzicem. Treść
            // ukrytej odpowiedzi nie wychodzi stąd nigdzie — pytamy tylko,
            // czy wiersz istnieje.
            if (Comment::query()->where('parent_id', $fresh->getKey())->exists()) {
                $fresh->forceFill(['body' => self::DELETED_PLACEHOLDER, 'body_removed_at' => now()])->save();
            } else {
                $fresh->delete();
            }

            if ($actor->getKey() !== $fresh->author_id && $actor->getKey() === $fresh->notifiableUserId()) {
                $this->notify->handle(
                    recipient: $fresh->author,
                    type: Notification::TYPE_MODERATION,
                    actor: $actor,
                    data: [
                        'message' => 'Twój komentarz „'.mb_substr($originalBody, 0, 120).'” został usunięty przez autora treści. Powód: '.$reason,
                        'url' => $fresh->subject()?->url(),
                    ],
                );
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteComment
{
    private const DELETED_PLACEHOLDER = 'Komentarz usunięty.';

    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(User $actor, Comment $comment, ?string $reason = null): void
    {
        DB::transaction(function () use ($actor, $comment, $reason): void {
            $fresh = Comment::query()->whereKey($comment->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();
            Gate::forUser($actor)->authorize('delete', $fresh);
            $originalBody = $fresh->body;

            // Decyzja dopiero POD zamkiem wspólnym z publikacją odpowiedzi.
            // Zakres replies i reguła placeholder/delete pozostają bez zmian.
            if ($fresh->replies()->exists()) {
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
                        // Nagłówek mówi, KTO usunął (audyt B9 pkt 4). Bez
                        // niego widok brał domyślne „Wiadomość od moderacji
                        // Kuking.”, czyli przypisywał moderacji decyzję,
                        // której moderacja nie podjęła.
                        'title' => self::tytul($fresh),
                        'message' => 'Twój komentarz „'.mb_substr($originalBody, 0, 120).'” został usunięty przez autora treści. Powód: '.$reason,
                        'url' => $fresh->subject()?->url(),
                    ],
                );
            }
        });
    }

    private static function tytul(Comment $comment): string
    {
        return match (true) {
            $comment->subject() instanceof Post => 'Twój komentarz został usunięty przez autora wpisu.',
            $comment->subject() instanceof Recipe => 'Twój komentarz został usunięty przez autora przepisu.',
            $comment->subject() instanceof CookedEvent => 'Twój komentarz został usunięty przez osobę, która ugotowała to danie.',
            default => 'Twój komentarz został usunięty przez autora treści, pod którą stał.',
        };
    }
}

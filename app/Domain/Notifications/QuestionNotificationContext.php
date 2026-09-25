<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class QuestionNotificationContext
{
    /**
     * Aktualne tytuły dla już odfiltrowanej strony powiadomień, bez kopii w JSON.
     * Dwa zbiorcze odczyty zamiast zapytania przy każdym wierszu. Brak komentarza
     * albo dostępnego pytania oznacza brak dodatkowego kontekstu.
     *
     * @param  list<Notification>  $notifications
     * @return Collection<string, string>
     */
    public function titles(array $notifications, User $viewer): Collection
    {
        if (! config('kuking.questions.enabled', false)) {
            return collect();
        }

        $ids = collect($notifications)
            ->filter(fn (Notification $notification) => in_array($notification->type, [Notification::TYPE_COMMENT, Notification::TYPE_REPLY], true))
            ->map(fn (Notification $notification) => $notification->data['comment_id'] ?? null)
            ->filter(fn ($id) => is_string($id) && Str::isUuid($id))
            ->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Comment::query()->widoczneDla($viewer)->whereKey($ids)
            ->whereNull('body_removed_at')
            ->with(['post' => fn ($query) => $query->where('kind', Post::KIND_QUESTION)
                ->widoczneDla($viewer)->whereHas('author', fn ($author) => $author->dostepnyJakoAutor())])
            ->get()
            ->filter(fn (Comment $comment) => $comment->post !== null)
            ->mapWithKeys(fn (Comment $comment) => [$comment->id => $comment->post->title]);
    }
}

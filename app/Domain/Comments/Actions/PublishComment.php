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
use RuntimeException;

/**
 * Dodanie komentarza pod wpisem, przepisem albo "Ugotowałem".
 *
 * Świadoma decyzja: odpowiedzi są jednopoziomowe. `parent_id` wskazuje na
 * komentarz główny, a odpowiedź na odpowiedź jest "podnoszona" do tego samego
 * wątku. Głębokie drzewa są nieczytelne przy powiększonym tekście i na
 * telefonie — a to nasi główni użytkownicy.
 */
final class PublishComment
{
    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(
        User $author,
        Post|Recipe|CookedEvent $subject,
        string $body,
        ?Comment $parent = null,
    ): Comment {
        $body = trim($body);

        if ($body === '') {
            throw new RuntimeException('Napisz coś, zanim wyślesz komentarz.');
        }

        $subjectOwner = $this->ownerOf($subject);

        if ($author->hasBlockRelationWith($subjectOwner)) {
            throw new RuntimeException('Nie można tu komentować.');
        }

        // Spłaszczamy wątki: odpowiedź na odpowiedź trafia do korzenia wątku.
        $parentId = $parent?->parent_id ?? $parent?->getKey();

        $comment = Comment::create([
            'author_id' => $author->getKey(),
            'post_id' => $subject instanceof Post ? $subject->getKey() : null,
            'recipe_id' => $subject instanceof Recipe ? $subject->getKey() : null,
            'cooked_event_id' => $subject instanceof CookedEvent ? $subject->getKey() : null,
            'parent_id' => $parentId,
            'body' => $body,
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->notify->handle(
            recipient: $subjectOwner,
            type: $parentId === null ? Notification::TYPE_COMMENT : Notification::TYPE_REPLY,
            actor: $author,
            data: [
                'comment_id' => $comment->getKey(),
                'excerpt' => mb_substr($body, 0, 120),
                'url' => $this->urlFor($subject),
            ],
        );

        // Jeśli odpowiadamy komuś innemu niż autor treści, ta osoba też
        // powinna się dowiedzieć — inaczej rozmowa się nie kleji.
        if ($parent !== null && $parent->author_id !== $subjectOwner->getKey()) {
            $this->notify->handle(
                recipient: $parent->author,
                type: Notification::TYPE_REPLY,
                actor: $author,
                data: [
                    'comment_id' => $comment->getKey(),
                    'excerpt' => mb_substr($body, 0, 120),
                    'url' => $this->urlFor($subject),
                ],
            );
        }

        return $comment;
    }

    private function ownerOf(Post|Recipe|CookedEvent $subject): User
    {
        return $subject instanceof CookedEvent ? $subject->user : $subject->author;
    }

    private function urlFor(Post|Recipe|CookedEvent $subject): string
    {
        return $subject->url();
    }
}

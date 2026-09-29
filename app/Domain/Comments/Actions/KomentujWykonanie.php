<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\User;

/**
 * Komentarz pod wykonaniem „Ugotowałem" — wyjęte z
 * `CookedEventController::comment()` bez zmiany zachowania (issue #970).
 */
final class KomentujWykonanie
{
    public function __construct(private readonly PublishComment $publishComment) {}

    /**
     * @throws BladDlaCzlowieka
     */
    public function handle(User $author, CookedEvent $wykonanie, string $body, ?string $parentId): Comment
    {
        return $this->publishComment->handle(
            author: $author,
            subject: $wykonanie,
            body: $body,
            // `widoczneDla()` — audyt W7-06. Bez tego można było podać
            // UUID komentarza ukrytego przez blokadę i podpiąć się pod
            // cudzy wątek. Akcja domenowa sprawdza to drugi raz, bo
            // kontrolerów jest kilka.
            parent: $parentId === null
                ? null
                : $wykonanie->comments()
                    ->widoczneDla($author)
                    ->whereKey($parentId)
                    ->first(),
            // ISSUE #761: patrz komentarz przy tym samym parametrze
            // w PostController::comment().
            parentRequested: $parentId !== null,
        );
    }
}

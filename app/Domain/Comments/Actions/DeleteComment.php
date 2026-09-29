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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteComment
{
    private const DELETED_PLACEHOLDER = Comment::DELETED_PLACEHOLDER;

    public function __construct(private readonly NotifyUser $notify) {}

    /**
     * Zwraca `false`, gdy komentarz był już usunięty — wtedy nic się nie
     * zmienia i nikt nie dostaje powiadomienia (issue #911).
     *
     * #2190: decyzję podejmujemy na ŚWIEŻYM, zablokowanym koncie wykonawcy,
     * nie na modelu przekazanym z kontrolera. Sankcja (zawieszenie, ban,
     * żądanie usunięcia konta) mogła zostać zatwierdzona po middleware i po
     * pierwszym `authorize()`; stary model przepuściłby wtedy usunięcie —
     * także CUDZEJ wypowiedzi przez właściciela treści, z placeholderem
     * i powiadomieniem dla autora. Kolejność zamków: konto → komentarz,
     * jak w `EditComment` i `LockCommentContext` (publikacja odpowiedzi).
     *
     * Odmowa to `AuthorizationException` z Policy (`CommentPolicy::delete()`
     * wymaga `isActive()`), rzucona przed jakimkolwiek zapisem — transakcja
     * nie zostawia placeholdera, kosza ani powiadomienia. Zawieszone konto
     * i tak nie ma tej trasy w `EnsureAccountIsActive` (usunięcie to zapis),
     * więc nikomu nie odbieramy prawa, które miał wcześniej.
     *
     * @throws AuthorizationException gdy świeży stan konta lub komentarza nie pozwala na usunięcie
     */
    public function handle(User $actor, Comment $comment, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($actor, $comment, $reason): bool {
            $freshActor = User::query()->whereKey($actor->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($freshActor === null) {
                throw new AuthorizationException;
            }

            $fresh = Comment::withTrashed()->whereKey($comment->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();
            Gate::forUser($freshActor)->authorize('delete', $fresh);

            // Issue #911: powtórzone żądanie (druga karta, ponowione wysłanie)
            // nie jest drugą decyzją. Bez tego korzeń z odpowiedziami dostawał
            // placeholder jeszcze raz, a autor komentarza drugie powiadomienie
            // z cytatem „Komentarz usunięty.” i drugim powodem. Sprawdzenie
            // POD zamkiem, więc dwa równoległe żądania też dają jeden skutek.
            if ($fresh->trashed() || $fresh->body_removed_at !== null) {
                return false;
            }

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

            if ($freshActor->getKey() !== $fresh->author_id && $freshActor->getKey() === $fresh->notifiableUserId()) {
                $this->notify->handle(
                    recipient: $fresh->author,
                    type: Notification::TYPE_MODERATION,
                    actor: $freshActor,
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

            return true;
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

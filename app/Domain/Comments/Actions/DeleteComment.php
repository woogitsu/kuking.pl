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
    /**
     * Napis w miejscu komentarza z odpowiedziami. Publiczny, bo tę samą
     * regułę stosuje moderacja (`ZdejmijTresc`, G31) — dwa napisy na jedno
     * zdarzenie rozjechałyby się przy pierwszej zmianie tekstu.
     */
    public const DELETED_PLACEHOLDER = 'Komentarz usunięty.';

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
                self::usunPustyNapisRodzica($fresh);
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

    /**
     * Napis „Komentarz usunięty.”, pod którym zniknęła OSTATNIA odpowiedź,
     * znika razem z nią (przegląd G31, D-251 pkt 11).
     *
     * Napis istnieje tylko po to, żeby odpowiedzi innych osób miały pod czym
     * stać. Bez odpowiedzi nie niesie nic, a usunąć go nie mógł już nikt:
     * `CommentPolicy::delete()` odmawia przy `body_removed_at` (i musi —
     * inaczej autor kasowałby komentarz zdjęty przez moderację). Sprzątanie
     * automatyczne zamiast przycisku dla moderatora: nie wymaga decyzji,
     * uzasadnienia ani nowego ekranu, bo tekstu już tu nie ma.
     *
     * Wołane w transakcji, po miękkim usunięciu odpowiedzi — przez autora
     * (`handle()` wyżej) i przez moderację (`ZdejmijTresc`). Blokada rodzica
     * ta sama co przy publikacji odpowiedzi, więc nowa odpowiedź nie wejdzie
     * między sprawdzeniem a usunięciem. Soft delete, nie kasowanie: kopia
     * tekstu z decyzji moderacji zostaje, a `RestoreContent` przywraca
     * komentarz razem z nią (i odstawia napis, gdy wraca odpowiedź).
     *
     * Odpowiedź UKRYTA przez moderację tu nie woła — ukrycie jest odwracalne
     * jednym kliknięciem i odpowiedź wróciłaby pod nieistniejący komentarz.
     */
    public static function usunPustyNapisRodzica(Comment $odpowiedz): void
    {
        if ($odpowiedz->parent_id === null) {
            return;
        }

        $rodzic = Comment::query()->whereKey($odpowiedz->parent_id)->lock('FOR NO KEY UPDATE')->first();

        if ($rodzic !== null && $rodzic->body_removed_at !== null && ! $rodzic->replies()->exists()) {
            $rodzic->delete();
        }
    }
}

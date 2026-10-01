<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\CommentThank;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * „Dziękuję" jednym kliknięciem pod komentarzem (issue #2355, F11).
 *
 * Autor treści — wpisu, przepisu albo wykonania — kwituje cudzy komentarz bez
 * pisania odpowiedzi. To NIE jest odpowiedź: nie tworzy wiersza w `comments`,
 * więc nie zamyka edycji komentarza (#1337), nie wchodzi do liczników rozmowy
 * i nie jest „odpowiedzią autora" w żadnej mierze, także w przyszłym wskaźniku
 * odpowiedzi (SOUL.md: podziękowanie ma tego wskaźnika nie zawyżać).
 *
 * ZASADY, KTÓRE TA AKCJA USTALA
 *  - Jedno kliknięcie = jeden wiersz i jedno powiadomienie. Drugie kliknięcie
 *    (podwójny dotyk, druga karta, ponowione żądanie) kończy się tym samym
 *    stanem bez błędu i BEZ drugiego powiadomienia: o powiadomieniu decyduje
 *    to, czy wiersz właśnie powstał (`INSERT … ON CONFLICT DO NOTHING`).
 *  - WYCOFANIA NIE MA. Podziękowanie jest uprzejmością, nie stanem, który
 *    trzeba móc odkręcić — a powiadomienie i tak już poszło. Dzięki temu
 *    „wycofaj i ponów" nie może wyprodukować drugiego powiadomienia ani
 *    pętli. Podziękowanie znika razem z komentarzem albo z kontem dziękującego
 *    (`EraseAccountData`, klucze obce).
 *  - Brak licznika, brak listy, brak sygnału do feedu. Komentujący dostaje
 *    powiadomienie; nikt inny nie widzi, że podziękowano.
 *  - Bramka to `CommentPolicy::thank()` — sprawdzana tu PONOWNIE na świeżym
 *    wierszu pod blokadą, bo komentarz mógł zostać usunięty albo ukryty
 *    między wczytaniem strony a kliknięciem.
 *  - Powiadomienie idzie przez `NotifyUser`: pomija blokady, konta, które nie
 *    mogą czytać, i własne akcje. Nie ma push — `KanalPush` zna tylko
 *    wybrane typy, a to nie jest pilne.
 */
final class ThankForComment
{
    public function __construct(private readonly NotifyUser $notify) {}

    /**
     * @return bool true, gdy podziękowanie właśnie powstało; false, gdy już było
     *
     * @throws AuthorizationException gdy Policy odmawia
     * @throws BladDlaCzlowieka gdy komentarza już nie ma
     */
    public function handle(User $thanker, Comment $comment): bool
    {
        return DB::transaction(function () use ($thanker, $comment): bool {
            // FOR SHARE: usunięcie komentarza (`DeleteComment`) bierze blokadę
            // zapisu, więc nie wyprzedzi nas w połowie decyzji.
            $fresh = Comment::query()
                ->with(['author', 'post', 'recipe', 'cookedEvent'])
                ->whereKey($comment->getKey())
                ->lock('FOR SHARE')
                ->first();

            if ($fresh === null) {
                throw new BladDlaCzlowieka('Tego komentarza już nie ma, więc nie ma za co dziękować.');
            }

            Gate::forUser($thanker)->authorize('thank', $fresh);

            // Dwa równoległe kliknięcia: drugie trafia w UNIQUE
            // (comment_id, thanker_id) i kończy się bez błędu.
            $powstalo = CommentThank::query()->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'comment_id' => $fresh->getKey(),
                'thanker_id' => $thanker->getKey(),
                'created_at' => now(),
            ]) > 0;

            if (! $powstalo) {
                return false;
            }

            $subject = $fresh->subject();

            $this->notify->handle(
                recipient: $fresh->author,
                type: Notification::TYPE_COMMENT_THANKED,
                actor: $thanker,
                data: [
                    'comment_id' => $fresh->getKey(),
                    // Adres zapasowy, gdy lista nie policzy strony wątku —
                    // jak przy `comment.created` (`CelPowiadomienia::adresZapasowy`).
                    'url' => $subject?->url(),
                ],
            );

            return true;
        });
    }
}

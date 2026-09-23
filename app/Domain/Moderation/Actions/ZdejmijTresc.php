<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Comments\Actions\DeleteComment;
use App\Models\Comment;
use App\Models\ModerationAction;
use Illuminate\Database\Eloquent\Model;

/**
 * Wykonanie decyzji `remove` na treści — jedno miejsce dla decyzji ze
 * zgłoszenia (`ModerationController::decide()`) i z urzędu
 * (`ZdejmijZUrzedu`), G31.
 *
 * KOMENTARZ Z ODPOWIEDZIAMI NIE ZNIKA
 * `applyAction()` robił tu gołe `$target->delete()`. Przy komentarzu
 * z odpowiedziami to był zwykły soft delete i odpowiedzi INNYCH osób
 * znikały razem z nim — wątek się rozsypywał, choć `DeleteComment` od
 * dawna pilnuje odwrotnej reguły przy usuwaniu przez autora. Teraz moderacja
 * stosuje tę samą regułę: napis „Komentarz usunięty.” i `body_removed_at`.
 *
 * Napis zastępuje tekst, a od decyzji przysługuje odwołanie, które ma
 * treść przywrócić. Dlatego pierwotny tekst ląduje przy decyzji
 * (`moderation_actions.tresc_sprzed_zdjecia`) i stamtąd bierze go
 * `RestoreContent`.
 *
 * Wywoływać WEWNĄTRZ transakcji — blokada komentarza jest ta sama co
 * w `DeleteComment` (wspólna z publikacją odpowiedzi), więc odpowiedź nie
 * wejdzie między sprawdzeniem a usunięciem.
 */
final class ZdejmijTresc
{
    public function handle(Model $target, ModerationAction $decyzja): void
    {
        if (! $target instanceof Comment) {
            $target->delete();

            return;
        }

        $komentarz = Comment::query()->whereKey($target->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();

        if (! $komentarz->replies()->exists()) {
            $komentarz->delete();

            return;
        }

        $decyzja->forceFill(['tresc_sprzed_zdjecia' => $komentarz->body])->save();

        $komentarz->forceFill([
            'body' => DeleteComment::DELETED_PLACEHOLDER,
            'body_removed_at' => now(),
        ])->save();
    }
}

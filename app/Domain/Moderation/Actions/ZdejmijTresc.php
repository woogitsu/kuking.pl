<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Comments\Actions\DeleteComment;
use App\Models\Comment;
use App\Models\ModerationAction;
use Illuminate\Database\Eloquent\Model;
use LogicException;

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
 * DWA KROKI, JEDNA TRANSAKCJA
 *  1. `tekstDoZachowania()` — PRZED zapisem decyzji; wynik idzie
 *     do tego samego INSERT-u co decyzja. Decyzji nikt potem nie poprawia
 *     osobnym UPDATE-em (D-251: rejestr decyzji jest dopisywany, nie
 *     edytowany).
 *  2. `handle()` — wykonanie.
 *
 * Oba WEWNĄTRZ jednej transakcji — blokada komentarza jest ta sama co
 * w `DeleteComment` (wspólna z publikacją odpowiedzi), więc odpowiedź nie
 * wejdzie między sprawdzeniem a usunięciem, a wynik kroku 1 zostaje prawdą
 * do kroku 2.
 */
final class ZdejmijTresc
{
    /**
     * Tekst komentarza, który trzeba zachować przy decyzji — albo NULL, gdy
     * treść zniknie zwykłym soft delete (nie komentarz albo bez odpowiedzi).
     */
    public function tekstDoZachowania(Model $target): ?string
    {
        if (! $target instanceof Comment) {
            return null;
        }

        $komentarz = Comment::query()->whereKey($target->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();

        return $komentarz->replies()->exists() ? $komentarz->body : null;
    }

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

        if ($decyzja->tresc_sprzed_zdjecia === null) {
            // Napis bez kopii = tekst przepada, a „cofam” po odwołaniu nie ma
            // czego przywrócić. Lepiej wycofać całą decyzję niż to.
            throw new LogicException('Decyzja remove na komentarzu z odpowiedziami bez tresc_sprzed_zdjecia — '
                .'wywołaj ZdejmijTresc::tekstDoZachowania() przed zapisem decyzji.');
        }

        $komentarz->forceFill([
            'body' => DeleteComment::DELETED_PLACEHOLDER,
            'body_removed_at' => now(),
        ])->save();
    }
}

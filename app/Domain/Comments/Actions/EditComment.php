<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Comments\KonfliktPoprawkiKomentarza;
use App\Models\Comment;
use Illuminate\Support\Facades\DB;

/**
 * Poprawka treści komentarza warunkowa względem wersji z formularza
 * (issue #982).
 *
 * Formularz niesie `Comment::wersjaTresci()` z chwili wyrenderowania. Pod
 * blokadą wiersza porównujemy ją z treścią w bazie: inna wersja to
 * `KonfliktPoprawkiKomentarza` i żadnego zapisu. Sama blokada bez porównania
 * tylko ustawiłaby zapisy w kolejce — starszy formularz i tak nadpisałby
 * nowszy tekst.
 *
 * Wyjątek od konfliktu: tekst z formularza jest już zapisany (ponowione
 * żądanie, podwójne kliknięcie). To sukces bez zapisu — nic by nie zginęło,
 * a ostrzeżenie o „innej karcie” byłoby fałszywe.
 *
 * `FOR NO KEY UPDATE`, a nie `FOR UPDATE`: tyle samo bierze sam `UPDATE`
 * treści i `DeleteComment`, więc publikacja odpowiedzi (klucz obcy
 * `parent_id`, `FOR KEY SHARE`) nie czeka na poprawkę rodzica.
 */
final class EditComment
{
    /**
     * Aktualizuje przekazany model, żeby wołający widział `wasChanged()`.
     * `$wersjaFormularza === null` — formularz sprzed tej zmiany, bez pola.
     */
    public function handle(Comment $comment, string $body, ?string $wersjaFormularza): void
    {
        DB::transaction(function () use ($comment, $body, $wersjaFormularza): void {
            $zapisany = Comment::query()->whereKey($comment->getKey())->lock('FOR NO KEY UPDATE')->firstOrFail();
            $comment->setRawAttributes($zapisany->getAttributes(), true);

            if ($wersjaFormularza !== null
                && ! hash_equals($comment->wersjaTresci(), $wersjaFormularza)
                && $comment->body !== $body) {
                throw new KonfliktPoprawkiKomentarza;
            }

            $comment->update(['body' => $body]);
        });
    }
}

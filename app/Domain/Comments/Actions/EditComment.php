<?php

declare(strict_types=1);

namespace App\Domain\Comments\Actions;

use App\Domain\Comments\KonfliktPoprawkiKomentarza;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Poprawka własnego komentarza (issue #1337, #982).
 *
 * Sprawdzenie w kontrolerze to za mało: odpowiedź mogła zostać zatwierdzona
 * między `authorize()` a zapisem i poprawka trafiłaby pod cudzą odpowiedź.
 * `LockCommentContext` przy publikacji odpowiedzi trzyma wiersz korzenia pod
 * `FOR NO KEY UPDATE` aż do zatwierdzenia — tu bierzemy ten sam zamek i dopiero
 * pod nim pytamy `CommentPolicy::update()` jeszcze raz. Zamek jest jeden,
 * więc nie powstaje nowa kolejność blokad.
 *
 * Zwraca `null`, gdy pod zamkiem poprawka nie jest już dozwolona; kontroler
 * wybiera wtedy komunikat na podstawie świeżego stanu.
 *
 * DRUGA KARTA (#982). Formularz niesie `Comment::wersjaTresci()` z chwili
 * wyrenderowania. Pod tym samym zamkiem porównujemy ją z treścią w bazie:
 * inna wersja to `KonfliktPoprawkiKomentarza` i żadnego zapisu. Sama blokada
 * bez porównania tylko ustawiłaby zapisy w kolejce — starszy formularz i tak
 * nadpisałby nowszy tekst. Wyjątek od konfliktu: tekst z formularza jest już
 * zapisany (ponowione żądanie, podwójne kliknięcie) — to sukces bez zmiany,
 * bo nic by nie zginęło, a ostrzeżenie o „innej karcie” byłoby fałszywe.
 * `$wersjaFormularza === null` — wołający bez wersji (formularz sprzed #982,
 * scenariusze wyścigów #1337): bez porównania, jak przed tą zmianą.
 *
 * Kolejność pod zamkiem: najpierw Policy, potem wersja. Poprawka, której
 * i tak nie wolno zrobić, nie ma zgłaszać konfliktu z drugą kartą.
 */
final class EditComment
{
    /**
     * @throws KonfliktPoprawkiKomentarza gdy formularz niesie nieaktualną wersję treści
     */
    public function handle(User $author, Comment $comment, string $body, ?string $wersjaFormularza = null): ?Comment
    {
        return DB::transaction(function () use ($author, $comment, $body, $wersjaFormularza): ?Comment {
            $fresh = Comment::query()->whereKey($comment->getKey())->lock('FOR NO KEY UPDATE')->first();

            if ($fresh === null || Gate::forUser($author)->denies('update', $fresh)) {
                return null;
            }

            if ($wersjaFormularza !== null
                && ! hash_equals($fresh->wersjaTresci(), $wersjaFormularza)
                && $fresh->body !== $body) {
                throw new KonfliktPoprawkiKomentarza;
            }

            $fresh->update(['body' => $body]);

            return $fresh;
        });
    }
}

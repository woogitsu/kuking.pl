<?php

declare(strict_types=1);

namespace App\Domain\Comments;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Odpowiedzi jednego wątku idą PORCJAMI, nie wszystkie naraz (issue #939).
 *
 * Paginacja komentarzy ogranicza liczbę WĄTKÓW na stronie, ale eager loading
 * `replies` dociągał do każdego z nich całą rozmowę — a nic nie ogranicza
 * liczby odpowiedzi w jednym wątku (ta sama osoba może odpowiadać bez końca).
 *
 * Jedna ścieżka dla wpisu, przepisu i wykonania:
 *  - `pierwszaPorcja()` w ograniczeniu eager loadingu — najstarsze
 *    `kuking.comments.replies_per_thread` odpowiedzi każdego wątku,
 *  - `uzupelnij()` po wczytaniu wątków — liczba WSZYSTKICH widocznych
 *    odpowiedzi (jednym zapytaniem) i, gdy adres niesie
 *    `?watek=<id>&odpowiedzi=<n>`, n-ta porcja tego jednego wątku.
 *
 * Bez JavaScriptu: dalsza porcja to zwykły link z kotwicą wątku.
 * `Notification::destinationUrls()` składa ten sam adres, żeby powiadomienie
 * o odpowiedzi prowadziło do porcji, w której ta odpowiedź jest widoczna.
 */
final class OdpowiedziWatku
{
    public const PARAMETR_WATKU = 'watek';

    public const PARAMETR_PORCJI = 'odpowiedzi';

    public static function rozmiarPorcji(): int
    {
        return max(1, (int) config('kuking.comments.replies_per_thread'));
    }

    /** Ograniczenie relacji `replies`: widoczność + pierwsza porcja. */
    public static function pierwszaPorcja(Builder $query, ?User $widz): Builder
    {
        return $query->widoczneDla($widz)->limit(self::rozmiarPorcji());
    }

    /**
     * @param  iterable<Comment>  $watki  komentarze główne z doładowaną pierwszą porcją
     * @param  array<int, string>  $relacjeOdpowiedzi  to samo, co kontroler doładowuje przy `replies.*`
     */
    public static function uzupelnij(iterable $watki, Request $request, array $relacjeOdpowiedzi): void
    {
        $poId = [];
        foreach ($watki as $watek) {
            $poId[(string) $watek->getKey()] = $watek;
        }

        if ($poId === []) {
            return;
        }

        $widz = $request->user();

        $liczby = Comment::query()
            ->whereIn('comments.parent_id', array_keys($poId))
            ->widoczneDla($widz)
            ->groupBy('comments.parent_id')
            ->selectRaw('comments.parent_id, count(*) as ile')
            ->pluck('ile', 'parent_id');

        foreach ($poId as $id => $watek) {
            $watek->odpowiedziRazem = (int) ($liczby[$id] ?? 0);
        }

        $rozwiniety = $request->query(self::PARAMETR_WATKU);
        $porcja = filter_var($request->query(self::PARAMETR_PORCJI), FILTER_VALIDATE_INT);

        if (! is_string($rozwiniety) || ! isset($poId[$rozwiniety]) || ! is_int($porcja) || $porcja < 2) {
            return;
        }

        $watek = $poId[$rozwiniety];
        $rozmiar = self::rozmiarPorcji();
        $watek->porcjaOdpowiedzi = $porcja;
        $watek->setRelation('replies', $watek->replies()
            ->widoczneDla($widz)
            ->with($relacjeOdpowiedzi)
            ->offset(($porcja - 1) * $rozmiar)
            ->limit($rozmiar)
            ->get());
    }
}

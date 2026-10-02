<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Domain\Search\SearchQuery;
use App\Models\Post;
use App\Support\FrazaWyszukiwania;
use Illuminate\Database\Eloquent\Builder;

/**
 * „Szukaj w moich wpisach” — fraza z pola na ekranie „Moje wpisy” (#2465).
 *
 * TEN SAM WZORZEC CO „SZUKAJ W MOICH WYKONANIACH” (#2070) I „W MOICH
 * ZESZYTACH” (#779): `kuking_normalize` po stronie bazy, fraza przez
 * `FrazaWyszukiwania` („zurek” znajdzie „Żurek”, `%` i `_` są zwykłym tekstem
 * — #753), te same progi długości i ten sam układ zdań błędu.
 *
 * ZAKRES: wyłącznie `posts.title` i `posts.body` WŁASNYCH wpisów autora.
 * Bez komentarzy, cudzych zapisów, tytułu podłączonego przepisu i zdjęć.
 * Fraza tylko ZAWĘŻA listę, którą ekran i tak pokazuje (D-328): dwa warunki
 * (`title OR body`) są zgrupowane w jednym nawiasie, więc nie wyprowadzają
 * wyników poza `author_id` ani poza stany/wyłączenia listy.
 *
 * PRYWATNOŚĆ: fraza żyje tylko w adresie `?szukaj=` tej trasy (GET, `noindex`,
 * odpowiedź prywatna). Nie trafia do analityki ani dziennika audytu.
 */
final class FrazaWMoichWpisach
{
    public const ETYKIETA = 'Szukaj w moich wpisach';

    private function __construct(
        public readonly string $fraza,
        public readonly ?string $blad,
    ) {}

    public static function zAdresu(mixed $wartosc): self
    {
        $fraza = is_string($wartosc) ? trim($wartosc) : '';

        if ($fraza === '') {
            return new self('', null);
        }

        if (mb_strlen($fraza) > SearchQuery::MAX_PHRASE_LENGTH) {
            return new self($fraza, 'Skróć tekst w polu „'.self::ETYKIETA.'” do '.SearchQuery::MAX_PHRASE_LENGTH.' znaków i spróbuj ponownie.');
        }

        // Długość PO normalizacji (#1050): fraza z samych emoji znika w `Str::ascii()`.
        if (mb_strlen(FrazaWyszukiwania::normalizuj($fraza)) < 2) {
            return new self($fraza, 'Wpisz co najmniej dwie litery z opisu albo tytułu wpisu.');
        }

        return new self($fraza, null);
    }

    /** Czy lista ma być zawężona — fraza jest i nie ma błędu. */
    public function aktywna(): bool
    {
        return $this->fraza !== '' && $this->blad === null;
    }

    /** @param  Builder<Post>  $query */
    public function zawez(Builder $query): void
    {
        if (! $this->aktywna()) {
            return;
        }

        $wzorzec = '%'.FrazaWyszukiwania::doLike(FrazaWyszukiwania::normalizuj($this->fraza)).'%';

        $query->where(fn ($szukaj) => $szukaj
            ->whereRaw('kuking_normalize(posts.body) like ?', [$wzorzec])
            ->orWhereRaw('kuking_normalize(posts.title) like ?', [$wzorzec]));
    }
}

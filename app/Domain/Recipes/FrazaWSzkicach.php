<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Domain\Search\SearchQuery;
use App\Models\Recipe;
use App\Support\FrazaWyszukiwania;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * „Szukaj w szkicach” — fraza z pola na prywatnej stronie „Wszystkie szkice”
 * (#2435).
 *
 * TEN SAM WZORZEC CO „SZUKAJ W MOICH WYKONANIACH” (#2070) I „W MOICH
 * ZESZYTACH” (#779): tytuł przepisu w kolumnie `recipes.title_search`
 * (`kuking_normalize`), fraza przez `FrazaWyszukiwania` — „zurek” znajdzie
 * „Żurek”, wielkość liter nie ma znaczenia, a `%` i `_` są zwykłym tekstem
 * (#753). Te same progi długości (2 znaki po normalizacji, #1050; najwyżej
 * `SearchQuery::MAX_PHRASE_LENGTH`) i ten sam układ zdań błędu.
 *
 * GŁÓWNA WYSZUKIWARKA SIĘ NIE ZMIENIA. `SearchQuery` nadal widzi tylko
 * opublikowane przepisy; ta klasa jest wywoływana WYŁĄCZNIE na zapytaniu,
 * które kontroler zbudował już od `$user->recipes()` i `status = draft`
 * (zakres autora i szkicu jest w zapytaniu PRZED frazą). Fraza tylko ZAWĘŻA
 * tę listę — niczego do niej nie dokłada i nie zmienia porządku.
 */
final class FrazaWSzkicach
{
    public const ETYKIETA = 'Szukaj w szkicach';

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
            return new self($fraza, 'Wpisz co najmniej dwie litery z tytułu szkicu.');
        }

        return new self($fraza, null);
    }

    /** Czy lista ma być zawężona — fraza jest i nie ma błędu. */
    public function aktywna(): bool
    {
        return $this->fraza !== '' && $this->blad === null;
    }

    /**
     * @param  Builder<Recipe>|Relation<Recipe, *, *>  $query
     */
    public function zawez(Builder|Relation $query): void
    {
        if (! $this->aktywna()) {
            return;
        }

        $query->where('recipes.title_search', 'like', '%'.FrazaWyszukiwania::doLike(FrazaWyszukiwania::normalizuj($this->fraza)).'%');
    }
}

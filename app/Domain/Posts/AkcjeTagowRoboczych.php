<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Models\Tag;
use App\Support\LimityTagow;

/**
 * Trzy przyciski tagów w formularzu wpisu — „Szukaj tagów", „Dodaj", „Usuń" —
 * wyjęte z `PostController` bez zmiany zachowania (issue #970).
 *
 * Wykonuje DOKŁADNIE JEDNĄ z trzech akcji na liście ROBOCZEJ (wolny tekst
 * z sesji formularza), a nie na prawdziwych wierszach `Tag` — te powstają
 * dopiero przy publikacji/zapisie w `ResolveTagsForPost`. „Szukaj tagów"
 * sam w sobie niczego nie zmienia: `tag_query` czyta następny render.
 */
final class AkcjeTagowRoboczych
{
    /**
     * @param  list<string>  $tagNames
     * @param  string|null  $usunTag  wartość pola `usun_tag` (pusta = brak akcji)
     * @param  string|null  $dodajTag  wartość pola `dodaj_tag` (pusta = brak akcji)
     * @param  bool  $pytanie  pytanie ma niższy limit tagów niż wpis
     * @return array{0: list<string>, 1: string|null} nowa lista i komunikat błędu (albo null)
     */
    public function zastosuj(array $tagNames, ?string $usunTag, ?string $dodajTag, bool $pytanie): array
    {
        if ($usunTag !== null && $usunTag !== '') {
            $doUsuniecia = Tag::znormalizujNazwe($usunTag);

            $tagNames = array_values(array_filter(
                $tagNames,
                static fn (string $nazwa): bool => Tag::znormalizujNazwe($nazwa) !== $doUsuniecia,
            ));

            return [$tagNames, null];
        }

        if ($dodajTag !== null && $dodajTag !== '') {
            $nowa = trim($dodajTag);
            $znormalizowana = Tag::znormalizujNazwe($nowa);

            if (! LimityTagow::dlugoscOk($znormalizowana) || ! LimityTagow::pasujeDoWzorca($znormalizowana)) {
                return [$tagNames, LimityTagow::komunikatNiepoprawnaNazwa()];
            }

            $jestJuzDodany = collect($tagNames)
                ->contains(fn (string $istniejacy): bool => Tag::znormalizujNazwe($istniejacy) === $znormalizowana);

            if ($jestJuzDodany) {
                return [$tagNames, LimityTagow::komunikatTagJuzDodany()];
            }

            if (count($tagNames) >= ($pytanie ? 3 : LimityTagow::maksTagowNaWpis())) {
                return [$tagNames, $pytanie ? 'Do pytania dodaj najwyżej 3 tagi.' : LimityTagow::komunikatZaDuzoTagow()];
            }

            $tagNames[] = $nowa;

            return [$tagNames, null];
        }

        // Zostaje tylko „Szukaj tagów" — lista niezmieniona.
        return [$tagNames, null];
    }
}

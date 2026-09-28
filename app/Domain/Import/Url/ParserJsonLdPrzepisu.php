<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\OdczytanyPrzepis;

/**
 * Odczyt przepisu z danych strukturalnych schema.org `Recipe` (JSON-LD)
 * — LOKALNIE, bez modelu AI i bez kosztu (D-300).
 *
 * Większość blogów kulinarnych osadza te dane dla wyszukiwarek, więc to jest
 * ścieżka pierwsza i zwykle jedyna. Model „GPT-6 Luna" wchodzi do gry
 * wyłącznie wtedy, gdy ta klasa zwróci `null`.
 *
 * Czego ta klasa CELOWO nie czyta:
 *  - `image`, `video`, `thumbnailUrl` — zdjęć z cudzych stron nie importujemy
 *    (decyzja właściciela z 26.09.2026), więc nawet adres zdjęcia nie wychodzi
 *    poza tę klasę;
 *  - `aggregateRating`, `review`, `author` — cudze oceny i cudze nazwisko nie
 *    są częścią szkicu; źródłem jest ADRES strony;
 *  - `nutrition` — wartości odżywcze liczymy sami, z tabeli (projekt §8).
 */
final class ParserJsonLdPrzepisu
{
    private const MAKS_GLEBOKOSC = 8;

    public function odczytaj(string $html): ?OdczytanyPrzepis
    {
        foreach ($this->bloki($html) as $dane) {
            $wezel = $this->znajdzPrzepis($dane, 0);

            if ($wezel === null) {
                continue;
            }

            $przepis = $this->zWezla($wezel);

            if ($przepis !== null) {
                return $przepis;
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private function bloki(string $html): array
    {
        $bloki = [];

        if (preg_match_all('#<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json["\']?[^>]*>(.*?)</script>#is', $html, $m) === false) {
            return [];
        }

        foreach ($m[1] as $surowy) {
            $surowy = trim($surowy);
            // Niektóre wtyczki owijają JSON w komentarz HTML albo CDATA.
            $surowy = (string) preg_replace('#^(<!--|<!\[CDATA\[)|(-->|\]\]>)$#', '', $surowy);

            $dane = json_decode(trim($surowy), true, 64);

            if ($dane !== null) {
                $bloki[] = $dane;
            }
        }

        return $bloki;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function znajdzPrzepis(mixed $dane, int $glebokosc): ?array
    {
        if (! is_array($dane) || $glebokosc > self::MAKS_GLEBOKOSC) {
            return null;
        }

        if ($this->jestPrzepisem($dane)) {
            /** @var array<string, mixed> $dane */
            return $dane;
        }

        foreach ($dane as $wartosc) {
            $znaleziony = $this->znajdzPrzepis($wartosc, $glebokosc + 1);

            if ($znaleziony !== null) {
                return $znaleziony;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $wezel
     */
    private function jestPrzepisem(array $wezel): bool
    {
        $typ = $wezel['@type'] ?? null;

        foreach ((array) $typ as $t) {
            if (is_string($t) && preg_match('#(^|[/:])Recipe$#i', trim($t)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $wezel
     */
    private function zWezla(array $wezel): ?OdczytanyPrzepis
    {
        $skladniki = $this->listaTekstow($wezel['recipeIngredient'] ?? $wezel['ingredients'] ?? []);
        $kroki = $this->kroki($wezel['recipeInstructions'] ?? [], 0);

        if ($skladniki === [] && $kroki === []) {
            return null;
        }

        $tytul = $this->tekst($wezel['name'] ?? $wezel['headline'] ?? '');

        return new OdczytanyPrzepis(
            tytul: $tytul !== '' ? $tytul : 'Przepis ze strony',
            opis: $this->tekst($wezel['description'] ?? '') ?: null,
            porcje: $this->porcje($wezel['recipeYield'] ?? null),
            przygotowanieMinut: $this->minuty($wezel['prepTime'] ?? null),
            gotowanieMinut: $this->minuty($wezel['cookTime'] ?? null),
            skladniki: $skladniki,
            kroki: $kroki,
        );
    }

    /**
     * @return list<string>
     */
    private function listaTekstow(mixed $wartosc): array
    {
        if (is_string($wartosc)) {
            return $this->wiersze($wartosc);
        }

        if (! is_array($wartosc)) {
            return [];
        }

        $wynik = [];

        foreach ($wartosc as $element) {
            if (is_string($element) || is_int($element) || is_float($element)) {
                $tekst = $this->tekst((string) $element);

                if ($tekst !== '') {
                    $wynik[] = $tekst;
                }
            } elseif (is_array($element) && isset($element['text']) && is_string($element['text'])) {
                $tekst = $this->tekst($element['text']);

                if ($tekst !== '') {
                    $wynik[] = $tekst;
                }
            }
        }

        return $wynik;
    }

    /**
     * `recipeInstructions` bywa tekstem, listą tekstów, listą `HowToStep`,
     * listą `HowToSection` z `itemListElement` albo `ItemList`.
     *
     * @return list<string>
     */
    private function kroki(mixed $wartosc, int $glebokosc): array
    {
        if ($glebokosc > self::MAKS_GLEBOKOSC) {
            return [];
        }

        if (is_string($wartosc)) {
            return $this->wiersze($wartosc);
        }

        if (! is_array($wartosc)) {
            return [];
        }

        // Pojedynczy węzeł zamiast listy.
        if (isset($wartosc['@type']) || isset($wartosc['itemListElement']) || isset($wartosc['text'])) {
            $wartosc = [$wartosc];
        }

        $wynik = [];

        foreach ($wartosc as $element) {
            if (is_string($element)) {
                array_push($wynik, ...$this->wiersze($element));

                continue;
            }

            if (! is_array($element)) {
                continue;
            }

            if (isset($element['itemListElement'])) {
                array_push($wynik, ...$this->kroki($element['itemListElement'], $glebokosc + 1));

                continue;
            }

            $tekst = $this->tekst(is_string($element['text'] ?? null) ? $element['text'] : (is_string($element['name'] ?? null) ? $element['name'] : ''));

            if ($tekst !== '') {
                $wynik[] = $tekst;
            }
        }

        return $wynik;
    }

    /**
     * Tekst, w którym kroki albo składniki rozdziela HTML (`<li>`, `<p>`,
     * `<br>`) albo znak nowej linii.
     *
     * @return list<string>
     */
    private function wiersze(string $tekst): array
    {
        $tekst = (string) preg_replace('#<\s*(br|/p|/li|/div|/h[1-6])\b[^>]*>#i', "\n", $tekst);
        $wynik = [];

        foreach (preg_split('/\R/u', $tekst) ?: [] as $wiersz) {
            $wiersz = $this->tekst($wiersz);

            if ($wiersz !== '') {
                $wynik[] = $wiersz;
            }
        }

        return $wynik;
    }

    private function tekst(mixed $wartosc): string
    {
        if (! is_string($wartosc)) {
            return '';
        }

        // Dwa razy: wtyczki WordPressa potrafią zakodować encje podwójnie
        // (`&amp;frac12;`).
        $tekst = html_entity_decode(strip_tags($wartosc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tekst = html_entity_decode(strip_tags($tekst), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $tekst));
    }

    /**
     * Liczba porcji tylko wtedy, gdy źródło podaje JEDNĄ liczbę:
     * „4", „4 porcje", „Serves 4". Przedział („4–6") i sztuki bez słowa
     * o porcjach zostają puste — nie zgadujemy.
     */
    private function porcje(mixed $wartosc): ?float
    {
        if (is_array($wartosc)) {
            $wartosc = $wartosc[0] ?? null;
        }

        if (is_int($wartosc) || is_float($wartosc)) {
            return $wartosc > 0 && $wartosc <= 1000 ? (float) $wartosc : null;
        }

        if (! is_string($wartosc)) {
            return null;
        }

        $tekst = mb_strtolower($this->tekst($wartosc));

        if (preg_match('/^(?:serves|dla|na)?\s*(\d{1,3})\s*(?:porcj\w*|osob\w*|os\.?|servings?|people|persons?)?$/u', $tekst, $m) === 1) {
            $liczba = (int) $m[1];

            return $liczba > 0 ? (float) $liczba : null;
        }

        return null;
    }

    /** Czas ISO 8601 (`PT1H30M`, `P0DT45M`) w minutach; inny zapis = brak. */
    private function minuty(mixed $wartosc): ?int
    {
        if (! is_string($wartosc)) {
            return null;
        }

        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/i', trim($wartosc), $m) !== 1) {
            return null;
        }

        $minuty = ((int) ($m[1] ?? 0)) * 1440 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0);

        return $minuty > 0 && $minuty <= 10080 ? $minuty : null;
    }
}

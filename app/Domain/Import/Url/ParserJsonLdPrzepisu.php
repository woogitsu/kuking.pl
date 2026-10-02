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
            $przepis = $this->znajdzPrzepis($dane, 0);

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
     * Pierwszy UŻYTECZNY Recipe w porządku grafu, nie pierwszy sam typ.
     * Pusty węzeł nie może zasłonić kolejnego przepisu w tym samym bloku.
     */
    private function znajdzPrzepis(mixed $dane, int $glebokosc): ?OdczytanyPrzepis
    {
        if (! is_array($dane) || $glebokosc > self::MAKS_GLEBOKOSC) {
            return null;
        }

        if ($this->jestPrzepisem($dane)) {
            /** @var array<string, mixed> $dane */
            $przepis = $this->zWezla($dane);

            if ($przepis !== null) {
                return $przepis;
            }
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

        $tytul = self::tekst($wezel['name'] ?? $wezel['headline'] ?? '');

        return new OdczytanyPrzepis(
            tytul: $tytul !== '' ? $tytul : 'Przepis ze strony',
            opis: self::tekst($wezel['description'] ?? '') ?: null,
            porcje: self::porcje($wezel['recipeYield'] ?? null),
            przygotowanieMinut: self::minuty($wezel['prepTime'] ?? null),
            gotowanieMinut: self::minuty($wezel['cookTime'] ?? null),
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
            return self::wiersze($wartosc);
        }

        if (! is_array($wartosc)) {
            return [];
        }

        $wynik = [];

        foreach ($wartosc as $element) {
            if (is_string($element) || is_int($element) || is_float($element)) {
                $tekst = self::tekst((string) $element);

                if ($tekst !== '') {
                    $wynik[] = $tekst;
                }
            } elseif (is_array($element) && isset($element['text']) && is_string($element['text'])) {
                $tekst = self::tekst($element['text']);

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
            return self::wiersze($wartosc);
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
                array_push($wynik, ...self::wiersze($element));

                continue;
            }

            if (! is_array($element)) {
                continue;
            }

            if (isset($element['itemListElement'])) {
                array_push($wynik, ...$this->kroki($element['itemListElement'], $glebokosc + 1));

                continue;
            }

            $tekst = self::tekst(is_string($element['text'] ?? null) ? $element['text'] : (is_string($element['name'] ?? null) ? $element['name'] : ''));

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
    public static function wiersze(string $tekst): array
    {
        // Granice rozpoznajemy dopiero po dwóch obsługiwanych warstwach encji.
        // Sprzątanie pojedynczego wiersza nie może już dekodować go ponownie.
        $tekst = self::decodeEntities($tekst);
        $tekst = (string) preg_replace('#<\s*(br|/p|/li|/div|/h[1-6])\b[^>]*>#i', "\n", $tekst);
        $wynik = [];

        foreach (preg_split('/\R/u', $tekst) ?: [] as $wiersz) {
            $wiersz = self::plainText($wiersz);

            if ($wiersz !== '') {
                $wynik[] = $wiersz;
            }
        }

        return $wynik;
    }

    public static function tekst(mixed $wartosc): string
    {
        if (! is_string($wartosc)) {
            return '';
        }

        return self::plainText(self::decodeEntities($wartosc));
    }

    private static function decodeEntities(string $tekst): string
    {
        // Dwie warstwy, nie pętla do skutku: WordPress zapisuje także
        // `&amp;frac12;`. Odtworzony markup sprzątamy dopiero po dekodowaniu.
        for ($layer = 0; $layer < 2; $layer++) {
            $tekst = html_entity_decode($tekst, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $tekst;
    }

    private static function plainText(string $tekst): string
    {
        // `strip_tags()` uznaje także zwykłe „<80” za początek niedomkniętego
        // znacznika i ucina resztę instrukcji. Chronimy tylko porównanie z
        // liczbą; prawdziwy markup nadal usuwa ta sama funkcja. Znacznik
        // odróżniamy od takiego samego znaku w wejściu bez szukania w pętli.
        $znacznik = "\u{E000}";
        $tekst = str_replace($znacznik, $znacznik.$znacznik, $tekst);
        $tekst = (string) preg_replace('/<(?=\d)/', $znacznik.'L', $tekst);
        $tekst = strtr(strip_tags($tekst), [
            $znacznik.$znacznik => $znacznik,
            $znacznik.'L' => '<',
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', $tekst));
    }

    /**
     * Liczba porcji tylko wtedy, gdy źródło podaje JEDNĄ liczbę:
     * „4", „1,25 porcji", „Serves 0.5". Przedział („4–6") i sztuki bez
     * słowa o porcjach zostają puste — nie zgadujemy. Granice 0,5–999 i
     * maksymalnie setne są takie same jak w formularzu przepisu.
     */
    public static function porcje(mixed $wartosc): ?float
    {
        if (is_array($wartosc)) {
            $wartosc = $wartosc[0] ?? null;
        }

        if (is_int($wartosc) || is_float($wartosc)) {
            return self::poprawnaLiczbaPorcji((float) $wartosc);
        }

        if (! is_string($wartosc)) {
            return null;
        }

        $tekst = mb_strtolower(self::tekst($wartosc));

        if (preg_match('/^(?:serves|dla|na)?\s*(\d{1,4}(?:[.,]\d{1,2})?)\s*(?:porcj\w*|osob\w*|os\.?|servings?|people|persons?)?$/u', $tekst, $m) === 1) {
            $liczba = (float) str_replace(',', '.', $m[1]);

            return self::poprawnaLiczbaPorcji($liczba);
        }

        return null;
    }

    private static function poprawnaLiczbaPorcji(float $liczba): ?float
    {
        if (! is_finite($liczba) || $liczba < 0.5 || $liczba > 999 || round($liczba, 2) !== $liczba) {
            return null;
        }

        return $liczba;
    }

    /** Czas ISO 8601 (`PT1H30M`, `P0DT45M`) w minutach; inny zapis = brak. */
    public static function minuty(mixed $wartosc): ?int
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

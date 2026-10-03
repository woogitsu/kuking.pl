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
        [$skladniki, $nierozpoznaneSkladniki] = $this->listaTekstow($wezel['recipeIngredient'] ?? $wezel['ingredients'] ?? []);
        $kroki = $this->kroki($wezel['recipeInstructions'] ?? [], 0);

        if ($skladniki === [] && $kroki === []) {
            return null;
        }

        $tytul = self::tekst($wezel['name'] ?? $wezel['headline'] ?? '');
        $czasPrzygotowania = $wezel['prepTime'] ?? null;
        $czasGotowania = $wezel['cookTime'] ?? null;
        $przygotowanieMinut = self::minuty($czasPrzygotowania);
        $gotowanieMinut = self::minuty($czasGotowania);
        $ostrzezenia = [];
        if ($nierozpoznaneSkladniki > 0) {
            $ostrzezenia['skladniki'] = $nierozpoznaneSkladniki;
        }
        if (self::odrzuconyCzas($czasPrzygotowania, $przygotowanieMinut)) {
            $ostrzezenia['przygotowanie'] = true;
        }
        if (self::odrzuconyCzas($czasGotowania, $gotowanieMinut)) {
            $ostrzezenia['gotowanie'] = true;
        }

        return new OdczytanyPrzepis(
            tytul: $tytul !== '' ? $tytul : 'Przepis ze strony',
            opis: self::tekst($wezel['description'] ?? '') ?: null,
            porcje: self::porcje($wezel['recipeYield'] ?? null),
            przygotowanieMinut: $przygotowanieMinut,
            gotowanieMinut: $gotowanieMinut,
            lacznieMinut: self::minuty($wezel['totalTime'] ?? null),
            skladniki: $skladniki,
            kroki: $kroki,
            ostrzezeniaParsera: $ostrzezenia,
        );
    }

    /**
     * @return array{0: list<string>, 1: int} teksty i liczba obiektów bez rozpoznanej treści
     */
    private function listaTekstow(mixed $wartosc): array
    {
        if (is_string($wartosc)) {
            return [self::wiersze($wartosc), 0];
        }

        if (! is_array($wartosc)) {
            return [[], $wartosc === null ? 0 : 1];
        }

        // Pojedynczy obiekt (np. jeden `PropertyValue`) to jedna pozycja, a nie
        // lista jego pól — inaczej nazwa i jednostka stałyby się osobnymi składnikami.
        if (! array_is_list($wartosc)) {
            $wartosc = [$wartosc];
        }

        $wynik = [];
        $nierozpoznane = 0;

        foreach ($wartosc as $element) {
            if (is_array($element) && $this->jestTypem($element, 'PropertyValue')) {
                $tekst = self::skladnikZPropertyValue($element);

                if ($tekst !== '') {
                    $wynik[] = $tekst;
                } else {
                    $nierozpoznane++;
                }
            } elseif (is_string($element) || is_int($element) || is_float($element)) {
                $tekst = self::tekst((string) $element);

                if ($tekst !== '') {
                    $wynik[] = $tekst;
                } else {
                    $nierozpoznane++;
                }
            } elseif (is_array($element) && isset($element['text']) && is_string($element['text'])) {
                $tekst = self::tekst($element['text']);

                if ($tekst !== '') {
                    $wynik[] = $tekst;
                } else {
                    $nierozpoznane++;
                }
            } elseif ($element !== null) {
                $nierozpoznane++;
            }
        }

        return [$wynik, $nierozpoznane];
    }

    public static function odrzuconyCzas(mixed $wartosc, ?int $minuty): bool
    {
        return $minuty === null && $wartosc !== null && $wartosc !== '';
    }

    /**
     * Jednostki z kodów UN/CEFACT (`unitCode`), które rozumiemy bez zgadywania.
     * Każdy inny kod zostaje w tekście składnika razem z prośbą o sprawdzenie
     * w źródle — nie tłumaczymy ani nie przeliczamy go po cichu (#2548).
     */
    private const JEDNOSTKI_UNIT_CODE = [
        'GRM' => 'g', 'KGM' => 'kg', 'MGM' => 'mg',
        'MLT' => 'ml', 'CLT' => 'cl', 'DLT' => 'dl', 'LTR' => 'l',
    ];

    /**
     * `PropertyValue` jako składnik: `value`, jednostka i `name` zapisane
     * wolnym tekstem. Nic nie jest przeliczane ani dopisywane — brak ilości
     * zostaje brakiem, a nieznany `unitCode` jest widoczny w tekście.
     *
     * @param  array<mixed>  $wezel
     */
    private static function skladnikZPropertyValue(array $wezel): string
    {
        $nazwa = self::tekst($wezel['name'] ?? '');
        $wartosc = $wezel['value'] ?? null;
        $ilosc = match (true) {
            is_int($wartosc), is_float($wartosc) => (string) $wartosc,
            is_string($wartosc) => self::tekst($wartosc),
            default => '',
        };
        $nieznanaIlosc = $wartosc !== null && ! is_int($wartosc) && ! is_float($wartosc) && ! is_string($wartosc);

        if ($nazwa === '' && $ilosc === '') {
            return '';
        }

        // Jednostka bez ilości nic nie znaczy — nie dopisujemy jej do samej nazwy.
        if ($ilosc === '') {
            return $nieznanaIlosc ? $nazwa.' — sprawdź ilość w źródle' : $nazwa;
        }

        $jednostka = self::tekst($wezel['unitText'] ?? '');
        $kod = self::tekst($wezel['unitCode'] ?? '');

        if ($jednostka === '' && $kod !== '') {
            $znana = self::JEDNOSTKI_UNIT_CODE[strtoupper($kod)] ?? null;

            if ($znana === null) {
                return $nazwa === ''
                    ? "{$ilosc} (kod jednostki ze źródła: {$kod}; sprawdź w źródle)"
                    : "{$nazwa} — ilość: {$ilosc}, kod jednostki ze źródła: {$kod}; sprawdź w źródle";
            }

            $jednostka = $znana;
        }

        return trim(implode(' ', array_filter([$ilosc, $jednostka, $nazwa], static fn (string $c): bool => $c !== '')));
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

        // Obiekt jest jednym węzłem także wtedy, gdy niesie tylko @id.
        // Iterowanie po jego wartościach zamieniałoby identyfikator w krok.
        if (! array_is_list($wartosc)) {
            $wartosc = [$wartosc];
        }

        // Pozycje porządkują listę tylko wtedy, gdy są poprawne i jednoznaczne
        // dla każdego elementu. Przy brakach i duplikatach zostaje kolejność
        // źródła — nie zgadujemy, w którym miejscu brakował krok.
        $pozycje = [];
        $widocznePozycje = [];

        foreach ($wartosc as $element) {
            $pozycja = is_array($element) ? self::pozycja($element['position'] ?? null) : null;

            if ($pozycja === null || isset($widocznePozycje[$pozycja])) {
                $pozycje = [];
                break;
            }

            $pozycje[] = $pozycja;
            $widocznePozycje[$pozycja] = true;
        }

        if (count($pozycje) === count($wartosc) && $pozycje !== []) {
            array_multisort($pozycje, SORT_ASC, SORT_NUMERIC, $wartosc);
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

            if ($this->jestTypem($element, 'ListItem')) {
                $item = $element['item'] ?? null;

                if (is_array($item) && ($this->jestTypem($item, 'HowToStep') || $this->jestTypem($item, 'HowToSection'))) {
                    array_push($wynik, ...$this->kroki($item, $glebokosc + 1));
                }

                // Nazwa opakowania nie jest instrukcją; @id/URL nie pobieramy.
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

    private static function pozycja(mixed $wartosc): ?int
    {
        if (! is_int($wartosc) && ! (is_string($wartosc) && ctype_digit($wartosc))) {
            return null;
        }

        $pozycja = filter_var($wartosc, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($pozycja) ? $pozycja : null;
    }

    /** @param array<mixed> $wezel */
    private function jestTypem(array $wezel, string $szukany): bool
    {
        foreach ((array) ($wezel['@type'] ?? null) as $typ) {
            if (is_string($typ) && preg_match('#(^|[/:])'.preg_quote($szukany, '#').'$#i', trim($typ)) === 1) {
                return true;
            }
        }

        return false;
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

    /** Czas ISO 8601 (`PT1H30M`, `PT120S`) w pełnych minutach; inny zapis albo ułamek minuty = brak. */
    public static function minuty(mixed $wartosc): ?int
    {
        if (! is_string($wartosc)) {
            return null;
        }

        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/i', trim($wartosc), $m) !== 1) {
            return null;
        }

        // Cały czas liczymy w sekundach, a limit 10080 minut sprawdzamy dopiero
        // na sumie — sekundy nie mogą ani znikać, ani omijać limitu (#2546).
        $sekundy = ((float) ($m[1] ?? 0)) * 86400 + ((float) ($m[2] ?? 0)) * 3600
            + ((float) ($m[3] ?? 0)) * 60 + (float) ($m[4] ?? 0);

        // Pole czasu przyjmuje pełne minuty. „PT90S” (1,5 min) nie jest ani
        // 1, ani 2 minutami — zgadywanie jest gorsze niż puste pole, które
        // autor uzupełnia sam (jak przy „4–6 porcji”). PT120S to dokładnie 2.
        if ($sekundy < 60 || $sekundy > 10080 * 60 || abs($sekundy / 60 - round($sekundy / 60)) > 1e-9) {
            return null;
        }

        return (int) round($sekundy / 60);
    }
}

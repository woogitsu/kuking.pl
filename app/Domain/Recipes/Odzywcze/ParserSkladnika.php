<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use Illuminate\Support\Str;

/**
 * Czyta ilość, jednostkę i nazwę z jednego wiersza składnika (D-299).
 *
 * SKĄD PARSER, SKORO SĄ KOLUMNY `quantity` I `unit_id`
 * Składnik to jedno pole wolnego tekstu (D-017): „2 szklanki mąki”,
 * „mąka pszenna – 500 g”, „pół kostki masła”. Formularze kolumn
 * strukturalnych nie wypełniają. Parser czyta tekst W CHWILI LICZENIA
 * i niczego nie zapisuje — tekst autora zostaje co do znaku.
 *
 * GDZIE SZUKAMY ILOŚCI (pierwsza trafiona wygrywa):
 *  1. na początku wiersza, także po „ok.”/„około”: „2 jajka”, „ok. 200 g”,
 *     także bez liczby, gdy wiersz zaczyna się od jednostki: „szczypta
 *     soli”, „kawałek selera” (= 1);
 *  2. po myślniku ze spacjami albo dwukropku: „mąka – 500 g”, „jajka: 3”;
 *  3. gdziekolwiek, ale tylko liczba ZE ZNANĄ JEDNOSTKĄ: „masło 200 g”.
 *     „mąka typ 650” i „mleko 3,2%” to nie są ilości.
 *
 * ZAKRES „2–3 ząbki” liczymy jako średnią (2,5). Waga w nawiasie
 * („1 puszka pomidorów (400 g)”) ma pierwszeństwo przed miarą domową, bo
 * autor podał ją wprost.
 *
 * Parser nie zna składników — dopasowanie nazwy do tabeli robi
 * `SlownikSkladnikow`, a przeliczenie na gramy `KalkulatorWartosci`.
 */
final class ParserSkladnika
{
    private const ULAMKI = ['½' => ' 1/2 ', '¼' => ' 1/4 ', '¾' => ' 3/4 ', '⅓' => ' 1/3 ', '⅔' => ' 2/3 ', '⅛' => ' 1/8 '];

    /** Liczebniki słowne po normalizacji. */
    private const SLOWA_LICZB = [
        // „połówki śliwek” to NIE jest 0,5 — to nieznana liczba połówek.
        'pol' => 0.5, 'polowa' => 0.5, 'polowe' => 0.5, 'polowka' => 0.5, 'polowke' => 0.5,
        'poltorej' => 1.5, 'poltora' => 1.5, 'cwierc' => 0.25,
        'jeden' => 1.0, 'jedna' => 1.0, 'jedno' => 1.0, 'jedne' => 1.0, 'jednej' => 1.0, 'jednego' => 1.0,
        'dwa' => 2.0, 'dwie' => 2.0, 'dwoch' => 2.0, 'trzy' => 3.0, 'trzech' => 3.0, 'cztery' => 4.0, 'czterech' => 4.0,
        'piec' => 5.0, 'szesc' => 6.0, 'siedem' => 7.0, 'osiem' => 8.0, 'dziewiec' => 9.0, 'dziesiec' => 10.0,
    ];

    /** Słowa między liczbą a jednostką, które nie zmieniają rachunku. */
    private const PRZYMIOTNIKI_MIARY = [
        'plaska', 'plaskie', 'plaskiej', 'plaskich', 'pelna', 'pelne', 'pelnej', 'pelnych',
        'czubata', 'czubate', 'czubatej', 'czubatych', 'duza', 'duze', 'duzej', 'duzych', 'duzy', 'duzego',
        'mala', 'male', 'malej', 'malych', 'maly', 'malego', 'srednia', 'srednie', 'sredniej', 'srednich', 'sredni', 'sredniego',
        'niepelna', 'niepelne', 'niepelnej', 'niecala', 'niecale', 'niecalej', 'rowna', 'rowne', 'solidna', 'solidne',
        'kopiata', 'kopiate', 'spora', 'spore', 'sporej',
    ];

    /** Po jednostce: „łyżka stołowa” to wciąż łyżka. */
    private const DOPISKI_JEDNOSTKI = ['stolowa', 'stolowe', 'stolowej', 'stolowych'];

    /** Dopiski znaczące „ilości tu nie ma i nie będzie” (gdy liczby brak). */
    private const BEZ_ILOSCI = '/\b(do smaku|wg uznania|wedlug uznania|ile wezmie|ile zabierze|na oko|opcjonalnie|do dekoracji|do posypania|do podania|do przybrania)\b/';

    public function odczytaj(string $tekst): OdczytanySkladnik
    {
        $t = self::normalizuj($tekst);

        [$t, $gramyNaSztuke, $nawiasPo] = $this->wyjmijNawiasy($t);
        $bezIlosci = preg_match(self::BEZ_ILOSCI, $t) === 1;

        // Myślnik ze spacjami i dwukropek oddzielają nazwę od reszty:
        // „mąka pszenna - 500 g”, „jajka: 3”, „sól - do smaku, na końcu”.
        $ogon = null;
        if (preg_match('/^(.+?)\s(?:-)\s(.+)$/', $t, $m) === 1 || preg_match('/^(.+?):\s*(.+)$/', $t, $m) === 1) {
            $t = trim($m[1]);
            $ogon = trim($m[2]);
        }

        $t = (string) preg_replace('/^(ok\.?|okolo|circa|ca\.?)\s+/', '', $t);

        $ilosc = null;
        $jednostka = null;
        $slowoJednostki = null;
        $nazwa = $t;

        $zPoczatku = $this->iloscNaPoczatku($t);
        if ($zPoczatku !== null) {
            [$ilosc, $jednostka, $slowoJednostki, $nazwa] = $zPoczatku;
        } elseif ($ogon !== null && ($zOgona = $this->iloscNaPoczatku((string) preg_replace('/^(ok\.?|okolo)\s+/', '', $ogon))) !== null) {
            [$ilosc, $jednostka, $slowoJednostki] = $zOgona;
        } elseif (($wSrodku = $this->iloscZJednostkaGdziekolwiek($t)) !== null) {
            [$ilosc, $jednostka, $slowoJednostki, $nazwa] = $wSrodku;
        }

        $gramyZNawiasu = null;
        if ($gramyNaSztuke !== null) {
            $mnoznik = ($nawiasPo || ($jednostka !== null && JednostkiMiary::jestPojemnikiem($jednostka)))
                ? ($ilosc ?? 1.0)
                : 1.0;
            $gramyZNawiasu = $gramyNaSztuke * $mnoznik;
        }

        $nazwa = self::oczyscNazwe($nazwa);
        // „2 liście laurowe”, „4 ziarna ziela”: przy jednostkach, które nie
        // są miarą (liść, ziarno, filet…), słowo bywa częścią nazwy.
        // Przy gramach, łyżkach i szklankach nigdy.
        $jestMiara = $jednostka !== null && (isset(JednostkiMiary::MASA[$jednostka]) || isset(JednostkiMiary::OBJETOSC[$jednostka]) || $jednostka === 'szczypta');
        $nazwaZJednostka = $slowoJednostki !== null && ! $jestMiara
            ? self::oczyscNazwe(rtrim($slowoJednostki, '.').' '.$nazwa)
            : null;

        return new OdczytanySkladnik($ilosc, $jednostka, $nazwa, $nazwaZJednostka, $gramyZNawiasu, $bezIlosci);
    }

    /**
     * Małe litery, bez ogonków, ułamki z klawiatury telefonu jako „1/2”,
     * wszystkie myślniki jako „ - ” (ale łącznik w „pszenno-żytni” zostaje).
     */
    public static function normalizuj(string $tekst): string
    {
        $t = mb_strtolower($tekst);
        $t = strtr($t, self::ULAMKI);
        $t = (string) preg_replace('/\s*[\x{2013}\x{2014}\x{2212}]\s*/u', ' - ', $t);
        $t = Str::lower(Str::ascii($t));
        // Zakres „2–3” po zamianie myślnika wyżej ma zostać zakresem,
        // a nie separatorem nazwy od ilości.
        $t = (string) preg_replace('/(\d)\s*-\s*(\d)/', '$1-$2', $t);

        return Str::squish($t);
    }

    /**
     * Nazwa do słownika: bez dopisku po przecinku („cebula, pokrojona”),
     * bez „do smaku”, bez przyimka na początku („z dorsza”), bez znaków
     * interpunkcyjnych poza procentem i łącznikiem.
     */
    public static function oczyscNazwe(string $nazwa): string
    {
        $nazwa = (string) preg_replace('/,(?!\d).*$/', '', $nazwa);
        $nazwa = (string) preg_replace(self::BEZ_ILOSCI, ' ', $nazwa);
        $nazwa = (string) preg_replace('/[^a-z0-9%,\- ]+/', ' ', $nazwa);
        $nazwa = Str::squish($nazwa);
        $nazwa = (string) preg_replace('/^(z|ze|od|do)\s+/', '', $nazwa);

        return trim($nazwa, ' -');
    }

    /**
     * Waga w nawiasie: „(400 g)”, „(po 200 g)”, „(ok. 1 kg)”. Wszystkie
     * nawiasy znikają z tekstu — to dopiski, nie nazwa.
     *
     * @return array{0: string, 1: float|null, 2: bool}
     */
    private function wyjmijNawiasy(string $t): array
    {
        $gramy = null;
        $po = false;

        if (preg_match_all('/\(([^)]*)\)/', $t, $nawiasy) > 0) {
            foreach ($nawiasy[1] as $wnetrze) {
                if ($gramy === null && preg_match('/(?:^|\s)(po\s+)?(?:ok\.?\s*|okolo\s+)?(\d+(?:[.,]\d+)?)\s*(g|gr|dag|dkg|kg)\b/', $wnetrze, $m) === 1) {
                    $kod = JednostkiMiary::kod($m[3]) ?? 'g';
                    $gramy = self::liczba($m[2]) * JednostkiMiary::MASA[$kod];
                    $po = trim($m[1]) !== '';
                }
            }
            $t = Str::squish((string) preg_replace('/\([^)]*\)/', ' ', $t));
        }

        return [$t, $gramy, $po];
    }

    /**
     * @return array{0: float, 1: string|null, 2: string|null, 3: string}|null
     */
    private function iloscNaPoczatku(string $t): ?array
    {
        $liczba = $this->liczbaNaPoczatku($t);

        if ($liczba === null) {
            // „szczypta soli”, „kawałek selera”, „niecała szklanka cukru” = 1.
            [$jednostka, $slowo, $reszta] = $this->jednostkaNaPoczatku($t, true);

            return $jednostka !== null ? [1.0, $jednostka, $slowo, $reszta] : null;
        }

        [$ilosc, $reszta] = $liczba;
        [$jednostka, $slowo, $reszta] = $this->jednostkaNaPoczatku($reszta, false);

        return [$ilosc, $jednostka, $slowo, $reszta];
    }

    /**
     * @return array{0: float, 1: string, 2: string, 3: string}|null
     */
    private function iloscZJednostkaGdziekolwiek(string $t): ?array
    {
        $slowa = implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), array_keys(JednostkiMiary::SLOWA)));
        $wzor = '/(?:^|\s)(\d+(?:[.,]\d+)?(?:\s*-\s*\d+(?:[.,]\d+)?)?)\s*('.$slowa.')(?=\s|$)(?!\s*%)/';

        if (preg_match($wzor, $t, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $reszta = Str::squish(substr($t, 0, $m[0][1]).' '.substr($t, $m[0][1] + strlen($m[0][0])));

        return [self::liczbaLubZakres($m[1][0]), (string) JednostkiMiary::kod($m[2][0]), $m[2][0], $reszta];
    }

    /**
     * @return array{0: float, 1: string}|null
     */
    private function liczbaNaPoczatku(string $t): ?array
    {
        $cyfra = '\d+(?:[.,]\d+)?';

        // „1 1/2”, „1/2”, „2-3”, „2 do 3”, „2 lub 3”, „250” (także „250g”).
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)(?![\d\/])/', $t, $m) === 1 && (int) $m[3] > 0) {
            return [(float) $m[1] + (float) $m[2] / (float) $m[3], ltrim(substr($t, strlen($m[0])))];
        }
        if (preg_match('/^(\d+)\/(\d+)(?![\d\/])/', $t, $m) === 1 && (int) $m[2] > 0) {
            return [(float) $m[1] / (float) $m[2], ltrim(substr($t, strlen($m[0])))];
        }
        if (preg_match('/^('.$cyfra.')\s*(?:-|do|lub|albo)\s*('.$cyfra.')(?![\d%])/', $t, $m) === 1) {
            return [(self::liczba($m[1]) + self::liczba($m[2])) / 2, ltrim(substr($t, strlen($m[0])))];
        }
        if (preg_match('/^('.$cyfra.')(?![\d%.,\/])/', $t, $m) === 1) {
            return [self::liczba($m[1]), ltrim(substr($t, strlen($m[0])))];
        }

        // Słownie: „dwie łyżki”, „pół kostki”, „dwie i pół szklanki”.
        $pierwsze = explode(' ', $t, 2)[0];
        if (isset(self::SLOWA_LICZB[$pierwsze])) {
            $ilosc = self::SLOWA_LICZB[$pierwsze];
            $reszta = explode(' ', $t, 2)[1] ?? '';
            if (preg_match('/^i\s+pol\b\s*/', $reszta, $m) === 1) {
                $ilosc += 0.5;
                $reszta = substr($reszta, strlen($m[0]));
            }

            return [$ilosc, ltrim($reszta)];
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string}
     */
    private function jednostkaNaPoczatku(string $t, bool $bezLiczby): array
    {
        $slowa = explode(' ', $t);
        $i = 0;
        while (isset($slowa[$i]) && in_array($slowa[$i], self::PRZYMIOTNIKI_MIARY, true)) {
            $i++;
        }

        $slowo = $slowa[$i] ?? '';
        $kod = JednostkiMiary::kod($slowo);

        // Bez liczby nie bierzemy jednoliterowych skrótów: „l” czy „g” na
        // początku wiersza to prędzej literówka niż „litr”.
        if ($kod === null || ($bezLiczby && strlen(rtrim($slowo, '.')) < 3)) {
            return [null, null, $t];
        }

        $j = $i + 1;
        while (isset($slowa[$j]) && in_array($slowa[$j], self::DOPISKI_JEDNOSTKI, true)) {
            $j++;
        }

        return [$kod, $slowo, implode(' ', array_slice($slowa, $j))];
    }

    private static function liczbaLubZakres(string $tekst): float
    {
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*-\s*(\d+(?:[.,]\d+)?)$/', $tekst, $m) === 1) {
            return (self::liczba($m[1]) + self::liczba($m[2])) / 2;
        }

        return self::liczba($tekst);
    }

    private static function liczba(string $tekst): float
    {
        return (float) str_replace(',', '.', $tekst);
    }
}

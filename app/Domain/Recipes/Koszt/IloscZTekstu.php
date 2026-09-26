<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Koszt;

use Illuminate\Support\Str;

/**
 * Ilość i miara z tekstu składnika — „2 szklanki mąki", „50 dag kiełbasy",
 * „3 jajka", „½ łyżeczki soli" (D-286, część 2).
 *
 * Tylko na potrzeby orientacyjnego kosztu. Tekst autora zostaje w bazie
 * nietknięty (D-017) — ta klasa niczego nie zapisuje i niczego nie
 * poprawia; jeśli czegoś nie rozumie, mówi `null`, a szacunek wtedy
 * uczciwie się nie pokazuje. Lepiej nie policzyć niż policzyć źle.
 *
 * Zwracana miara jest jedną z: `g`, `ml`, `szklanka`, `lyzka`, `lyzeczka`,
 * `szczypta`, `sztuka`, `kotlet` albo `nieprzeliczalna` (garść, pęczek,
 * ząbek, plaster, puszka… — bez wagi, której nie znamy).
 *
 * „Kotlet” jest osobną miarą, nie „sztuką” (#1964): sztuka schabu to cały
 * kawałek mięsa, a kotlet to plaster o typowej masie. Masę zna dopiero
 * `SzacunekKosztuZCen::MASA_KOTLETA`, per produkt — dla produktu spoza tej
 * listy kotlet zostaje bez masy i szacunek się nie pokazuje.
 *
 * UWAGA NA DUBLOWANIE: skalowanie porcji (V2, osobna gałąź) też potrzebuje
 * rozbioru ilości. Gdy tamten parser wejdzie na `main`, ta klasa powinna
 * z niego korzystać zamiast trzymać własne reguły.
 */
final class IloscZTekstu
{
    /** @var array<string, array{0: string, 1: float}> forma słowa → [miara, mnożnik] */
    private const JEDNOSTKI = [
        'g' => ['g', 1], 'gr' => ['g', 1], 'gram' => ['g', 1], 'gramy' => ['g', 1], 'gramow' => ['g', 1],
        'dag' => ['g', 10], 'dkg' => ['g', 10], 'deko' => ['g', 10], 'dekagram' => ['g', 10], 'dekagramy' => ['g', 10], 'dekagramow' => ['g', 10],
        'kg' => ['g', 1000], 'kilo' => ['g', 1000], 'kilogram' => ['g', 1000], 'kilogramy' => ['g', 1000], 'kilogramow' => ['g', 1000],
        'ml' => ['ml', 1], 'mililitr' => ['ml', 1], 'mililitry' => ['ml', 1], 'mililitrow' => ['ml', 1],
        'l' => ['ml', 1000], 'litr' => ['ml', 1000], 'litra' => ['ml', 1000], 'litry' => ['ml', 1000], 'litrow' => ['ml', 1000],
        'szklanka' => ['szklanka', 1], 'szklanki' => ['szklanka', 1], 'szklanek' => ['szklanka', 1], 'szklanke' => ['szklanka', 1],
        'lyzka' => ['lyzka', 1], 'lyzki' => ['lyzka', 1], 'lyzek' => ['lyzka', 1], 'lyzke' => ['lyzka', 1],
        'lyzeczka' => ['lyzeczka', 1], 'lyzeczki' => ['lyzeczka', 1], 'lyzeczek' => ['lyzeczka', 1], 'lyzeczke' => ['lyzeczka', 1],
        'szczypta' => ['szczypta', 1], 'szczypty' => ['szczypta', 1], 'szczypte' => ['szczypta', 1], 'szczypt' => ['szczypta', 1],
        'szt' => ['sztuka', 1], 'sztuka' => ['sztuka', 1], 'sztuki' => ['sztuka', 1], 'sztuk' => ['sztuka', 1],
        'kostka' => ['sztuka', 1], 'kostki' => ['sztuka', 1], 'kostek' => ['sztuka', 1],
        'tabliczka' => ['sztuka', 1], 'tabliczki' => ['sztuka', 1], 'tabliczek' => ['sztuka', 1],
        // Formy jak w `JednostkiMiary::SLOWA` wartości odżywczych (PR #1900).
        'kotlet' => ['kotlet', 1], 'kotlety' => ['kotlet', 1], 'kotletow' => ['kotlet', 1], 'kotleta' => ['kotlet', 1],
    ];

    /** Słowa miary bez wagi, którą dałoby się uczciwie przyjąć. */
    private const NIEPRZELICZALNE = [
        'garsc', 'garsci', 'garstka', 'garstki', 'peczek', 'peczka', 'peczki', 'peczkow',
        'zabek', 'zabki', 'zabkow', 'plaster', 'plastry', 'plastrow', 'plasterek', 'plasterki', 'plasterkow',
        'opakowanie', 'opakowania', 'opakowan', 'puszka', 'puszki', 'puszek', 'sloik', 'sloika', 'sloiki', 'sloikow',
        'kromka', 'kromki', 'kromek', 'torebka', 'torebki', 'torebek', 'galazka', 'galazki', 'galazek',
        'lisc', 'liscie', 'lisci', 'kawalek', 'kawalki', 'kawalkow', 'filizanka', 'filizanki', 'kubek', 'kubki',
    ];

    /**
     * @return array{ilosc: float, miara: string}|null
     */
    public static function rozbierz(string $tekst): ?array
    {
        $t = self::normalizuj($tekst);

        // Liczba (także „1,5", „1/2", „1 1/2", „2-3"), po niej opcjonalnie słowo.
        $wzor = '/(?<![a-z0-9.,\/])(\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:[.,]\d+)?)(?:\s*-\s*(\d+(?:[.,]\d+)?))?\s*(%|[a-z]+\.?)?/';

        if (preg_match_all($wzor, $t, $trafienia, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($trafienia as $trafienie) {
                $slowo = rtrim($trafienie[3][0] ?? '', '.');
                $przed = substr($t, max(0, $trafienie[0][1] - 4), min(4, $trafienie[0][1]));

                // „śmietana 18%", „mąka typ 650" — to nie są ilości.
                if ($slowo === '%' || str_ends_with($przed, 'typ ')) {
                    continue;
                }

                $ilosc = self::liczba($trafienie[1][0]);
                if (($trafienie[2][0] ?? '') !== '') {
                    // „2-3 szklanki" — środek przedziału.
                    $ilosc = ($ilosc + self::liczba($trafienie[2][0])) / 2;
                }

                if ($ilosc <= 0) {
                    return null;
                }

                return self::zMiara($ilosc, $slowo);
            }
        }

        // „pół szklanki", „półtorej łyżki" — słownie, zawsze z miarą.
        if (preg_match('/(?<![a-z])(poltorej|poltora|pol)\s+([a-z]+)/', $t, $m) === 1
            && (isset(self::JEDNOSTKI[$m[2]]) || in_array($m[2], self::NIEPRZELICZALNE, true))) {
            return self::zMiara($m[1] === 'pol' ? 0.5 : 1.5, $m[2]);
        }

        return null;
    }

    /** ASCII, małe litery, ułamki zapisane cyframi, pojedyncze spacje. */
    public static function normalizuj(string $tekst): string
    {
        $tekst = strtr($tekst, ['½' => ' 1/2', '¼' => ' 1/4', '¾' => ' 3/4', '⅓' => ' 1/3', '–' => '-', '—' => '-']);
        $tekst = Str::lower(Str::ascii($tekst));

        return trim((string) preg_replace('/\s+/', ' ', $tekst));
    }

    /**
     * @return array{ilosc: float, miara: string}
     */
    private static function zMiara(float $ilosc, string $slowo): array
    {
        if (isset(self::JEDNOSTKI[$slowo])) {
            [$miara, $mnoznik] = self::JEDNOSTKI[$slowo];

            return ['ilosc' => $ilosc * $mnoznik, 'miara' => $miara];
        }

        if (in_array($slowo, self::NIEPRZELICZALNE, true)) {
            return ['ilosc' => $ilosc, 'miara' => 'nieprzeliczalna'];
        }

        // Liczba, a po niej nazwa („3 jajka", „2 cebule") — to są sztuki.
        return ['ilosc' => $ilosc, 'miara' => 'sztuka'];
    }

    private static function liczba(string $zapis): float
    {
        $zapis = str_replace(',', '.', trim($zapis));

        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $zapis, $m) === 1) {
            return (int) $m[3] === 0 ? 0.0 : (int) $m[1] + (int) $m[2] / (int) $m[3];
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $zapis, $m) === 1) {
            return (int) $m[2] === 0 ? 0.0 : (int) $m[1] / (int) $m[2];
        }

        return (float) $zapis;
    }
}

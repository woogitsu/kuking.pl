<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

/**
 * Przelicza JEDEN wiersz składnika na inną liczbę porcji (D-284, V2).
 *
 * SKĄD LICZBA, SKORO BAZA JEJ NIE MA
 * Składnik to jedno pole wolnego tekstu (D-017, #364): „2 łyżki masła”,
 * „mąka – 500 g”, „pół kostki drożdży”. Kolumny `quantity`/`unit_id` są
 * w schemacie, ale formularze ich nie wypełniają. Dlatego ilość czytamy
 * z tekstu W CHWILI POKAZANIA, niczego nie zapisując: tekst autora zostaje
 * w bazie co do znaku, a przeliczenie jest tylko widokiem dla jednej osoby.
 *
 * GDZIE SZUKAMY ILOŚCI — w tej kolejności, pierwsza trafiona wygrywa:
 *  1. na początku wiersza, także po „ok.”/„około”: „2 jajka”, „ok. 200 g”;
 *  2. po myślniku albo dwukropku: „mąka pszenna – 500 g”, „jajka: 3”;
 *  3. gdziekolwiek, ale tylko liczba ZE ZNANĄ JEDNOSTKĄ: „masło 200 g”,
 *     „jajka 3 szt.” — bez jednostki „mąka typ 650” to nie jest ilość.
 * Przeliczamy tylko tę jedną liczbę. „2 puszki (po 400 g)” daje
 * „4 puszki (po 400 g)”, i to jest poprawne.
 *
 * CZEGO NIE RUSZAMY — wiersz zostaje dokładnie taki, jak napisał autor:
 *  - „Bez ilości” (`no_amount`, issue #44) — tego się nie mnoży z definicji;
 *  - szczypta, odrobina, „do smaku”, „ile weźmie”, „na oko”, „według uznania”;
 *  - wiersz, w którym nie znaleźliśmy liczby („sól”, „natka pietruszki”).
 *
 * GRANICA, O KTÓREJ TRZEBA WIEDZIEĆ
 * Odmieniamy słowo jednostki („1 łyżka”, „3 łyżki”, „5 łyżek”, „½ łyżki”),
 * ale nie rzeczownik bez jednostki: „2 jajka” razy 2,5 to „5 jajka”.
 * Polszczyzna potrzebowałaby tu słownika odmiany każdego produktu. Widz
 * dostaje nad listą zdanie „Przeliczone na N porcji” i jednym dotknięciem
 * wraca do ilości z przepisu.
 */
final class PrzeliczSkladnik
{
    private const LICZBA = '(?:\d+\s+\d+\/\d+|\d+\s?[½¼¾⅓⅔⅛]|\d+[.,]\d+|\d+\/\d+|[½¼¾⅓⅔⅛]|\d+)';

    private const SLOWO = '(?:półtorej|półtora|pół)(?!\p{L})';

    private const OKOLO = '(?:(?:około|ok\.|ok(?=\s))\s*)?';

    /** Słowa, przy których wiersza nie przeliczamy wcale. */
    private const BEZ_PRZELICZANIA = '/szczypt|odrobin|do\s+smaku|ile\s+(?:weźmie|wejdzie|potrzeba)|na\s+oko|według\s+uznania|wg\.?\s+uznania/iu';

    private const ULAMKI_ZNAKI = [
        '½' => 1 / 2,
        '¼' => 1 / 4,
        '¾' => 3 / 4,
        '⅓' => 1 / 3,
        '⅔' => 2 / 3,
        '⅛' => 1 / 8,
    ];

    public static function przelicz(string $tekst, bool $bezIlosci, float $mnoznik): PrzeliczonySkladnik
    {
        if ($bezIlosci || abs($mnoznik - 1.0) < 1e-9 || $mnoznik <= 0) {
            return PrzeliczonySkladnik::bezZmian($tekst);
        }

        if (preg_match(self::BEZ_PRZELICZANIA, $tekst) === 1) {
            return PrzeliczonySkladnik::bezZmian($tekst);
        }

        $trafienie = self::znajdzIlosc($tekst);

        if ($trafienie === null) {
            return PrzeliczonySkladnik::bezZmian($tekst);
        }

        $od = self::liczba($trafienie['od']);
        $do = $trafienie['do'] !== '' ? self::liczba($trafienie['do']) : null;

        if ($od === null || ($trafienie['do'] !== '' && $do === null)) {
            return PrzeliczonySkladnik::bezZmian($tekst);
        }

        $jednostka = $trafienie['jednostka'] !== '' ? JednostkaKuchenna::zFormy($trafienie['jednostka']) : null;
        $rodzaj = $jednostka?->rodzaj ?? JednostkaKuchenna::KUCHENNA;

        if ($rodzaj === JednostkaKuchenna::NIEPOLICZALNA) {
            return PrzeliczonySkladnik::bezZmian($tekst);
        }

        $ilosc = IloscKuchenna::zapis($od * $mnoznik, $rodzaj);
        $doOdmiany = IloscKuchenna::zaokraglij($od * $mnoznik, $rodzaj);

        if ($do !== null) {
            $ilosc .= $trafienie['separator'].IloscKuchenna::zapis($do * $mnoznik, $rodzaj);
            $doOdmiany = IloscKuchenna::zaokraglij($do * $mnoznik, $rodzaj);
        }

        if ($jednostka !== null) {
            $ilosc .= $trafienie['spacja'].$jednostka->forma($doOdmiany, $trafienie['jednostka']);
        }

        $wynik = new PrzeliczonySkladnik(
            przed: $trafienie['przed'],
            ilosc: $ilosc,
            po: mb_substr($tekst, mb_strlen($trafienie['przed']) + mb_strlen($trafienie['calosc'])),
            zmieniony: true,
        );

        // Zaokrąglenie potrafi oddać dokładnie to, co było (2 × ⅛ łyżeczki
        // → ¼ łyżeczki jest zmianą, ale 1,05 × 200 g → 200 g już nie).
        if ($wynik->tekst() === $tekst) {
            return PrzeliczonySkladnik::bezZmian($tekst);
        }

        return $wynik;
    }

    /**
     * @return array{przed: string, calosc: string, od: string, separator: string, do: string, spacja: string, jednostka: string}|null
     */
    private static function znajdzIlosc(string $tekst): ?array
    {
        $jednostki = JednostkaKuchenna::wzorzec();
        $ilosc = '(?<od>'.self::LICZBA.'|'.self::SLOWO.')(?:(?<separator>\s*[-–—]\s*)(?<do>'.self::LICZBA.'))?';
        $poIlosci = '(?=\s|$|\p{L}|\(|,)';
        $jednostka = '(?:(?<spacja>\s*)(?<jednostka>'.$jednostki.')(?!\p{L}))?';

        $wzorce = [
            // 1. Na początku wiersza.
            '/^(?<przed>\s*'.self::OKOLO.')(?<calosc>'.$ilosc.$poIlosci.$jednostka.')/iu',
            // 2. Po myślniku albo dwukropku („mąka – 500 g”).
            '/^(?<przed>.*?\S\s*(?:[–—:]|\s-)\s*'.self::OKOLO.')(?<calosc>'.$ilosc.$poIlosci.$jednostka.')/iu',
        ];

        foreach ($wzorce as $wzorzec) {
            if (preg_match($wzorzec, $tekst, $m) === 1) {
                return self::trafienie($m);
            }
        }

        // 1a. Sama jednostka na początku, bez liczby: „szklanka mąki”,
        // „łyżka miodu” — tak mówi się o JEDNEJ sztuce i tak podpowiada
        // kreator („Pisz tak, jak mówisz: «szklanka mąki»”). Liczba wchodzi
        // przed słowo, które zaczyna się wtedy małą literą: „2 szklanki mąki”.
        $mianowniki = JednostkaKuchenna::wzorzecMianownika();

        if (preg_match('/^(?<przed>\s*'.self::OKOLO.')(?<calosc>(?<jednostka>'.$mianowniki.'))(?!\p{L})/iu', $tekst, $m) === 1) {
            return [
                'przed' => $m['przed'],
                'calosc' => $m['calosc'],
                'od' => '1',
                'separator' => '',
                'do' => '',
                'spacja' => ' ',
                'jednostka' => mb_strtolower($m['jednostka']),
            ];
        }

        // 3. Liczba ze znaną jednostką gdziekolwiek („masło 200 g”).
        $wzorzec = '/^(?<przed>.*?(?<![\p{L}\d.,\/]))(?<calosc>'.$ilosc.'(?<spacja>\s*)(?<jednostka>'.$jednostki.')(?!\p{L}))/iu';

        if (preg_match($wzorzec, $tekst, $m) === 1) {
            return self::trafienie($m);
        }

        return null;
    }

    /**
     * @param  array<array-key, string>  $m
     * @return array{przed: string, calosc: string, od: string, separator: string, do: string, spacja: string, jednostka: string}
     */
    private static function trafienie(array $m): array
    {
        return [
            'przed' => $m['przed'],
            'calosc' => $m['calosc'],
            'od' => $m['od'],
            'separator' => $m['separator'] ?? '',
            'do' => $m['do'] ?? '',
            'spacja' => $m['spacja'] ?? '',
            'jednostka' => $m['jednostka'] ?? '',
        ];
    }

    /** „1,5”, „1 1/2”, „1½”, „¾”, „pół”, „półtorej” → liczba; nic sensownego → null. */
    private static function liczba(string $zapis): ?float
    {
        $zapis = trim(mb_strtolower($zapis));

        return match (true) {
            $zapis === 'pół' => 0.5,
            $zapis === 'półtora', $zapis === 'półtorej' => 1.5,
            default => self::liczbaZCyfr($zapis),
        };
    }

    private static function liczbaZCyfr(string $zapis): ?float
    {
        $calosci = 0.0;

        // Znak ułamka na końcu: „1½”, „1 ½”, „½”.
        foreach (self::ULAMKI_ZNAKI as $znak => $wartosc) {
            if (str_ends_with($zapis, $znak)) {
                $reszta = trim(mb_substr($zapis, 0, -1));

                return ($reszta === '' ? 0.0 : (float) $reszta) + $wartosc;
            }
        }

        // Liczba mieszana: „1 1/2”.
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $zapis, $m) === 1) {
            $calosci = (float) $m[1];
            $zapis = $m[2].'/'.$m[3];
        }

        if (preg_match('/^(\d+)\/(\d+)$/', $zapis, $m) === 1) {
            if ((int) $m[2] === 0) {
                return null;
            }

            return $calosci + (int) $m[1] / (int) $m[2];
        }

        $zapis = str_replace(',', '.', $zapis);

        return is_numeric($zapis) ? (float) $zapis : null;
    }
}

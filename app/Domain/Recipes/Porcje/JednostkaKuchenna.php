<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

use App\Support\Odmiana;

/**
 * Jednostki, które przelicznik porcji rozpoznaje w tekście składnika (D-284).
 *
 * TO NIE JEST TABELA `units`. Tamta jest słownikiem pod przyszłą
 * normalizację i przepis jej dziś nie wypełnia (`recipe_ingredients.unit_id`
 * jest puste w każdym wierszu z formularza). Tu stoi wyłącznie to, czego
 * trzeba, żeby w zdaniu napisanym przez człowieka — „2 łyżki masła” —
 * poprawnie podmienić liczbę i odmienić słowo za nią: „3 łyżki”, „5 łyżek”,
 * „1½ łyżki”.
 *
 * TRZY RODZAJE ZAOKRĄGLENIA, bo w kuchni liczy się inaczej gramy, a inaczej
 * łyżki:
 *  - `METRYCZNA` — g, dag, ml: liczby całkowite zaokrąglone do sensownego
 *    kroku (nikt nie odważy 237 g mąki, odważy 240 g);
 *  - `METRYCZNA_DUZA` — kg, l: ułamek dziesiętny z przecinkiem (1,25 kg);
 *  - `KUCHENNA` — łyżka, szklanka, sztuka i każda rzecz bez jednostki
 *    („2 jajka”): ułamki zwykłe ½ ¼ ¾ ⅓ ⅔, bo tak się mówi w kuchni.
 *
 * `NIEPOLICZALNA` (szczypta) się nie przelicza wcale: trzy szczypty soli
 * na potrojony przepis to nie jest to, co autor miał na myśli.
 */
final class JednostkaKuchenna
{
    public const METRYCZNA = 'metryczna';

    public const METRYCZNA_DUZA = 'metryczna_duza';

    public const KUCHENNA = 'kuchenna';

    public const NIEPOLICZALNA = 'niepoliczalna';

    /**
     * Klucz → [rodzaj, [1, 2–4, 5+, ułamek], skróty zostawiane bez odmiany].
     *
     * Forma „ułamek” to dopełniacz liczby pojedynczej — „½ szklanki”,
     * „1,5 litra”. Dla słów żeńskich na -ka jest taka sama jak forma 2–4.
     *
     * @var array<string, array{0: string, 1: array{0: string, 1: string, 2: string, 3: string}, 2: list<string>}>
     */
    private const JEDNOSTKI = [
        'g' => [self::METRYCZNA, ['gram', 'gramy', 'gramów', 'grama'], ['g', 'gr', 'gr.']],
        'dag' => [self::METRYCZNA, ['dekagram', 'dekagramy', 'dekagramów', 'dekagrama'], ['dag', 'dkg', 'dag.', 'dkg.']],
        'kg' => [self::METRYCZNA_DUZA, ['kilogram', 'kilogramy', 'kilogramów', 'kilograma'], ['kg', 'kg.']],
        'ml' => [self::METRYCZNA, ['mililitr', 'mililitry', 'mililitrów', 'mililitra'], ['ml', 'ml.']],
        'l' => [self::METRYCZNA_DUZA, ['litr', 'litry', 'litrów', 'litra'], ['l', 'l.']],
        'lyzka' => [self::KUCHENNA, ['łyżka', 'łyżki', 'łyżek', 'łyżki'], ['łyż.']],
        'lyzeczka' => [self::KUCHENNA, ['łyżeczka', 'łyżeczki', 'łyżeczek', 'łyżeczki'], ['łyżecz.']],
        'szklanka' => [self::KUCHENNA, ['szklanka', 'szklanki', 'szklanek', 'szklanki'], ['szkl.']],
        'filizanka' => [self::KUCHENNA, ['filiżanka', 'filiżanki', 'filiżanek', 'filiżanki'], []],
        'kieliszek' => [self::KUCHENNA, ['kieliszek', 'kieliszki', 'kieliszków', 'kieliszka'], []],
        'kostka' => [self::KUCHENNA, ['kostka', 'kostki', 'kostek', 'kostki'], []],
        'puszka' => [self::KUCHENNA, ['puszka', 'puszki', 'puszek', 'puszki'], []],
        'paczka' => [self::KUCHENNA, ['paczka', 'paczki', 'paczek', 'paczki'], []],
        'torebka' => [self::KUCHENNA, ['torebka', 'torebki', 'torebek', 'torebki'], []],
        'opakowanie' => [self::KUCHENNA, ['opakowanie', 'opakowania', 'opakowań', 'opakowania'], ['op.']],
        'sloik' => [self::KUCHENNA, ['słoik', 'słoiki', 'słoików', 'słoika'], []],
        'butelka' => [self::KUCHENNA, ['butelka', 'butelki', 'butelek', 'butelki'], []],
        'sztuka' => [self::KUCHENNA, ['sztuka', 'sztuki', 'sztuk', 'sztuki'], ['szt.', 'szt']],
        'zabek' => [self::KUCHENNA, ['ząbek', 'ząbki', 'ząbków', 'ząbka'], []],
        'glowka' => [self::KUCHENNA, ['główka', 'główki', 'główek', 'główki'], []],
        'plaster' => [self::KUCHENNA, ['plaster', 'plastry', 'plastrów', 'plastra'], []],
        'plasterek' => [self::KUCHENNA, ['plasterek', 'plasterki', 'plasterków', 'plasterka'], []],
        'kromka' => [self::KUCHENNA, ['kromka', 'kromki', 'kromek', 'kromki'], []],
        'peczek' => [self::KUCHENNA, ['pęczek', 'pęczki', 'pęczków', 'pęczka'], []],
        'garsc' => [self::KUCHENNA, ['garść', 'garście', 'garści', 'garści'], []],
        'listek' => [self::KUCHENNA, ['listek', 'listki', 'listków', 'listka'], []],
        'galazka' => [self::KUCHENNA, ['gałązka', 'gałązki', 'gałązek', 'gałązki'], []],
        'szczypta' => [self::NIEPOLICZALNA, ['szczypta', 'szczypty', 'szczypt', 'szczypty'], []],
    ];

    private function __construct(
        public readonly string $klucz,
        public readonly string $rodzaj,
        /** Czy w tekście stał skrót („g”, „szt.”) — skrótu się nie odmienia. */
        public readonly bool $skrot,
    ) {}

    /**
     * Wzorzec wszystkich form do wyrażenia regularnego — najdłuższe najpierw,
     * żeby „łyżeczki” nie zostało przeczytane jako „łyżeczka” + „i”.
     */
    public static function wzorzec(): string
    {
        static $wzorzec = null;

        if ($wzorzec !== null) {
            return $wzorzec;
        }

        $formy = array_keys(self::mapaForm());
        usort($formy, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $wzorzec = implode('|', array_map(static fn (string $f): string => preg_quote($f, '/'), $formy));
    }

    /**
     * Pierwsze formy („szklanka”, „łyżka”, „litr”) jednostek, które się
     * przelicza — do rozpoznania wiersza „szklanka mąki” jako JEDNEJ szklanki.
     * Bez skrótów: „g mąki” bez liczby nie jest niczym.
     */
    public static function wzorzecMianownika(): string
    {
        $formy = [];

        foreach (self::JEDNOSTKI as [$rodzaj, $odmiana]) {
            if ($rodzaj !== self::NIEPOLICZALNA) {
                $formy[] = $odmiana[0];
            }
        }

        usort($formy, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', array_map(static fn (string $f): string => preg_quote($f, '/'), $formy));
    }

    /** Jednostka dla formy znalezionej w tekście, bez względu na wielkość liter. */
    public static function zFormy(string $forma): ?self
    {
        $mapa = self::mapaForm();
        $klucz = mb_strtolower($forma);

        if (! isset($mapa[$klucz])) {
            return null;
        }

        [$jednostka, $skrot] = $mapa[$klucz];

        return new self($jednostka, self::JEDNOSTKI[$jednostka][0], $skrot);
    }

    /**
     * Forma słowa dla pokazywanej liczby. Skrót zostaje taki, jaki napisał
     * autor — „200 g” po przeliczeniu to „300 g”, nie „300 gramów”.
     */
    public function forma(float $ile, string $oryginal): string
    {
        $odmiana = self::JEDNOSTKI[$this->klucz][1];

        if ($this->skrot) {
            return $oryginal;
        }

        $calkowita = abs($ile - round($ile)) < 0.001;

        $forma = $calkowita
            ? Odmiana::rzeczownik((int) round($ile), $odmiana[0], $odmiana[1], $odmiana[2])
            : $odmiana[3];

        // „Łyżka” na początku zdania autora zostaje z wielkiej litery.
        if (mb_substr($oryginal, 0, 1) !== mb_strtolower(mb_substr($oryginal, 0, 1))) {
            return mb_strtoupper(mb_substr($forma, 0, 1)).mb_substr($forma, 1);
        }

        return $forma;
    }

    /** @return array<string, array{0: string, 1: bool}> forma małymi literami → [klucz, czy skrót] */
    private static function mapaForm(): array
    {
        static $mapa = null;

        if ($mapa !== null) {
            return $mapa;
        }

        $mapa = [];

        foreach (self::JEDNOSTKI as $klucz => [, $odmiana, $skroty]) {
            foreach ($odmiana as $forma) {
                $mapa[$forma] = [$klucz, false];
            }

            foreach ($skroty as $skrot) {
                $mapa[$skrot] = [$klucz, true];
            }
        }

        return $mapa;
    }
}

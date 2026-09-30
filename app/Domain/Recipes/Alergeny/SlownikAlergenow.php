<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Alergeny;

use Illuminate\Support\Str;

/**
 * Słownik PODPOWIEDZI alergenów (etap 2 z #1902, D-333).
 *
 * DETERMINISTYCZNY, bez AI i bez sieci: stała lista wzorców na nazwach
 * składników. Wynik to wyłącznie PODPOWIEDŹ dla autora — kody, które warto
 * rozważyć, wraz z fragmentem składnika, który je wywołał. Zasady, które
 * mają być prawdziwe na każdym ekranie korzystającym z tej klasy:
 *
 *  1. Podpowiedź jest ZAWSZE niezaznaczona. Nic, co zwraca ta klasa, nie
 *     trafia do `recipes.allergens` bez kliknięcia autora i bez pola
 *     „Składniki sprawdzone” (`OznaczAlergenyPrzepisu`).
 *  2. Brak podpowiedzi NIC NIE ZNACZY. Słownik nie zna sosów, kiełbas,
 *     gotowych mieszanek ani nazw własnych; dlatego żaden ekran nie mówi
 *     „nie wykryto” ani nie zamienia pustego wyniku w jakikolwiek komunikat.
 *     Klasa zwraca po prostu pustą tablicę.
 *  3. Wolno mylić się w stronę PODPOWIEDZI, której autor nie potrzebuje
 *     (męczy), nie w stronę ciszy (uspokaja) — ale każdy wzorzec z dwuznaczną
 *     nazwą ma wykluczenia i test regresyjny (`SlownikAlergenowTest`).
 *
 * Jak dopasowujemy. Tekst składnika zamieniamy na tokeny: małe litery, bez
 * ogonków, bez znaków niealfanumerycznych (`mąki pszennej` → `maki pszennej`).
 * Wzorce czyta się na POJEDYNCZYM tokenie (początek słowa łapie polską
 * fleksję: `smietan` → śmietana, śmietanki, śmietanką). Wykluczenie patrzy
 * na token poprzedni i dwa następne W OBRĘBIE TEJ SAMEJ FRAZY (tekst jest
 * dzielony na przecinkach i średnikach: „mąka pszenna, ryż” to dwa składniki)
 * — tyle, ile potrzeba dla „mleko
 * kokosowe”, „masło orzechowe”, „mąka ryżowa”, „orzech muszkatołowy”.
 * Token poprzedzony słowem „bez” nie podpowiada nic („bez mleka”).
 * Dwuwyrazowe nazwy, które mają inny alergen niż ich słowa (`masło orzechowe`
 * = orzeszki ziemne, `ocet winny` = siarczyny), są we frazach.
 */
final class SlownikAlergenow
{
    /**
     * Wzorce tokenów i wykluczeń (sąsiednie tokeny) dla każdego alergenu.
     * Wszystko na tekście po zamianie na ASCII.
     */
    private const TOKENY = [
        'gluten' => [
            'tokeny' => [
                '/^(maka|maki|make)$/', '/^pszeni/', '/^zytn/', '/^zyto/', '/^jeczmi/', '/^owsian/', '/^owies/',
                '/^orkisz/', '/^peczak/', '/^makaron/', '/^kuskus/', '/^bulgur/', '/^mann[aey]$/', '/^chleb/',
                '/^bulk[aiek]/', '/^bulce$/', '/^pieczyw/', '/^panierk/', '/^piwo$/', '/^piwa$/', '/^seitan/',
                '/^otreb/', '/^grzank/', '/^sucharki/', '/^krakers/', '/^biszkopt/',
            ],
            'wyklucz' => [
                '/^ryz/', '/^kukurydz/', '/^gryczan/', '/^ziemniacz/', '/^kokos/', '/^migdal/', '/^cieciorz/',
                '/^jaglan/', '/^bezgluten/', '/^amarant/', '/^kasztan/', '/^sojow/', '/^skrobi/', '/^tapiok/',
            ],
        ],
        'crustaceans' => [
            'tokeny' => ['/^krewet/', '/^krab/', '/^homar/', '/^langust/', '/^skorupiak/', '/^scampi/'],
            'wyklucz' => [],
        ],
        'eggs' => [
            'tokeny' => ['/^jaj/', '/^zoltk/', '/^majonez/', '/^(beza|bezy|bezami)$/', '/^ajerkoniak/'],
            'wyklucz' => [],
        ],
        'fish' => [
            'tokeny' => [
                '/^ryb/', '/^dorsz/', '/^losos/', '/^tunczyk/', '/^sledz/', '/^sledzi/', '/^makrel/', '/^sardyn/',
                '/^sardel/', '/^anchois/', '/^pstrag/', '/^karp(ia|ie|iem|)$/', '/^szczupak/', '/^sandacz/',
                '/^halibut/', '/^mintaj/', '/^surimi/', '/^kawior/',
            ],
            'wyklucz' => [],
        ],
        'peanuts' => [
            'tokeny' => ['/^orzeszk/', '/^arachid/'],
            'wyklucz' => ['/^pini/'],
        ],
        'soy' => [
            'tokeny' => ['/^soja/', '/^soi$/', '/^soje/', '/^sojow/', '/^tofu/', '/^edamame/', '/^tempeh/', '/^miso$/', '/^natto/', '/^teriyaki/'],
            'wyklucz' => [],
        ],
        'milk' => [
            'tokeny' => [
                '/^mlek/', '/^mleczn/', '/^mleczk/', '/^smietan/', '/^(maslo|masla|maslem|masle)$/', '/^maslank/',
                '/^jogurt/', '/^kefir/', '/^twarog/', '/^twarozk/', '/^(ser|sera|serem|sery|serow\w*|serek|serka|serkiem|serki)$/',
                '/^mozzarell/', '/^mascarpone/', '/^parmezan/', '/^ricott/', '/^(feta|fety)$/', '/^gouda/',
                '/^cheddar/', '/^camembert/', '/^brie$/', '/^serwatk/', '/^kazein/',
            ],
            'wyklucz' => [
                '/^kokos/', '/^sojow/', '/^migdal/', '/^owsian/', '/^ryzow/', '/^orzech/', '/^roslin/', '/^wegansk/',
                '/^kakaow/', '/^shea/', '/^sezam/', '/^arachid/', '/^laskow/', '/^konopn/', '/^gryczan/',
            ],
        ],
        'nuts' => [
            'tokeny' => [
                '/^migdal/', '/^orzech/', '/^laskow/', '/^nerkowc/', '/^pistacj/', '/^pekan/', '/^makadam/',
                '/^nutell/', '/^marcepan/', '/^pralin/', '/^gianduj/', '/^amaretto$/', '/^amaretti$/',
            ],
            'wyklucz' => ['/^kokos/', '/^muszkatol/', '/^ziemn/', '/^pini/', '/^masl/'],
        ],
        'celery' => [
            'tokeny' => ['/^seler/'],
            'wyklucz' => [],
        ],
        'mustard' => [
            'tokeny' => ['/^gorczyc/', '/^musztard/', '/^dijon/'],
            'wyklucz' => [],
        ],
        'sesame' => [
            'tokeny' => ['/^sezam/', '/^tahin/', '/^gomasio/'],
            'wyklucz' => [],
        ],
        'sulphites' => [
            'tokeny' => ['/^siarczyn/', '/^(wino|wina|winem|winie)$/'],
            'wyklucz' => [],
        ],
        'lupin' => [
            'tokeny' => ['/^lubin/'],
            'wyklucz' => [],
        ],
        'molluscs' => [
            'tokeny' => ['/^malz/', '/^ostryg/', '/^kalmar/', '/^osmiorni/', '/^przegrzebk/', '/^mieczak/', '/^slimak/', '/^chobotnic/', '/^omulk/'],
            'wyklucz' => [],
        ],
    ];

    /**
     * Nazwy wieloczłonowe, w których alergen wynika z CAŁEJ nazwy. Wzorzec
     * czytany na całym znormalizowanym tekście składnika.
     */
    private const FRAZY = [
        'peanuts' => ['/\bmasl\w* orzechow/', '/\borzech\w* ziemn/', '/\bpasta orzechow/'],
        'sulphites' => ['/\boct\w* (winn|balsamicz)/', '/\bmorel\w* suszon/', '/\bsuszon\w* morel/', '/\bdwutlenek siarki/'],
        // Sos sojowy zawiera zwykle pszenicę; sam token „sojowy” podpowiada soję.
        'gluten' => ['/\bsos\w* sojow\w*+(?! bezgluten)/'],
    ];

    /** Słowo przed tokenem, które odwraca sens („bez mleka”). */
    private const PRZECZENIE = '/^bez$/';

    /**
     * @param  list<string>  $skladniki  teksty składników (i zamienników) tak, jak wpisał autor
     * @return array<string, list<string>> kod alergenu → fragmenty składników, w których słownik
     *                                     widzi ten alergen; tylko niepuste wpisy, w kolejności słownika
     */
    public static function podpowiedzi(array $skladniki): array
    {
        $wynik = [];

        foreach ($skladniki as $tekst) {
            $tekst = trim($tekst);
            if ($tekst === '') {
                continue;
            }

            // Wykluczenia i „bez” działają tylko W OBRĘBIE FRAZY (do przecinka
            // albo średnika): „mąka pszenna, ryż” to dwa składniki, więc ryż
            // nie może wyciszać podpowiedzi glutenu z mąki.
            $kodyTekstu = [];
            foreach ((array) preg_split('/[,;]+/u', $tekst) as $fraza) {
                $znormalizowany = self::normalizuj((string) $fraza);
                $tokeny = $znormalizowany === '' ? [] : explode(' ', $znormalizowany);

                foreach (self::kodyDla($znormalizowany, $tokeny) as $kod) {
                    $kodyTekstu[$kod] = true;
                }
            }

            foreach (array_keys($kodyTekstu) as $kod) {
                $fragment = mb_strimwidth($tekst, 0, 60, '…');
                if (! in_array($fragment, $wynik[$kod] ?? [], true)) {
                    $wynik[$kod][] = $fragment;
                }
            }
        }

        $uporzadkowany = [];
        foreach (Alergen::kody() as $kod) {
            if (isset($wynik[$kod])) {
                $uporzadkowany[$kod] = $wynik[$kod];
            }
        }

        return $uporzadkowany;
    }

    /**
     * @param  list<string>  $tokeny
     * @return list<string>
     */
    private static function kodyDla(string $znormalizowany, array $tokeny): array
    {
        $kody = [];

        foreach (self::TOKENY as $kod => $regula) {
            foreach ($tokeny as $i => $token) {
                if (! self::pasujeDoKtoregos($regula['tokeny'], $token)) {
                    continue;
                }
                if (isset($tokeny[$i - 1]) && preg_match(self::PRZECZENIE, $tokeny[$i - 1]) === 1) {
                    continue;
                }
                if (self::wykluczony($regula['wyklucz'], $tokeny, $i)) {
                    continue;
                }

                $kody[$kod] = true;
                break;
            }
        }

        foreach (self::FRAZY as $kod => $wzorce) {
            foreach ($wzorce as $wzorzec) {
                if (preg_match($wzorzec, $znormalizowany) === 1) {
                    $kody[$kod] = true;
                    break;
                }
            }
        }

        return array_keys($kody);
    }

    /** @param  list<string>  $wzorce */
    private static function pasujeDoKtoregos(array $wzorce, string $token): bool
    {
        foreach ($wzorce as $wzorzec) {
            if (preg_match($wzorzec, $token) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $wyklucz
     * @param  list<string>  $tokeny
     */
    private static function wykluczony(array $wyklucz, array $tokeny, int $i): bool
    {
        if ($wyklucz === []) {
            return false;
        }

        foreach ([$i - 1, $i + 1, $i + 2] as $j) {
            if (isset($tokeny[$j]) && self::pasujeDoKtoregos($wyklucz, $tokeny[$j])) {
                return true;
            }
        }

        return false;
    }

    private static function normalizuj(string $tekst): string
    {
        $ascii = Str::ascii(mb_strtolower($tekst));

        return Str::squish((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use App\Models\AliasSkladnika;
use App\Models\SkladnikOdzywczy;

/**
 * Dopasowuje polską nazwę z przepisu do pozycji tabeli wartości (D-299).
 *
 * JAK: z nazwy („letniej wody”, „maki pszennej chlebowej typ 750”) robimy
 * wszystkie ciągłe fragmenty do sześciu słów i pytamy bazę JEDNYM
 * zapytaniem o te, które są aliasami. Wygrywa NAJDŁUŻSZY trafiony
 * fragment, przy remisie — bliższy początku. Dzięki temu „sok z cytryny”
 * trafia w sok, a nie w cytrynę, a „mąki pszennej chlebowej typ 750”
 * w mąkę pszenną mimo dopisków, których słownik nie zna.
 *
 * DWIE GRANICE, ŻEBY NIE ZGADYWAĆ:
 *  - słowa PRZED trafionym fragmentem wolno pominąć tylko wtedy, gdy są
 *    zwykłym opisem („letniej”, „świeżych”, „dużych”, „roztopionego”).
 *    „ser kozi” nie zamieni się w „kozi”, a „mleko kokosowe” w „mleko”;
 *  - słowa PO fragmencie wolno pominąć, chyba że zmieniają produkt
 *    („kokosowe”, „orzechowe”, „sojowe”, „migdałowe”, „owsiane”, „ryżowe”).
 *
 * Brak dopasowania to nie błąd, tylko „tego składnika nie ma w tabeli” —
 * kalkulator liczy go do masy, której nie zna.
 */
final class SlownikSkladnikow
{
    private const NAJDLUZSZY_FRAGMENT = 6;

    /** Tematy przymiotników opisowych, które wolno pominąć przed nazwą. */
    private const OPIS_PRZED = '/^(swiez|duz|mal|nieduz|niewielk|spor|dorodn|okazal|sredn|drobn|letn|ciepl|zimn|gorac|dojrzal|obran|posiekan|pokrojon|roztopion|rozpuszczon|aktywn|dobr|ulubion|domow|zwykl|umyt|wypestkowan|mrozon|mielon|startego|drobno|grubo|pokrojon|ugotowan|upieczon|wczorajsz|kupn|ekologiczn|wiejsk|mlod|twardego|miekk|czerwon|bial|zolt|ciemn|jasn)(y|a|e|ego|ej|ych|ym|ymi|i|ie|ich|iej|iego|o)?$/';

    /** Słowa po nazwie, które zmieniają produkt. */
    private const ZMIENIA_PRODUKT = '/^(kokosow|orzechow|sojow|migdalow|owsian|ryzow|roslinn|arachidow|sezamow|lniane|lnian|konopn)/';

    /** Słowa-wypełniacze przed nazwą: „trochę soli”, „kilka pomidorów”. */
    private const WYPELNIACZE = ['troche', 'odrobina', 'odrobine', 'odrobiny', 'kilka', 'pare', 'nieco', 'sporo', 'duzo', 'malo', 'np', 'np.', 'najlepiej', 'ewentualnie'];

    /**
     * @param  list<string>  $nazwy  nazwy po `ParserSkladnika::oczyscNazwe()`
     * @return array<string, SkladnikOdzywczy|null> nazwa → pozycja tabeli albo null
     */
    public function dopasuj(array $nazwy): array
    {
        $nazwy = array_values(array_unique(array_filter($nazwy, static fn (string $n): bool => $n !== '')));
        $fragmentyNazw = [];
        $wszystkie = [];

        foreach ($nazwy as $nazwa) {
            $fragmentyNazw[$nazwa] = self::fragmenty($nazwa);
            foreach ($fragmentyNazw[$nazwa] as $f) {
                $wszystkie[$f['tekst']] = true;
            }
        }

        $aliasy = $wszystkie === []
            ? collect()
            : AliasSkladnika::query()
                ->whereIn('alias', array_keys($wszystkie))
                ->with('skladnik.miary')
                ->get()
                ->keyBy('alias');

        $wynik = [];
        foreach ($nazwy as $nazwa) {
            $wynik[$nazwa] = null;
            foreach ($fragmentyNazw[$nazwa] as $f) {
                $alias = $aliasy->get($f['tekst']);
                if ($alias instanceof AliasSkladnika && $alias->skladnik !== null) {
                    $wynik[$nazwa] = $alias->skladnik;
                    break;
                }
            }
        }

        return $wynik;
    }

    /**
     * Fragmenty w kolejności pierwszeństwa: najdłuższe najpierw, przy
     * równej długości — bliżej początku. Tylko te, które spełniają obie
     * granice z opisu klasy.
     *
     * @return list<array{tekst: string, od: int, dlugosc: int}>
     */
    public static function fragmenty(string $nazwa): array
    {
        $slowa = explode(' ', $nazwa);
        $ile = count($slowa);
        $fragmenty = [];

        for ($dlugosc = min(self::NAJDLUZSZY_FRAGMENT, $ile); $dlugosc >= 1; $dlugosc--) {
            for ($od = 0; $od + $dlugosc <= $ile; $od++) {
                if (! self::moznaPominac(array_slice($slowa, 0, $od), array_slice($slowa, $od + $dlugosc))) {
                    continue;
                }
                $fragmenty[] = ['tekst' => implode(' ', array_slice($slowa, $od, $dlugosc)), 'od' => $od, 'dlugosc' => $dlugosc];
            }
        }

        return $fragmenty;
    }

    /**
     * @param  list<string>  $przed
     * @param  list<string>  $po
     */
    private static function moznaPominac(array $przed, array $po): bool
    {
        foreach ($przed as $slowo) {
            if (! in_array($slowo, self::WYPELNIACZE, true) && preg_match(self::OPIS_PRZED, $slowo) !== 1) {
                return false;
            }
        }

        foreach ($po as $slowo) {
            if (preg_match(self::ZMIENIA_PRODUKT, $slowo) === 1) {
                return false;
            }
        }

        return true;
    }

    /** Alias w postaci, w jakiej parser zostawia nazwę. */
    public static function normalizujAlias(string $alias): string
    {
        return ParserSkladnika::oczyscNazwe(ParserSkladnika::normalizuj($alias));
    }
}

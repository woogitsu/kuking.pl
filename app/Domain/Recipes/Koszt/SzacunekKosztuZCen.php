<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Koszt;

use App\Models\CenaSkladnika;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Support\Str;

/**
 * Orientacyjny koszt dania z cennika składników — gdy autor nie podał
 * własnej kwoty (D-286, część 2; projekt: `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §8.4).
 *
 * Liczone w PHP, deterministycznie: te same składniki i ten sam cennik dają
 * zawsze ten sam przedział. Bez modelu językowego.
 *
 * KIEDY NIE LICZYMY — i mówimy dlaczego:
 *  - któryś składnik nie ma ilości, z której da się ustalić masę
 *    („mąka, ile weźmie", „garść koperku", „2 cebule" bez wagi sztuki);
 *  - składniki, dla których znamy cenę, to mniej niż 90% masy przepisu.
 *
 * Nie prosimy autora o gramy „żeby kalkulator działał" — przepis domowy
 * ma prawo mówić „szklanka" i „do smaku".
 *
 * KIEDY MILCZYMY całkiem: żaden składnik nie trafił w cennik. Zdanie „nie
 * liczymy" przy każdym przepisie z warzywami (GUS ich dziś nie podaje)
 * byłoby szumem, nie informacją.
 *
 * Czego świadomie nie liczymy do masy:
 *  - `no_amount` („sól do smaku") i składniki bez ilości z listy drobiazgów
 *    (sól, pieprz, zioła, „do smaku", „do posypania") — ich koszt jest
 *    pomijalny, a zatrzymywanie na nich szacunku byłoby karą za normalny
 *    zapis przepisu;
 *  - wodę — nic nie kosztuje, a jej litry sztucznie podbijałyby pokrycie
 *    (rosół: 3 l wody i kurczak dawałyby „90% masy" bez ani jednej
 *    marchewki).
 */
final class SzacunekKosztuZCen
{
    /** Próg pokrycia masy przepisu składnikami z ceną. */
    public const PROG_POKRYCIA = 0.9;

    /** Rozrzut przedziału wokół wyliczonej kwoty. */
    public const ROZRZUT = 0.15;

    /** Masa miar domowych dla składnika, którego NIE znamy (tylko do pokrycia). */
    private const MASA_OGOLNA = ['szklanka' => 200.0, 'lyzka' => 12.0, 'lyzeczka' => 5.0, 'szczypta' => 0.5];

    /** Składniki bez ilości, których koszt jest pomijalny (formy ASCII, całe słowa). */
    private const DROBIAZGI = [
        'sol', 'soli', 'pieprz', 'pieprzu', 'lisc laurowy', 'liscie laurowe', 'lisci laurowych', 'ziele angielskie',
        'ziela angielskiego', 'natka', 'natki', 'koperek', 'koperku', 'szczypiorek', 'szczypiorku', 'majeranek',
        'majeranku', 'oregano', 'bazylia', 'bazylii', 'tymianek', 'tymianku', 'cynamon', 'cynamonu',
        'galka muszkatolowa', 'galki muszkatolowej', 'papryka slodka', 'papryki slodkiej', 'przyprawy', 'przyprawa',
        'do smaku', 'ile wezmie', 'opcjonalnie', 'do podania', 'do dekoracji', 'do posypania', 'do smazenia',
        'do posmarowania', 'do wysmarowania',
    ];

    /** Kody `units.code` → miara z `IloscZTekstu` i mnożnik. */
    private const KODY_JEDNOSTEK = [
        'g' => ['g', 1], 'dag' => ['g', 10], 'kg' => ['g', 1000], 'ml' => ['ml', 1], 'l' => ['ml', 1000],
        'szklanka' => ['szklanka', 1], 'lyzka' => ['lyzka', 1], 'lyzeczka' => ['lyzeczka', 1],
        'szczypta' => ['szczypta', 1], 'szt' => ['sztuka', 1],
    ];

    public function __construct(private readonly CennikSkladnikow $cennik) {}

    /**
     * `null` — nic do powiedzenia (brak składników, pusty cennik albo żaden
     * składnik nie trafił w cennik).
     */
    public function dla(Recipe $recipe): ?WynikSzacunku
    {
        $recipe->loadMissing('ingredients.unit');

        if ($recipe->ingredients->isEmpty() || $this->cennik->pusty()) {
            return null;
        }

        $pokryte = 0.0;
        $niepokryte = 0.0;
        $koszt = 0.0;
        $okresy = [];
        $bezMasy = [];
        $bezCeny = [];

        foreach ($recipe->ingredients as $skladnik) {
            if ($skladnik->no_amount) {
                continue;
            }

            $tekst = (string) $skladnik->ingredient_text;
            $ilosc = $this->ilosc($skladnik);

            if ($ilosc === null) {
                if (! $this->drobiazg($tekst)) {
                    $bezMasy[] = $tekst;
                }

                continue;
            }

            $produkt = $this->cennik->dopasuj($tekst);
            $gramy = $produkt === null ? null : $this->gramyProduktu($ilosc, $produkt);

            if ($produkt !== null && $gramy !== null) {
                if ($produkt->bezplatny()) {
                    continue;
                }

                $pokryte += $gramy;
                $koszt += $gramy * $produkt->cenaZaGram();
                $okresy[] = $produkt->okres;

                continue;
            }

            $ogolne = $this->gramyOgolne($ilosc);

            // „Garść koperku", „szczypta soli" — drobiazg także z ilością.
            if ($produkt === null && ($ogolne === null || $ogolne <= self::MASA_OGOLNA['lyzeczka']) && $this->drobiazg($tekst)) {
                continue;
            }

            if ($ogolne === null) {
                $bezMasy[] = $tekst;
            } else {
                $niepokryte += $ogolne;
                $bezCeny[] = $tekst;
            }
        }

        if ($pokryte <= 0.0) {
            return null;
        }

        if ($bezMasy !== []) {
            return WynikSzacunku::bez(
                'Nie podajemy orientacyjnego kosztu: nie wiemy, ile waży '.$this->przyklad($bezMasy)
                .'. To nie błąd — tak się pisze przepisy.',
            );
        }

        if ($pokryte / ($pokryte + $niepokryte) < self::PROG_POKRYCIA) {
            return WynikSzacunku::bez(
                'Nie podajemy orientacyjnego kosztu: nie mamy średnich cen części składników, na przykład '
                .$this->przyklad($bezCeny).'.',
            );
        }

        $od = max(0, (int) floor($koszt * (1 - self::ROZRZUT)));
        $do = (int) ceil($koszt * (1 + self::ROZRZUT));

        if ($do <= $od) {
            $do = $od + 1;
        }

        return WynikSzacunku::przedzial($od, $do, $this->opisOkresu($okresy));
    }

    /**
     * @return array{ilosc: float, miara: string}|null
     */
    private function ilosc(RecipeIngredient $skladnik): ?array
    {
        // Rozbite pola mają pierwszeństwo — jeśli kiedyś je ktoś wypełni,
        // są pewniejsze niż odczyt tekstu.
        if ($skladnik->quantity !== null && $skladnik->quantity > 0 && $skladnik->unit !== null) {
            $kod = (string) $skladnik->unit->code;
            [$miara, $mnoznik] = self::KODY_JEDNOSTEK[$kod] ?? ['nieprzeliczalna', 1];

            return ['ilosc' => (float) $skladnik->quantity * $mnoznik, 'miara' => $miara];
        }

        return IloscZTekstu::rozbierz((string) $skladnik->ingredient_text);
    }

    /**
     * Masa w gramach wg miar TEGO składnika; `null`, gdy cennik nie zna
     * jego miary (np. „łyżka kiełbasy").
     *
     * @param  array{ilosc: float, miara: string}  $ilosc
     */
    private function gramyProduktu(array $ilosc, CenaSkladnika $produkt): ?float
    {
        $ile = $ilosc['ilosc'];

        return match ($ilosc['miara']) {
            'g' => $ile,
            'ml' => $produkt->jednostka === 'l'
                ? $ile * $produkt->g_na_jednostke / 1000
                : ($produkt->g_szklanka !== null ? $ile * $produkt->g_szklanka / 250 : null),
            'szklanka' => $produkt->g_szklanka !== null ? $ile * $produkt->g_szklanka : null,
            'lyzka' => $produkt->g_lyzka !== null ? $ile * $produkt->g_lyzka : null,
            'lyzeczka' => $produkt->g_lyzeczka !== null ? $ile * $produkt->g_lyzeczka : null,
            'sztuka' => $produkt->g_sztuka !== null ? $ile * $produkt->g_sztuka : null,
            default => null,
        };
    }

    /**
     * Przybliżona masa składnika spoza cennika — wyłącznie do liczenia
     * pokrycia. `null` dla sztuk i miar bez wagi.
     *
     * @param  array{ilosc: float, miara: string}  $ilosc
     */
    private function gramyOgolne(array $ilosc): ?float
    {
        return match ($ilosc['miara']) {
            'g', 'ml' => $ilosc['ilosc'],
            'szklanka', 'lyzka', 'lyzeczka', 'szczypta' => $ilosc['ilosc'] * self::MASA_OGOLNA[$ilosc['miara']],
            default => null,
        };
    }

    private function drobiazg(string $tekst): bool
    {
        $t = ' '.IloscZTekstu::normalizuj($tekst).' ';

        foreach (self::DROBIAZGI as $fraza) {
            if (preg_match('/(?<![a-z])'.preg_quote($fraza, '/').'(?![a-z])/', $t) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $teksty
     */
    private function przyklad(array $teksty): string
    {
        $pierwszy = '„'.Str::limit(trim($teksty[0]), 60).'”';
        $reszta = count($teksty) - 1;

        return $reszta > 0 ? $pierwszy.' i '.$reszta.' '.($reszta === 1 ? 'innego składnika' : 'innych składników') : $pierwszy;
    }

    /**
     * @param  list<string>  $okresy
     */
    private function opisOkresu(array $okresy): string
    {
        $okresy = array_values(array_unique($okresy));
        sort($okresy);

        return 'średnie ceny detaliczne GUS z '.implode(', ', $okresy).' r.';
    }
}

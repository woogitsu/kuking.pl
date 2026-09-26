<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\SkladnikOdzywczy;

/**
 * Szacuje kcal, białko, tłuszcz i węglowodany przepisu — deterministycznie,
 * w PHP, z tabeli CIQUAL/USDA, bez modelu AI (D-299).
 *
 * KOLEJNOŚĆ DLA JEDNEGO WIERSZA:
 *  1. „Bez ilości” (`no_amount`, issue #44) → pomijamy jawnie;
 *  2. ilość i jednostka: z kolumn `quantity`/`unit_id`, jeśli kiedyś będą
 *     wypełnione, inaczej z tekstu (`ParserSkladnika`);
 *  3. nazwa → pozycja tabeli (`SlownikSkladnikow`);
 *  4. gramy: waga w nawiasie › g/dag/kg › szczypta (0,5 g) › miara domowa
 *     składnika › objętość × gęstość składnika;
 *  5. brak liczby: „do smaku” albo składnik pomijalny (sól, pieprz, zioła,
 *     woda) → pomijamy; każdy inny → „bez znanej masy”, i wtedy przepisu
 *     nie liczymy wcale, bo nie wiemy, ile waży całość.
 *
 * WYNIK NA PORCJĘ z `recipes.servings`. Przeliczenie przepisu na inną
 * liczbę porcji (skalowanie porcji, V2) mnoży wszystkie składniki tym samym
 * mnożnikiem, więc wartości NA JEDNĄ PORCJĘ się od niego nie zmieniają —
 * sekcja nie musi wiedzieć, na ile porcji ktoś właśnie patrzy.
 */
final class KalkulatorWartosci
{
    public function __construct(
        private readonly ParserSkladnika $parser,
        private readonly SlownikSkladnikow $slownik,
    ) {}

    public function policz(Recipe $recipe): WynikWartosci
    {
        $recipe->loadMissing('ingredients.unit');

        /** @var iterable<RecipeIngredient> $skladniki */
        $skladniki = $recipe->ingredients;
        $odczyty = [];
        $nazwy = [];

        foreach ($skladniki as $skladnik) {
            $odczyt = $this->parser->odczytaj((string) $skladnik->ingredient_text);
            $odczyt = $this->zKolumnStrukturalnych($skladnik, $odczyt);
            $odczyty[] = [$skladnik, $odczyt];
            $nazwy[] = $odczyt->nazwa;
            if ($odczyt->nazwaZJednostka !== null) {
                $nazwy[] = $odczyt->nazwaZJednostka;
            }
        }

        $dopasowania = $this->slownik->dopasuj($nazwy);
        $wiersze = [];
        $suma = ['kcal' => 0.0, 'bialko' => 0.0, 'tluszcz' => 0.0, 'weglowodany' => 0.0];

        foreach ($odczyty as [$skladnik, $odczyt]) {
            $pozycja = $dopasowania[$odczyt->nazwa] ?? null;
            $jednostka = $odczyt->jednostka;
            $ilosc = $odczyt->ilosc;

            // „2 liście laurowe”: słowo jednostki bywa częścią nazwy.
            if ($pozycja === null && $odczyt->nazwaZJednostka !== null) {
                $pozycja = $dopasowania[$odczyt->nazwaZJednostka] ?? null;
                if ($pozycja !== null && ! isset(JednostkiMiary::MASA[(string) $jednostka]) && ! isset(JednostkiMiary::OBJETOSC[(string) $jednostka])) {
                    $jednostka = $this->maMiare($pozycja, (string) $jednostka) ? $jednostka : 'szt';
                }
            }

            $tekst = (string) $skladnik->ingredient_text;

            if ($skladnik->no_amount) {
                $wiersze[] = new WierszWyliczenia($tekst, WierszWyliczenia::POMINIETY, $pozycja?->klucz, null);

                continue;
            }

            $gramy = $this->gramy($odczyt, $ilosc, $jednostka, $pozycja);

            if ($gramy === null) {
                $pomin = ($ilosc === null && $odczyt->gramyZNawiasu === null)
                    && ($odczyt->bezIlosciZTekstu || ($pozycja !== null && $pozycja->pomijalny));
                $wiersze[] = new WierszWyliczenia($tekst, $pomin ? WierszWyliczenia::POMINIETY : WierszWyliczenia::BEZ_MASY, $pozycja?->klucz, null);

                continue;
            }

            if ($pozycja === null) {
                $wiersze[] = new WierszWyliczenia($tekst, WierszWyliczenia::NIEZNANY_SKLADNIK, null, $gramy);

                continue;
            }

            $wiersze[] = new WierszWyliczenia($tekst, WierszWyliczenia::POLICZONY, $pozycja->klucz, $gramy);
            $suma['kcal'] += $pozycja->kcal_100g * $gramy / 100;
            $suma['bialko'] += $pozycja->bialko_100g * $gramy / 100;
            $suma['tluszcz'] += $pozycja->tluszcz_100g * $gramy / 100;
            $suma['weglowodany'] += $pozycja->weglowodany_100g * $gramy / 100;
        }

        $porcje = $recipe->servings !== null && (float) $recipe->servings > 0 ? (float) $recipe->servings : null;
        $dzielnik = $porcje ?? 1.0;

        return new WynikWartosci(
            $wiersze,
            $porcje,
            $suma['kcal'] / $dzielnik,
            $suma['bialko'] / $dzielnik,
            $suma['tluszcz'] / $dzielnik,
            $suma['weglowodany'] / $dzielnik,
        );
    }

    /**
     * Masa wiersza w gramach albo null, gdy nie da się jej ustalić.
     * Bez dopasowania w tabeli masę znamy tylko z jednostek masy
     * i z nawiasu — tyle wystarcza, żeby policzyć pokrycie.
     */
    private function gramy(OdczytanySkladnik $odczyt, ?float $ilosc, ?string $jednostka, ?SkladnikOdzywczy $pozycja): ?float
    {
        if ($odczyt->gramyZNawiasu !== null) {
            return $odczyt->gramyZNawiasu;
        }

        if ($ilosc === null || $ilosc <= 0) {
            return null;
        }

        if ($jednostka !== null && isset(JednostkiMiary::MASA[$jednostka])) {
            return $ilosc * JednostkiMiary::MASA[$jednostka];
        }

        if ($jednostka === 'szczypta') {
            return $ilosc * JednostkiMiary::SZCZYPTA_GRAMY;
        }

        if ($pozycja === null) {
            return null;
        }

        $miara = $this->miara($pozycja, $jednostka ?? 'szt');
        if ($miara !== null) {
            return $ilosc * $miara;
        }

        if ($jednostka !== null && isset(JednostkiMiary::OBJETOSC[$jednostka]) && $pozycja->gestosc_g_ml !== null) {
            return $ilosc * JednostkiMiary::OBJETOSC[$jednostka] * $pozycja->gestosc_g_ml;
        }

        return null;
    }

    private function miara(SkladnikOdzywczy $pozycja, string $jednostka): ?float
    {
        foreach ($pozycja->miary as $miara) {
            if ($miara->jednostka === $jednostka) {
                return $miara->gramy;
            }
        }

        return null;
    }

    private function maMiare(SkladnikOdzywczy $pozycja, string $jednostka): bool
    {
        return $this->miara($pozycja, $jednostka) !== null;
    }

    /**
     * Kolumny `quantity`/`unit_id` są dziś puste w każdym wierszu
     * z formularza (D-017). Gdy kiedyś będą wypełnione (import, OCR),
     * mają pierwszeństwo przed tym, co parser wyczytał z tekstu.
     */
    private function zKolumnStrukturalnych(RecipeIngredient $skladnik, OdczytanySkladnik $odczyt): OdczytanySkladnik
    {
        if ($skladnik->quantity === null) {
            return $odczyt;
        }

        $kod = $skladnik->unit !== null ? (JednostkiMiary::KODY_UNITS[$skladnik->unit->code] ?? null) : null;

        return new OdczytanySkladnik(
            (float) $skladnik->quantity,
            $kod,
            $odczyt->nazwa,
            $odczyt->nazwaZJednostka,
            null,
            $odczyt->bezIlosciZTekstu,
        );
    }
}

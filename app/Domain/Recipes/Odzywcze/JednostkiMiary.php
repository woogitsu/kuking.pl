<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

/**
 * Jednostki, które kalkulator wartości odżywczych rozpoznaje w tekście
 * składnika, i to, ile ważą bez znajomości składnika (D-299).
 *
 * Słowa są zapisane PO NORMALIZACJI (`Str::ascii` + małe litery), bo tak
 * wygląda tekst, który parser przegląda: „łyżki” → „lyzki”.
 *
 * TRZY RODZAJE JEDNOSTEK:
 *  - masa (g, dag, kg) — gramy znane zawsze;
 *  - objętość (ml, l, szklanka 250 ml, łyżka 15 ml, łyżeczka 5 ml) — gramy
 *    znane tylko przez gęstość składnika albo jego miarę domową, bo
 *    szklanka mąki waży co innego niż szklanka cukru;
 *  - wszystko inne (sztuka, ząbek, pęczek, kostka, puszka…) — wyłącznie
 *    przez miarę domową składnika z `miary_domowe`.
 *
 * Szczypta ma jedną wagę dla każdego składnika: 0,5 g. Tyle mieści się
 * w dwóch palcach bez względu na to, czy to sól, czy cynamon, a przy tej
 * masie różnica i tak znika w zaokrągleniu.
 *
 * Filiżanka i kieliszek są rozpoznawane (żeby nie wzięło się ich za nazwę
 * składnika), ale nie mają domyślnej objętości: filiżanka bywa 100 ml
 * i 250 ml. Bez miary domowej taki wiersz zostaje „bez znanej masy”.
 */
final class JednostkiMiary
{
    /** Gramy na jednostkę masy. */
    public const MASA = ['g' => 1.0, 'dag' => 10.0, 'kg' => 1000.0];

    /** Mililitry na jednostkę objętości. */
    public const OBJETOSC = ['ml' => 1.0, 'l' => 1000.0, 'szklanka' => 250.0, 'lyzka' => 15.0, 'lyzeczka' => 5.0];

    public const SZCZYPTA_GRAMY = 0.5;

    /**
     * Pojemniki: waga w nawiasie („1 puszka (400 g)”) dotyczy JEDNEGO
     * pojemnika, więc mnożymy ją przez liczbę pojemników.
     */
    public const POJEMNIKI = ['puszka', 'sloik', 'sloiczek', 'opakowanie', 'kostka', 'torebka', 'butelka', 'karton', 'kubek', 'tabliczka', 'woreczek'];

    /**
     * Forma słowa → kod jednostki. Kody są tymi samymi napisami, co kolumna
     * `miary_domowe.jednostka`.
     *
     * @var array<string, string>
     */
    public const SLOWA = [
        'g' => 'g', 'g.' => 'g', 'gr' => 'g', 'gr.' => 'g', 'gram' => 'g', 'gramy' => 'g', 'gramow' => 'g', 'grama' => 'g', 'gramach' => 'g',
        'dag' => 'dag', 'dag.' => 'dag', 'dkg' => 'dag', 'dkg.' => 'dag', 'deko' => 'dag', 'dekagram' => 'dag', 'dekagramy' => 'dag', 'dekagramow' => 'dag', 'dekagrama' => 'dag',
        'kg' => 'kg', 'kg.' => 'kg', 'kilo' => 'kg', 'kilogram' => 'kg', 'kilogramy' => 'kg', 'kilogramow' => 'kg', 'kilograma' => 'kg',
        'ml' => 'ml', 'ml.' => 'ml', 'mililitr' => 'ml', 'mililitry' => 'ml', 'mililitrow' => 'ml', 'mililitra' => 'ml',
        'l' => 'l', 'l.' => 'l', 'litr' => 'l', 'litry' => 'l', 'litrow' => 'l', 'litra' => 'l', 'litrami' => 'l',
        'szklanka' => 'szklanka', 'szklanki' => 'szklanka', 'szklanek' => 'szklanka', 'szklanke' => 'szklanka', 'szkl' => 'szklanka', 'szkl.' => 'szklanka', 'szklankami' => 'szklanka',
        'lyzka' => 'lyzka', 'lyzki' => 'lyzka', 'lyzek' => 'lyzka', 'lyzke' => 'lyzka', 'lyz.' => 'lyzka', 'lyzkami' => 'lyzka',
        'lyzeczka' => 'lyzeczka', 'lyzeczki' => 'lyzeczka', 'lyzeczek' => 'lyzeczka', 'lyzeczke' => 'lyzeczka', 'lyzecz.' => 'lyzeczka',
        'szczypta' => 'szczypta', 'szczypty' => 'szczypta', 'szczypt' => 'szczypta', 'szczypte' => 'szczypta', 'szczypta.' => 'szczypta',
        'garsc' => 'garsc', 'garscie' => 'garsc', 'garsci' => 'garsc',
        'szt' => 'szt', 'szt.' => 'szt', 'sztuka' => 'szt', 'sztuki' => 'szt', 'sztuk' => 'szt',
        'peczek' => 'peczek', 'peczki' => 'peczek', 'peczkow' => 'peczek', 'peczka' => 'peczek',
        'zabek' => 'zabek', 'zabki' => 'zabek', 'zabkow' => 'zabek', 'zabka' => 'zabek',
        'glowka' => 'glowka', 'glowki' => 'glowka', 'glowek' => 'glowka', 'glowke' => 'glowka',
        'plaster' => 'plaster', 'plastry' => 'plaster', 'plastrow' => 'plaster', 'plastra' => 'plaster',
        'plasterek' => 'plasterek', 'plasterki' => 'plasterek', 'plasterkow' => 'plasterek', 'plasterka' => 'plasterek',
        'kromka' => 'kromka', 'kromki' => 'kromka', 'kromek' => 'kromka', 'kromke' => 'kromka',
        'kostka' => 'kostka', 'kostki' => 'kostka', 'kostek' => 'kostka', 'kostke' => 'kostka',
        'opakowanie' => 'opakowanie', 'opakowania' => 'opakowanie', 'opakowan' => 'opakowanie', 'op.' => 'opakowanie',
        'paczka' => 'opakowanie', 'paczki' => 'opakowanie', 'paczek' => 'opakowanie', 'paczke' => 'opakowanie',
        'puszka' => 'puszka', 'puszki' => 'puszka', 'puszek' => 'puszka', 'puszke' => 'puszka',
        'sloik' => 'sloik', 'sloiki' => 'sloik', 'sloikow' => 'sloik', 'sloika' => 'sloik',
        'sloiczek' => 'sloiczek', 'sloiczki' => 'sloiczek', 'sloiczka' => 'sloiczek',
        'torebka' => 'torebka', 'torebki' => 'torebka', 'torebek' => 'torebka', 'torebke' => 'torebka',
        'woreczek' => 'woreczek', 'woreczki' => 'woreczek', 'woreczkow' => 'woreczek', 'woreczka' => 'woreczek',
        'lisc' => 'lisc', 'liscie' => 'lisc', 'lisci' => 'lisc', 'liscia' => 'lisc',
        'listek' => 'listek', 'listki' => 'listek', 'listkow' => 'listek', 'listka' => 'listek',
        'galazka' => 'galazka', 'galazki' => 'galazka', 'galazek' => 'galazka',
        'kawalek' => 'kawalek', 'kawalki' => 'kawalek', 'kawalkow' => 'kawalek', 'kawalka' => 'kawalek',
        'filet' => 'filet', 'filety' => 'filet', 'filetow' => 'filet', 'fileta' => 'filet',
        'kulka' => 'kulka', 'kulki' => 'kulka', 'kulek' => 'kulka',
        'kubek' => 'kubek', 'kubki' => 'kubek', 'kubkow' => 'kubek', 'kubka' => 'kubek',
        'butelka' => 'butelka', 'butelki' => 'butelka', 'butelek' => 'butelka',
        'karton' => 'karton', 'kartony' => 'karton', 'kartonow' => 'karton', 'kartonu' => 'karton',
        'lodyga' => 'lodyga', 'lodygi' => 'lodyga', 'lodyg' => 'lodyga',
        'laska' => 'laska', 'laski' => 'laska', 'lasek' => 'laska',
        'bochenek' => 'bochenek', 'bochenki' => 'bochenek', 'bochenka' => 'bochenek',
        'tabliczka' => 'tabliczka', 'tabliczki' => 'tabliczka', 'tabliczek' => 'tabliczka',
        'ziarno' => 'ziarno', 'ziarna' => 'ziarno', 'ziaren' => 'ziarno',
        'filizanka' => 'filizanka', 'filizanki' => 'filizanka', 'filizanek' => 'filizanka',
        'kieliszek' => 'kieliszek', 'kieliszki' => 'kieliszek', 'kieliszkow' => 'kieliszek', 'kieliszka' => 'kieliszek',
    ];

    /**
     * Kody z tabeli `units` (pola strukturalne, dziś puste z formularzy),
     * przetłumaczone na kody tej klasy.
     *
     * @var array<string, string>
     */
    public const KODY_UNITS = [
        'g' => 'g', 'dag' => 'dag', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'l',
        'szklanka' => 'szklanka', 'lyzka' => 'lyzka', 'lyzeczka' => 'lyzeczka',
        'szczypta' => 'szczypta', 'garsc' => 'garsc', 'szt' => 'szt', 'pecz' => 'peczek',
        'zabek' => 'zabek', 'opak' => 'opakowanie', 'plaster' => 'plaster',
    ];

    public static function kod(string $slowo): ?string
    {
        return self::SLOWA[$slowo] ?? null;
    }

    public static function jestPojemnikiem(string $kod): bool
    {
        return in_array($kod, self::POJEMNIKI, true);
    }
}

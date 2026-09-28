<?php

declare(strict_types=1);

namespace App\Domain\Feed;

/**
 * Treść strony „Jak dobieramy wpisy" (#1811, D-305) — jedyne miejsce, w którym
 * stoją jej zdania.
 *
 * PO CO ZDANIA W KLASIE, A NIE W WIDOKU
 * Strona obiecuje, jak działa każda lista wpisów w serwisie. Taka obietnica
 * psuje się po cichu: ktoś zmienia `DiscoverFeed`, a zdanie o rotacji zostaje.
 * Dlatego każde zdanie ma tu klucz, widok rysuje WYŁĄCZNIE to, co jest w tej
 * klasie, a `JakDobieramyWpisyMowiPrawdeTest` trzyma dla każdego klucza dowód
 * w kodzie (test zachowania, fragment zapytania albo wartość konfiguracji).
 * Nowe zdanie bez dowodu oblewa test; dowód bez zdania też.
 *
 * Liczby, które zależą od konfiguracji (dni ukrycia, ile wpisów serii widać),
 * są brane z niej, nie przepisane z palca.
 *
 * `{kuking}` w zdaniu widok zamienia na `<x-kuking-word />` (AGENTS.md §11:
 * nazwa w tekście bieżącym dwukolorowo).
 *
 * Każda nowa reguła doboru (AGENTS.md §8, D-275) = wpis w `docs/DECISIONS.md`,
 * zdanie tutaj i dowód w teście.
 */
final class JakDobieramyWpisy
{
    /**
     * @return array<string, array{tytul: string, zdania: array<string, string>}>
     */
    public static function sekcje(): array
    {
        $dni = (int) config('kuking.ukrycia.dni');
        $widoczneZSerii = SerieWpisow::WIDOCZNE_Z_SERII;
        $ileWSerii = $widoczneZSerii === 2 ? 'dwa' : (string) $widoczneZSerii;

        return [
            'start' => [
                'tytul' => 'Start',
                'zdania' => [
                    'start.zrodla' => 'Na Starcie widzisz wpisy osób i tagów, które obserwujesz, oraz swoje — od najnowszych.',
                    'start.podpis' => 'Przy wpisie, który trafił do Ciebie przez tag, jest napisane „Z tagu: …”.',
                    'start.serie' => "Gdy ktoś opublikuje kilka wpisów pod rząd, widzisz {$ileWSerii}, a resztę po naciśnięciu „Pokaż” — nic nie znika i kolejność się nie zmienia.",
                    'start.pusty' => 'Dopóki nie ma tu nic od innych osób, pokazujemy wpisy ze „Świeżo z {kuking}”.',
                ],
            ],
            'odkrywanie' => [
                'tytul' => 'Świeżo z {kuking}',
                'zdania' => [
                    'odkrywanie.rotacja' => 'Najpierw widzisz najnowszy wpis każdej osoby, potem drugi wpis każdej i tak dalej.',
                    'odkrywanie.rownosc' => 'Nikt nie stoi wyżej dlatego, że publikuje częściej albo zebrał więcej reakcji.',
                    'odkrywanie.ukryci' => 'Wpisów osób, które ukrywasz albo blokujesz, tu nie ma.',
                ],
            ],
            'tablica' => [
                'tytul' => 'Tablica na dziś i polecane tagi',
                'zdania' => [
                    'tablica.wybor' => 'Na tablicy z kilkoma osobami i daniami na dziś pozycje wybrane przez gospodarza serwisu mają napis „Wybór gospodarza”.',
                    'tablica.reszta' => 'Resztę miejsc uzupełniamy osobami, których jeszcze nie obserwujesz, i wpisami, które pojawiły się ostatnio — najwyżej jedną pozycją od osoby.',
                    'tablica.tagi' => 'Polecane tagi na liście wszystkich tagów też wybiera gospodarz i tak są podpisane.',
                ],
            ],
            'szukaj' => [
                'tytul' => 'Wyszukiwarka',
                'zdania' => [
                    'szukaj.przepisy' => 'Przepisy układamy według tego, jak dobrze tytuł pasuje do wpisanych słów, a przy takim samym dopasowaniu — od najnowszych.',
                    'szukaj.osoby' => 'Osoby układamy według tego, jak bardzo nazwa przypomina wpisane słowa.',
                ],
            ],
            'list' => [
                'tytul' => 'Tygodniowy e-mail',
                'zdania' => [
                    'list.zgoda' => 'Wysyłamy go tylko osobom, które się na niego zgodziły w ustawieniach.',
                    'list.czas' => 'Wszystko jest w nim ułożone po czasie, a od każdej obserwowanej osoby jest jeden, najnowszy wpis.',
                ],
            ],
            'ukrycia' => [
                'tytul' => 'Ukrywanie',
                'zdania' => [
                    'ukrycia.co' => "Możesz ukryć pojedynczy wpis albo osobę — tylko dla siebie, domyślnie na {$dni} dni.",
                    'ukrycia.gdzie' => 'Ukryty wpis znika z Twoich list. Ukryta osoba znika z miejsc, w których sami podsuwamy Ci ludzi, także z wpisów, które trafiają na Twój Start przez tag.',
                    'ukrycia.obserwowani' => 'Osoby, którą obserwujesz, się nie ukrywa — wystarczy przestać ją obserwować.',
                    'ukrycia.cofniecie' => 'Każde ukrycie cofniesz w Ustawieniach, na liście „Ukryte”.',
                ],
            ],
            'nie' => [
                'tytul' => 'Czego nie robimy',
                'zdania' => [
                    'nie.popularnosc' => 'Nie układamy wpisów według popularności: liczba obserwujących, komentarzy, zapisów w zeszytach ani odsłon nikogo nie przesuwa wyżej.',
                    'nie.reakcje' => '„Ugotowałem” i „Smakowicie wygląda” nie zmieniają kolejności żadnej listy.',
                    'nie.zachowanie' => 'Nie uczymy się Twojego gustu z tego, co oglądasz i w co klikasz.',
                    'nie.ukrycia' => 'Ukrycia widzisz tylko Ty: nie powiadamiamy o nich autora, nie liczymy, ile osób kogoś ukryło, i nie zmniejszają one zasięgu jego wpisów u innych.',
                ],
            ],
        ];
    }

    /**
     * Wszystkie zdania płasko, klucz → treść — dla testu.
     *
     * @return array<string, string>
     */
    public static function zdania(): array
    {
        return array_merge(...array_values(array_map(fn (array $s) => $s['zdania'], self::sekcje())));
    }
}

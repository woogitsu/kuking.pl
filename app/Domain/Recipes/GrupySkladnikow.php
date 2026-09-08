<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

/**
 * Grupy składników — „Ciasto", „Farsz", „Do podania" (D-033, część pierwsza).
 *
 * JEDNO MIEJSCE, W KTÓRYM PŁASKA LISTA SKŁADNIKÓW ZAMIENIA SIĘ W GRUPY.
 * Czytają je: strona przepisu, podgląd w kreatorze i przepis w eksporcie
 * danych. Każde z tych miejsc miało wcześniej albo własną kopię tej reguły,
 * albo nie miało jej wcale — a wtedy ten sam przepis wygląda inaczej na
 * ekranie i inaczej w pliku, który człowiek sobie pobrał (wzór ten sam, co
 * przy `StepTimer`: jeden przelicznik, nie trzy).
 *
 * TRZY REGUŁY UKŁADU
 *
 * 1. SKŁADNIKI BEZ GRUPY IDĄ NA GÓRĘ, BEZ NAGŁÓWKA. Przepis bez ani jednej
 *    grupy — czyli zdecydowana większość przepisów — wygląda dokładnie tak
 *    jak dotąd: jedna lista, żadnego nagłówka, żadnej ramki, nic, co
 *    sugerowałoby, że autor czegoś nie wypełnił.
 *
 * 2. GRUPY IDĄ W KOLEJNOŚCI, W JAKIEJ PODAŁ JE AUTOR — czyli w kolejności
 *    pierwszego wystąpienia w liście składników (`position`). Kolejność nie
 *    ma osobnej kolumny i mieć nie powinna: autor pisze listę od góry do
 *    dołu i to jest cała informacja o kolejności, jaką ma.
 *
 * 3. WIERSZE TEJ SAMEJ GRUPY TRAFIAJĄ POD JEDEN NAGŁÓWEK, nawet gdy leżą
 *    w liście z przeplotem („Ciasto, Farsz, Ciasto") i nawet gdy nazwa
 *    została zapisana raz z dużej, raz z małej litery („Farsz" / „farsz").
 *    Baza tego nie pilnuje — CHECK nie widzi sąsiednich wierszy, a UNIQUE
 *    nie widzi wielkości liter (`docs/research/repos/TandoorRecipes-recipes.md`
 *    §2.2, rekomendacja R8). Nagłówek dostaje PIERWSZĄ pisownię, jakiej użył
 *    autor: to jego słowo, więc poprawiamy powtórzenie, a nie człowieka.
 *
 * DLACZEGO SCALANIE JEST TUTAJ, SKORO `PublishRecipe` UJEDNOLICA NAZWY PRZY
 * ZAPISIE. Bo zapis to nie jedyna droga do bazy: są jeszcze fabryki, konsola,
 * seeder i przyszły import, a w tabeli leżą już wiersze sprzed tej reguły.
 * Widok, który przy przeplocie pokazuje dwa nagłówki „Ciasto", nie wygląda
 * na dane do poprawienia — wygląda na usterkę strony.
 */
final class GrupySkladnikow
{
    /**
     * Układa składniki w grupy gotowe do wypisania.
     *
     * Przyjmuje i modele `RecipeIngredient`, i zwykłe tablice z kluczem
     * `group_name` (tak wyglądają wiersze w podglądzie kreatora) — stąd
     * `data_get()` zamiast strzałki albo nawiasu.
     *
     * @param  iterable<mixed>  $skladniki
     * @return list<array{nazwa: ?string, skladniki: list<mixed>}>
     */
    public static function ulozyc(iterable $skladniki): array
    {
        /** @var list<mixed> $bezGrupy */
        $bezGrupy = [];

        /** @var array<string, array{nazwa: string, skladniki: list<mixed>}> $grupy */
        $grupy = [];

        foreach ($skladniki as $skladnik) {
            $nazwa = self::nazwa($skladnik);

            if ($nazwa === null) {
                $bezGrupy[] = $skladnik;

                continue;
            }

            $klucz = self::klucz($nazwa);

            // Pierwsze wystąpienie ustala i kolejność grupy, i jej pisownię.
            $grupy[$klucz] ??= ['nazwa' => $nazwa, 'skladniki' => []];
            $grupy[$klucz]['skladniki'][] = $skladnik;
        }

        $ulozone = [];

        if ($bezGrupy !== []) {
            $ulozone[] = ['nazwa' => null, 'skladniki' => $bezGrupy];
        }

        foreach ($grupy as $grupa) {
            $ulozone[] = $grupa;
        }

        return $ulozone;
    }

    /**
     * Nazwa grupy tego wiersza albo `null`, gdy wiersz do żadnej nie należy.
     *
     * Pusty ciąg i same spacje znaczą „bez grupy", a nie „grupa o pustej
     * nazwie": nagłówek bez tekstu to na ekranie pusta linia, a w czytniku
     * ekranu „nagłówek poziomu trzeciego" i cisza.
     */
    public static function nazwa(mixed $skladnik): ?string
    {
        $nazwa = trim((string) (data_get($skladnik, 'group_name') ?? ''));

        return $nazwa === '' ? null : $nazwa;
    }

    /**
     * Klucz scalający: „Farsz", „farsz" i „farsz  na  pierogi" z podwójną
     * spacją to jedna grupa. Bez `unaccent` — polskie znaki diakrytyczne są
     * tu ZNACZĄCE („Sos" to nie „Sós"), a fałszywe scalenie dwóch różnych
     * nazw jest gorsze niż dwa nagłówki.
     */
    public static function klucz(string $nazwa): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($nazwa)));
    }
}

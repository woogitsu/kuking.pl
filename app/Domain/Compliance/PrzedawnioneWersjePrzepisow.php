<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\RecipeVersion;

/**
 * Retencja `recipe_versions` — DECYZJA WŁAŚCICIELA z 2026-09-20:
 * `config('kuking.przepisy.version_retention_months')` miesięcy (domyślnie
 * 24) od `created_at` migawki, Z JEDNYM WYJĄTKIEM: PIERWSZA WERSJA KAŻDEGO
 * PRZEPISU NIE JEST KASOWANA NIGDY, niezależnie od wieku.
 *
 * DLACZEGO PIERWSZA WERSJA ZOSTAJE — a nie jest to „wyjątek dla wygody".
 *
 * Migawka o numerze 1 to jedyny wiersz, który mówi, JAK TEN PRZEPIS
 * WYGLĄDAŁ, GDY POWSTAŁ. Cała reszta historii jest wobec niej różnicą:
 * „autorka zmieniła proporcje", „doszedł krok z piekarnikiem" nie znaczy nic
 * bez punktu wyjścia. Gdyby retencja ruszała także ją, przepis opublikowany
 * w 2026 i poprawiony raz w 2029 zostałby z jedną wersją z 2029 — czyli
 * z historią, która zaczyna się od zmiany i nie ma czego z nią porównać.
 * Przepis edytowany RZADKO — a takich jest najwięcej — straciłby historię
 * w ogóle, i to właśnie ten, którego wersji nikt nigdy nie nadpisał.
 *
 * Koszt tej decyzji jest policzalny i mały: przy N przepisach zostaje
 * dokładnie N nieusuwalnych wierszy, po jednym na przepis. To jest ten sam
 * rząd wielkości co sama tabela `recipes` — a oszczędność, którą dałoby ich
 * skasowanie, jest żadna wobec tego, co by za nią zniknęło.
 *
 * WYJĄTEK JEST REGUŁĄ KODU, NIE WARTOŚCIĄ W CONFIGU — tak samo jak
 * `AuditLogEntry::NIGDY_NIE_KASUJ`. Liczbę miesięcy wolno przestawić zmienną
 * środowiskową; „skasuj też pierwsze wersje" nie ma się dać przestawić bez
 * recenzji kodu.
 *
 * DLACZEGO ZWYKŁY MASOWY `DELETE`, A NIE PĘTLA PER WIERSZ
 * Ten sam powód co przy `PrzedawnioneWpisyAudytu` i `PrzedawnioneSygnaly`:
 * wiersz `recipe_versions` nie ma żadnego odpowiednika po stronie storage
 * (migawka NIE ZAPISUJE zdjęć — `SnapshotRecipeVersion` bierze tytuł, opis,
 * czasy, pochodzenie, składniki i kroki), nikt na niego nie wskazuje kluczem
 * obcym i nic nie kaskaduje z niego dalej. Jeden `DELETE` jest więc i
 * szybszy, i równie bezpieczny na przerwanie w połowie — baza gwarantuje
 * atomowość jednego zapytania, a predykat to wyłącznie wiek wiersza i jego
 * numer, więc kolejny przebieg po prostu dobierze to, co zostało.
 *
 * PREDYKAT JEST IDEMPOTENTNY I NIE „ZJADA SIĘ" MIĘDZY PRZEBIEGAMI.
 * Chroniony wiersz jest wskazany przez `min(version_number)` W OBRĘBIE
 * PRZEPISU, a nie przez „najstarszy, który akurat został". Gdyby było
 * odwrotnie, każdy kolejny przebieg chroniłby inny wiersz i po kilku
 * przebiegach historia zostałaby skrócona do ostatniej wersji — czyli
 * automat kasowałby więcej, niż deklaruje, bez żadnej zmiany w kodzie.
 * Ponieważ wiersza numer 1 nie kasujemy nigdy, `min(version_number)` jest
 * stały w czasie i dwa uruchomienia z rzędu dają ten sam wynik: drugie
 * kasuje zero.
 */
final class PrzedawnioneWersjePrzepisow
{
    /**
     * Wiersz chroniony: najniższy numer wersji W OBRĘBIE TEGO SAMEGO
     * PRZEPISU. `version_number` ma `UNIQUE (recipe_id, version_number)`
     * i `CHECK (> 0)`, więc jest jednoznaczny.
     */
    private const PIERWSZA_WERSJA_PRZEPISU = <<<'SQL'
        version_number = (
            select min(pierwsza.version_number)
            from recipe_versions as pierwsza
            where pierwsza.recipe_id = recipe_versions.recipe_id
        )
        SQL;

    /**
     * @param  int  $miesiecyKarencji  ile miesięcy trzymamy migawkę
     * @param  bool  $naSucho  policz, ale niczego nie kasuj
     * @return array{skasowano: int, niekasowalne: int} liczba skasowanych
     *                                                  wierszy i liczba wierszy starszych niż próg, które ZOSTAŁY
     *                                                  jako pierwsze wersje swoich przepisów (informacyjnie — dry-run
     *                                                  i normalny przebieg liczą to samo, bo ta druga liczba nigdy
     *                                                  nie zależy od trybu)
     */
    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): array
    {
        // `subMonthsNoOverflow`, NIE `subMonths` — ta sama pułapka co
        // w `PrzedawnionePowiadomienia` (A6-04) i `PrzedawnioneWpisyAudytu`:
        // przepełnienie daty (31 marca minus miesiąc) przesuwa próg w stronę
        // NOWSZYCH wierszy i kasuje je przed czasem. Pełne uzasadnienie
        // i pomiar są tam, przy oryginalnym znalezisku.
        $prog = now()->subMonthsNoOverflow($miesiecyKarencji);

        $niekasowalne = RecipeVersion::query()
            ->where('created_at', '<', $prog)
            ->whereRaw(self::PIERWSZA_WERSJA_PRZEPISU)
            ->count();

        $doSkasowania = RecipeVersion::query()
            ->where('created_at', '<', $prog)
            ->whereRaw('not ('.self::PIERWSZA_WERSJA_PRZEPISU.')');

        $skasowano = $naSucho ? $doSkasowania->count() : $doSkasowania->delete();

        return ['skasowano' => $skasowano, 'niekasowalne' => $niekasowalne];
    }
}

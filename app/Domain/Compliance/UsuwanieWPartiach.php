<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * KASOWANIE PRZEDAWNIONYCH WIERSZY PARTIAMI, Z BUDŻETEM NA PRZEBIEG (#1657).
 *
 * Do września 2026 retencja prostych tabel (`product_signals`, `audit_log`,
 * zwykłe `notifications`, `sessions`, potwierdzenia RODO) robiła jeden
 * `DELETE ... WHERE <wiek>` na cały zaległy backlog. Komentarze obiecywały,
 * że przerwanie „dobierze resztę" — ale przerwana JEDNA instrukcja wycofuje
 * się w całości, więc po timeoucie albo restarcie następny przebieg zaczynał
 * ten sam koszt od zera. Czas, WAL, blokady i martwe krotki rosły z całym
 * backlogiem (pierwszy przebieg po włączeniu retencji, po przestoju
 * harmonogramu, po skróceniu progu).
 *
 * TERAZ:
 *  - partia identyfikatorów w stałym porządku po kluczu głównym, potem
 *    `DELETE ... WHERE <ten sam predykat> AND klucz IN (...)` we własnej,
 *    krótkiej transakcji — zatwierdzony postęp zostaje po przerwaniu;
 *  - predykat jest sprawdzany PONOWNIE w `DELETE`: wiersz, który między
 *    wyborem partii a usunięciem przestał być kandydatem (sesja znów
 *    aktywna, nałożone wstrzymanie RODO), nie jest kasowany;
 *  - budżet wierszy na tabelę i przebieg. Wyczerpany budżet zostawia resztę
 *    na następną noc i ostrzeżenie w dzienniku (nazwa tabeli i liczby, bez
 *    identyfikatorów i treści), a nie ukrywa backlogu.
 *
 * Budżet dotyczy jednej tabeli: wyczerpanie go przez jedną z nich nie
 * zabiera miejsca pozostałym — każda komenda woła tę klasę osobno.
 */
final class UsuwanieWPartiach
{
    public function __construct(
        private readonly int $partia,
        private readonly int $budzet,
    ) {}

    public static function zKonfiguracji(): self
    {
        return new self(
            max(1, (int) config('kuking.retencja.partia')),
            max(1, (int) config('kuking.retencja.budzet')),
        );
    }

    /**
     * @param  callable(): (EloquentBuilder<Model>|Builder)  $kandydaci  świeże zapytanie
     *                                                                   o kandydatów przy każdym wywołaniu
     * @return int ile wierszy skasowano (najwyżej budżet)
     */
    public function usun(callable $kandydaci, string $klucz, string $tabela): int
    {
        $skasowano = 0;

        while ($skasowano < $this->budzet) {
            $limit = min($this->partia, $this->budzet - $skasowano);

            $identyfikatory = $kandydaci()
                ->reorder()
                ->orderBy($klucz)
                ->limit($limit)
                ->pluck($klucz)
                ->all();

            if ($identyfikatory === []) {
                return $skasowano;
            }

            $wPartii = DB::transaction(
                fn (): int => $kandydaci()->whereIn($klucz, $identyfikatory)->delete(),
            );

            $skasowano += $wPartii;

            // Pełna partia bez ani jednego skasowanego wiersza znaczy, że
            // wybór i `DELETE` rozjechały się — kolejna pętla wybrałaby te
            // same wiersze. Kończymy, zamiast kręcić się do końca budżetu.
            if (count($identyfikatory) < $limit || $wPartii === 0) {
                return $skasowano;
            }
        }

        if ($kandydaci()->exists()) {
            Log::warning('Retencja: wyczerpany budżet przebiegu, reszta zostaje na następny.', [
                'tabela' => $tabela,
                'skasowano' => $skasowano,
                'budzet' => $this->budzet,
                'pozostalo' => $kandydaci()->count(),
                'stage' => 'retention_budget_exhausted',
            ]);
        }

        return $skasowano;
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WERSJA PRZEPISU JAKO CEL ZGŁOSZENIA — nowa wartość `recipe_version`
 * w `reports.target_type` (issue #2390, decyzja właściciela z 1.10.2026).
 *
 * Do tej pory wersji z historii zmian nie dało się zgłosić: ukrywała ją
 * moderacja z urzędu (`moderation_actions.target_type = 'recipe_version'`,
 * #2270), a `reports_target_type_check` jej nie znał. Osoba trzecia, która
 * zobaczyła w starej wersji cudzy numer telefonu, mogła zgłosić tylko cały
 * przepis — a ten bywa w porządku. Teraz zgłasza konkretną wersję; usunięcie
 * danych z historii to ukrycie CAŁEJ wersji (bez redakcji fragmentu migawki,
 * historia pozostaje niezmienna).
 *
 * `moderation_actions.target_type` nie ma CHECK-a, więc nic tam się nie zmienia.
 *
 * AGENTS.md §6: CHECK na istniejącej tabeli zakładamy jako `NOT VALID`
 * i walidujemy osobno, poza transakcją. Zdjęcie starego i założenie nowego
 * ograniczenia idą JEDNYM poleceniem `ALTER TABLE`, więc nie ma chwili bez
 * żadnego CHECK-a.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy w `reports` leży choć jedno
 * zgłoszenie wersji — to sprawy moderacyjne z decyzjami i odwołaniami,
 * a węższy CHECK by je odrzucił. Bez takich wierszy cofnięcie przechodzi.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE = 'reports_target_type_check';

    /** Wartości `target_type` PO tej migracji. */
    private const PO = "'user','post','recipe','comment','cooked_event','media','collection','recipe_version','unknown'";

    /** Wartości `target_type` PRZED tą migracją. */
    private const PRZED = "'user','post','recipe','comment','cooked_event','media','collection','unknown'";

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->zalozCheck(self::PO);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $wersji = (int) DB::table('reports')->where('target_type', 'recipe_version')->count();

        if ($wersji > 0) {
            throw new RuntimeException(
                'Liczba zgłoszeń wersji przepisu (`target_type = recipe_version`) w `reports`: '.$wersji.'. '
                .'Cofnięcie tej migracji odrzuciłoby te wiersze przez CHECK, a są to sprawy '
                .'moderacyjne z decyzjami i odwołaniami. Rozstrzygnij je i przenieś ręcznie '
                .'albo skasuj świadomie, potem cofnij migrację.',
            );
        }

        $this->zalozCheck(self::PRZED);
    }

    private function zalozCheck(string $wartosci): void
    {
        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE
            .', ADD CONSTRAINT '.self::OGRANICZENIE.' CHECK (target_type IN ('.$wartosci.')) NOT VALID');
        DB::statement('ALTER TABLE reports VALIDATE CONSTRAINT '.self::OGRANICZENIE);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

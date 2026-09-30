<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZESZYT JAKO CEL ZGŁOSZENIA — nowa wartość `collection` w `reports.target_type`
 * (issue #2279, audyt 30.09 Z3).
 *
 * Regulamin §7 obiecuje przycisk „Zgłoś” przy każdej treści. Publiczny
 * zeszyt pokazuje nazwę i opis, które napisał jego właściciel, a zgłosić ich
 * nie było jak: `ReportController` nie znał takiego celu, a CHECK w bazie by
 * go odrzucił. Moderacja zeszytu to ostrzeżenie, zawieszenie albo ban
 * właściciela (`ModerationAction::DOZWOLONE['collection']`) — bez „ukryj”
 * i bez „usuń”, bo zeszyt nie ma statusu ani miękkiego kasowania.
 *
 * AGENTS.md §6: CHECK na istniejącej tabeli zakładamy jako `NOT VALID`
 * i walidujemy osobno, poza transakcją. Zdjęcie starego i założenie nowego
 * ograniczenia idą JEDNYM poleceniem `ALTER TABLE`, więc nie ma chwili bez
 * żadnego CHECK-a.
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy w `reports` leży choć jedno
 * zgłoszenie zeszytu — to sprawy moderacyjne z decyzjami i odwołaniami,
 * a węższy CHECK by je odrzucił. Bez takich wierszy cofnięcie przechodzi.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE = 'reports_target_type_check';

    /** Wartości `target_type` PO tej migracji. */
    private const PO = "'user','post','recipe','comment','cooked_event','media','collection','unknown'";

    /** Wartości `target_type` PRZED tą migracją. */
    private const PRZED = "'user','post','recipe','comment','cooked_event','media','unknown'";

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

        $zeszytow = (int) DB::table('reports')->where('target_type', 'collection')->count();

        if ($zeszytow > 0) {
            throw new RuntimeException(
                'Liczba zgłoszeń zeszytów (`target_type = collection`) w `reports`: '.$zeszytow.'. '
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

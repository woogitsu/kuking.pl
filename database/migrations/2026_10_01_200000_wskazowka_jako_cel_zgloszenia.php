<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WSKAZÓWKA OD GOTUJĄCYCH JAKO CEL ZGŁOSZENIA — nowa wartość `recipe_hint`
 * w `reports.target_type` i kolumna ukrycia `recipe_hints.moderation_hidden_at`
 * (#2352, decyzja właściciela z 1.10.2026, D-333, wiersz „#2352 — osobne
 * ukrycie wskazówki”).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do tej pory moderacja wskazówki dziedziczyła z WYKONANIA: zgłoszenie
 * dotyczyło `cooked_event`, a jedyną decyzją zdejmującą treść było `remove`
 * całego wykonania (kasowanie na stałe, razem z komentarzami). Wskazówka
 * to tylko uwaga z wykonania pokazana przy cudzym przepisie za zgodą
 * kucharza — moderacja ma móc zdjąć SAMĄ wskazówkę z sekcji przy przepisie,
 * zostawiając wykonanie nietknięte.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DWIE ZMIANY
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. `recipe_hints.moderation_hidden_at timestamptz NULL` — OD KIEDY moderacja
 *     ukryła wskazówkę; `NULL` = nie ukryta. To osobny znacznik, a nie nowy
 *     `status`: stan zgody kucharza (`accepted`, `withdrawn`) jest jego decyzją
 *     i ma zostać czytelny — kucharz może wycofać zgodę także na ukrytą
 *     wskazówkę (RODO art. 7 ust. 3), a po uznanym odwołaniu wskazówka wraca
 *     do tego, co kucharz postanowił. Kolumna jest polem sterującym: poza
 *     `$fillable`, ustawia ją wyłącznie decyzja moderacyjna.
 *     CHECK `recipe_hints_ukrycie_check`: ukryć można tylko wskazówkę, na
 *     którą kucharz kiedykolwiek się zgodził (`accepted` albo `withdrawn`).
 *     Które konto ukryło, stoi w `moderation_actions` (nie ma tu klucza do
 *     konta: nie wchodzi do inwentarza ani do wymazywania).
 *  2. `reports_target_type_check` zyskuje `recipe_hint`.
 *
 * `moderation_actions.target_type` nie ma CHECK-a, więc nic tam się nie zmienia.
 *
 * AGENTS.md §6: kolumna `NULL` bez DEFAULT zmienia tylko katalog; oba CHECK-i
 * na istniejących tabelach idą `NOT VALID`, a `VALIDATE` osobno, poza
 * transakcją. Stary CHECK na `reports` jest zdejmowany i zakładany nowy
 * JEDNYM poleceniem `ALTER TABLE` — nie ma chwili bez żadnego CHECK-a.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK — ODMAWIA (D-088)
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` odmawia w dwóch przypadkach, każdy z innego powodu:
 *  - w `reports` leży zgłoszenie wskazówki — to sprawa moderacyjna z decyzją
 *    i odwołaniami, a węższy CHECK by ją odrzucił;
 *  - choć jedna wskazówka jest ukryta — zdjęcie kolumny ODSŁONIŁOBY ją
 *    publicznie, a ponowne `up()` wróciłoby z `NULL`, czyli nie ukrywałoby
 *    niczego, bez śladu błędu.
 * Przy braku takich wierszy (świeża baza, CI, `migrate:refresh`) cofnięcie
 * przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE_ZGLOSZEN = 'reports_target_type_check';

    private const OGRANICZENIE_UKRYCIA = 'recipe_hints_ukrycie_check';

    /** Wartości `target_type` PO tej migracji. */
    private const PO = "'user','post','recipe','comment','cooked_event','media','collection','recipe_version','recipe_hint','unknown'";

    /** Wartości `target_type` PRZED tą migracją. */
    private const PRZED = "'user','post','recipe','comment','cooked_event','media','collection','recipe_version','unknown'";

    // Ukryć można tylko wskazówkę, na którą kucharz się zgodził (stan `accepted`
    // albo późniejszy `withdrawn`). Wiersze bez ukrycia przechodzą bez względu na stan.
    private const WARUNEK_UKRYCIA = "moderation_hidden_at IS NULL OR status IN ('accepted', 'withdrawn')";

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        if (! Schema::hasColumn('recipe_hints', 'moderation_hidden_at')) {
            DB::statement('ALTER TABLE recipe_hints ADD COLUMN moderation_hidden_at timestamptz NULL');
        }

        DB::statement('ALTER TABLE recipe_hints DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE_UKRYCIA);
        DB::statement('ALTER TABLE recipe_hints ADD CONSTRAINT '.self::OGRANICZENIE_UKRYCIA
            .' CHECK ('.self::WARUNEK_UKRYCIA.') NOT VALID');
        DB::statement('ALTER TABLE recipe_hints VALIDATE CONSTRAINT '.self::OGRANICZENIE_UKRYCIA);

        $this->zalozCheckZgloszen(self::PO);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $zgloszen = (int) DB::table('reports')->where('target_type', 'recipe_hint')->count();

        if ($zgloszen > 0) {
            throw new RuntimeException(
                'Liczba zgłoszeń wskazówek od gotujących (`target_type = recipe_hint`) w `reports`: '.$zgloszen.'. '
                .'Cofnięcie tej migracji odrzuciłoby te wiersze przez CHECK, a są to sprawy '
                .'moderacyjne z decyzjami i odwołaniami. Rozstrzygnij je i przenieś ręcznie '
                .'albo skasuj świadomie, potem cofnij migrację.',
            );
        }

        if (Schema::hasColumn('recipe_hints', 'moderation_hidden_at')) {
            $ukrytych = (int) DB::table('recipe_hints')->whereNotNull('moderation_hidden_at')->count();

            if ($ukrytych > 0) {
                throw new RuntimeException(
                    'Liczba wskazówek ukrytych przez moderację (`recipe_hints.moderation_hidden_at`): '.$ukrytych.'. '
                    .'Zdjęcie kolumny odsłoniłoby je publicznie przy przepisach, a ponowna migracja '
                    .'nie przywróciłaby ukrycia. Przywróć je decyzją moderacyjną (albo zdecyduj '
                    .'z właścicielem, co z nimi zrobić), potem cofnij migrację.',
                );
            }

            DB::statement('ALTER TABLE recipe_hints DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE_UKRYCIA);
            DB::statement('ALTER TABLE recipe_hints DROP COLUMN moderation_hidden_at');
        }

        $this->zalozCheckZgloszen(self::PRZED);
    }

    private function zalozCheckZgloszen(string $wartosci): void
    {
        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE_ZGLOSZEN
            .', ADD CONSTRAINT '.self::OGRANICZENIE_ZGLOSZEN.' CHECK (target_type IN ('.$wartosci.')) NOT VALID');
        DB::statement('ALTER TABLE reports VALIDATE CONSTRAINT '.self::OGRANICZENIE_ZGLOSZEN);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

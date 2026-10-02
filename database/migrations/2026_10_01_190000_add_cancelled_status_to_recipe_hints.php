<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Anuluj prośbę” (#2352, decyzja właściciela z 1 października 2026, D-333):
 * autor przepisu może wycofać własną CZEKAJĄCĄ prośbę o wskazówkę. Nowy stan
 * `cancelled` (a nie ponowne użycie `withdrawn`): `withdrawn` znaczy „KUCHARZ
 * wycofał zgodę” i ma datę decyzji kucharza, a tu kucharz nie odpowiadał.
 * Pomieszanie obu zmieniłoby znaczenie wiersza w paczce danych i w logice
 * „Nie i wycofanie są ostateczne”.
 *
 * Zmiana to dwa CHECK-i na istniejącej tabeli (AGENTS.md §6):
 *  - `recipe_hints_status_check` — słownik zyskuje `cancelled`;
 *  - `recipe_hints_stan_spojny_check` — `cancelled` jest jak `proposed`: bez
 *    `decided_at` i bez `withdrawn_at` (kucharz nie odpowiedział; kiedy autor
 *    anulował, mówi `updated_at`). Dzięki temu nie dochodzi żadna kolumna
 *    (eksport i wymazanie się nie zmieniają).
 *
 * Stare CHECK-i są zdejmowane, a nowe zakładane JEDNYM poleceniem `ALTER TABLE`
 * (`NOT VALID`) — nie ma chwili bez żadnego z nich — a osobny `VALIDATE` idzie
 * poza transakcją (`$withinTransaction = false`), żeby blokada z pierwszego
 * kroku nie trwała do końca drugiego. Dane istniejące spełniają nowe reguły z definicji
 * (nowa reguła jest szersza od starej), więc `up()` niczego nie sprawdza
 * wcześniej; gdyby `VALIDATE` padł, niezwalidowane CHECK-i są zdejmowane,
 * a stare przywracane, żeby ponowne `migrate` zaczęło od czystego stanu.
 *
 * ROLLBACK: `down()` ODMAWIA, gdy w tabeli są wiersze `cancelled` (D-088).
 * Stare CHECK-i nie znają tego stanu, a „naprawa” wierszy zgadywałaby cudzą
 * decyzję: zamiana na `proposed` wskrzesiłaby prośbę, którą autor wycofał
 * (kucharz dostałby ją znowu do odpowiedzi), a skasowanie pozwoliłoby prosić
 * drugi raz o to samo wykonanie. Komunikat mówi, co zrobić ręcznie. Przy
 * braku takich wierszy (CI, `migrate:refresh`) cofnięcie przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const STATUS = 'recipe_hints_status_check';

    private const SPOJNY = 'recipe_hints_stan_spojny_check';

    private const STATUS_NOWY = "status IN ('proposed', 'accepted', 'declined', 'withdrawn', 'cancelled')";

    private const STATUS_STARY = "status IN ('proposed', 'accepted', 'declined', 'withdrawn')";

    private const SPOJNY_NOWY = "(status IN ('proposed', 'cancelled') AND decided_at IS NULL AND withdrawn_at IS NULL)
        OR (status IN ('accepted', 'declined') AND decided_at IS NOT NULL AND withdrawn_at IS NULL)
        OR (status = 'withdrawn' AND decided_at IS NOT NULL AND withdrawn_at IS NOT NULL)";

    private const SPOJNY_STARY = "(status = 'proposed' AND decided_at IS NULL AND withdrawn_at IS NULL)
        OR (status IN ('accepted', 'declined') AND decided_at IS NOT NULL AND withdrawn_at IS NULL)
        OR (status = 'withdrawn' AND decided_at IS NOT NULL AND withdrawn_at IS NOT NULL)";

    public function up(): void
    {
        $this->zamien(self::STATUS_NOWY, self::SPOJNY_NOWY, self::STATUS_STARY, self::SPOJNY_STARY);
    }

    public function down(): void
    {
        if (Schema::hasTable('recipe_hints')) {
            $this->odmowJesliSaAnulowane();
        }

        $this->zamien(self::STATUS_STARY, self::SPOJNY_STARY, self::STATUS_NOWY, self::SPOJNY_NOWY);
    }

    /**
     * Zakłada CHECK-i z `$status`/`$spojny`; gdy walidacja padnie, przywraca
     * poprzednie (`$statusPoprzedni`/`$spojnyPoprzedni`) i przerywa.
     */
    private function zamien(string $status, string $spojny, string $statusPoprzedni, string $spojnyPoprzedni): void
    {
        if (! Schema::hasTable('recipe_hints')) {
            return;
        }

        $this->podmienOgraniczenia($status, $spojny);

        try {
            DB::statement('ALTER TABLE recipe_hints VALIDATE CONSTRAINT '.self::STATUS);
            DB::statement('ALTER TABLE recipe_hints VALIDATE CONSTRAINT '.self::SPOJNY);
        } catch (Throwable $e) {
            $this->podmienOgraniczenia($statusPoprzedni, $spojnyPoprzedni);

            throw new RuntimeException(
                'Nie udało się zwalidować ograniczeń tabeli recipe_hints: w tabeli są wiersze niezgodne z nową regułą. '
                .'Poprzednie ograniczenia zostały przywrócone. Sprawdź wiersze zapytaniem '
                .'SELECT id, status, decided_at, withdrawn_at FROM recipe_hints i uruchom migrację ponownie.',
                previous: $e,
            );
        }
    }

    /**
     * Zdjęcie starych i założenie nowych CHECK-ów w JEDNYM poleceniu `ALTER TABLE`:
     * nie ma chwili, w której tabela nie ma żadnego z nich (równoległy zapis nie
     * wpisze wtedy wiersza łamiącego regułę). Oba nowe wchodzą `NOT VALID`;
     * sprawdzenie istniejących wierszy robi osobny `VALIDATE` (blokada
     * `SHARE UPDATE EXCLUSIVE`, bez blokowania zapisu).
     */
    private function podmienOgraniczenia(string $status, string $spojny): void
    {
        DB::statement(
            'ALTER TABLE recipe_hints '
            .'DROP CONSTRAINT IF EXISTS '.self::STATUS.', '
            .'DROP CONSTRAINT IF EXISTS '.self::SPOJNY.', '
            .'ADD CONSTRAINT '.self::STATUS.' CHECK ('.$status.') NOT VALID, '
            .'ADD CONSTRAINT '.self::SPOJNY.' CHECK ('.$spojny.') NOT VALID',
        );
    }

    private function odmowJesliSaAnulowane(): void
    {
        $ile = (int) DB::table('recipe_hints')->where('status', 'cancelled')->count();

        if ($ile === 0) {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji nie zna stanu „anulowana” — w tabeli recipe_hints są takie wiersze: {$ile}.

            To prośby, które autor przepisu sam wycofał, zanim kucharz odpowiedział. Nie zgadujemy za ludzi:
              - zamiana na „proposed” wskrzesiłaby prośbę i kucharz dostałby ją znowu do odpowiedzi;
              - skasowanie wierszy pozwoliłoby autorom prosić drugi raz o to samo wykonanie.

            Co zrobić ręcznie, jeśli cofnięcie schematu jest naprawdę potrzebne:
              1. zrób kopię tabeli:
                 CREATE TABLE recipe_hints_kopia AS SELECT * FROM recipe_hints;
              2. zdecyduj z właścicielem, co ma się stać z tymi wierszami, i zrób to jawnym zapytaniem;
              3. dopiero wtedy cofnij migrację ponownie.

            Przy braku takich wierszy cofnięcie przechodzi bez pytania.
            TEKST);
    }
};

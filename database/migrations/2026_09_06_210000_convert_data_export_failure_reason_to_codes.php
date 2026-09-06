<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `data_exports.failure_reason` przechodzi z wolnego tekstu na zamknięty
 * zbiór kodów z `App\Models\DataExport::REASONS` (audyt W7-07).
 *
 * PROBLEM
 * `GenerateUserExport::reasonFor()` zapisywał w tej kolumnie zdanie
 * zbudowane z `$e->getMessage()` — dokładnie to, przed czym ostrzegał
 * komentarz nad tą metodą („nie może to być «SQLSTATE[42P01]»"). Kolumna
 * jest renderowana wprost na ekranie ustawień (`resources/views/pages/
 * settings/data.blade.php`), więc techniczny szczegół (SQLSTATE, ścieżka
 * na dysku tymczasowym, komunikat biblioteki ZIP) trafiał na ekran
 * użytkownika. Od teraz w kolumnie siedzi jeden z czterech krótkich kodów
 * (`account_missing`, `storage`, `timeout`, `unknown`), a ekran woła
 * `DataExport::failureReasonLabel()`, która dopiero zamienia kod na tekst
 * po polsku.
 *
 * BACKFILL
 * Rozpoznajemy DOKŁADNIE dwa znane literały, które stary `reasonFor()`
 * i `failed()` zapisywały wprost, bez udziału `$e->getMessage()`:
 *   - `Konto nie istnieje.`                                    → account_missing
 *   - `Przygotowanie paczki przerwane (przekroczony limit czasu).` → timeout
 * Każdy inny wiersz z niepustym `failure_reason` (w tym KAŻDY, który
 * zaczynał się od „Nie udało się przygotować paczki: " i ciągnął dalej
 * oryginalnym komunikatem wyjątku) trafia do `unknown`. Nie da się z samego
 * zdania odtworzyć, czy awaria była w magazynie plików czy gdzie indziej —
 * ten fakt nigdy nie był tu zapisany osobno, tylko zgadywany z treści.
 * Zgadywanie po podłańcuchach (np. szukanie „Storage"/„disk" w treści)
 * dawałoby fałszywe trafienia i nie jest niczym lepszym niż `unknown`.
 *
 * Kolejność zapytań ma znaczenie: najpierw dwa dokładne dopasowania,
 * dopiero potem „reszta, która nie jest już jednym z czterech kodów" —
 * dzięki temu migracja jest bezpieczna do uruchomienia niezależnie od tego,
 * czy nowy kod `GenerateUserExport` (zapisujący już kody, nie zdania)
 * ruszył przed migracją, czy po niej.
 *
 * TYP KOLUMNY
 * `string(500)` ZOSTAJE, nie zwężamy. Kody są dziś krótkie (`storage`,
 * `timeout`, `account_missing`, `unknown`), więc zwężenie by się zmieściło,
 * ale to jest osobna decyzja o niczym tu nie zależnym — węższa kolumna nic
 * nie kosztuje, ale też niczego nie chroni (kod i tak jest kontrolowany
 * przez `DataExport::REASONS`, nie przez długość pola), a szerszy limit
 * zostaje jako margines, gdyby zbiór kodów urósł.
 *
 * ROLLBACK
 * Cofamy `failure_reason` do `NULL` tam, gdzie dziś siedzi jeden z naszych
 * czterech kodów. Oryginalnych wolnych tekstów NIE da się odtworzyć — ale
 * nigdy nie były one źródłem prawdy (to zawsze był tylko zserializowany
 * `$e->getMessage()`), a sama treść historycznych awarii i tak została
 * w logu aplikacji (`Log::warning` w `GenerateUserExport::handle()`), nie
 * w tej tabeli. `NULL` po cofnięciu nie jest więc utratą informacji, którą
 * kiedykolwiek dało się tu bezpiecznie pokazać użytkownikowi.
 */
return new class extends Migration
{
    private const KODY = ['account_missing', 'storage', 'timeout', 'unknown'];

    public function up(): void
    {
        DB::table('data_exports')
            ->where('status', 'failed')
            ->where('failure_reason', 'Konto nie istnieje.')
            ->update(['failure_reason' => 'account_missing']);

        DB::table('data_exports')
            ->where('status', 'failed')
            ->where('failure_reason', 'Przygotowanie paczki przerwane (przekroczony limit czasu).')
            ->update(['failure_reason' => 'timeout']);

        DB::table('data_exports')
            ->where('status', 'failed')
            ->whereNotNull('failure_reason')
            ->whereNotIn('failure_reason', self::KODY)
            ->update(['failure_reason' => 'unknown']);
    }

    public function down(): void
    {
        DB::table('data_exports')
            ->where('status', 'failed')
            ->whereIn('failure_reason', self::KODY)
            ->update(['failure_reason' => null]);
    }
};

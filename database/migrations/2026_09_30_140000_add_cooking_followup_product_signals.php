<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cztery nowe nazwy w zamkniętym słowniku `product_signals` — pomiar
 * „Jak wyszło?” (F1, research 30.09.2026, D-333).
 *
 * PO CO
 * Karta F1 każe NAJPIERW zmierzyć, ile gotowań w trybie gotowania dochodzi
 * do ostatniego kroku bez „Ugotowałem”. Tego dziś nie wie nikt: postęp
 * gotowania żyje w sesji przeglądarki (`CookingModeController`), a z sesji
 * nie da się policzyć niczego zbiorczo. Stąd sygnały:
 *
 *   - `cooking_last_step_reached`  — nowe gotowanie doszło do ostatniego kroku,
 *   - `cooking_last_step_cooked`   — to gotowanie skończyło się „Ugotowałem”
 *                                    (`properties.po_pytaniu`: czy po „Jak wyszło?”),
 *   - `cooking_followup_shown`     — „Jak wyszło?” pokazało się pierwszy raz,
 *   - `cooking_followup_dismissed` — ktoś odpowiedział „Nie teraz”.
 *
 * PRYWATNOŚĆ: sygnały zapisujemy BEZ `user_id` i bez przepisu
 * (`App\Domain\Recipes\Gotowanie\JakWyszlo`). Raport potrzebuje samych
 * liczników, a „kto co gotował” zna tylko sesja tej osoby. Mieści się to
 * w obecnym opisie polityki prywatności („zdarzenia dotyczące korzystania
 * z aplikacji, w miarę możliwości bez danych identyfikujących wprost”,
 * retencja 90 dni) — polityka nie zmienia się.
 *
 * DDL BEZ PRZERWY W OCHRONIE (AGENTS.md §6)
 * Nowy CHECK powstaje pod tymczasową nazwą z `NOT VALID`, potem osobno
 * `VALIDATE CONSTRAINT`, dopiero potem znika stary i nowy przejmuje jego
 * nazwę. Między krokami tabela ma zawsze co najmniej jeden CHECK.
 *
 * ROLLBACK
 * `down()` kasuje wyłącznie wiersze tych czterech sygnałów (telemetria
 * z retencją 90 dni, nie decyzja człowieka — D-088 nie dotyczy) i przywraca
 * poprzedni słownik tą samą drogą.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NAZWA = 'product_signals_signal_name_check';

    private const TYMCZASOWA = 'product_signals_signal_name_check_nowy';

    private const STARE = [
        'photo_upload_failed', 'search_performed', 'weekly_digest_queued', 'weekly_digest_unsubscribed',
        'pwa_prompt_shown', 'pwa_install_requested', 'pwa_prompt_dismissed', 'pwa_installed',
    ];

    private const NOWE = [
        'cooking_last_step_reached', 'cooking_last_step_cooked', 'cooking_followup_shown', 'cooking_followup_dismissed',
    ];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->przestawCheck([...self::STARE, ...self::NOWE]);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::table('product_signals')->whereIn('signal_name', self::NOWE)->delete();

        $this->przestawCheck(self::STARE);
    }

    /** @param  list<string>  $nazwy */
    private function przestawCheck(array $nazwy): void
    {
        $lista = implode(', ', array_map(static fn (string $n): string => "'".$n."'", $nazwy));

        DB::statement('ALTER TABLE product_signals DROP CONSTRAINT IF EXISTS '.self::TYMCZASOWA);
        DB::statement('ALTER TABLE product_signals ADD CONSTRAINT '.self::TYMCZASOWA." CHECK (signal_name IN ({$lista})) NOT VALID");
        DB::statement('ALTER TABLE product_signals VALIDATE CONSTRAINT '.self::TYMCZASOWA);
        DB::statement('ALTER TABLE product_signals DROP CONSTRAINT IF EXISTS '.self::NAZWA);
        DB::statement('ALTER TABLE product_signals RENAME CONSTRAINT '.self::TYMCZASOWA.' TO '.self::NAZWA);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trzy nowe nazwy w zamkniętym słowniku `product_signals` — pomiar
 * „Zużyj w pierwszej kolejności” (#1903, D-333).
 *
 * PO CO
 * Serwis nie ma jeszcze prawdziwych użytkowników, więc nie ma pomiaru, czy
 * ktokolwiek używa terminów przy produktach. Sygnały (bez nazw produktów,
 * bez dat, bez identyfikacji konta):
 *
 *   - `pantry_expiry_set`            — ktoś zapisał termin przy produkcie,
 *   - `pantry_priority_viewed`       — ktoś otworzył listę, na której jest
 *                                      sekcja „Zużyj w pierwszej kolejności”,
 *   - `pantry_cook_priority_opened`  — ktoś otworzył przepisy w trybie
 *                                      „Najpierw to, co się psuje”.
 *
 * Odsetek kont z co najmniej jednym terminem liczy się wprost z `pantry_items`
 * (bez sygnału). „Zużyte” i „wyrzucone” nie są mierzone (D-333, D6 A): serwis
 * nie ocenia, co ktoś zrobił z produktem.
 *
 * PRYWATNOŚĆ: sygnały zapisujemy BEZ `user_id` (`ZapiszSygnal::handle(null, …)`),
 * tak jak sygnały „Jak wyszło?”. Retencja 90 dni, sprzeciw wobec statystyk
 * (#2277) obowiązuje tak jak dla innych sygnałów.
 *
 * DDL BEZ PRZERWY W OCHRONIE (AGENTS.md §6): nowy CHECK powstaje pod
 * tymczasową nazwą z `NOT VALID`, potem `VALIDATE`, dopiero potem znika stary
 * i nowy przejmuje jego nazwę. Między krokami tabela ma zawsze CHECK.
 *
 * ROLLBACK: `down()` kasuje wyłącznie wiersze tych trzech sygnałów (telemetria
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
        'cooking_last_step_reached', 'cooking_last_step_cooked', 'cooking_followup_shown', 'cooking_followup_dismissed',
    ];

    private const NOWE = [
        'pantry_expiry_set', 'pantry_priority_viewed', 'pantry_cook_priority_opened',
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

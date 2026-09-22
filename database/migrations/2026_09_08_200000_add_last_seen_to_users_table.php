<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users.ostatnio_widziany_at` — jedyny znacznik, który zamienia bramkę V1
 * z `docs/ROADMAP.md` („Planner/groups/forks dopiero gdy WAC i D30 pokazują
 * powroty") w liczbę do przeczytania (`php artisan kuking:raport`).
 *
 * DLACZEGO KOLUMNA NA `users`, A NIE TRZECI SYGNAŁ W `product_signals`
 * Zmierzone, nie zgadnięte — dwa niezależne pomiary, żaden z osobna nie
 * rozstrzyga, ale RAZEM tak:
 *
 * 1. **Retencja `product_signals` NIE jest tym, co przesądza.** Domyślna
 *    wartość `config('kuking.analytics.signal_retention_days')` to 90 dni
 *    (`config/kuking.php`, komenda `kuking:sprzataj-sygnaly` w
 *    `routes/console.php`, codziennie o 04:00) — więcej niż 30, więc D30
 *    dałoby się policzyć z sygnałów, gdyby to była jedyna przeszkoda.
 * 2. **Ale `product_signals` NIE JEST ogólnym dziennikiem odwiedzin i nie ma
 *    nim być.** Migracja `2026_09_06_220000_create_product_signals_table.php`
 *    ma dwa CHECK-i, z których pierwszy zamyka `signal_name` na dokładnie
 *    DWA zdarzenia (`photo_upload_failed`, `search_performed`) — to jest
 *    świadomy, wąski kształt, wprost przeciwstawiony w komentarzu tamtej
 *    migracji ogólnemu serwisowi `product_events`
 *    (`docs/seo/ANALYTICS.md` §7), którego to repozytorium NIE buduje
 *    (AGENTS.md §3: zero „kolejnej biblioteki/serwisu na przyszłość" bez
 *    zmierzonej potrzeby). Trzeci sygnał w rodzaju „ktoś tu był" na KAŻDE
 *    uwierzytelnione żądanie zmieniałby charakter tej tabeli z „rzadkie
 *    zdarzenia techniczne" na „log każdej wizyty każdego konta" — czyli
 *    dokładnie w ten serwis, którego AGENTS.md zabrania budować bez
 *    osobnego, zmierzonego uzasadnienia.
 * 3. **Koszt zapisu jest strukturalnie inny.** Wiersz w `product_signals`
 *    PRZYBYWA przy każdym zdarzeniu — nawet throttlowany do jednego na
 *    kilkanaście minut na osobę, to nadal tabela, która rośnie z każdym
 *    aktywnym kontem i wymaga retencji, żeby nie rosła bez końca (dokładnie
 *    to, co robi `PrzedawnioneSygnaly` dla dwóch istniejących zdarzeń).
 *    Kolumna na `users` jest NADPISYWANA, nie dopisywana: jeden wiersz na
 *    konto, raz na zawsze, zero przyrostu w czasie i zero potrzeby
 *    retencji/sprzątania. Dla pytania „kiedy ktoś był ostatnio" — gdzie
 *    liczy się wyłącznie NAJNOWSZA wartość — jest to tańsze i prostsze niż
 *    log zdarzeń, nie tylko inaczej zamodelowane.
 *
 * `timestampTz`, NIE `date` — to samo uzasadnienie co reszta schematu
 * (AGENTS.md §6: `timestamptz` dla czasu) i to samo ostrzeżenie co
 * `App\Support\Czas`: WAC/D7/D30 porównują ten znacznik z `created_at`
 * (`timestamptz`), więc obie strony porównania muszą nieść tę samą strefę
 * (UTC w bazie), inaczej różnica dni byłaby przesunięta o godziny zależnie
 * od strefy SESJI Postgresa — dokładnie usterka opisana w
 * `docs/research/ANALITYKA_STAN_WDROZENIA.md` §1.3, tu uniknięta przez
 * to, że obie kolumny to `timestamptz` i porównanie dzieje się na
 * uniwersalnym momencie, nie na dacie ściennej.
 *
 * `NULLABLE`, BEZ WARTOŚCI DOMYŚLNEJ
 * `NULL` znaczy „nigdy nie widziany od czasu wdrożenia tej kolumny" —
 * odróżnialne od „widziany bardzo dawno temu". Konto sprzed tej migracji nie
 * dostaje fałszywego „teraz" ani fałszywego „nigdy" — dostaje stan
 * rzeczywiście nieznany, a WAC/D7/D30 (§`App\Domain\Analytics`) traktują
 * `NULL` jako „nie liczy się do aktywnych", nie jako zero.
 *
 * INDEKS
 * Zwykły B-tree na samej kolumnie: obie klasy raportu (`AktywniOstatnio`
 * i `PowrotPoDniach`) filtrują `WHERE ostatnio_widziany_at >= ?` albo
 * `WHERE ostatnio_widziany_at IS NOT NULL AND ostatnio_widziany_at >= ...`
 * — bez indeksu to pełne przejście po `users` przy każdym uruchomieniu
 * `kuking:raport`. Bez `WHERE deleted_at ...` (jak przy indeksach
 * częściowych gdzie indziej w tym pliku), bo `users` nie ma soft delete.
 *
 * ROLLBACK
 * Bezpieczny bez zastrzeżeń: kolumna jest WYŁĄCZNIE odczytywana przez
 * raport, żadna reguła autoryzacji ani żadna inna funkcja produktu jej nie
 * używa. `down()` kasuje kolumnę i indeks — jedyny skutek to utrata
 * najnowszego znacznika „ostatnio widziany" dla każdego konta; middleware
 * odbuduje go od nowa przy najbliższej wizycie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('ostatnio_widziany_at')->nullable()->after('remember_token');
        });

        if ($this->isPostgres()) {
            DB::statement('CREATE INDEX users_ostatnio_widziany_idx ON users (ostatnio_widziany_at)');
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS users_ostatnio_widziany_idx');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ostatnio_widziany_at');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

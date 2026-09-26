<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `ai_budzet_dzienny` — ile pieniędzy na płatny model OpenAI poszło danego
 * dnia (D-297). Jeden wiersz na dzień kalendarzowy w strefie
 * `kuking.strefa` (Europe/Warsaw).
 *
 * DLACZEGO W POSTGRESIE, A NIE W CACHE'U: budżet jest pieniędzmi. Licznik
 * w cache'u znika przy restarcie i nie zna `SELECT … FOR UPDATE`, a to
 * właśnie blokada wiersza dnia sprawia, że dwa równoległe odczyty nie
 * przekroczą limitu razem (AGENTS.md §3: bez Redisa).
 *
 * KWOTY W MIKRO-USD (1 USD = 1 000 000). Liczby całkowite, bo sumujemy
 * tysiące małych kwot, a `float` gubi grosze. Przy cenie w USD za milion
 * tokenów koszt w mikro-USD to po prostu `tokeny × cena` — bez dzielenia.
 *
 * ROLLBACK: `down()` kasuje tabelę. Po ponownym `migrate` dzisiejszy
 * i miesięczny licznik zaczynają od zera, czyli serwis może w tym miesiącu
 * wydać drugi raz tyle, ile już wydał. Dlatego `down()` ODMAWIA, gdy
 * w bieżącym miesiącu są wydatki (D-088 — wartość, której `up()` nie
 * odtworzy), i mówi, co zrobić: wyłączyć funkcję (`OPENAI_IMPORT_KEY=`)
 * albo potwierdzić wprost zmienną `KUKING_ROLLBACK_KASUJ_BUDZET_AI=true`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_budzet_dzienny', function (Blueprint $table): void {
            $table->date('dzien')->primary();
            $table->bigInteger('zarezerwowano_mikrousd')->default(0);
            $table->bigInteger('wydano_mikrousd')->default(0);
            $table->integer('liczba_wywolan')->default(0);
            $table->timestampsTz();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ai_budzet_dzienny ADD CONSTRAINT ai_budzet_dzienny_kwoty_check CHECK (zarezerwowano_mikrousd >= 0 AND wydano_mikrousd >= 0 AND liczba_wywolan >= 0)');
    }

    public function down(): void
    {
        if (Schema::hasTable('ai_budzet_dzienny') && ! $this->wolnoKasowac()) {
            $wydatki = (int) DB::table('ai_budzet_dzienny')
                ->where('dzien', '>=', now()->timezone((string) config('kuking.strefa', 'Europe/Warsaw'))->startOfMonth()->toDateString())
                ->sum(DB::raw('zarezerwowano_mikrousd + wydano_mikrousd'));

            if ($wydatki > 0) {
                throw new RuntimeException(
                    'Cofnięcie tej migracji wyzerowałoby licznik wydatków na model OpenAI w bieżącym miesiącu '
                    .'(wydano i zarezerwowano razem: '.number_format($wydatki / 1_000_000, 2, ',', ' ')." USD). Po ponownym migrate serwis mógłby wydać drugi raz tyle samo.\n\n"
                    ."CO ZROBIĆ\n"
                    ."Najpierw wyłącz odczyt przepisów: usuń OPENAI_IMPORT_KEY ze zmiennych środowiska.\n"
                    .'Jeśli licznik ma naprawdę zniknąć: KUKING_ROLLBACK_KASUJ_BUDZET_AI=true php artisan migrate:rollback',
                );
            }
        }

        Schema::dropIfExists('ai_budzet_dzienny');
    }

    /** `getenv()`, nie `env()` — ten sam powód co w migracji dziennika zgód. */
    private function wolnoKasowac(): bool
    {
        return filter_var((string) getenv('KUKING_ROLLBACK_KASUJ_BUDZET_AI'), FILTER_VALIDATE_BOOLEAN);
    }
};

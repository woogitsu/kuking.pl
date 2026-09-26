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
 * `ai_rezerwacje` — KSIĘGA REZERWACJI (ta sama migracja, bo bez niej budżet
 * nie ma sensu). Jeden wiersz na jedną próbę płatnego wywołania: klucz
 * `(import_id, proba)` jest UNIKALNY, a stan przechodzi wyłącznie
 *
 *     zarezerwowana ──► wyslana ──► rozliczona
 *           │               └─────► (wygaszenie = rozliczona całą kwotą)
 *           └──────────────────────► zwolniona
 *
 * warunkowym `UPDATE … WHERE stan IN ('zarezerwowana', 'wyslana')`. Dlatego
 * rozliczenie jest idempotentne (drugie rozliczenie tej samej próby nie
 * trafia w żaden wiersz, więc nie dotyka budżetu — #1974), a rezerwację,
 * której nikt nie domknął (proces zabity, zapis zlecenia padł — #1973),
 * znajduje `failed()`, następna próba i `kuking:odzyskaj-importy`. Wiersz
 * rezerwacji powstaje w TEJ SAMEJ transakcji co zwiększenie
 * `zarezerwowano_mikrousd` — nie ma rezerwacji bez śladu.
 *
 * `import_id` BEZ KLUCZA OBCEGO, świadomie: usunięcie konta kasuje zlecenia
 * (`EraseAccountData`), a otwarta rezerwacja musi przeżyć zlecenie, żeby
 * sprzątanie po czasie ją domknęło — kaskada zabrałaby pieniądze z księgi,
 * zostawiając je w `zarezerwowano_mikrousd`. Sam UUID po usunięciu
 * zlecenia nikogo nie wskazuje.
 *
 * ROLLBACK: `down()` kasuje obie tabele. Po ponownym `migrate` dzisiejszy
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

        Schema::create('ai_rezerwacje', function (Blueprint $table): void {
            $table->id();
            $table->uuid('import_id');
            $table->unsignedSmallInteger('proba');
            // Rozliczenie trafia do TEGO SAMEGO dnia, także po północy.
            $table->date('dzien');
            $table->foreign('dzien')->references('dzien')->on('ai_budzet_dzienny')->restrictOnDelete();
            $table->bigInteger('mikrousd');
            $table->string('stan', 15)->default('zarezerwowana');
            $table->bigInteger('wydano_mikrousd')->nullable();
            $table->timestampTz('zamknieto_at')->nullable();
            $table->timestampsTz();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE ai_budzet_dzienny ADD CONSTRAINT ai_budzet_dzienny_kwoty_check CHECK (zarezerwowano_mikrousd >= 0 AND wydano_mikrousd >= 0 AND liczba_wywolan >= 0)');

        DB::statement("ALTER TABLE ai_rezerwacje ADD CONSTRAINT ai_rezerwacje_stan_check CHECK (stan IN ('zarezerwowana', 'wyslana', 'rozliczona', 'zwolniona'))");
        DB::statement('ALTER TABLE ai_rezerwacje ADD CONSTRAINT ai_rezerwacje_kwoty_check CHECK (proba >= 1 AND mikrousd >= 0 AND (wydano_mikrousd IS NULL OR wydano_mikrousd >= 0))');
        // Kwota wydana jest DOKŁADNIE przy rozliczonej; zamknięcie dokładnie
        // przy stanie końcowym — „rozliczona bez kwoty” nie ma znaczenia.
        DB::statement("ALTER TABLE ai_rezerwacje ADD CONSTRAINT ai_rezerwacje_wydano_check CHECK ((stan = 'rozliczona') = (wydano_mikrousd IS NOT NULL))");
        DB::statement("ALTER TABLE ai_rezerwacje ADD CONSTRAINT ai_rezerwacje_zamkniecie_check CHECK ((stan IN ('rozliczona', 'zwolniona')) = (zamknieto_at IS NOT NULL))");
        // Klucz idempotencji: jedna próba zlecenia = jedna rezerwacja.
        DB::statement('CREATE UNIQUE INDEX ai_rezerwacje_import_proba_unique ON ai_rezerwacje (import_id, proba)');
        // Sprzątanie po czasie pyta wyłącznie o otwarte.
        DB::statement("CREATE INDEX ai_rezerwacje_otwarte_idx ON ai_rezerwacje (created_at) WHERE stan IN ('zarezerwowana', 'wyslana')");
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

        Schema::dropIfExists('ai_rezerwacje');
        Schema::dropIfExists('ai_budzet_dzienny');
    }

    /** `getenv()`, nie `env()` — ten sam powód co w migracji dziennika zgód. */
    private function wolnoKasowac(): bool
    {
        return filter_var((string) getenv('KUKING_ROLLBACK_KASUJ_BUDZET_AI'), FILTER_VALIDATE_BOOLEAN);
    }
};

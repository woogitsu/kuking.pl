<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `importy_przepisow` — jedno zlecenie odczytu przepisu (V2, D-298).
 *
 * CO TO JEST: ślad zlecenia „odczytaj przepis z tego zdjęcia kartki", a nie
 * treść przepisu. Treść od pierwszej chwili żyje w zwykłym szkicu
 * (`recipes`, `status = draft`, `visibility = private`), a zdjęcie kartki —
 * w `recipes.source_scan_media_id`. Dzięki temu zdjęcie ma już wszystkie
 * ochrony, jakie serwis daje skanowi kartki: eksport, kasowanie z kontem,
 * `DostepDoZdjecia`, `KasujZdjecie`. Ta tabela NIE wskazuje na `media`
 * i to jest świadome: nowe odwołanie do `media` znaczyłoby kolejny rodzic
 * zdjęcia do obsłużenia w pięciu miejscach naraz.
 *
 * RETENCJA (D-298, `kuking:sprzataj-importy`): `odpowiedz_modelu` znika po
 * 30 dniach (surowy JSON do diagnozy błędów odczytu — może zawierać tekst
 * z kartki), wiersz po 90 dniach. Wiersz nie niesie treści przepisu.
 *
 * `status` I `kod_bledu` POZA `$fillable` (AGENTS.md §7, D-006) — zmienia je
 * wyłącznie zadanie odczytu nazwanymi metodami modelu.
 *
 * ROLLBACK: `down()` kasuje tabelę. Dane są pochodne (ślad zleceń z 90 dni,
 * bez treści przepisu i bez zdjęć — te zostają w `recipes`/`media`), a dzienne
 * i miesięczne limity na osobę liczą się z tej tabeli: po cofnięciu i ponownym
 * `migrate` limity osób zaczynają się od zera. To jest wada dopuszczalna
 * (najwyżej kilka dodatkowych odczytów w jednym dniu, dalej pod budżetem
 * kwotowym z `ai_budzet_dzienny`), więc `down()` nie odmawia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importy_przepisow', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Szkic powstaje RAZEM ze zleceniem (zdjęcie jest zapisane, zanim
            // cokolwiek pójdzie do modelu). Usunięcie szkicu nie kasuje śladu
            // zlecenia — ten znika z retencją.
            $table->foreignUuid('recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->string('zrodlo', 10);
            $table->string('status', 20)->default('oczekuje');
            $table->string('kod_bledu', 30)->nullable();
            // Adres strony dla importu z URL (osobny etap, D-298).
            $table->text('source_url')->nullable();
            $table->unsignedSmallInteger('proby')->default(0);
            // Rezerwacje budżetu NIE stoją w tym wierszu, tylko w księdze
            // `ai_rezerwacje` (klucz: zlecenie + próba, D-298 „maszyna
            // stanów”). Tu jest suma tego, co rozliczono za wszystkie próby.
            $table->bigInteger('koszt_mikrousd')->nullable();
            $table->integer('tokeny_wejscia')->nullable();
            $table->integer('tokeny_wyjscia')->nullable();
            $table->jsonb('odpowiedz_modelu')->nullable();
            $table->uuid('klucz_wyslania')->nullable();
            $table->timestampTz('rozpoczeto_at')->nullable();
            $table->timestampTz('zakonczono_at')->nullable();
            $table->timestampsTz();
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE importy_przepisow ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement("ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_zrodlo_check CHECK (zrodlo IN ('zdjecie', 'url', 'pdf'))");
        DB::statement("ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_status_check CHECK (status IN ('oczekuje', 'w_toku', 'gotowy', 'nieudany', 'wstrzymany_limitem'))");
        DB::statement(
            'ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_kod_bledu_check CHECK (kod_bledu IS NULL OR kod_bledu IN ('
            ."'limit_osoby', 'budzet_dzienny', 'budzet_miesieczny', 'brak_zgody', 'wylaczony', 'model_niedostepny', "
            ."'nieczytelne', 'odpowiedz_bledna', 'zdjecie_niedostepne', 'szkic_zmieniony', 'blad_wewnetrzny'))",
        );
        // Kod błędu jest DOKŁADNIE wtedy, gdy zlecenie się nie udało albo
        // stoi na limicie — „gotowy z kodem błędu" to stan bez znaczenia.
        DB::statement(
            "ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_kod_przy_bledzie_check CHECK ((status IN ('nieudany', 'wstrzymany_limitem')) = (kod_bledu IS NOT NULL))",
        );
        DB::statement('ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_kwoty_check CHECK (koszt_mikrousd IS NULL OR koszt_mikrousd >= 0)');
        DB::statement("ALTER TABLE importy_przepisow ADD CONSTRAINT importy_przepisow_url_check CHECK (source_url IS NULL OR zrodlo = 'url')");

        // Limity na osobę („ile zleceń dziś / w tym miesiącu") i lista zleceń osoby.
        DB::statement('CREATE INDEX importy_przepisow_osoba_czas_idx ON importy_przepisow (user_id, created_at DESC)');
        // Retencja chodzi po wieku wiersza.
        DB::statement('CREATE INDEX importy_przepisow_created_idx ON importy_przepisow (created_at)');
        // Bramka publikacji szkicu z odczytu pyta „czy ten przepis ma odczyt".
        DB::statement('CREATE INDEX importy_przepisow_recipe_idx ON importy_przepisow (recipe_id) WHERE recipe_id IS NOT NULL');
        // Odzyskiwanie porzuconych zleceń (`kuking:odzyskaj-importy`, co
        // kwadrans) pyta tylko o stany przejściowe — reszta tabeli to stany
        // końcowe, których indeks nie potrzebuje.
        DB::statement("CREATE INDEX importy_przepisow_przejsciowe_idx ON importy_przepisow (updated_at) WHERE status IN ('oczekuje', 'w_toku')");
        // Idempotencja: jedno wysłanie formularza = jedno zlecenie (ADR_IDEMPOTENCJA_FORMULARZY).
        DB::statement('CREATE UNIQUE INDEX importy_przepisow_klucz_wyslania_unique ON importy_przepisow (user_id, klucz_wyslania) WHERE klucz_wyslania IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('importy_przepisow');
    }
};

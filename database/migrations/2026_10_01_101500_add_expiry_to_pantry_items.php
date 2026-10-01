<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Zużyj w pierwszej kolejności” — termin, rodzaj terminu, ilość i flaga
 * „mrożone” przy produkcie z listy „Co mam w domu” (#1903, D-333).
 *
 * CO TU JEST
 * Cztery opcjonalne kolumny w istniejącym `pantry_items`:
 *  - `expires_on date NULL` — dzień z opakowania. `date`, nie `timestamptz`:
 *    termin z opakowania to dzień kalendarzowy bez strefy. Strefa wchodzi
 *    dopiero przy pytaniu „czy to już dziś” i liczy ją aplikacja
 *    (`Europe/Warsaw`, `App\Support\Czas`), nie baza;
 *  - `expiry_kind varchar(12) NULL` — `use_by` („Należy zużyć do”) albo
 *    `best_before` („Najlepiej spożyć przed”). Priorytet liczy się tak samo,
 *    różnica jest na opakowaniu i ma zostać widoczna;
 *  - `quantity_note varchar(40) NULL` — ilość jako WOLNY TEKST („pół kostki”).
 *    Bez liczb i jednostek z tego samego powodu co `ingredient_text` (D-333):
 *    liczby wymagałyby parsera i konwersji;
 *  - `frozen boolean NOT NULL DEFAULT false` — produkt w zamrażarce wypada
 *    z pilnych; wpisany termin zostaje.
 *
 * `ADD COLUMN` bez przepisywania tabeli: kolumny nullable albo stały DEFAULT
 * to zmiana samych metadanych (PostgreSQL 11+). `lock_timeout = 5s` ustawia
 * migrator (`LimitBlokadMigracji`).
 *
 * CHECK-i (AGENTS.md §6: `NOT VALID`, potem osobno `VALIDATE`, poza jedną
 * transakcją — stąd `$withinTransaction = false`):
 *  - rodzaj terminu tylko z dwóch wartości;
 *  - termin i rodzaj idą parą: nie ma terminu bez rodzaju i odwrotnie;
 *  - termin w stałym zakresie 2020-01-01 … 2100-12-31 (stałe, bez `now()`,
 *    żeby CHECK był niezmienny);
 *  - ilość po obcięciu spacji ma od 1 do 40 znaków.
 * Każdy jest prawdziwy dla istniejących wierszy (nowe kolumny są puste).
 *
 * INDEKSU PO TERMINIE NIE MA. Lista ma najwyżej 150 pozycji na konto i zawsze
 * jest filtrowana po `user_id` (pokrywa ją `UNIQUE (user_id, klucz)`).
 * Sobotnie przypomnienie idzie od kont ze zgodą (częściowy indeks na `users`
 * w migracji `add_pantry_reminder_consent_to_users`), a potem po produktach
 * konta — nie skanuje wszystkich produktów.
 *
 * PRYWATNOŚĆ. Termin, ilość i flaga są w paczce danych (`co_mam_w_domu`),
 * znikają z wymazaniem konta razem z wierszem i nie trafiają nigdzie poza
 * konto właściciela.
 *
 * ROLLBACK (D-088, odmowa WĄSKA). `down()` odmawia wyłącznie wtedy, gdy
 * istnieje wiersz z terminem, ilością albo flagą „mrożone” — to dane wpisane
 * przez człowieka, których `up()` nie odtworzy. Komunikat mówi, co zrobić.
 * Na pustej i świeżej bazie cofnięcie przechodzi bez pytania. Wymuszenie po
 * zrobieniu kopii: `KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI=1`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> nazwa => warunek */
    private const OGRANICZENIA = [
        'pantry_items_expiry_kind_check' => "expiry_kind IS NULL OR expiry_kind IN ('use_by', 'best_before')",
        'pantry_items_expiry_pair_check' => '(expires_on IS NULL) = (expiry_kind IS NULL)',
        'pantry_items_expires_on_range_check' => "expires_on IS NULL OR expires_on BETWEEN DATE '2020-01-01' AND DATE '2100-12-31'",
        'pantry_items_quantity_note_check' => 'quantity_note IS NULL OR char_length(btrim(quantity_note)) BETWEEN 1 AND 40',
    ];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE pantry_items ADD COLUMN IF NOT EXISTS expires_on date');
        DB::statement('ALTER TABLE pantry_items ADD COLUMN IF NOT EXISTS expiry_kind varchar(12)');
        DB::statement('ALTER TABLE pantry_items ADD COLUMN IF NOT EXISTS quantity_note varchar(40)');
        DB::statement('ALTER TABLE pantry_items ADD COLUMN IF NOT EXISTS frozen boolean NOT NULL DEFAULT false');

        foreach (self::OGRANICZENIA as $nazwa => $warunek) {
            DB::statement("ALTER TABLE pantry_items DROP CONSTRAINT IF EXISTS {$nazwa}");
            DB::statement("ALTER TABLE pantry_items ADD CONSTRAINT {$nazwa} CHECK ({$warunek}) NOT VALID");
            DB::statement("ALTER TABLE pantry_items VALIDATE CONSTRAINT {$nazwa}");
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->upewnijSieZeWolnoKasowacTerminy();

        foreach (array_keys(self::OGRANICZENIA) as $nazwa) {
            DB::statement("ALTER TABLE pantry_items DROP CONSTRAINT IF EXISTS {$nazwa}");
        }

        DB::statement('ALTER TABLE pantry_items DROP COLUMN IF EXISTS frozen');
        DB::statement('ALTER TABLE pantry_items DROP COLUMN IF EXISTS quantity_note');
        DB::statement('ALTER TABLE pantry_items DROP COLUMN IF EXISTS expiry_kind');
        DB::statement('ALTER TABLE pantry_items DROP COLUMN IF EXISTS expires_on');
    }

    private function upewnijSieZeWolnoKasowacTerminy(): void
    {
        if (! Schema::hasColumn('pantry_items', 'expires_on')) {
            return;
        }

        $ile = (int) DB::table('pantry_items')
            ->where(function ($q): void {
                $q->whereNotNull('expires_on')
                    ->orWhereNotNull('quantity_note')
                    ->orWhere('frozen', true);
            })
            ->count();

        if ($ile === 0) {
            return;
        }

        // `getenv()`, nie `env()` — przy `config:cache` `env()` zwraca null.
        if (getenv('KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI') === '1') {
            return;
        }

        throw new RuntimeException(<<<TEKST
            Cofnięcie tej migracji skasuje terminy, ilości i oznaczenia „mrożone” przy produktach z list „Co mam w domu” — bezpowrotnie.
            Liczba produktów z takimi danymi: {$ile}.

            Zanim cofniesz:
              1. zrób kopię:
                 CREATE TABLE pantry_items_terminy_kopia AS SELECT id, expires_on, expiry_kind, quantity_note, frozen FROM pantry_items WHERE expires_on IS NOT NULL OR quantity_note IS NOT NULL OR frozen;
              2. sprawdź, czy naprawdę potrzebujesz cofnięcia SCHEMATU — błąd w ekranie
                 „Zużyj w pierwszej kolejności” naprawia się bez ruszania bazy (kolumny zostają, kod je ignoruje);
              3. jeśli tak, uruchom ponownie z KUKING_ROLLBACK_KASUJE_TERMINY_SPIZARNI=1.

            Na świeżym środowisku, gdzie nikt jeszcze nic nie wpisał, cofnięcie działa bez pytania.
            TEKST);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sobotnie przypomnienie o produktach do zużycia — OSOBNA zgoda (#1903, D-333).
 *
 * `users.wants_pantry_reminder` (`boolean NOT NULL DEFAULT false`) — zgoda na
 * jeden list tygodniowo, w sobotę rano, wysyłany tylko wtedy, gdy na liście
 * „Co mam w domu” jest co wymienić. Domyślnie WYŁĄCZONA: ani podanie terminu
 * przy produkcie, ani założenie listy jej nie daje (PKE art. 398, ten sam
 * wzorzec co `wants_weekly_digest` i `wants_birthday_email`). Każda zmiana
 * zgody zapisuje wiersz w `dziennik_zgod` (D-072) — stąd nowy cel
 * `przypomnienie_spizarni` w CHECK-u `dziennik_zgod_cel_check`.
 *
 * Deduplikacja listów NIE ma kolumny na `users`: rezerwację „jeden list na
 * dobę na adres” robi `przypomnienia_dobowe` (`PrzypomnienieDobowe`), a dzień
 * tygodnia pilnuje sama komenda.
 *
 * INDEKS CZĘŚCIOWY `users_wants_pantry_reminder_idx` (`WHERE
 * wants_pantry_reminder`): komenda skanuje wszystkie konta ze zgodą, a zgodę
 * ma mała mniejszość — bez indeksu byłby to przegląd całej tabeli `users`
 * w każdą sobotę. `CREATE INDEX CONCURRENTLY`, z zdjęciem niedokończonej
 * budowy (INVALID) przed ponowną próbą (AGENTS.md §6).
 *
 * ISTNIEJĄCA TABELA `dziennik_zgod`: nowy CHECK celu jest nadzbiorem starego,
 * więc `NOT VALID` + `VALIDATE` poza jedną transakcją (`$withinTransaction =
 * false`).
 *
 * ROLLBACK (D-088). `down()` ODMAWIA, gdy ktoś ma zgodę albo dziennik zgód ma
 * choć jeden wiersz tego celu. Zgoda wróciłaby jako `false` bez śladu, a
 * wierszy dziennika nie wolno kasować (wyzwalacz append-only), więc starego
 * CHECK-a nie da się przywrócić bez utraty dowodu. Na świeżej bazie i bez
 * takich danych cofnięcie przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEKS = 'users_wants_pantry_reminder_idx';

    private const CELE_PO = "('tygodniowy_digest', 'zyczenia_urodzinowe', 'odczyt_ai', 'regulamin', 'przypomnienie_spizarni')";

    private const CELE_PRZED = "('tygodniowy_digest', 'zyczenia_urodzinowe', 'odczyt_ai', 'regulamin')";

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS wants_pantry_reminder boolean NOT NULL DEFAULT false');

        $this->podmienCelZgody(self::CELE_PO);

        if ($this->indeksNiedokonczony()) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEKS);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEKS.' ON users (id) WHERE wants_pantry_reminder');
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->odmowJesliSaDane();

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEKS);
        $this->podmienCelZgody(self::CELE_PRZED);
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS wants_pantry_reminder');
    }

    private function podmienCelZgody(string $cele): void
    {
        DB::statement('ALTER TABLE dziennik_zgod DROP CONSTRAINT IF EXISTS dziennik_zgod_cel_check');
        DB::statement("ALTER TABLE dziennik_zgod ADD CONSTRAINT dziennik_zgod_cel_check CHECK (cel IN {$cele}) NOT VALID");
        DB::statement('ALTER TABLE dziennik_zgod VALIDATE CONSTRAINT dziennik_zgod_cel_check');
    }

    private function odmowJesliSaDane(): void
    {
        $zeZgoda = Schema::hasColumn('users', 'wants_pantry_reminder')
            ? (int) DB::table('users')->where('wants_pantry_reminder', true)->count()
            : 0;
        $wierszyDziennika = (int) DB::table('dziennik_zgod')->where('cel', 'przypomnienie_spizarni')->count();

        if ($zeZgoda === 0 && $wierszyDziennika === 0) {
            return;
        }

        throw new RuntimeException(
            'Cofnięcie odmówione. Liczba kont ze zgodą na sobotnie przypomnienie o produktach '
            .'(wants_pantry_reminder = true): '.$zeZgoda.'. Liczba wierszy dziennika zgód z celem '
            ."przypomnienie_spizarni: {$wierszyDziennika}. Zgoda wróciłaby po cofnięciu jako false bez "
            .'żadnego śladu, a wierszy dziennika zgód nie wolno kasować (D-072, wyzwalacz tylko do '
            ."dopisywania).\n\n"
            ."CO ZROBIĆ:\n"
            ."  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej nie czyta "
            ."tej kolumny, a szerszy CHECK niczego mu nie psuje;\n"
            ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz listę zgód przed cofnięciem:\n"
            ."      SELECT id FROM users WHERE wants_pantry_reminder = true;\n"
            .'    Dziennik zgód zostaje w bazie — tej migracji nie da się wtedy cofnąć w całości.',
        );
    }

    private function indeksNiedokonczony(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

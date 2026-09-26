<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Urodziny w profilu: DZIEŃ I MIESIĄC, BEZ ROKU (issue #1755, etap a).
 *
 * DLACZEGO BEZ ROKU
 * Decyzja właściciela z 25.09.2026 po researchu
 * `docs/research/PROFIL_FORMA_I_URODZINY.md` §3.2: do życzeń rok nie jest
 * potrzebny, a pełna data urodzenia stoi wprost na liście „nie zbierać"
 * (`docs/SECURITY_PRIVACY_LEGAL.md`). Rok nie służy też weryfikacji wieku
 * (`docs/legal/COMPLIANCE.md` §4) — to deklaracja jak checkbox 16+.
 *
 * DLACZEGO NA `users`, A NIE NA `profiles`
 * `profiles` to dane PUBLICZNEGO profilu. Urodzin nie pokazujemy nikomu poza
 * samą osobą (wyjątek: przypomnienie obserwującym po jawnym włączeniu, etap d),
 * więc siedzą obok innych prywatnych ustawień konta.
 *
 * CHECK-I W BAZIE, NIE TYLKO W WALIDATORZE
 *   - oba pola puste albo oba wypełnione — pół daty nie ma sensu;
 *   - miesiąc 1–12, dzień 1–31;
 *   - dzień istnieje w danym miesiącu: 31 kwietnia i 30 lutego odpadają,
 *     29 lutego przechodzi (życzenia wtedy 28 lutego w latach
 *     nieprzestępnych — to reguła wyświetlania, nie zapisu).
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy ktokolwiek wpisał datę. To dane,
 * które człowiek sam podał; po cyklu `rollback` → `migrate` kolumny wróciłyby
 * puste, życzenia przestałyby przychodzić bez śladu błędu. Na świeżej bazie
 * i przy samych pustych polach rollback przechodzi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->smallInteger('birthday_day')->nullable();
            $table->smallInteger('birthday_month')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_birthday_pair_check '
            .'CHECK ((birthday_day IS NULL) = (birthday_month IS NULL))',
        );

        DB::statement(
            'ALTER TABLE users ADD CONSTRAINT users_birthday_range_check '
            .'CHECK (birthday_month IS NULL OR ('
            .'birthday_month BETWEEN 1 AND 12 AND birthday_day BETWEEN 1 AND '
            .'CASE WHEN birthday_month = 2 THEN 29 WHEN birthday_month IN (4, 6, 9, 11) THEN 30 ELSE 31 END'
            .'))',
        );
    }

    public function down(): void
    {
        // Strażnik PRZED zdjęciem kolumn — po `dropColumn` nie ma czego liczyć.
        $zData = DB::table('users')->whereNotNull('birthday_month')->count();

        if ($zData > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba kont z wpisaną datą urodzin '
                .'(birthday_day, birthday_month): '.$zData.'. To dane podane przez człowieka — '
                .'po cofnięciu i ponownym `migrate` kolumny wróciłyby puste, a życzenia '
                ."przestałyby przychodzić bez żadnego błędu (D-088).\n\n"
                ."CO ZROBIĆ:\n"
                .'  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej '
                ."nie czyta tych kolumn i działa z nimi bez zmian;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz dane przed cofnięciem:\n"
                ."      SELECT id, birthday_day, birthday_month FROM users WHERE birthday_month IS NOT NULL;\n"
                .'    i odtwórz je tym samym `UPDATE` po powrocie na tę wersję schematu.',
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_birthday_range_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_birthday_pair_check');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['birthday_day', 'birthday_month']);
        });
    }
};

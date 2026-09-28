<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mail z życzeniami urodzinowymi — tylko za OSOBNĄ zgodą (issue #1755, etap c).
 *
 * `users.wants_birthday_email` (`boolean DEFAULT false`) — zgoda na list.
 * Mail z życzeniami jest bliżej komunikacji marketingowej niż zdanie na
 * stronie (research §3.2, PKE art. 398), więc wymaga własnej, jawnej zgody:
 * podanie daty jej NIE daje. Każda zmiana zgody zapisuje wiersz w dzienniku
 * zgód (D-072) — stąd nowy cel `zyczenia_urodzinowe` w CHECK-u tej tabeli.
 *
 * `users.birthday_email_sent_on` (`date NULL`) — dzień (w strefie
 * Europe/Warsaw), w którym ostatnio wyszedł list. Bariera przed dublem:
 * komenda zajmuje dzień warunkowym `UPDATE ... WHERE birthday_email_sent_on
 * IS DISTINCT FROM dziś` PRZED `Mail::queue()` — ponowiony przebieg tego
 * samego dnia nie wyśle drugiego listu (ten sam wzorzec co
 * `weekly_digest_sends`, D-077).
 *
 * ROLLBACK (D-088): `down()` ODMAWIA, gdy ktoś ma zgodę albo dziennik zgód ma
 * choć jeden wiersz tego celu. Zgoda wróciłaby jako `false` bez śladu,
 * a wierszy dziennika nie wolno kasować (wyzwalacz append-only), więc starego
 * CHECK-a nie da się przywrócić bez utraty dowodu.
 */
return new class extends Migration
{
    private const CELE_PRZED = "'tygodniowy_digest'";

    private const CELE_PO = "'tygodniowy_digest', 'zyczenia_urodzinowe'";

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('wants_birthday_email')->default(false);
            $table->date('birthday_email_sent_on')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE dziennik_zgod DROP CONSTRAINT dziennik_zgod_cel_check');
        DB::statement('ALTER TABLE dziennik_zgod ADD CONSTRAINT dziennik_zgod_cel_check CHECK (cel IN ('.self::CELE_PO.'))');
    }

    public function down(): void
    {
        $zeZgoda = DB::table('users')->where('wants_birthday_email', true)->count();
        $wierszyDziennika = DB::table('dziennik_zgod')->where('cel', 'zyczenia_urodzinowe')->count();

        if ($zeZgoda > 0 || $wierszyDziennika > 0) {
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba kont ze zgodą na mail urodzinowy '
                .'(wants_birthday_email = true): '.$zeZgoda.'. Liczba wierszy dziennika zgód '
                ."z celem zyczenia_urodzinowe: {$wierszyDziennika}. Zgoda wróciłaby po cofnięciu "
                .'jako false bez żadnego śladu, a wierszy dziennika zgód nie wolno kasować '
                ."(D-072, wyzwalacz tylko do dopisywania).\n\n"
                ."CO ZROBIĆ:\n"
                .'  - przy awaryjnym rollbacku WDROŻENIA nie cofaj tej migracji — kod sprzed niej '
                ."nie czyta tych kolumn, a szerszy CHECK niczego mu nie psuje;\n"
                ."  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz listę zgód przed cofnięciem:\n"
                ."      SELECT id FROM users WHERE wants_birthday_email = true;\n"
                .'    Dziennik zgód zostaje w bazie — tej migracji nie da się wtedy cofnąć w całości.',
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE dziennik_zgod DROP CONSTRAINT dziennik_zgod_cel_check');
            DB::statement('ALTER TABLE dziennik_zgod ADD CONSTRAINT dziennik_zgod_cel_check CHECK (cel IN ('.self::CELE_PRZED.'))');
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['wants_birthday_email', 'birthday_email_sent_on']);
        });
    }
};

<?php

declare(strict_types=1);

use App\Support\NumerSprawy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numer sprawy dostaje własną kolumnę z UNIQUE, bo wyliczany z UUID-a nie był
 * unikalny (decyzja właściciela z 7 września 2026, HANDOVER §7.4 pkt 4).
 *
 * CO BYŁO ZMIERZONE
 * Numer pokazywany zgłaszającemu był ośmioma pierwszymi znakami UUID-a v7
 * wiersza. W UUID-zie v7 pierwsze 48 bitów to znacznik czasu w milisekundach,
 * więc osiem znaków szesnastkowych to jego 32 GÓRNE bity — zmieniają się raz
 * na 2^16 ms, czyli raz na 65,5 sekundy:
 *
 *     Str::uuid7('2026-09-07 19:00:30') → 01a07d3e-4cb0-7099-...  → 01A07D3E
 *     Str::uuid7('2026-09-07 19:01:10') → 01a07d3e-e8f0-739c-...  → 01A07D3E
 *
 * Dwa UUID-y wygenerowane 40 sekund od siebie, dwa różne wiersze, jeden numer
 * sprawy. Zmierzone wprost, nie wyliczone z dokumentacji.
 *
 * DLACZEGO TO JEST WIĘCEJ NIŻ NIEZRĘCZNOŚĆ
 * Dla zgłaszającego BEZ KONTA numer jest jedynym śladem sprawy — nie ma konta,
 * nie ma listy zgłoszeń, a poczty serwis dziś nie wysyła (`docs/decyzje/POCZTA.md`).
 * Dwa numery identyczne znaczą, że ani on, ani moderator nie umie powiedzieć,
 * o którą sprawę chodzi. A to są sprawy z terminem odpowiedzi z DSA art. 16.
 *
 * DLACZEGO KOLUMNA, A NIE DŁUŻSZY WYCINEK UUID-a
 * Dłuższy wycinek daje unikalność STATYSTYCZNĄ, której nikt nie pilnuje.
 * `AGENTS.md` §6 mówi o prawdziwych ograniczeniach w bazie, a nie o walidacji
 * w PHP — i tutaj to nie jest formalizm: kolumna z UNIQUE sprawia, że
 * powtórzony numer jest niemożliwy, a nie tylko nieprawdopodobny.
 *
 * FORMAT: `KU-XXXX-XXXX`, 30-znakowy alfabet bez `0`, `1`, `I`, `L`, `O`
 * i `U` — uzasadnienie przy `App\Support\NumerSprawy`. CHECK składa się
 * z tej samej stałej, więc wzór w bazie i wzór w PHP nie mogą się rozjechać.
 *
 * BACKFILL: istniejące wiersze dostają nowe numery. Jest to bezpieczne
 * dokładnie dziś i tylko dziś: poczty nie ma, więc ŻADEN numer nie został
 * jeszcze nikomu wysłany i nikt nie trzyma starego w ręku. Po pierwszym
 * wysłanym liście ta migracja byłaby zmianą numeru sprawy pod ręką
 * zgłaszającego i wymagałaby innego planu.
 *
 * ROLLBACK: `down()` zdejmuje CHECK, indeks i kolumnę — ale ODMAWIA, gdy
 * w tabeli są zgłoszenia prawne. Skasowanie kolumny odebrałoby zgłaszającemu
 * bez konta jedyny sposób rozpoznania własnej sprawy, a numerów nie da się
 * odtworzyć: są losowe. Świadome wymuszenie:
 * `KUKING_ROLLBACK_KASUJE_NUMERY_SPRAW=1` (najpierw kopia tabeli). To ta sama
 * konwencja i ten sam powód, co przy
 * `2026_09_06_200000_add_legal_notice_fields_to_reports`.
 */
return new class extends Migration
{
    private const INDEKS = 'reports_numer_sprawy_unique';

    private const CHECK = 'reports_numer_sprawy_check';

    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('numer_sprawy', 12)->nullable()->after('id');
        });

        $this->uzupelnijIstniejace();

        Schema::table('reports', function (Blueprint $table): void {
            $table->string('numer_sprawy', 12)->nullable(false)->change();
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEKS.' ON reports (numer_sprawy)');

        // CHECK w BAZIE, nie tylko wzór w PHP: numer trafia do pisma
        // urzędowego i do korespondencji ze zgłaszającym, więc wartość
        // w innym formacie nie ma prawa się tam znaleźć żadną drogą — ani
        // przez seeder, ani przez `php artisan tinker`, ani przez przyszłe API.
        DB::statement(
            'ALTER TABLE reports ADD CONSTRAINT '.self::CHECK.' CHECK ('
            ."numer_sprawy ~ '^KU-[".NumerSprawy::ALFABET.']{4}-['.NumerSprawy::ALFABET."]{4}$'"
            .')',
        );
    }

    public function down(): void
    {
        $prawne = DB::table('reports')->where('source', 'legal_notice')->count();

        // `getenv()`, NIE `env()` — ta sama konwencja, co w trzech pozostałych
        // migracjach z furtką. Poza katalogiem `config/` `env()` nie widzi
        // wartości z pliku `.env`, kiedy konfiguracja jest zbuforowana
        // (`config:cache`), i dlatego Larastan tego pilnuje regułą
        // `larastan.noEnvCallsOutsideOfConfig`. Tu akurat furtkę i tak podaje
        // się w środowisku procesu, ale rozjazd konwencji między czterema
        // migracjami robiącymi to samo jest kosztem bez żadnej korzyści.
        if ($prawne > 0 && getenv('KUKING_ROLLBACK_KASUJE_NUMERY_SPRAW') !== '1') {
            throw new RuntimeException(
                'W `reports` jest '.$prawne.' zgłoszeń prawnych (DSA art. 16), a ich numery spraw są '
                .'jedynym sposobem, w jaki zgłaszający bez konta rozpoznaje własną sprawę. '
                .'Numery są losowe, więc po skasowaniu kolumny nie da się ich odtworzyć. '
                .'Jeśli naprawdę chcesz je stracić: zrób kopię tabeli i uruchom ponownie '
                .'z KUKING_ROLLBACK_KASUJE_NUMERY_SPRAW=1.',
            );
        }

        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('numer_sprawy');
        });
    }

    /**
     * Numery dla wierszy, które już są w tabeli.
     *
     * Losujemy w PHP, nie w SQL-u: ten sam generator, który obsługuje nowe
     * zgłoszenia, więc backfill nie może wyprodukować wartości w formacie,
     * którego CHECK nie przyjmie. Zbiór już użytych trzymamy w pamięci —
     * przy tej skali (zamknięta beta) jest to kilkadziesiąt wierszy, a nie
     * miliony.
     */
    private function uzupelnijIstniejace(): void
    {
        $uzyte = [];

        DB::table('reports')
            ->whereNull('numer_sprawy')
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($wiersze) use (&$uzyte): void {
                foreach ($wiersze as $wiersz) {
                    do {
                        $numer = NumerSprawy::wygeneruj();
                    } while (isset($uzyte[$numer]));

                    $uzyte[$numer] = true;

                    DB::table('reports')->where('id', $wiersz->id)->update(['numer_sprawy' => $numer]);
                }
            });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Baza\StraznikNowychMigracji;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NOWE MIGRACJE TRZYMAJĄ SIĘ AGENTS.md §6 (DDL na gorącej bazie).
 *
 * PO CO
 * `2026_09_24_120000_add_appeal_id_to_moderation_actions.php` dodaje do
 * istniejącej, gorącej tabeli `moderation_actions` kolumnę z kluczem obcym
 * (`->constrained()`), indeks unikalny (`CREATE UNIQUE INDEX` bez
 * CONCURRENTLY) i CHECK (`ADD CONSTRAINT … CHECK` bez NOT VALID) — z
 * pominięciem AGENTS.md §6. Migracja jest na produkcji; właściciel
 * zdecydował, że jej NIE ruszamy (patrz komentarz `StraznikNowychMigracji`).
 * Ten test pilnuje, żeby ten sam błąd nie powtórzył się w migracji, która
 * powstanie PO tej decyzji.
 *
 * Sam mechanizm sprawdzania statycznego żyje w `StraznikNowychMigracji`,
 * żeby dało się go pokryć kontrolą dodatnią (test niżej) i kontrolą ujemną
 * (`scripts/kontrole-negatywne-alfa08.py`: mutacja tego pliku ma zgasić
 * wykrywanie i zapalić `test_straznik_wykrywa_kazde_z_trzech_naruszen_par6`).
 */
class NoweMigracjeTrzymajaSieParagrafu6Test extends TestCase
{
    #[Test]
    public function nowe_migracje_w_repozytorium_nie_lamia_paragrafu_6(): void
    {
        $naruszenia = [];

        foreach (StraznikNowychMigracji::nowePliki() as $plik) {
            $tresc = (string) file_get_contents($plik);

            foreach (StraznikNowychMigracji::sprawdzTresc($tresc) as $powod) {
                $naruszenia[] = basename($plik).': '.$powod;
            }
        }

        $this->assertSame(
            [],
            $naruszenia,
            "Nowa migracja (nowsza niż StraznikNowychMigracji::PROG) łamie AGENTS.md §6 (DDL na gorącej bazie):\n\n"
            .implode("\n\n", $naruszenia)
            ."\n\nCO ZROBIĆ: przepisz DDL na wzorzec z "
            .'2026_09_23_100000_powiaz_status_zgloszenia_z_rozstrzygnieciem.php — indeks przez `CREATE INDEX '
            .'CONCURRENTLY` w migracji z `$withinTransaction = false`, klucz obcy/CHECK przez `ADD CONSTRAINT … '
            .'NOT VALID` i osobno `VALIDATE CONSTRAINT`. Migracja `add_appeal_id_to_moderation_actions` (24.09.2026) '
            .'jest wyjątkiem świadomie zostawionym na produkcji — nie jest wzorcem do naśladowania.',
        );
    }

    /**
     * KONTROLA DODATNIA: strażnik naprawdę wykrywa wszystkie trzy rodzaje
     * naruszenia, na fabrykowanej treści migracji zbudowanej na wzorcu
     * `add_appeal_id_to_moderation_actions` — czyli dokładnie tego, co ta
     * migracja robi źle. Bez tego testu strażnik mógłby nic nie wykrywać,
     * a `nowe_migracje_w_repozytorium_nie_lamia_paragrafu_6` świeciłby się
     * na zielono nad pustym zbiorem naruszeń.
     */
    #[Test]
    public function straznik_wykrywa_kazde_z_trzech_naruszen_par6(): void
    {
        $fkPrzezBlueprint = <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void {
                    Schema::table('moderation_actions', function (Blueprint $table): void {
                        $table->foreignUuid('appeal_id')->nullable()->constrained('appeals')->nullOnDelete();
                    });
                }
                public function down(): void {}
            };
            PHP;

        $naruszenia = StraznikNowychMigracji::sprawdzTresc($fkPrzezBlueprint);
        $this->assertNotEmpty($naruszenia, 'Strażnik nie zauważył FK dodanego przez Blueprint na istniejącej tabeli.');
        $this->assertTrue(
            (bool) array_filter($naruszenia, static fn (string $n): bool => str_contains($n, 'Blueprint') && str_contains($n, 'Klucz obcy')),
            'Komunikat nie mówi o kluczu obcym dodanym przez Blueprint: '.implode(' | ', $naruszenia),
        );

        $indeksBezConcurrently = <<<'SQL'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;
            return new class extends Migration {
                public function up(): void {
                    DB::statement('CREATE UNIQUE INDEX moderation_actions_one_per_appeal ON moderation_actions (appeal_id)');
                }
                public function down(): void {}
            };
            SQL;

        $naruszenia = StraznikNowychMigracji::sprawdzTresc($indeksBezConcurrently);
        $this->assertNotEmpty($naruszenia, 'Strażnik nie zauważył indeksu bez CONCURRENTLY na istniejącej tabeli.');
        $this->assertTrue(
            (bool) array_filter($naruszenia, static fn (string $n): bool => str_contains($n, 'CONCURRENTLY')),
            'Komunikat nie mówi o braku CONCURRENTLY: '.implode(' | ', $naruszenia),
        );

        $checkBezNotValid = <<<'SQL'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;
            return new class extends Migration {
                public function up(): void {
                    DB::statement("ALTER TABLE moderation_actions ADD CONSTRAINT moderation_actions_appeal_or_report_check CHECK (appeal_id IS NULL OR report_id IS NULL)");
                }
                public function down(): void {}
            };
            SQL;

        $naruszenia = StraznikNowychMigracji::sprawdzTresc($checkBezNotValid);
        $this->assertNotEmpty($naruszenia, 'Strażnik nie zauważył CHECK bez NOT VALID na istniejącej tabeli.');
        $this->assertTrue(
            (bool) array_filter($naruszenia, static fn (string $n): bool => str_contains($n, 'NOT VALID') && str_contains($n, 'CHECK')),
            'Komunikat nie mówi o CHECK bez NOT VALID: '.implode(' | ', $naruszenia),
        );
    }

    /**
     * KONTROLA DODATNIA (druga połowa): strażnik NIE fałszywie alarmuje na
     * poprawnym wzorcu (§6) ani na nowej tabeli, która swoich indeksów
     * i CHECK-ów jeszcze z nikim nie dzieli.
     */
    #[Test]
    public function straznik_nie_alarmuje_na_poprawnym_wzorcu_ani_na_nowej_tabeli(): void
    {
        $poprawnyWzorzec = <<<'SQL'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;
            return new class extends Migration {
                public $withinTransaction = false;
                public function up(): void {
                    DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS moderation_actions_one_per_appeal ON moderation_actions (appeal_id) WHERE appeal_id IS NOT NULL');
                    DB::statement('ALTER TABLE moderation_actions ADD CONSTRAINT moderation_actions_appeal_or_report_check CHECK (appeal_id IS NULL OR report_id IS NULL) NOT VALID');
                    DB::statement('ALTER TABLE moderation_actions VALIDATE CONSTRAINT moderation_actions_appeal_or_report_check');
                }
                public function down(): void {}
            };
            SQL;

        $this->assertSame([], StraznikNowychMigracji::sprawdzTresc($poprawnyWzorzec));

        $nowaTabela = <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void {
                    Schema::create('nowa_tabela', function (Blueprint $table): void {
                        $table->uuid('id')->primary();
                        $table->foreignUuid('appeal_id')->nullable()->constrained('appeals')->nullOnDelete();
                    });
                    DB::statement('CREATE UNIQUE INDEX nowa_tabela_appeal_id ON nowa_tabela (appeal_id)');
                    DB::statement('ALTER TABLE nowa_tabela ADD CONSTRAINT nowa_tabela_check CHECK (appeal_id IS NOT NULL)');
                }
                public function down(): void {
                    Schema::dropIfExists('nowa_tabela');
                }
            };
            PHP;

        $this->assertSame(
            [],
            StraznikNowychMigracji::sprawdzTresc($nowaTabela),
            'Nowa tabela (Schema::create w tej samej migracji) nie potrzebuje CONCURRENTLY ani NOT VALID (AGENTS.md §6).',
        );
    }

    /**
     * Próg naprawdę odcina historię (appeal_id, 24.09.2026) i naprawdę
     * łapie plik z datą późniejszą — na katalogu fixture, żeby test nie
     * zależał od tego, co akurat leży w `database/migrations` dzisiaj.
     */
    #[Test]
    public function prog_pomija_historie_a_widzi_migracje_pozniejsze_niz_on_sam(): void
    {
        $katalog = sys_get_temp_dir().'/straznik-par6-'.bin2hex(random_bytes(4));
        mkdir($katalog);

        try {
            file_put_contents($katalog.'/2026_09_24_120000_add_appeal_id_to_moderation_actions.php', '<?php');
            file_put_contents($katalog.'/2026_09_25_100000_create_tag_highlights_table.php', '<?php');
            file_put_contents($katalog.'/2026_09_26_100000_cos_nowego.php', '<?php');

            $nowe = array_map('basename', StraznikNowychMigracji::nowePliki($katalog));

            $this->assertSame(['2026_09_26_100000_cos_nowego.php'], $nowe);
        } finally {
            array_map('unlink', glob($katalog.'/*.php') ?: []);
            rmdir($katalog);
        }
    }
}

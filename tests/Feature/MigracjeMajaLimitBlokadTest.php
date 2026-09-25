<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Baza\LimitBlokadMigracji;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Migracja chodzi z `lock_timeout`, a po niej sesja wraca do wartości
 * domyślnej (audyt B3 W3, `LimitBlokadMigracji`).
 *
 * Test uruchamia PRAWDZIWE `artisan migrate` na jednorazowej migracji
 * z katalogu tymczasowego — sam listener wołany wprost nie dowodziłby, że
 * jest podpięty pod zdarzenia migratora. Migracja zapisuje, co widzi
 * w `SHOW lock_timeout`, do tabeli tymczasowej sesji.
 */
class MigracjeMajaLimitBlokadTest extends TestCase
{
    use RefreshDatabase;

    private string $katalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->katalog = sys_get_temp_dir().'/kuking-limit-blokad-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->katalog);
        DB::statement('CREATE TEMPORARY TABLE limit_blokad_pomiar (tryb text, wartosc text)');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->katalog);

        parent::tearDown();
    }

    public function test_migracja_transakcyjna_ma_limit_blokad(): void
    {
        $this->migracja('2099_01_01_000000_pomiar_limitu_transakcyjna.php', 'transakcyjna', true);

        $this->assertSame(LimitBlokadMigracji::LIMIT, $this->zmierzone('transakcyjna'));
    }

    public function test_migracja_bez_transakcji_ma_limit_blokad(): void
    {
        $this->migracja('2099_01_01_000001_pomiar_limitu_bez_transakcji.php', 'bez_transakcji', false);

        $this->assertSame(LimitBlokadMigracji::LIMIT, $this->zmierzone('bez_transakcji'));
    }

    public function test_po_migracji_sesja_wraca_do_wartosci_domyslnej(): void
    {
        $przed = DB::selectOne('SHOW lock_timeout')->lock_timeout;

        $this->migracja('2099_01_01_000002_pomiar_limitu_reset.php', 'reset', true);

        $this->assertSame(LimitBlokadMigracji::LIMIT, $this->zmierzone('reset'));
        $this->assertSame($przed, DB::selectOne('SHOW lock_timeout')->lock_timeout);
        $this->assertNotSame(LimitBlokadMigracji::LIMIT, $przed);
    }

    private function migracja(string $plik, string $tryb, bool $transakcja): void
    {
        $wTransakcji = $transakcja ? 'true' : 'false';

        File::put($this->katalog.'/'.$plik, <<<PHP
            <?php
            use Illuminate\\Database\\Migrations\\Migration;
            use Illuminate\\Support\\Facades\\DB;
            return new class extends Migration {
                public \$withinTransaction = {$wTransakcji};
                public function up(): void {
                    DB::insert("INSERT INTO limit_blokad_pomiar VALUES ('{$tryb}', current_setting('lock_timeout'))");
                }
                public function down(): void {}
            };
            PHP);

        $this->assertSame(0, Artisan::call('migrate', [
            '--path' => $this->katalog,
            '--realpath' => true,
            '--force' => true,
        ]), Artisan::output());
    }

    private function zmierzone(string $tryb): ?string
    {
        return DB::table('limit_blokad_pomiar')->where('tryb', $tryb)->value('wartosc');
    }
}

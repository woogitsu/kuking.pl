<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\ExportFileNames;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Po wymazaniu konta i po nieudanym eksporcie nie zostają kopie danych
 * (audyt B5, znaleziska 4 i 6).
 *
 *  - pkt 4: próba eksportu, która wgrała ZIP i padła przed `finalize()`,
 *    zostawiała w magazynie pełną paczkę konta pod kluczem, którego wiersz
 *    nie znał — bez terminu, także po wymazaniu konta;
 *  - pkt 6: `password_reset_tokens` (kluczowana jawnym adresem) przeżywała
 *    wymazanie konta i nie miała żadnego sprzątania.
 */
class PoZamknieciuKontaNieZostajaKopieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);
    }

    /** Awaria zapisu `ready` — po wgraniu pliku, przed zapisaniem klucza w wierszu. */
    private function awariaPrzyFinalizacji(): void
    {
        DB::listen(function ($zapytanie): void {
            if (str_contains($zapytanie->sql, 'update "data_exports"')
                && in_array(DataExport::STATUS_READY, $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria po wgraniu paczki.');
            }
        });
    }

    public function test_paczka_wgrana_przed_nieudana_finalizacja_znika_z_magazynu(): void
    {
        $basia = $this->user('basia');
        $export = DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        $klucz = ExportFileNames::objectKey($export);

        $wgrano = false;
        $this->awariaPrzyFinalizacji();
        DB::listen(function ($zapytanie) use (&$wgrano, $klucz): void {
            $wgrano = $wgrano || Storage::disk('local')->exists($klucz);
        });

        try {
            (new GenerateUserExport((string) $export->getKey()))->handle();
            $this->fail('Job powinien rzucić wyjątek.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Wstrzyknięta awaria', $e->getMessage());
        }

        // Kontrola dodatnia: plik naprawdę był w magazynie w chwili awarii.
        $this->assertTrue($wgrano, 'Awaria wypadła przed wgraniem — test nie mierzy tego, co ma mierzyć.');

        $this->assertSame(DataExport::STATUS_FAILED, $export->fresh()->status);
        $this->assertNull($export->fresh()->object_key);
        Storage::disk('local')->assertMissing($klucz);
    }

    public function test_gotowa_paczka_nie_jest_kasowana_przez_hook_failed(): void
    {
        $basia = $this->user('basia');
        $export = DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        $klucz = ExportFileNames::objectKey($export);
        Storage::disk('local')->put($klucz, 'zip');
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_READY, 'disk' => 'local', 'object_key' => $klucz,
            'bytes' => 3, 'completed_at' => now(), 'expires_at' => now()->addDays(7),
        ]);

        (new GenerateUserExport((string) $export->getKey()))->failed(new RuntimeException('spóźniony'));

        Storage::disk('local')->assertExists($klucz);
    }

    public function test_sprzatanie_eksportow_zabiera_plik_nieudanej_proby(): void
    {
        $basia = $this->user('basia');
        $export = DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        $klucz = ExportFileNames::objectKey($export);
        Storage::disk('local')->put($klucz, 'zip');
        DB::table('data_exports')->where('id', $export->getKey())->update([
            'status' => DataExport::STATUS_FAILED, 'failure_reason' => DataExport::REASON_UNKNOWN,
            'updated_at' => now()->subHours(2),
        ]);

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();

        Storage::disk('local')->assertMissing($klucz);
    }

    public function test_wymazanie_konta_kasuje_wszystkie_paczki_i_link_resetu_hasla(): void
    {
        $basia = $this->user('basia', ['email' => 'Basia.Kowalska@example.com']);
        $halina = $this->user('halina', ['email' => 'halina@example.com']);

        Storage::disk('local')->put('eksporty/'.$basia->getKey().'/osierocona-kuking-moje-dane.zip', 'zip');
        Storage::disk('local')->put('eksporty/'.$halina->getKey().'/jej-paczka.zip', 'zip');

        $tabela = (string) config('auth.passwords.users.table');
        DB::table($tabela)->insert([
            ['email' => 'basia.kowalska@example.com', 'token' => 'skrot-1', 'created_at' => now()],
            ['email' => 'halina@example.com', 'token' => 'skrot-2', 'created_at' => now()],
        ]);

        $basia->fresh()->markForDeletion();
        $this->assertTrue(app(EraseAccountData::class)->handle($basia->fresh()));

        Storage::disk('local')->assertMissing('eksporty/'.$basia->getKey().'/osierocona-kuking-moje-dane.zip');
        $this->assertDatabaseMissing($tabela, ['email' => 'basia.kowalska@example.com']);

        // Kontrola dodatnia: cudze dane nietknięte.
        Storage::disk('local')->assertExists('eksporty/'.$halina->getKey().'/jej-paczka.zip');
        $this->assertDatabaseHas($tabela, ['email' => 'halina@example.com']);
    }

    public function test_nocne_sprzatanie_kasuje_wygasle_zetony_a_zywe_zostawia(): void
    {
        $tabela = (string) config('auth.passwords.users.table');
        $minuty = (int) config('auth.passwords.users.expire');
        DB::table($tabela)->insert([
            ['email' => 'stary@example.com', 'token' => 'skrot-1', 'created_at' => now()->subMinutes($minuty + 1)],
            ['email' => 'swiezy@example.com', 'token' => 'skrot-2', 'created_at' => now()],
        ]);

        $this->artisan('kuking:sprzataj-resety-hasel')->assertSuccessful();

        $this->assertDatabaseMissing($tabela, ['email' => 'stary@example.com']);
        $this->assertDatabaseHas($tabela, ['email' => 'swiezy@example.com']);
    }

    public function test_wygasle_zetony_resetu_sprzata_harmonogram(): void
    {
        $nazwy = array_map(fn ($e) => $e->description, app(Schedule::class)->events());

        $this->assertContains('kuking:sprzataj-resety-hasel', $nazwy);
    }
}

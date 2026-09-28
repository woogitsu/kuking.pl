<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\LimitImportowOsoby;
use App\Domain\Import\LimitImportu;
use App\Domain\Import\PrzedawnioneImporty;
use App\Models\ImportPrzepisu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class WspolnyLimitImportuTest extends TestCase
{
    use RefreshDatabase;

    public function test_ocr_url_i_pdf_zuzywaja_ten_sam_limit_a_ponowienie_klucza_nie_zuzywa_drugiego_miejsca(): void
    {
        config(['kuking.import.limity.na_osobe_dzien' => 2, 'kuking.import.limity.na_osobe_miesiac' => 30]);
        $osoba = $this->user();
        $klucz = (string) Str::uuid();

        $ocr = DB::transaction(fn () => app(LimitImportowOsoby::class)->rezerwuj($osoba, 'zdjecie', $klucz));
        $this->assertNotNull($ocr);
        $this->assertFalse($ocr['istnieje']);
        $this->assertSame($ocr['id'], DB::transaction(fn () => app(LimitImportowOsoby::class)->rezerwuj($osoba, 'zdjecie', $klucz))['id']);

        $url = app(LimitImportu::class)->zuzyj($osoba, 'url', (string) Str::uuid());
        $this->assertFalse($url['istnieje']);
        $this->assertSame(2, DB::table('proby_importu')->where('user_id', $osoba->getKey())->count());
        $this->assertSame(LimitImportowOsoby::DZIEN, app(LimitImportowOsoby::class)->przekroczony($osoba));

        try {
            app(LimitImportu::class)->zuzyj($osoba, 'pdf', (string) Str::uuid());
            $this->fail('PDF nie może ominąć wspólnego limitu OCR i URL.');
        } catch (ImportOdrzucony $e) {
            $this->assertSame(ImportOdrzucony::LIMIT_OSOBY, $e->kod);
        }

        $this->assertSame(2, DB::table('proby_importu')->where('user_id', $osoba->getKey())->count());
    }

    public function test_stare_proby_znikaja_po_retencji_a_biezacy_miesiac_zostaje(): void
    {
        $osoba = $this->user();
        $stara = app(LimitImportu::class)->zuzyj($osoba, 'url', (string) Str::uuid());
        DB::table('proby_importu')->where('id', $stara['id'])->update(['created_at' => now()->subDays(100)]);
        $nowa = app(LimitImportu::class)->zuzyj($osoba, 'pdf', (string) Str::uuid());

        $this->assertSame(1, app(PrzedawnioneImporty::class)->posprzataj(true)['proby']);
        $this->assertSame(1, app(PrzedawnioneImporty::class)->posprzataj()['proby']);
        $this->assertDatabaseMissing('proby_importu', ['id' => $stara['id']]);
        $this->assertDatabaseHas('proby_importu', ['id' => $nowa['id']]);
    }

    public function test_miesieczny_limit_liczy_te_same_proby_niezaleznie_od_zrodla(): void
    {
        config(['kuking.import.limity.na_osobe_dzien' => 5, 'kuking.import.limity.na_osobe_miesiac' => 1]);
        $osoba = $this->user();
        app(LimitImportu::class)->zuzyj($osoba, 'pdf', (string) Str::uuid());

        $this->assertSame(LimitImportowOsoby::MIESIAC, app(LimitImportowOsoby::class)->przekroczony($osoba));
        try {
            app(LimitImportu::class)->zuzyj($osoba, 'url', (string) Str::uuid());
            $this->fail('URL ominął miesięczne miejsce zajęte przez PDF.');
        } catch (ImportOdrzucony $e) {
            $this->assertSame(ImportOdrzucony::LIMIT_OSOBY_MIESIAC, $e->kod);
        }
    }

    public function test_legacy_ocr_liczy_sie_do_limitu_a_powiazane_zlecenie_nie_jest_liczone_podwojnie(): void
    {
        config(['kuking.import.limity.na_osobe_dzien' => 2]);
        $osoba = $this->user();
        $stary = new ImportPrzepisu;
        $stary->forceFill([
            'user_id' => $osoba->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => ImportPrzepisu::STATUS_GOTOWY,
        ])->save();

        $nowa = DB::transaction(fn () => app(LimitImportowOsoby::class)
            ->rezerwuj($osoba, 'zdjecie', (string) Str::uuid(), (string) $stary->getKey()));
        $this->assertNotNull($nowa, 'Stare OCR zajmuje jedno z dwóch miejsc.');
        // Powiązane OCR ma jeden wiersz w każdej z dwóch tabel, lecz jedną próbę.
        $this->assertNull(app(LimitImportowOsoby::class)->przekroczony($osoba));
        app(LimitImportu::class)->zuzyj($osoba, 'url', (string) Str::uuid());
        $this->assertSame(LimitImportowOsoby::DZIEN, app(LimitImportowOsoby::class)->przekroczony($osoba));
    }

    public function test_cofniecie_ksiegi_odmawia_gdy_biezacy_miesiac_ma_proby(): void
    {
        $osoba = $this->user();
        app(LimitImportu::class)->zuzyj($osoba, 'url', (string) Str::uuid());
        $migracja = require database_path('migrations/2026_09_28_080000_create_proby_importu_table.php');

        try {
            $migracja->down();
            $this->fail('Rollback wyzerowałby miesięczny limit.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bieżącego miesiąca', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('proby_importu'));
        $this->assertSame(1, DB::table('proby_importu')->count());
    }

    public function test_pusta_ksiega_moze_byc_cofnieta_i_odtworzona(): void
    {
        $migracja = require database_path('migrations/2026_09_28_080000_create_proby_importu_table.php');
        $migracja->down();
        $this->assertFalse(Schema::hasTable('proby_importu'));
        $migracja->up();
        $this->assertTrue(Schema::hasTable('proby_importu'));
    }
}

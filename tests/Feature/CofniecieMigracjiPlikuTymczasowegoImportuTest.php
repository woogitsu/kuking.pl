<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ImportPrzepisu;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migracja `2026_09_29_150000_add_plik_tymczasowy_and_kody_pdf_to_importy_przepisow`
 * (#28 etap 2, #2051, D-088): import z PDF-a zapamiętuje ścieżkę pliku na
 * dysku importu i powód odmowy odczytu pliku.
 *
 * `down()` NIE odmawia, ale nie jest bezstratny — kody PDF zamienia na
 * `blad_wewnetrzny` (status `nieudany` zostaje), kolumna znika, a kody adresu
 * z poprzedniej migracji zostają nietknięte.
 */
final class CofniecieMigracjiPlikuTymczasowegoImportuTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require base_path('database/migrations/2026_09_29_150000_add_plik_tymczasowy_and_kody_pdf_to_importy_przepisow.php');
    }

    private function zlecenie(string $zrodlo, string $kod, ?string $plik = null): string
    {
        $osoba = $this->user();

        $zlecenie = new ImportPrzepisu;
        $zlecenie->forceFill([
            'user_id' => $osoba->getKey(),
            'zrodlo' => $zrodlo,
            'status' => ImportPrzepisu::STATUS_NIEUDANY,
            'kod_bledu' => $kod,
            'plik_tymczasowy' => $plik,
            'zakonczono_at' => now(),
        ])->save();

        return (string) $zlecenie->getKey();
    }

    public function test_po_migracji_kazdy_powod_odmowy_pliku_da_sie_zapisac_a_lista_zostaje_zamknieta(): void
    {
        foreach (ImportPrzepisu::KODY_PDF as $kod) {
            $id = $this->zlecenie(ImportPrzepisu::ZRODLO_PDF, $kod);

            $this->assertSame($kod, ImportPrzepisu::query()->findOrFail($id)->kod_bledu);
        }

        $this->expectException(QueryException::class);
        $this->zlecenie(ImportPrzepisu::ZRODLO_PDF, 'zmyslony_powod');
    }

    public function test_sciezka_pliku_jest_tylko_przy_zleceniu_z_pdf(): void
    {
        $id = $this->zlecenie(ImportPrzepisu::ZRODLO_PDF, ImportPrzepisu::KOD_BLAD_WEWNETRZNY, 'import-pdf-tmp/a.pdf');
        $this->assertSame('import-pdf-tmp/a.pdf', ImportPrzepisu::query()->findOrFail($id)->plik_tymczasowy);

        $this->expectException(QueryException::class);
        $this->zlecenie(ImportPrzepisu::ZRODLO_URL, ImportPrzepisu::KOD_BLAD_WEWNETRZNY, 'import-pdf-tmp/b.pdf');
    }

    public function test_cofniecie_zamienia_kody_pdf_zachowuje_status_i_kody_adresu_usuwa_kolumne_i_przywraca_wezsza_liste(): void
    {
        $pdf = array_map(fn (string $kod): string => $this->zlecenie(ImportPrzepisu::ZRODLO_PDF, $kod, 'import-pdf-tmp/x.pdf'), ImportPrzepisu::KODY_PDF);
        $adres = $this->zlecenie(ImportPrzepisu::ZRODLO_URL, 'adres_niepubliczny');
        $stary = $this->zlecenie(ImportPrzepisu::ZRODLO_ZDJECIE, ImportPrzepisu::KOD_BUDZET_DZIENNY);

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('importy_przepisow', 'plik_tymczasowy'));
        foreach ($pdf as $id) {
            $wiersz = ImportPrzepisu::query()->findOrFail($id);
            $this->assertSame(ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $wiersz->kod_bledu);
            $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $wiersz->status, 'Status zostaje — ginie tylko dokładny powód.');
        }
        $this->assertSame('adres_niepubliczny', ImportPrzepisu::query()->findOrFail($adres)->kod_bledu, 'Kody adresu należą do poprzedniej migracji.');
        $this->assertSame(ImportPrzepisu::KOD_BUDZET_DZIENNY, ImportPrzepisu::query()->findOrFail($stary)->kod_bledu);

        $this->assertNull(DB::selectOne("SELECT 1 FROM pg_indexes WHERE indexname = 'importy_przepisow_plik_idx'"));
        $this->assertNull(DB::selectOne("SELECT 1 FROM pg_constraint WHERE conname = 'importy_przepisow_plik_check'"));

        DB::table('importy_przepisow')->where('id', $stary)->update(['kod_bledu' => ImportPrzepisu::KOD_BUDZET_DZIENNY]);
        try {
            DB::transaction(fn () => DB::table('importy_przepisow')->where('id', $stary)->update(['kod_bledu' => 'pdf_uszkodzony']));
            $this->fail('Po cofnięciu lista nie powinna przyjmować kodu PDF.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_kontrola_dodatnia_cofniecie_i_ponowne_wykonanie_przywraca_kolumne_i_kody(): void
    {
        $this->migracja()->down();
        $this->migracja()->up();

        $id = $this->zlecenie(ImportPrzepisu::ZRODLO_PDF, 'pdf_bez_tekstu', 'import-pdf-tmp/c.pdf');

        $wiersz = ImportPrzepisu::query()->findOrFail($id);
        $this->assertSame('pdf_bez_tekstu', $wiersz->kod_bledu);
        $this->assertSame('import-pdf-tmp/c.pdf', $wiersz->plik_tymczasowy);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ImportPrzepisu;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migracja `2026_09_29_120000_extend_importy_przepisow_kod_bledu_o_adres`
 * (#28, D-088): import z adresu zapisuje w zleceniu powód odmowy strony.
 *
 * `up()` poszerza zamkniętą listę `kod_bledu`; `down()` NIE odmawia, ale nie
 * jest bezstratny — siedem nowych kodów zamienia na `blad_wewnetrzny` (status
 * `nieudany` zostaje), bo stara lista ich nie przyjmie. Wiersz zlecenia nie
 * niesie treści przepisu, a limity osoby liczą się z `proby_importu`.
 */
final class CofniecieMigracjiKodowAdresuImportuTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require base_path('database/migrations/2026_09_29_120000_extend_importy_przepisow_kod_bledu_o_adres.php');
    }

    private function zlecenieNieudane(string $kod): string
    {
        $osoba = $this->user();

        $zlecenie = new ImportPrzepisu;
        $zlecenie->forceFill([
            'user_id' => $osoba->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_URL,
            'status' => ImportPrzepisu::STATUS_NIEUDANY,
            'kod_bledu' => $kod,
            'zakonczono_at' => now(),
        ])->save();

        return (string) $zlecenie->getKey();
    }

    public function test_po_migracji_kazdy_powod_odmowy_strony_da_sie_zapisac(): void
    {
        foreach (ImportPrzepisu::KODY_ADRESU as $kod) {
            $id = $this->zlecenieNieudane($kod);

            $this->assertSame($kod, ImportPrzepisu::query()->findOrFail($id)->kod_bledu);
        }
    }

    public function test_lista_pozostaje_zamknieta_dla_zmyslonego_kodu(): void
    {
        $this->expectException(QueryException::class);

        $this->zlecenieNieudane('zmyslony_powod');
    }

    public function test_cofniecie_zamienia_nowe_kody_na_blad_wewnetrzny_zachowuje_status_i_przywraca_wezsza_liste(): void
    {
        $ids = array_map(fn (string $kod): string => $this->zlecenieNieudane($kod), ImportPrzepisu::KODY_ADRESU);
        $stary = $this->zlecenieNieudane(ImportPrzepisu::KOD_BUDZET_DZIENNY);

        $this->migracja()->down();

        foreach ($ids as $id) {
            $wiersz = ImportPrzepisu::query()->findOrFail($id);
            $this->assertSame(ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $wiersz->kod_bledu);
            $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $wiersz->status, 'Status zostaje — ginie tylko dokładny powód.');
        }
        $this->assertSame(ImportPrzepisu::KOD_BUDZET_DZIENNY, ImportPrzepisu::query()->findOrFail($stary)->kod_bledu, 'Stare kody nietknięte.');

        try {
            DB::transaction(fn () => $this->zlecenieNieudane('adres_niepubliczny'));
            $this->fail('Po cofnięciu lista nie powinna przyjmować kodu adresu.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_kontrola_dodatnia_cofniecie_i_ponowne_wykonanie_przywraca_nowe_kody(): void
    {
        $this->migracja()->down();
        $this->migracja()->up();

        $id = $this->zlecenieNieudane('za_dlugo');

        $this->assertSame('za_dlugo', ImportPrzepisu::query()->findOrFail($id)->kod_bledu);
    }
}

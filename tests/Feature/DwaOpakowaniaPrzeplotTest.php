<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\DrugieOpakowanieProduktu;
use App\Domain\Pantry\Opakowanie;
use App\Domain\Pantry\ZmienTerminProduktu;
use App\Models\PantryItem;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Dwa rzeczywiste połączenia PG18 mierzą blokadę wiersza przy zamianie A na B (#2783). */
final class DwaOpakowaniaPrzeplotTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $produktId = null;

    protected function tearDown(): void
    {
        try {
            // Strażnik down() słusznie odmawia utraty UUID awansowanego B.
            if ($this->produktId !== null) {
                DB::connection('pgsql')->table('pantry_items')->where('id', $this->produktId)->delete();
            }
            DB::disconnect('pantry_race_2');
        } finally {
            DB::setDefaultConnection('pgsql');
            parent::tearDown();
        }
    }

    public function test_awans_b_przed_starym_zapisem_a_blokuje_odczyt_i_odmawia_bez_zmiany_b(): void
    {
        $produkt = $this->produktZDwomaOpakowaniami();
        $odciskA = Opakowanie::zProduktu($produkt->fresh(['secondPackage']))[0]->odcisk();
        $drugi = $this->drugiePolaczenie();
        $pierwszy = DB::connection('pgsql');

        $pierwszy->beginTransaction();
        try {
            $this->assertSame(DrugieOpakowanieProduktu::USUNIETO, app(DrugieOpakowanieProduktu::class)->usun($produkt, 'pierwsze', $odciskA));
            $this->odmowaNaBlokadzie($drugi, function () use ($produkt, $odciskA): void {
                app(ZmienTerminProduktu::class)->handle($produkt, ['ilosc' => 'stary zapis A'], odcisk: $odciskA);
            }, 'ODCISK_2783_WYS_CZYTA_POD_BLOKADA');
            $pierwszy->commit();
        } finally {
            if ($pierwszy->transactionLevel() > 0) {
                $pierwszy->rollBack();
            }
        }

        $pierwotne = DB::getDefaultConnection();
        DB::setDefaultConnection('pantry_race_2');
        try {
            try {
                app(ZmienTerminProduktu::class)->handle($produkt, ['ilosc' => 'stary zapis A'], odcisk: $odciskA);
                self::fail('Po zatwierdzeniu awansu B stary formularz A nie może się zapisać.');
            } catch (ValidationException $blad) {
                $this->assertSame([DrugieOpakowanieProduktu::BLAD_ZMIENILO_SIE], $blad->errors()['opakowanie']);
            }
        } finally {
            DB::setDefaultConnection($pierwotne);
        }

        $zostalo = $pierwszy->table('pantry_items')->where('id', $produkt->getKey())->first();
        $this->assertSame('2 litry B', $zostalo->quantity_note);
        $this->assertSame('2026-10-20', (string) $zostalo->expires_on);
        $this->assertNotNull($zostalo->first_package_id);
        $this->assertSame(0, $pierwszy->table('pantry_second_packages')->count());
    }

    public function test_zapis_a_przed_awansem_b_blokuje_awans_a_po_zatwierdzeniu_zachowuje_b(): void
    {
        $produkt = $this->produktZDwomaOpakowaniami();
        $odciskA = Opakowanie::zProduktu($produkt->fresh(['secondPackage']))[0]->odcisk();
        $drugi = $this->drugiePolaczenie();
        $pierwszy = DB::connection('pgsql');

        $pierwszy->beginTransaction();
        try {
            $this->assertTrue(app(ZmienTerminProduktu::class)->handle($produkt, ['ilosc' => 'zmienione A'], odcisk: $odciskA));
            $this->odmowaNaBlokadzie($drugi, function () use ($produkt, $odciskA): void {
                app(DrugieOpakowanieProduktu::class)->usun($produkt, 'pierwsze', $odciskA);
            }, 'ODCISK_2783_AWANS_CZYTA_POD_BLOKADA');
            $pierwszy->commit();
        } finally {
            if ($pierwszy->transactionLevel() > 0) {
                $pierwszy->rollBack();
            }
        }

        // Stary odcisk odmawia po zmianie A. Dopiero świeżo otwarty formularz
        // może usunąć A, a B zachowuje własny termin i ilość.
        $pierwotne = DB::getDefaultConnection();
        DB::setDefaultConnection('pantry_race_2');
        try {
            $this->assertSame(DrugieOpakowanieProduktu::ZMIENILO_SIE, app(DrugieOpakowanieProduktu::class)->usun($produkt, 'pierwsze', $odciskA));
            $odciskPoZapisie = Opakowanie::zProduktu($produkt->fresh(['secondPackage']))[0]->odcisk();
            $this->assertSame(DrugieOpakowanieProduktu::USUNIETO, app(DrugieOpakowanieProduktu::class)->usun($produkt, 'pierwsze', $odciskPoZapisie));
        } finally {
            DB::setDefaultConnection($pierwotne);
        }

        $zostalo = $pierwszy->table('pantry_items')->where('id', $produkt->getKey())->first();
        $this->assertSame('2 litry B', $zostalo->quantity_note);
        $this->assertSame('2026-10-20', (string) $zostalo->expires_on);
        $this->assertNotNull($zostalo->first_package_id);
        $this->assertSame(0, $pierwszy->table('pantry_second_packages')->count());
    }

    private function produktZDwomaOpakowaniami(): PantryItem
    {
        $osoba = $this->user();
        $produkt = $osoba->pantryItems()->create(['name' => 'mleko']);
        $this->produktId = (string) $produkt->getKey();
        DB::table('pantry_items')->where('id', $this->produktId)->update([
            'expires_on' => '2026-10-14', 'expiry_kind' => 'best_before', 'quantity_note' => '1 litr A',
        ]);
        DB::table('pantry_second_packages')->insert([
            'pantry_item_id' => $this->produktId, 'expires_on' => '2026-10-20',
            'expiry_kind' => 'use_by', 'quantity_note' => '2 litry B', 'frozen' => false,
        ]);

        return $produkt;
    }

    private function drugiePolaczenie(): Connection
    {
        config(['database.connections.pantry_race_2' => config('database.connections.pgsql')]);
        $drugie = DB::connection('pantry_race_2');
        $this->assertSame('pgsql', $drugie->getDriverName());
        $this->assertNotSame(
            DB::connection('pgsql')->selectOne('SELECT pg_backend_pid() AS pid')->pid,
            $drugie->selectOne('SELECT pg_backend_pid() AS pid')->pid,
            'Test musi używać dwóch fizycznych sesji PostgreSQL.',
        );

        return $drugie;
    }

    /** @param  \Closure(): void  $akcja */
    private function odmowaNaBlokadzie(Connection $drugie, \Closure $akcja, string $marker): void
    {
        $pierwotne = DB::getDefaultConnection();
        DB::setDefaultConnection('pantry_race_2');
        $drugie->statement("SET lock_timeout = '350ms'");
        try {
            try {
                $akcja();
                self::fail('Druga sesja nie może ominąć jeszcze niezatwierdzonej blokady produktu.');
            } catch (QueryException $blad) {
                $this->assertSame('55P03', $blad->errorInfo[0]);
                $this->assertStringContainsString(
                    'for update',
                    strtolower($blad->getSql()),
                    $marker.': oczekiwana blokada odczytu SELECT FOR UPDATE, nie dopiero zapis UPDATE.',
                );
            }
        } finally {
            $drugie->statement("SET lock_timeout = '0'");
            DB::setDefaultConnection($pierwotne);
        }
    }
}

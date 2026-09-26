<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapisDoUgotowania;
use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `save → cooked w 30 dni` w `kuking:raport` (issue #1015): pary osoba–przepis
 * z pełnej, zamkniętej kohorty pierwszych zapisów.
 *
 * Kohorta dla TERAZ = 8.09.2026 12:00 UTC to pierwsze zapisy
 * z (10.07.2026 12:00, 9.08.2026 12:00].
 */
class RaportZapisDoUgotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const TERAZ = '2026-09-08 12:00:00';

    private User $autorka;

    private Recipe $przepis;

    private int $numerZeszytu = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->teraz());
        $this->autorka = $this->user('autorka');
        $this->przepis = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
    }

    private function teraz(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TERAZ, 'UTC');
    }

    private function dniTemu(int $dni): CarbonImmutable
    {
        return $this->teraz()->subDays($dni);
    }

    private function zeszyt(User $kto): Collection
    {
        $this->numerZeszytu++;

        return Collection::create([
            'owner_id' => $kto->getKey(),
            'name' => "Zeszyt {$this->numerZeszytu}",
            'visibility' => 'private',
        ]);
    }

    private function zapis(User $kto, CarbonImmutable $kiedy, ?Recipe $przepis = null, ?Collection $zeszyt = null): Collection
    {
        $zeszyt ??= $this->zeszyt($kto);

        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => ($przepis ?? $this->przepis)->getKey(),
            'created_at' => $kiedy,
        ]);

        return $zeszyt;
    }

    private function ugotowanie(User $kto, CarbonImmutable $kiedy, ?Recipe $przepis = null): void
    {
        CookedEvent::factory()->create([
            'user_id' => $kto->getKey(),
            'recipe_id' => ($przepis ?? $this->przepis)->getKey(),
            'cooked_at' => $kiedy,
        ]);
    }

    /** @return array{kohorta_od: CarbonImmutable, kohorta_do: CarbonImmutable, w_kohorcie: int, ugotowane: int, procent: float|null, w_oknie_obserwacji: int} */
    private function wynik(): array
    {
        return app(ZapisDoUgotowania::class)->policz();
    }

    public function test_ugotowanie_w_ciagu_trzydziestu_dni_po_zapisie_jest_konwersja(): void
    {
        $basia = $this->user('basia');
        $this->zapis($basia, $this->dniTemu(45));
        $this->ugotowanie($basia, $this->dniTemu(40));

        $wynik = $this->wynik();

        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(1, $wynik['ugotowane']);
        // Jedna para to anegdota — bez procentu.
        $this->assertNull($wynik['procent']);
    }

    public function test_ugotowanie_przed_zapisem_nie_jest_konwersja(): void
    {
        $basia = $this->user('basia');
        $this->ugotowanie($basia, $this->dniTemu(50));
        $this->zapis($basia, $this->dniTemu(45));

        $wynik = $this->wynik();

        // Para zostaje w mianowniku, ale wcześniejsze gotowanie jej nie zalicza.
        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['ugotowane']);
    }

    public function test_ugotowanie_po_trzydziestu_dniach_nie_jest_konwersja(): void
    {
        $spozniona = $this->user('spozniona');
        $zapisSpoznionej = $this->dniTemu(55);
        $this->zapis($spozniona, $zapisSpoznionej);
        $this->ugotowanie($spozniona, $zapisSpoznionej->addDays(ZapisDoUgotowania::DNI)->addMinute());

        $naCzas = $this->user('na_czas');
        $zapisNaCzas = $this->dniTemu(55);
        $this->zapis($naCzas, $zapisNaCzas);
        $this->ugotowanie($naCzas, $zapisNaCzas->addDays(ZapisDoUgotowania::DNI)->subMinute());

        $wynik = $this->wynik();

        $this->assertSame(2, $wynik['w_kohorcie']);
        $this->assertSame(1, $wynik['ugotowane']);
    }

    public function test_kilka_zeszytow_i_powtorne_ugotowania_licza_sie_raz_dla_pary(): void
    {
        $basia = $this->user('basia');
        $this->zapis($basia, $this->dniTemu(45));
        $this->zapis($basia, $this->dniTemu(40));
        $this->zapis($basia, $this->dniTemu(35));
        $this->ugotowanie($basia, $this->dniTemu(38));
        $this->ugotowanie($basia, $this->dniTemu(34));

        $wynik = $this->wynik();

        // KONTROLA: licząc wiersze `collection_items`, byłoby tu 3.
        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(1, $wynik['ugotowane']);
        $this->assertSame(0, $wynik['w_oknie_obserwacji']);
    }

    public function test_ponowny_zapis_przepisu_zapisanego_przed_kohorta_nie_otwiera_nowej_pary(): void
    {
        $basia = $this->user('basia');
        // Pierwszy zapis przed kohortą; drugi, do innego zeszytu, w jej środku.
        $this->zapis($basia, $this->dniTemu(70));
        $this->zapis($basia, $this->dniTemu(45));
        $this->ugotowanie($basia, $this->dniTemu(44));

        // I odwrotnie: zapis w kohorcie powtórzony w ostatnich 30 dniach
        // nie trafia do okna obserwacji jako druga para.
        $jan = $this->user('jan');
        $this->zapis($jan, $this->dniTemu(45));
        $this->zapis($jan, $this->dniTemu(5));

        $wynik = $this->wynik();

        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['ugotowane']);
        $this->assertSame(0, $wynik['w_oknie_obserwacji']);
    }

    public function test_zapis_mlodszy_niz_trzydziesci_dni_nie_wchodzi_do_mianownika(): void
    {
        $basia = $this->user('basia');
        $this->zapis($basia, $this->dniTemu(10));
        $this->ugotowanie($basia, $this->dniTemu(5));

        $wynik = $this->wynik();

        $this->assertSame(0, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['ugotowane']);
        $this->assertSame(1, $wynik['w_oknie_obserwacji']);

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Za wcześnie na wniosek')
            ->expectsOutputToContain('Zapisy młodsze niż 30 dni: 1 — jeszcze w oknie, nie wliczone.');
    }

    public function test_zapis_starszy_niz_kohorta_nie_jest_liczony(): void
    {
        $basia = $this->user('basia');
        $this->zapis($basia, $this->dniTemu(2 * ZapisDoUgotowania::DNI)->subMinute());

        $wynik = $this->wynik();

        $this->assertSame(0, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['w_oknie_obserwacji']);
    }

    public function test_procent_od_minimum_par(): void
    {
        for ($i = 0; $i < ZapisDoUgotowania::MINIMUM_PAR; $i++) {
            $osoba = $this->user("osoba_{$i}");
            $this->zapis($osoba, $this->dniTemu(45));

            if ($i < 3) {
                $this->ugotowanie($osoba, $this->dniTemu(40));
            }
        }

        $wynik = $this->wynik();

        $this->assertSame(20, $wynik['w_kohorcie']);
        $this->assertSame(3, $wynik['ugotowane']);
        $this->assertSame(15.0, $wynik['procent']);

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Ugotowane w 30 dni po zapisie: 3 z 20 zapisów · 15,0% (cel co najmniej 15%)');
    }

    public function test_wlasny_przepis_i_konta_wykluczone_nie_licza_sie(): void
    {
        // Autorka zapisuje i gotuje własny przepis — dziennik, nie pętla.
        $this->zapis($this->autorka, $this->dniTemu(45));
        $this->ugotowanie($this->autorka, $this->dniTemu(40));

        config(['kuking.account.test_usernames' => ['qa_wewnetrzne']]);
        $testowe = $this->user('qa_wewnetrzne');
        $this->zapis($testowe, $this->dniTemu(45));
        $this->ugotowanie($testowe, $this->dniTemu(40));

        $prawdziwa = $this->user('prawdziwa');
        $this->zapis($prawdziwa, $this->dniTemu(45));

        $wynik = $this->wynik();

        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['ugotowane']);
    }

    public function test_ugotowanie_innego_przepisu_albo_przez_inna_osobe_nie_zalicza_zapisu(): void
    {
        $basia = $this->user('basia');
        $inny = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
        $this->zapis($basia, $this->dniTemu(45));
        $this->ugotowanie($basia, $this->dniTemu(40), $inny);
        $this->ugotowanie($this->user('ktos_inny'), $this->dniTemu(40));

        $wynik = $this->wynik();

        $this->assertSame(1, $wynik['w_kohorcie']);
        $this->assertSame(0, $wynik['ugotowane']);
    }

    public function test_komenda_pokazuje_liczby_bez_danych_osobowych(): void
    {
        $zeszyt = $this->zapis($this->user('cicha_osoba'), $this->dniTemu(45));
        $zeszyt->update(['name' => 'Na urodziny taty']);

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Zapis → „Ugotowałem” w 30 dni — pierwszy zapis cudzego przepisu przez daną osobę, zapisy z 10.07.2026–09.08.2026.')
            ->expectsOutputToContain('Ugotowane w 30 dni po zapisie: 0 z 1 zapisów · za mało danych (mniej niż 20 zapisów w kohorcie)')
            ->doesntExpectOutputToContain('cicha_osoba')
            ->doesntExpectOutputToContain('Na urodziny taty')
            ->doesntExpectOutputToContain($this->przepis->title);
    }

    public function test_pusta_baza_nie_wywala_komendy(): void
    {
        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Brak zapisanych przepisów — jeszcze nie da się tego policzyć.');
    }
}

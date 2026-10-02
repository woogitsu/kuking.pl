<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Pantry\CoUgotuje;
use App\Models\CookedEvent;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tryb „Najpierw to, co się psuje” (`/co-ugotuje?najpierw=termin`, #1903).
 * Pierwszym kluczem jest liczba PILNYCH produktów tej osoby (termin do
 * dziś + 3 dni, nie mrożone), które pasują do składników przepisu. Dalej
 * bez zmian. „Dziś” to 10 października 2026.
 */
class CoUgotujeNajpierwTerminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_przepis_z_dwoma_pilnymi_produktami_jest_przed_przepisem_z_jednym(): void
    {
        $ja = $this->user();
        $this->lista($ja, [
            'mleko' => '2026-10-11', 'szynka' => '2026-10-12', 'jajka' => null, 'mąka' => null, 'ser' => '2026-10-30',
        ]);

        // Ten z jednym pilnym, ale kompletem produktów i krótszy czas.
        $jeden = $this->przepis('Naleśniki', ['mleko', 'jajka', 'mąka'], 5, 5);
        $dwa = $this->przepis('Zapiekanka', ['mleko', 'szynka', 'szczypiorek', 'pieprz', 'sól morska'], 30, 40);
        $zero = $this->przepis('Makaron z serem', ['ser', 'makaron'], 1, 1);

        $wynik = app(CoUgotuje::class)->dla($ja, 0, 20, true);

        $this->assertSame(['Zapiekanka', 'Naleśniki'], $wynik['przepisy']->pluck('title')->all());
        $this->assertNotContains($zero->title, $wynik['przepisy']->pluck('title')->all(), 'Przepis bez pilnego produktu nie wchodzi.');
        $this->assertSame(
            [['nazwa' => 'mleko', 'termin' => '2026-10-11'], ['nazwa' => 'szynka', 'termin' => '2026-10-12']],
            $wynik['do_zuzycia'][$dwa->getKey()],
        );
        $this->assertSame([['nazwa' => 'mleko', 'termin' => '2026-10-11']], $wynik['do_zuzycia'][$jeden->getKey()]);
    }

    public function test_domyslny_widok_nie_zmienia_kolejnosci_ani_zbioru(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11', 'szynka' => '2026-10-12', 'jajka' => null, 'mąka' => null, 'ser' => null]);
        $this->przepis('Naleśniki', ['mleko', 'jajka', 'mąka'], 5, 5);
        $this->przepis('Zapiekanka', ['mleko', 'szynka', 'szczypiorek'], 30, 40);
        $this->przepis('Makaron z serem', ['ser', 'makaron'], 1, 1);

        $domyslny = app(CoUgotuje::class)->dla($ja);
        $bezTerminow = app(CoUgotuje::class)->dla($ja, 0, 20, false);

        $this->assertSame($domyslny['przepisy']->pluck('id')->all(), $bezTerminow['przepisy']->pluck('id')->all());
        $this->assertSame(['Naleśniki', 'Makaron z serem', 'Zapiekanka'], $domyslny['przepisy']->pluck('title')->all());
        $this->assertSame([], $domyslny['do_zuzycia']);

        $this->actingAs($ja)->get(route('pantry.cook'))->assertOk()
            ->assertSee(CoUgotuje::REGULA)
            ->assertDontSee('Zużyjesz:')
            ->assertSee('Najpierw to, co się psuje');
    }

    public function test_mrozone_i_bez_terminu_i_zbyt_dalekie_nie_liczą_sie_jako_pilne(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11', 'kurczak' => '2026-10-09', 'ser' => '2026-10-14', 'jajka' => null]);
        $ja->pantryItems()->where('name', 'kurczak')->update(['frozen' => true]);
        $this->przepis('Rosół', ['kurczak', 'marchew']);
        $this->przepis('Jajecznica', ['jajka', 'masło']);
        $this->przepis('Grzanka', ['ser', 'chleb']);
        $pilny = $this->przepis('Budyń', ['mleko', 'cukier']);

        $wynik = app(CoUgotuje::class)->dla($ja, 0, 20, true);

        $this->assertSame([$pilny->title], $wynik['przepisy']->pluck('title')->all());
    }

    /**
     * Mrożony produkt z terminem sprzed miesiąca nie liczy się jako pilny ani w
     * kolejności (`pilnych_pasuje`), ani w zdaniu „Zużyjesz”, nawet gdy stoi w tym
     * samym przepisie co produkt pilny.
     */
    public function test_mrozony_produkt_w_tym_samym_przepisie_nie_podbija_liczby_pilnych_ani_zdania(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11', 'szynka' => '2026-10-11', 'kurczak' => '2026-09-10']);
        $ja->pantryItems()->where('name', 'kurczak')->update(['frozen' => true]);

        // A: jeden pilny + mrożony, brakuje 0; B: dwa pilne, brakuje 1. Poprawnie: B przed A.
        $a = $this->przepis('Rosół na mleku', ['mleko', 'kurczak']);
        $b = $this->przepis('Zapiekanka z szynką', ['mleko', 'szynka', 'sól morska']);

        $wynik = app(CoUgotuje::class)->dla($ja, 0, 20, true);

        $this->assertSame([$b->title, $a->title], $wynik['przepisy']->pluck('title')->all());
        $this->assertSame([['nazwa' => 'mleko', 'termin' => '2026-10-11']], $wynik['do_zuzycia'][$a->getKey()]);
    }

    public function test_termin_nalezy_zuzyc_do_ktory_minal_nie_jest_pilny(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-09-20']);
        $przepis = $this->przepis('Budyń', ['mleko', 'cukier']);

        $wynik = app(CoUgotuje::class)->dla($ja, 0, 20, true);

        $this->assertSame([], $wynik['przepisy']->pluck('title')->all());
        $this->actingAs($ja)->get(route('pantry.cook'))->assertOk()
            ->assertSee('Nie znaleźliśmy przepisu z tymi produktami')
            ->assertDontSee($przepis->title)
            ->assertDontSee('Najpierw wpisz, co masz w domu');
    }

    public function test_po_terminie_nalezy_zuzyc_do_nie_jest_skladnikiem_w_zadnym_trybie_ale_inne_terminy_pozostaja(): void
    {
        $ja = $this->user();
        $obca = $this->user('obca_z_terminem');
        $this->lista($ja, [
            'mleko' => '2026-10-09', 'jajka' => '2026-10-10',
            'mąka' => '2026-10-09', 'kurczak' => '2026-10-09',
            'ser' => '2026-10-11',
        ]);
        $ja->pantryItems()->where('name', 'mąka')->update(['expiry_kind' => 'best_before']);
        $ja->pantryItems()->where('name', 'kurczak')->update(['frozen' => true]);
        $this->lista($obca, ['tajne jajka' => '2026-10-10']);

        $mieszany = $this->przepis('Placek z mlekiem', ['mąka', 'mleko']);
        $this->przepis('Budyń tylko z mleka', ['mleko']);
        $this->przepis('Kurczak pieczony', ['kurczak']);
        $this->przepis('Jajecznica', ['jajka']);
        $this->przepis('Ser zapiekany', ['ser']);

        $domyslny = app(CoUgotuje::class)->dla($ja);
        $this->assertContains($mieszany->getKey(), $domyslny['przepisy']->modelKeys(), 'USE_BY_2453_BEZ_DOBORU');
        $this->assertSame(['mleko'], $domyslny['brakujace'][$mieszany->getKey()], 'USE_BY_2453_BEZ_DOBORU');
        $this->assertNotContains('Budyń tylko z mleka', $domyslny['przepisy']->pluck('title')->all(), 'USE_BY_2453_BEZ_DOBORU');
        $this->assertContains('Kurczak pieczony', $domyslny['przepisy']->pluck('title')->all(), 'Mrożony produkt zostaje dostępny.');
        $this->assertContains('Jajecznica', $domyslny['przepisy']->pluck('title')->all(), 'Termin dzisiaj zostaje dostępny.');
        $this->assertContains('Ser zapiekany', $domyslny['przepisy']->pluck('title')->all(), 'Termin jutro zostaje dostępny.');

        $pilny = app(CoUgotuje::class)->dla($ja, 0, 20, true);
        $this->assertSame(['mąka'], array_column($pilny['do_zuzycia'][$mieszany->getKey()], 'nazwa'), 'USE_BY_2453_BEZ_DOBORU');
        $this->assertNotContains('Budyń tylko z mleka', $pilny['przepisy']->pluck('title')->all(), 'USE_BY_2453_BEZ_DOBORU');
        $this->assertContains('Jajecznica', $pilny['przepisy']->pluck('title')->all());

        $this->actingAs($ja)->get(route('pantry.cook'))->assertOk()
            ->assertSee('Placek z mlekiem')
            ->assertSee('Brakuje: mleko.')
            ->assertDontSee('Budyń tylko z mleka');
        $this->get(route('pantry.cook', ['najpierw' => 'termin']))->assertOk()
            ->assertSee('Zużyjesz:')
            ->assertDontSee('Budyń tylko z mleka')
            ->assertDontSee('tajne jajka');
    }

    public function test_zdanie_zuzyjesz_zawiera_tylko_produkty_wlasciciela(): void
    {
        $ja = $this->user();
        $obca = $this->user('obca');
        $this->lista($ja, ['mleko' => '2026-10-11']);
        $this->lista($obca, ['szynka' => '2026-10-11', 'mleko' => '2026-10-10']);
        $this->przepis('Zapiekanka', ['mleko', 'szynka', 'ser']);

        $strona = $this->actingAs($ja)->get(route('pantry.cook', ['najpierw' => 'termin']))->assertOk();

        $strona->assertSee('Zużyjesz:')
            ->assertSee('mleko (do 11 października)')
            ->assertSee(CoUgotuje::REGULA_NAJPIERW_TERMIN)
            ->assertSee('Przepisy na produkty z krótkim terminem')
            ->assertDontSee('szynka (do');
        $this->assertStringNotContainsString('10 października)', strip_tags($strona->getContent()), 'Termin cudzego mleka nie może się pokazać.');
    }

    public function test_widocznosc_przepisow_jak_w_widoku_domyslnym(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11']);
        $this->przepis('Publiczny budyń', ['mleko', 'cukier']);
        $this->przepis('Szkic budyniu', ['mleko', 'cukier'], szkic: true);
        $this->przepis('Prywatny budyń', ['mleko', 'cukier'], atrybuty: ['visibility' => 'private']);
        $zbanowany = $this->user('zbanowany', ['status' => User::STATUS_BANNED]);
        $this->przepis('Budyń zbanowanego', ['mleko', 'cukier'], atrybuty: ['author_id' => $zbanowany->getKey()]);

        $tytuly = app(CoUgotuje::class)->dla($ja, 0, 20, true)['przepisy']->pluck('title')->all();

        $this->assertSame(['Publiczny budyń'], $tytuly);
    }

    public function test_ugotowalem_i_zapisy_nie_zmieniaja_kolejnosci(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11', 'szynka' => '2026-10-11']);
        $popularny = $this->przepis('Popularny budyń', ['mleko', 'cukier'], 5, 5);
        $rzadki = $this->przepis('Rzadki rosół', ['mleko', 'szynka'], 50, 50);

        CookedEvent::factory()->count(12)->create(['recipe_id' => $popularny->getKey()]);

        $tytuly = app(CoUgotuje::class)->dla($ja, 0, 20, true)['przepisy']->pluck('title')->all();

        $this->assertSame([$rzadki->title, $popularny->title], $tytuly);
    }

    public function test_brak_dopasowan_mowi_to_wprost_i_prowadzi_do_wszystkich_propozycji(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-12-01', 'jajka' => null]);
        $this->przepis('Jajecznica', ['jajka']);

        $this->actingAs($ja)->get(route('pantry.cook', ['najpierw' => 'termin']))->assertOk()
            ->assertSee('Żaden przepis nie pasuje do produktów z krótkim terminem.')
            ->assertSee('Zobacz wszystkie propozycje')
            ->assertSee(route('pantry.cook'), false);
    }

    public function test_otwarcie_trybu_zostawia_anonimowy_sygnal_tylko_na_pierwszej_stronie(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11']);

        $this->actingAs($ja)->get(route('pantry.cook', ['najpierw' => 'termin']))->assertOk();
        $this->actingAs($ja)->get(route('pantry.cook', ['najpierw' => 'termin', 'od' => 20]))->assertOk();
        $this->actingAs($ja)->get(route('pantry.cook'))->assertOk();

        $wiersze = ProductSignal::query()->where('signal_name', ZapiszSygnal::PANTRY_COOK_PRIORITY_VIEWED)->get();
        $this->assertCount(1, $wiersze);
        $this->assertNull($wiersze[0]->user_id);
    }

    public function test_sygnal_trybu_termin_leci_raz_na_sesje_a_nie_przy_kazdym_wejsciu_na_pierwsza_strone(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11']);

        $this->actingAs($ja)->get(route('pantry.cook', ['najpierw' => 'termin']))->assertOk();
        $this->get(route('pantry.cook', ['najpierw' => 'termin']))->assertOk();
        $this->get(route('pantry.cook', ['najpierw' => 'termin', 'od' => 0]))->assertOk();

        $this->assertSame(1, ProductSignal::query()->where('signal_name', ZapiszSygnal::PANTRY_COOK_PRIORITY_VIEWED)->count());
    }

    public function test_tryb_termin_dziala_i_stronicuje_na_wielu_przepisach(): void
    {
        $ja = $this->user();
        $this->lista($ja, ['mleko' => '2026-10-11', 'szynka' => '2026-10-12', 'jajka' => null, 'mąka' => null]);
        for ($i = 0; $i < 60; $i++) {
            $this->przepis('Przepis '.$i, [$i % 2 === 0 ? 'mleko' : 'kasza', 'mąka', 'sól '.$i]);
        }

        $wynik = app(CoUgotuje::class)->dla($ja, 0, 20, true);

        $this->assertCount(20, $wynik['przepisy']);
        $this->assertTrue($wynik['jest_wiecej']);
        $this->assertCount(20, $wynik['do_zuzycia']);
    }

    /** @param  array<string, string|null>  $produkty nazwa => termin (Y-m-d) albo null */
    private function lista(User $user, array $produkty): void
    {
        foreach ($produkty as $nazwa => $termin) {
            $produkt = $user->pantryItems()->create(['name' => $nazwa]);

            if ($termin !== null) {
                DB::table('pantry_items')->where('id', $produkt->getKey())->update(['expires_on' => $termin, 'expiry_kind' => 'use_by']);
            }
        }
    }

    /**
     * @param  list<string>  $skladniki
     * @param  array<string, mixed>  $atrybuty
     */
    private function przepis(string $tytul, array $skladniki, ?int $przygotowanie = 10, ?int $gotowanie = 10, array $atrybuty = [], bool $szkic = false): Recipe
    {
        $fabryka = Recipe::factory();
        $przepis = ($szkic ? $fabryka->draft() : $fabryka)->create([
            'title' => $tytul,
            'prep_minutes' => $przygotowanie,
            'cook_minutes' => $gotowanie,
            ...(isset($atrybuty['author_id']) ? [] : ['author_id' => $this->user('autor'.substr(md5($tytul), 0, 8))->getKey()]),
            ...$atrybuty,
        ]);

        foreach ($skladniki as $i => $tekst) {
            $przepis->ingredients()->create(['position' => $i, 'ingredient_text' => $tekst]);
        }

        return $przepis;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zeszyt: filtr „Ugotowane przeze mnie” i kolejność „Ostatnio ugotowane”
 * (issue #2411, decyzja właściciela z 2.10.2026, D-333).
 *
 * Liczą się wyłącznie WŁASNE wykonania zalogowanej osoby; przepis z wieloma
 * wykonaniami to jeden wynik z najnowszą datą; wykonania innych osób niczego
 * nie zmieniają; widoczność, blokady i prywatność zeszytów stoją pierwsze.
 */
class ZeszytUgotowanePrzezMnieTest extends TestCase
{
    use RefreshDatabase;

    private User $basia;

    private Collection $zeszyt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basia = $this->user('basia');
        $this->zeszyt = Collection::create(['owner_id' => $this->basia->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
    }

    private function zapisany(string $tytul, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create($atrybuty + ['title' => $tytul]);
        $this->zeszyt->recipes()->attach($przepis->getKey());

        return $przepis;
    }

    private function ugotowal(User $kto, Recipe $przepis, string $kiedy): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kto->getKey(),
            'recipe_id' => $przepis->getKey(),
            'cooked_at' => $kiedy,
        ]);
    }

    /**
     * @param  array<string, string>  $zapytanie
     * @return list<string> tytuły w kolejności z sekcji wyników
     */
    private function tytuly(array $zapytanie, ?User $kto = null): array
    {
        $html = $this->actingAs($kto ?? $this->basia)->get(route('collections.index', $zapytanie))->assertOk()->getContent();
        $this->assertIsString($html);

        return $this->tytulyZHtml($html);
    }

    /**
     * @return list<string>
     */
    private function tytulyZHtml(string $html): array
    {
        $start = strpos($html, 'data-wyniki-w-zeszytach');
        $this->assertNotFalse($start, 'Brak sekcji wyników.');
        $sekcja = substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
        preg_match_all('/<p class="m-0 font-bold"><a href="[^"]*">([^<]+)<\/a>/u', $sekcja, $m);

        return array_map(html_entity_decode(...), $m[1]);
    }

    public function test_filtr_zwraca_tylko_zapisane_przepisy_z_wlasnym_wykonaniem_raz_na_przepis(): void
    {
        $zupa = $this->zapisany('Zupa');
        $this->zapisany('Sernik');
        $bigos = $this->zapisany('Bigos');

        $this->ugotowal($this->basia, $zupa, '2026-09-01 10:00:00');
        $this->ugotowal($this->basia, $zupa, '2026-09-20 10:00:00');
        $this->ugotowal($this->basia, $zupa, '2026-09-10 10:00:00');
        $this->ugotowal($this->basia, $bigos, '2026-08-01 10:00:00');

        $this->assertSame(['Bigos', 'Zupa'], $this->tytuly(['ugotowane' => '1']));
        // Kontrola dodatnia: bez filtra i bez frazy nie ma listy w ogóle (jak dotąd).
        $this->actingAs($this->basia)->get(route('collections.index'))->assertDontSee('data-wyniki-w-zeszytach', false);
    }

    public function test_wykonania_innych_osob_nie_wplywaja_na_filtr_kolejnosc_ani_date(): void
    {
        $obca = $this->user('obca');
        $zupa = $this->zapisany('Zupa');
        $sernik = $this->zapisany('Sernik');

        $this->ugotowal($this->basia, $zupa, '2026-08-01 10:00:00');
        $this->ugotowal($obca, $zupa, '2026-10-01 10:00:00');
        $this->ugotowal($obca, $sernik, '2026-10-02 10:00:00');

        $this->assertSame(['Zupa'], $this->tytuly(['ugotowane' => '1']));

        $html = $this->actingAs($this->basia)->get(route('collections.index', ['ugotowane' => '1']))->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('1 sierpnia 2026', $html);
        $this->assertStringNotContainsString('1 października 2026', $html);
        $this->assertStringNotContainsString('2 października 2026', $html);
    }

    public function test_kolejnosc_ostatnio_ugotowane_uzywa_najnowszej_daty_a_przepisy_bez_wykonania_ida_na_koniec(): void
    {
        $a = $this->zapisany('Arbuz');
        $b = $this->zapisany('Barszcz');
        $c = $this->zapisany('Chleb');
        $this->zapisany('Dżem');

        $this->ugotowal($this->basia, $a, '2026-09-30 10:00:00');
        $this->ugotowal($this->basia, $a, '2026-01-01 10:00:00');
        $this->ugotowal($this->basia, $b, '2026-10-01 10:00:00');
        $this->ugotowal($this->basia, $c, '2026-05-01 10:00:00');

        $this->assertSame(['Barszcz', 'Arbuz', 'Chleb', 'Dżem'], $this->tytuly(['kolejnosc' => 'ostatnio-ugotowane']));
        // Kontrola dodatnia: sam filtr zostawia domyślną kolejność, czyli alfabet.
        $this->assertSame(['Arbuz', 'Barszcz', 'Chleb'], $this->tytuly(['ugotowane' => '1']));
    }

    public function test_wybory_lacza_sie_z_fraza_i_filtr_z_kolejnoscia_oraz_pokazuja_prywatna_date(): void
    {
        $zupaGrzybowa = $this->zapisany('Zupa grzybowa');
        $zupaPomidorowa = $this->zapisany('Zupa pomidorowa');
        $this->zapisany('Zupa ogórkowa');
        $this->zapisany('Sernik');

        $this->ugotowal($this->basia, $zupaGrzybowa, '2026-03-01 10:00:00');
        $this->ugotowal($this->basia, $zupaPomidorowa, '2026-09-01 10:00:00');

        $this->assertSame(
            ['Zupa pomidorowa', 'Zupa grzybowa'],
            $this->tytuly(['szukaj' => 'zupa', 'ugotowane' => '1', 'kolejnosc' => 'ostatnio-ugotowane']),
        );
        $this->assertSame(
            ['Zupa grzybowa', 'Zupa ogórkowa', 'Zupa pomidorowa'],
            $this->tytuly(['szukaj' => 'zupa']),
        );
        $this->assertSame(
            ['Zupa pomidorowa', 'Zupa grzybowa', 'Zupa ogórkowa'],
            $this->tytuly(['szukaj' => 'zupa', 'kolejnosc' => 'ostatnio-ugotowane']),
        );

        $html = $this->actingAs($this->basia)->get(route('collections.index', ['szukaj' => 'zupa', 'ugotowane' => '1']))->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('Ostatnio ugotowane przez Ciebie', $html);
        $this->assertStringContainsString('1 września 2026', $html);
        $this->assertStringNotContainsString('Pasuje przez składnik', $html);
    }

    public function test_bez_wyboru_data_wykonania_nie_pojawia_sie_w_wynikach_frazy(): void
    {
        $zupa = $this->zapisany('Zupa');
        $this->ugotowal($this->basia, $zupa, '2026-03-01 10:00:00');

        $html = $this->actingAs($this->basia)->get(route('collections.index', ['szukaj' => 'zupa']))->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('Ostatnio ugotowane przez Ciebie', $html);
    }

    public function test_widocznosc_blokady_i_cudze_zeszyty_nadal_obowiazuja(): void
    {
        $obca = $this->user('obca');
        $prywatny = $this->zapisany('Nalewka tajemna', ['visibility' => 'public']);
        $zablokowany = User::factory()->create();
        $cudzy = $this->zapisany('Pierogi cudze', ['author_id' => $zablokowany->getKey()]);
        $this->ugotowal($this->basia, $prywatny, '2026-09-01 10:00:00');
        $this->ugotowal($this->basia, $cudzy, '2026-09-02 10:00:00');
        $cudzyZeszyt = Collection::create(['owner_id' => $obca->getKey(), 'name' => 'Obcy', 'visibility' => 'private']);
        $wCudzym = Recipe::factory()->create(['title' => 'Tylko w cudzym']);
        $cudzyZeszyt->recipes()->attach($wCudzym->getKey());
        $this->ugotowal($this->basia, $wCudzym, '2026-09-03 10:00:00');

        // Kontrola dodatnia.
        $this->assertEqualsCanonicalizing(['Nalewka tajemna', 'Pierogi cudze'], $this->tytuly(['ugotowane' => '1']));

        $prywatny->forceFill(['visibility' => 'private'])->save();
        DB::table('blocks')->insert(['blocker_id' => $this->basia->getKey(), 'blocked_id' => $zablokowany->getKey(), 'created_at' => now()]);

        $html = $this->actingAs($this->basia)->get(route('collections.index', ['ugotowane' => '1']))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringNotContainsString('Nalewka tajemna', $html);
        $this->assertStringNotContainsString('Pierogi cudze', $html);
        $this->assertStringNotContainsString('Tylko w cudzym', $html, 'przepis tylko w cudzym zeszycie nie wchodzi do wyniku');
        $this->assertStringContainsString('Żaden z zapisanych przepisów nie ma jeszcze Twojego wykonania', $html);
    }

    public function test_obca_osoba_z_wlasnym_zeszytem_nie_widzi_wykonan_innych(): void
    {
        $obca = $this->user('obca');
        $zeszytObcej = Collection::create(['owner_id' => $obca->getKey(), 'name' => 'Jej', 'visibility' => 'private']);
        $zupa = $this->zapisany('Zupa');
        $zeszytObcej->recipes()->attach($zupa->getKey());
        $this->ugotowal($this->basia, $zupa, '2026-09-01 10:00:00');

        $this->assertSame([], $this->tytuly(['ugotowane' => '1'], $obca), 'wykonanie Basi nie robi z zapisu obcej „ugotowanego”');
        $this->assertSame(['Zupa'], $this->tytuly(['ugotowane' => '1'], $this->basia));
    }

    public function test_pusty_wynik_filtra_i_czyszczenie_sa_czytelne_a_dziwne_wartosci_ignorowane(): void
    {
        $this->zapisany('Zupa');

        $this->actingAs($this->basia)->get(route('collections.index', ['ugotowane' => '1']))
            ->assertOk()
            ->assertSee('Żaden z zapisanych przepisów nie ma jeszcze Twojego wykonania „Ugotowałem”', false)
            ->assertSee('Wyczyść wyszukiwanie')
            ->assertDontSee('Zeszyt jest jeszcze pusty');

        foreach (['ugotowane[]=1', 'ugotowane=0', 'ugotowane=tak', 'kolejnosc[]=x', 'kolejnosc=inna'] as $zapytanie) {
            $this->actingAs($this->basia)->get('/zeszyt?'.$zapytanie)
                ->assertOk()
                ->assertDontSee('data-wyniki-w-zeszytach', false);
        }
    }

    public function test_formularz_ma_widoczne_etykiety_i_wybory_zaznaczone_zgodnie_z_adresem(): void
    {
        $html = $this->actingAs($this->basia)->get(route('collections.index', ['ugotowane' => '1', 'kolejnosc' => 'ostatnio-ugotowane']))->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('Ugotowane przeze mnie', $html);
        $this->assertMatchesRegularExpression('/<input id="f-zeszyt-ugotowane" type="checkbox" name="ugotowane" value="1"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/<input type="radio" name="kolejnosc" value="ostatnio-ugotowane"[^>]*checked/', $html);

        $domyslny = $this->actingAs($this->basia)->get(route('collections.index'))->getContent();
        $this->assertIsString($domyslny);
        $this->assertDoesNotMatchRegularExpression('/<input id="f-zeszyt-ugotowane"[^>]*checked/', $domyslny);
        $this->assertMatchesRegularExpression('/<input type="radio" name="kolejnosc" value="alfabetycznie"[^>]*checked/', $domyslny);
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wynikow(): void
    {
        $this->ugotowal($this->basia, $this->zapisany('Zupa 0'), '2026-09-01 10:00:00');

        $zlicz = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->basia)->get(route('collections.index', ['ugotowane' => '1', 'kolejnosc' => 'ostatnio-ugotowane']))->assertOk();

            return count(DB::getQueryLog());
        };

        $jedna = $zlicz();

        for ($i = 1; $i <= 12; $i++) {
            $przepis = $this->zapisany('Zupa '.$i);
            $this->ugotowal($this->basia, $przepis, '2026-09-0'.(($i % 9) + 1).' 10:00:00');
            $this->ugotowal($this->basia, $przepis, '2026-08-0'.(($i % 9) + 1).' 10:00:00');
        }

        $this->assertSame($jedna, $zlicz(), 'liczba zapytań zależy od liczby wyników (N+1)');
    }
}

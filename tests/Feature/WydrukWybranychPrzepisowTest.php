<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wydruk wybranych przepisów z zeszytu ze wspólnym spisem (issue #2463,
 * decyzja właściciela z 2.10.2026, D-333).
 *
 * Domyślnie cały zeszyt (bez zmian). Wybór to lista identyfikatorów w
 * adresie — NIE autoryzacja: wchodzi wyłącznie jako zawężenie zakresu
 * widocznych przepisów, więc cudze, spoza zeszytu, ukryte i usunięte
 * identyfikatory niczego nie ujawniają. Okładka, spis i liczba dotyczą
 * wyłącznie wyboru; wybór jest niezależny od zdjęć.
 */
class WydrukWybranychPrzepisowTest extends TestCase
{
    use RefreshDatabase;

    private User $halina;

    private Collection $zeszyt;

    /** @var list<Recipe> */
    private array $przepisy = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina', ['display_name' => 'Halina']);
        $this->zeszyt = Collection::create(['owner_id' => $this->halina->getKey(), 'name' => 'Rodzinny', 'visibility' => 'private']);

        foreach (['Arbuz', 'Barszcz', 'Chleb', 'Dżem', 'Eklery', 'Fasolka'] as $tytul) {
            $this->przepisy[] = $this->przepis($tytul);
        }
    }

    private function przepis(string $tytul, ?Collection $zeszyt = null, ?User $autor = null, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => ($autor ?? $this->halina)->getKey(), 'title' => $tytul] + $atrybuty);
        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'ingredient_text' => "Skladnik: {$tytul}"]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => "Krok: {$tytul}"]);
        ($zeszyt ?? $this->zeszyt)->recipes()->attach($przepis->getKey(), ['created_at' => now(), 'added_by_id' => $this->halina->getKey()]);

        return $przepis;
    }

    /**
     * @param  array<string, mixed>  $parametry
     */
    private function druk(?User $kto = null, array $parametry = []): TestResponse
    {
        $zadanie = $kto === null ? $this : $this->actingAs($kto);

        return $zadanie->get(route('collections.print', ['collection' => $this->zeszyt] + $parametry));
    }

    /**
     * @param  list<Recipe>  $wybrane
     * @return array<string, mixed>
     */
    private function wybor(array $wybrane, array $dodatkowe = []): array
    {
        return ['tryb' => 'wybrane', 'przepisy' => array_map(fn (Recipe $p): string => (string) $p->getKey(), $wybrane)] + $dodatkowe;
    }

    /** @return list<string> tytuły ze spisu treści w kolejności */
    private function spis(string $html): array
    {
        preg_match('/<ol class="zeszyt-spis-lista">(.*?)<\/ol>/s', $html, $blok);
        preg_match_all('/<a href="#przepis-\d+">([^<]+)<\/a>/u', $blok[1] ?? '', $m);

        return array_map(html_entity_decode(...), $m[1]);
    }

    public function test_domyslnie_drukuje_sie_caly_zeszyt_i_jest_przycisk_wyboru(): void
    {
        $html = $this->druk($this->halina)->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertSame(['Arbuz', 'Barszcz', 'Chleb', 'Dżem', 'Eklery', 'Fasolka'], $this->spis($html));
        $this->assertStringContainsString('>Wybierz przepisy</a>', $html);
        $this->assertStringNotContainsString('data-wybor-przepisow', $html);
        $this->assertStringNotContainsString('>Cały zeszyt</a>', $html);
        $this->assertStringContainsString('6 przepisów', $html);
    }

    public function test_wybrane_przepisy_dostaja_wspolny_spis_okladke_i_liczbe_tylko_dla_wyboru(): void
    {
        $html = $this->druk($this->halina, $this->wybor([$this->przepisy[4], $this->przepisy[1], $this->przepisy[2]]))->assertOk()->getContent();

        $this->assertIsString($html);
        // Kolejność deterministyczna jak przy całości, nie kolejność z adresu.
        $this->assertSame(['Barszcz', 'Chleb', 'Eklery'], $this->spis($html));
        $this->assertStringContainsString('Przepis 3 z 3', $html);
        $this->assertStringContainsString('Wybrane przepisy: 3 przepisy', $html);
        $this->assertStringContainsString('Wydruk obejmuje tylko wybrane przepisy: 3 z 6.', $html);
        $this->assertStringContainsString('>Cały zeszyt</a>', $html);
        $this->assertStringNotContainsString('Skladnik: Arbuz', $html);
        $this->assertStringContainsString('Skladnik: Chleb', $html);
    }

    public function test_duplikaty_identyfikatorow_i_smieci_nie_psuja_wyboru(): void
    {
        $id = (string) $this->przepisy[0]->getKey();
        $html = $this->druk($this->halina, ['tryb' => 'wybrane', 'przepisy' => [$id, $id, strtoupper($id), 'nie-uuid', ['x'], '']])->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertSame(['Arbuz'], $this->spis($html));
        $this->assertStringContainsString('Wybrane przepisy: 1 przepis', $html);
    }

    public function test_pusty_wybor_daje_instrukcje_zamiast_calego_zeszytu(): void
    {
        foreach ([['tryb' => 'wybrane'], ['tryb' => 'wybrane', 'przepisy' => []], ['przepisy' => 'tekst']] as $parametry) {
            $html = $this->druk($this->halina, $parametry)->assertOk()->getContent();

            $this->assertIsString($html);
            $this->assertStringContainsString('Nie wybrano żadnego przepisu.', $html);
            $this->assertStringContainsString('zaznacz przynajmniej jeden przepis', $html);
            $this->assertStringNotContainsString('Skladnik:', $html, 'pusty wybór nie wraca po cichu do całego zeszytu');
            $this->assertStringNotContainsString('zeszyt-okladka', $html);
            $this->assertStringNotContainsString('data-drukuj-przepis', $html, 'nie ma czego drukować');
        }
    }

    public function test_cudze_spoza_zeszytu_ukryte_i_usuniete_identyfikatory_nie_ujawniaja_tytulow(): void
    {
        $obca = $this->user('obca', ['display_name' => 'Obca']);
        $cudzyZeszyt = Collection::create(['owner_id' => $obca->getKey(), 'name' => 'Obcy', 'visibility' => 'private']);
        $spozaZeszytu = $this->przepis('Sekretna zupa spoza', $cudzyZeszyt, $obca);
        $ukryty = $this->przepis('Ukryty gulasz', null, $this->user('autor2463'), ['status' => 'hidden']);
        $usuniety = $this->przepis('Usunięty bigos');
        $usuniety->delete();
        $zablokowany = $this->user('zablokowany');
        $odBlokowanego = $this->przepis('Kotlet blokady', null, $zablokowany);
        DB::table('blocks')->insert(['blocker_id' => $this->halina->getKey(), 'blocked_id' => $zablokowany->getKey(), 'created_at' => now()]);

        $html = $this->druk($this->halina, $this->wybor([$this->przepisy[0], $spozaZeszytu, $ukryty, $usuniety, $odBlokowanego]))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertSame(['Arbuz'], $this->spis($html));
        foreach (['Sekretna zupa', 'Ukryty gulasz', 'Usunięty bigos', 'Kotlet blokady'] as $tytul) {
            $this->assertStringNotContainsString($tytul, $html);
        }
        // Zmiana dostępności między wyborem a podglądem: sama liczba, bez nazw.
        $this->assertStringContainsString('Wybrane przepisy, które nie są już dla Ciebie dostępne: 4.', $html);
    }

    public function test_wybor_nie_przyznaje_dostepu_obcym_ani_po_cofnieciu_wspolpracy(): void
    {
        $obca = $this->user('obca');
        $this->druk($obca, $this->wybor([$this->przepisy[0]]))->assertForbidden();

        $wspolpracownik = $this->user('jurek');
        DB::table('collection_members')->insert(['collection_id' => $this->zeszyt->getKey(), 'user_id' => $wspolpracownik->getKey(), 'created_at' => now()]);
        $this->assertSame(['Arbuz'], $this->spis((string) $this->druk($wspolpracownik, $this->wybor([$this->przepisy[0]]))->assertOk()->getContent()));

        DB::table('collection_members')->where('user_id', $wspolpracownik->getKey())->delete();
        $this->druk($wspolpracownik, $this->wybor([$this->przepisy[0]]))->assertForbidden();
        $this->get(route('collections.print.select', $this->zeszyt))->assertForbidden();
    }

    public function test_przepis_spoza_pierwszej_setki_mozna_wybrac_a_limit_obowiazuje_dla_wyboru(): void
    {
        config(['kuking.collections.print_max_recipes' => 3]);
        $dalekie = $this->przepis('Zupa na końcu alfabetu');

        $html = $this->druk($this->halina, $this->wybor([$dalekie]))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertSame(['Zupa na końcu alfabetu'], $this->spis($html));

        $wiele = $this->druk($this->halina, $this->wybor(array_slice($this->przepisy, 0, 5)))->assertOk()->getContent();
        $this->assertIsString($wiele);
        $this->assertCount(3, $this->spis($wiele));
        $this->assertStringContainsString('Ten zeszyt ma więcej przepisów, niż mieści jeden wydruk', $wiele);
    }

    public function test_zdjecia_i_wybor_sa_niezalezne_a_odnosniki_niosa_oba(): void
    {
        $html = $this->druk($this->halina, $this->wybor([$this->przepisy[0], $this->przepisy[1]], ['bez-zdjec' => 1]))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/href="(?=[^"]*bez-zdjec=1)(?=[^"]*tryb=wybrane)(?=[^"]*druk=1)(?=[^"]*przepisy%5B0%5D)[^"]*"[^>]*>Wydrukuj zeszyt<\/a>/', $html);
        $this->assertMatchesRegularExpression('/href="(?=[^"]*tryb=wybrane)(?=[^"]*przepisy%5B1%5D)(?![^"]*bez-zdjec)[^"]*"[^>]*>Ze zdjęciami<\/a>/', $html);
        // „Cały zeszyt" zachowuje zdjęcia, ale zdejmuje wybór.
        $this->assertMatchesRegularExpression('/href="(?=[^"]*bez-zdjec=1)(?![^"]*przepisy)(?![^"]*tryb)[^"]*"[^>]*>Cały zeszyt<\/a>/', $html);
        // Zmiana zdjęć nie rozszerza zestawu przepisów.
        $this->assertSame(['Arbuz', 'Barszcz'], $this->spis($html));
    }

    public function test_ekran_wyboru_ma_tytuly_etykiety_novalidate_i_zachowuje_zaznaczenie_i_opcje(): void
    {
        $html = $this->actingAs($this->halina)
            ->get(route('collections.print.select', ['collection' => $this->zeszyt, 'przepisy' => [(string) $this->przepisy[2]->getKey()], 'bez-zdjec' => 1]))
            ->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/<form[^>]*method="GET"[^>]*novalidate/', $html);
        $this->assertStringContainsString('name="tryb" value="wybrane"', $html);
        $this->assertStringContainsString('name="bez-zdjec" value="1"', $html);
        $this->assertSame(6, substr_count($html, 'name="przepisy[]"'));
        $this->assertMatchesRegularExpression('/id="f-przepis-'.$this->przepisy[2]->getKey().'" type="checkbox" name="przepisy\[\]" value="[^"]+" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="f-przepis-'.$this->przepisy[0]->getKey().'"[^>]*checked/', $html);
        $this->assertStringContainsString('Pokaż wybrane do druku', $html);
        $this->assertStringContainsString('Arbuz', $html);
    }

    public function test_ekran_wyboru_nie_ujawnia_niedostepnych_przepisow_i_ma_stala_liczbe_zapytan(): void
    {
        $this->przepis('Ukryty gulasz', null, $this->user('autor2463'), ['status' => 'hidden']);
        $html = $this->actingAs($this->halina)->get(route('collections.print.select', $this->zeszyt))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringNotContainsString('Ukryty gulasz', $html);

        $zlicz = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->halina)->get(route('collections.print.select', $this->zeszyt))->assertOk();

            return count(DB::getQueryLog());
        };
        $przed = $zlicz();
        for ($i = 0; $i < 10; $i++) {
            $this->przepis('Kolejny '.$i);
        }
        $this->assertSame($przed, $zlicz(), 'liczba zapytań rośnie z liczbą przepisów');
    }

    public function test_wydruk_wybranych_ma_stala_liczbe_zapytan_niezaleznie_od_liczby_wybranych(): void
    {
        $zlicz = function (array $wybrane): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->druk($this->halina, $this->wybor($wybrane))->assertOk();

            return count(DB::getQueryLog());
        };

        $jeden = $zlicz([$this->przepisy[0]]);
        $this->assertSame($jeden, $zlicz($this->przepisy), 'liczba zapytań zależy od liczby wybranych przepisów');
    }
}

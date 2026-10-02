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
 * „Z notatkami / Bez notatek” na wydruku zeszytu (issue #2438, decyzja
 * właściciela z 2.10.2026, D-333).
 *
 * Wybór jest niezależny od zdjęć i tylko ZAWĘŻA: parametr w adresie nie
 * przyznaje prawa do notatek. „Bez notatek” znaczy, że dopisek z zeszytu nie
 * ma ani w HTML, ani w ukrytych elementach — samo ukrycie CSS-em nie
 * wystarcza. Baza, daty i notatki zostają nietknięte.
 */
class WydrukZeszytuBezNotatekTest extends TestCase
{
    use RefreshDatabase;

    private const NOTATKA = 'na urodziny taty — tajny dopisek';

    private User $halina;

    private Collection $zeszyt;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->halina = $this->user('halina', ['display_name' => 'Halina']);
        $this->zeszyt = Collection::create(['owner_id' => $this->halina->getKey(), 'name' => 'Rodzinny', 'visibility' => 'private']);
        $this->przepis = Recipe::factory()->create(['author_id' => $this->halina->getKey(), 'title' => 'Sernik babci', 'source_note' => 'Z przepisu prababci']);
        RecipeIngredient::create(['recipe_id' => $this->przepis->getKey(), 'position' => 0, 'ingredient_text' => 'Twaróg', 'note' => 'uwaga autora do składnika']);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 0, 'instruction' => 'Wymieszaj']);
        $this->zeszyt->recipes()->attach($this->przepis->getKey(), ['created_at' => '2026-03-01 10:00:00', 'note' => self::NOTATKA, 'added_by_id' => $this->halina->getKey()]);
    }

    /**
     * @param  array<string, mixed>  $parametry
     */
    private function druk(?User $kto = null, array $parametry = []): TestResponse
    {
        $zadanie = $kto === null ? $this : $this->actingAs($kto);

        return $zadanie->get(route('collections.print', ['collection' => $this->zeszyt] + $parametry));
    }

    public function test_domyslnie_wydruk_ma_notatke_jak_dotad(): void
    {
        $html = $this->druk($this->halina)->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString(self::NOTATKA, $html);
        $this->assertStringContainsString('Notatka z zeszytu', $html);
        $this->assertStringContainsString('Ten wydruk będzie ze zdjęciami i z notatkami z Twojego zeszytu', $html);
        $this->assertStringContainsString('>Bez notatek</a>', $html);
    }

    public function test_bez_notatek_dopisek_nie_trafia_do_html_ani_do_danych_ale_uwagi_autora_zostaja(): void
    {
        $html = $this->druk($this->halina, ['bez-notatek' => 1])->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString(self::NOTATKA, $html);
        $this->assertStringNotContainsString('tajny dopisek', $html);
        $this->assertStringNotContainsString('Notatka z zeszytu', $html);
        // Uwagi autora, pochodzenie i treść przepisu zostają.
        $this->assertStringContainsString('uwaga autora do składnika', $html);
        $this->assertStringContainsString('Z przepisu prababci', $html);
        $this->assertStringContainsString('Twaróg', $html);
        $this->assertStringContainsString('Wymieszaj', $html);
        $this->assertStringContainsString('Ten wydruk będzie ze zdjęciami i bez notatek z zeszytu', $html);
        $this->assertStringContainsString('>Z notatkami</a>', $html);
    }

    public function test_wybor_notatek_jest_niezalezny_od_zdjec_w_obu_kierunkach_i_w_przycisku_druku(): void
    {
        $html = $this->druk($this->halina, ['bez-notatek' => 1, 'bez-zdjec' => 1])->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('Ten wydruk będzie bez zdjęć i bez notatek z zeszytu', $html);
        // „Ze zdjęciami” zachowuje brak notatek; „Z notatkami” zachowuje brak zdjęć.
        $this->assertMatchesRegularExpression('/href="[^"]*bez-notatek=1[^"]*"[^>]*>Ze zdjęciami<\/a>/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*bez-zdjec=1[^"]*"[^>]*>Ze zdjęciami<\/a>/', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*bez-zdjec=1[^"]*"[^>]*>Z notatkami<\/a>/', $html);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*bez-notatek=1[^"]*"[^>]*>Z notatkami<\/a>/', $html);
        // Przycisk drukowania niesie oba wybory.
        $this->assertMatchesRegularExpression('/href="(?=[^"]*bez-notatek=1)(?=[^"]*bez-zdjec=1)(?=[^"]*druk=1)[^"]*"[^>]*>Wydrukuj zeszyt<\/a>/', $html);

        // Przy zdjęciach przełączenie notatek zachowuje zdjęcia.
        $domyslny = $this->druk($this->halina)->getContent();
        $this->assertIsString($domyslny);
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*bez-zdjec=1[^"]*"[^>]*>Bez notatek<\/a>/', $domyslny);
    }

    public function test_wybor_nie_zmienia_bazy_dat_ani_powiadomien(): void
    {
        $przed = DB::table('collection_items')->get()->toArray();
        $powiadomien = DB::table('notifications')->count();

        $this->druk($this->halina, ['bez-notatek' => 1])->assertOk();

        $this->assertEquals($przed, DB::table('collection_items')->get()->toArray());
        $this->assertSame($powiadomien, DB::table('notifications')->count());
    }

    public function test_parametr_nie_przyznaje_prawa_do_notatek_obcym_i_po_cofnieciu_dostepu(): void
    {
        $this->zeszyt->forceFill(['visibility' => 'public'])->save();
        $obca = $this->user('obca');

        foreach ([$obca, null] as $widz) {
            foreach ([[], ['bez-notatek' => 0], ['bez-notatek' => 1]] as $parametry) {
                $html = $this->druk($widz, $parametry)->assertOk()->getContent();
                $this->assertIsString($html);
                $this->assertStringNotContainsString(self::NOTATKA, $html);
                $this->assertStringNotContainsString('z notatkami z Twojego zeszytu', $html);
                $this->assertStringNotContainsString('>Bez notatek</a>', $html, 'obcy nie dostaje przełącznika notatek');
            }
        }

        // Współpracownik z dostępem dostaje wybór; po cofnięciu dostępu — 403.
        $this->zeszyt->forceFill(['visibility' => 'private'])->save();
        $wspolpracownik = $this->user('jurek');
        DB::table('collection_members')->insert(['collection_id' => $this->zeszyt->getKey(), 'user_id' => $wspolpracownik->getKey(), 'created_at' => now()]);
        $this->assertStringContainsString(self::NOTATKA, (string) $this->druk($wspolpracownik)->assertOk()->getContent());
        $this->assertStringNotContainsString(self::NOTATKA, (string) $this->druk($wspolpracownik, ['bez-notatek' => 1])->assertOk()->getContent());

        DB::table('collection_members')->where('user_id', $wspolpracownik->getKey())->delete();
        $this->druk($wspolpracownik, ['bez-notatek' => 1])->assertForbidden();
    }

    public function test_dziwna_wartosc_parametru_nie_ukrywa_notatek_przypadkiem_ani_nie_daje_500(): void
    {
        foreach (['bez-notatek[]=1', 'bez-notatek=0', 'bez-notatek=nie'] as $zapytanie) {
            $html = $this->actingAs($this->halina)
                ->get(route('collections.print', ['collection' => $this->zeszyt]).'?'.$zapytanie)
                ->assertOk()->getContent();
            $this->assertIsString($html);
            $this->assertStringContainsString(self::NOTATKA, $html, $zapytanie);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migracja `2026_10_01_140300_add_rdzenie_to_recipe_ingredients`: kolumna
 * `rdzenie` wypełniana wyzwalaczem, backfill istniejących wierszy, niezmiennik
 * `NOT NULL` i indeks GIN. `down()` jest bezstratny (dane pochodne) i nie
 * odmawia — test pilnuje, że cofnięcie i ponowne wejście wracają do tego samego.
 *
 * @bez-kontroli-dodatniej Test wykonuje up() i down() migracji na prawdziwej bazie i porównuje stan katalogu oraz danych, nie asertuje na treści źródła.
 */
final class MigracjaRdzenieSkladnikaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_01_140300_add_rdzenie_to_recipe_ingredients.php';

    public function test_nowa_i_zmieniona_linijka_ma_rdzenie_bez_udzialu_aplikacji(): void
    {
        $linijka = $this->linijka('Mąka pszenna typ 500');

        $this->assertSame($this->zFunkcji('Mąka pszenna typ 500'), $this->rdzenie($linijka));

        DB::table('recipe_ingredients')->where('id', $linijka)->update(['ingredient_text' => 'Masło extra']);
        $this->assertSame($this->zFunkcji('Masło extra'), $this->rdzenie($linijka));
    }

    public function test_rdzenie_nie_moga_byc_puste(): void
    {
        $linijka = $this->linijka('jajka');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('recipe_ingredients_rdzenie_not_null_check');

        DB::table('recipe_ingredients')->where('id', $linijka)->update(['rdzenie' => null]);
    }

    public function test_cofniecie_zdejmuje_wszystko_a_ponowne_wejscie_uzupelnia_istniejace_wiersze(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn('recipe_ingredients', 'rdzenie'));
        $this->assertNull(DB::selectOne("SELECT 1 AS j FROM pg_indexes WHERE indexname = 'recipe_ingredients_rdzenie_gin_idx'"));
        $this->assertNull(DB::selectOne("SELECT 1 AS j FROM pg_trigger WHERE tgname = 'recipe_ingredients_rdzenie_trg'"));
        $this->assertNull(DB::selectOne("SELECT 1 AS j FROM pg_proc WHERE proname = 'kuking_recipe_ingredients_rdzenie'"));

        // Wiersz sprzed ponownego wejścia (stan „przed wdrożeniem”).
        $linijka = $this->linijka('Ogórki kiszone');

        $this->migracja()->up();

        $this->assertSame($this->zFunkcji('Ogórki kiszone'), $this->rdzenie($linijka));
        $this->assertNotNull(DB::selectOne("SELECT 1 AS j FROM pg_indexes WHERE indexname = 'recipe_ingredients_rdzenie_gin_idx'"));
        $this->assertTrue((bool) DB::scalar("SELECT convalidated FROM pg_constraint WHERE conname = 'recipe_ingredients_rdzenie_not_null_check'"));
    }

    public function test_up_jest_powtarzalne(): void
    {
        $linijka = $this->linijka('śmietana 18%');

        $this->migracja()->up();
        $this->migracja()->up();

        $this->assertSame($this->zFunkcji('śmietana 18%'), $this->rdzenie($linijka));
    }

    private function linijka(string $tekst): string
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);

        return (string) $przepis->ingredients()->create(['position' => 0, 'ingredient_text' => $tekst])->getKey();
    }

    private function rdzenie(string $id): string
    {
        return (string) DB::scalar('SELECT rdzenie FROM recipe_ingredients WHERE id = ?', [$id]);
    }

    private function zFunkcji(string $tekst): string
    {
        return (string) DB::scalar('SELECT public.kuking_rdzenie_skladnika(?)', [$tekst]);
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}

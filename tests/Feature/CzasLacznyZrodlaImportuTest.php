<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\Url\ParserJsonLdPrzepisu;
use App\Domain\Import\Url\ParserMikrodanychPrzepisu;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Czas łączny podany przez źródło (`totalTime`) — #2572, decyzja właściciela
 * z 2.10.2026. Zachowany OSOBNO od prep/cook, bez zgadywania podziału.
 *
 * @bez-kontroli-dodatniej Test mierzy zachowanie parserów, bazy i widoku, nie tekst źródeł.
 */
final class CzasLacznyZrodlaImportuTest extends TestCase
{
    use RefreshDatabase;

    private function jsonLd(string $czasy): string
    {
        $json = '{"@context":"https://schema.org","@type":"Recipe","name":"Danie testowe",'.$czasy
            .'"recipeIngredient":["200 g mąki"],"recipeInstructions":[{"@type":"HowToStep","text":"Przygotuj danie."}]}';

        return '<html><head><script type="application/ld+json">'.$json.'</script></head><body></body></html>';
    }

    public function test_json_ld_z_samym_total_time_zachowuje_90_minut_bez_prep_i_cook(): void
    {
        $p = (new ParserJsonLdPrzepisu)->odczytaj($this->jsonLd('"totalTime":"PT1H30M",'));

        $this->assertNotNull($p);
        $this->assertSame(90, $p->lacznieMinut);
        $this->assertNull($p->przygotowanieMinut);
        $this->assertNull($p->gotowanieMinut);
    }

    public function test_json_ld_z_trzema_czasami_zachowuje_kazdy_bez_sprawdzania_sumy(): void
    {
        $p = (new ParserJsonLdPrzepisu)->odczytaj($this->jsonLd('"prepTime":"PT10M","cookTime":"PT20M","totalTime":"PT2H",'));

        $this->assertSame(10, $p->przygotowanieMinut);
        $this->assertSame(20, $p->gotowanieMinut);
        $this->assertSame(120, $p->lacznieMinut, 'Sprzeczna suma to informacja źródła, nie powód korekty.');
    }

    public function test_json_ld_bez_total_time_i_ze_zlym_formatem_daje_null(): void
    {
        $this->assertNull((new ParserJsonLdPrzepisu)->odczytaj($this->jsonLd(''))->lacznieMinut);
        $this->assertNull((new ParserJsonLdPrzepisu)->odczytaj($this->jsonLd('"totalTime":"półtorej godziny",'))->lacznieMinut);
        $this->assertNull((new ParserJsonLdPrzepisu)->odczytaj($this->jsonLd('"totalTime":90,'))->lacznieMinut);
        $this->assertNull((new ParserJsonLdPrzepisu)->odczytaj($this->jsonLd('"totalTime":"PT0M",'))->lacznieMinut);
    }

    public function test_mikrodane_czytaja_total_time(): void
    {
        $html = fn (string $czas): string => '<html><body><div itemscope itemtype="https://schema.org/Recipe"><h1 itemprop="name">Danie</h1>'
            .$czas.'<span itemprop="recipeIngredient">200 g mąki</span><div itemprop="recipeInstructions"><p>Zrób.</p></div></div></body></html>';

        $p = (new ParserMikrodanychPrzepisu)->odczytaj($html('<meta itemprop="totalTime" content="PT1H30M">'));
        $this->assertSame(90, $p->lacznieMinut);
        $this->assertNull($p->przygotowanieMinut);
        $this->assertNull($p->gotowanieMinut);

        $this->assertNull((new ParserMikrodanychPrzepisu)->odczytaj($html(''))->lacznieMinut);
        $this->assertNull((new ParserMikrodanychPrzepisu)->odczytaj($html('<meta itemprop="totalTime" content="dużo">'))->lacznieMinut);
    }

    public function test_szkic_zachowuje_total_time_osobno_od_prep_i_cook(): void
    {
        $autor = User::factory()->create();

        $recipe = app(ZapiszSzkicZImportu::class)->handle(
            $autor,
            PrzepisZImportu::ZRODLO_URL,
            'json_ld',
            new OdczytanyPrzepis(tytul: 'Danie', skladniki: ['200 g mąki'], kroki: ['Zrób.'], lacznieMinut: 90),
            'https://blog.example.pl/danie',
        );

        $wiersz = DB::table('recipes')->where('id', $recipe->getKey())->first();
        $this->assertSame(90, $wiersz->czas_laczny_zrodla_minut);
        $this->assertNull($wiersz->prep_minutes, 'Total nie jest przygotowaniem.');
        $this->assertNull($wiersz->cook_minutes, 'Total nie jest gotowaniem.');
        $this->assertNull($recipe->fresh()->totalMinutes(), 'Total źródła nie jest czasem Kuking.');
    }

    public function test_edycja_bez_klucza_nie_czysci_czasu_zrodla(): void
    {
        $autor = User::factory()->create();
        $recipe = Recipe::factory()->draft()->create(['author_id' => $autor->getKey(), 'prep_minutes' => null, 'cook_minutes' => null, 'czas_laczny_zrodla_minut' => 90]);

        app(PublishRecipe::class)->handle(
            $autor,
            ['title' => 'Nowy tytuł', 'visibility' => 'private'],
            [['text' => '200 g mąki']],
            [['instruction' => 'Zrób.']],
            publish: false,
            existing: $recipe,
        );

        $this->assertSame(90, $recipe->fresh()->czas_laczny_zrodla_minut);
    }

    public function test_strona_pokazuje_zdanie_gdy_brak_prep_i_cook_autora(): void
    {
        $przepis = Recipe::factory()->create(['prep_minutes' => null, 'cook_minutes' => null, 'czas_laczny_zrodla_minut' => 90]);

        $this->get(route('recipes.show', $przepis->slug))->assertOk()
            ->assertSee('Źródło podaje łącznie: około 1 godz. 30 min.', false);
    }

    public function test_strona_nie_mnozy_czasow_gdy_autor_podal_prep_i_cook(): void
    {
        $przepis = Recipe::factory()->create(['prep_minutes' => 10, 'cook_minutes' => 20, 'czas_laczny_zrodla_minut' => 90]);

        $this->get(route('recipes.show', $przepis->slug))->assertOk()
            ->assertSee('Około 30 min')
            ->assertDontSee('Źródło podaje łącznie');
    }

    public function test_strona_bez_czasu_zrodla_nie_ma_zdania(): void
    {
        $przepis = Recipe::factory()->create(['prep_minutes' => null, 'cook_minutes' => null]);

        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertDontSee('Źródło podaje łącznie');
    }

    public function test_json_ld_nie_publikuje_total_time_ze_zrodla(): void
    {
        $przepis = Recipe::factory()->create(['prep_minutes' => null, 'cook_minutes' => null, 'czas_laczny_zrodla_minut' => 90]);

        $this->get(route('recipes.show', $przepis->slug))->assertOk()->assertDontSee('"totalTime"', false);
    }

    public function test_eksport_niesie_czas_laczny_zrodla(): void
    {
        $autor = User::factory()->create();
        Recipe::factory()->create(['author_id' => $autor->getKey(), 'czas_laczny_zrodla_minut' => 90]);

        $paczka = app(CollectUserExportData::class)->handle($autor->fresh(), new ExportPhotoPlan($autor), Carbon::parse('2026-10-02 12:00:00', 'UTC'));

        $this->assertSame(90, $paczka['przepisy'][0]['czas_laczny_zrodla_minuty'] ?? null);
    }

    public function test_check_odrzuca_zero_i_wartosc_ponad_siedem_dni(): void
    {
        $przepis = Recipe::factory()->create();

        foreach ([0, -5, 10081] as $zla) {
            try {
                DB::transaction(fn () => DB::table('recipes')->where('id', $przepis->getKey())->update(['czas_laczny_zrodla_minut' => $zla]));
                $this->fail("CHECK przepuścił {$zla}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('recipes_czas_laczny_zrodla_check', $e->getMessage());
            }
        }

        DB::table('recipes')->where('id', $przepis->getKey())->update(['czas_laczny_zrodla_minut' => 10080]);
        $this->assertSame(10080, (int) DB::table('recipes')->where('id', $przepis->getKey())->value('czas_laczny_zrodla_minut'));
    }

    private function migracja(): object
    {
        return require database_path('migrations/2026_10_02_190000_add_czas_laczny_zrodla_to_recipes.php');
    }

    public function test_rollback_odmawia_przy_zapisanej_wartosci_i_dziala_bez_niej(): void
    {
        $przepis = Recipe::factory()->create(['czas_laczny_zrodla_minut' => 90]);

        try {
            $this->migracja()->down();
            $this->fail('Rollback powinien odmówić przy zapisanej wartości.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('czas łączny', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('recipes', 'czas_laczny_zrodla_minut'));

        DB::table('recipes')->where('id', $przepis->getKey())->update(['czas_laczny_zrodla_minut' => null]);
        $migracja = $this->migracja();
        $migracja->down();
        $this->assertFalse(Schema::hasColumn('recipes', 'czas_laczny_zrodla_minut'));

        $migracja->up();
        $this->assertTrue(Schema::hasColumn('recipes', 'czas_laczny_zrodla_minut'));
    }
}

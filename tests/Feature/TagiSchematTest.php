<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\FiltrWulgaryzmow;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagPromotion;
use App\Support\LimityTagow;
use Database\Seeders\TagSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schemat tagów — etap 1/5 D-021 (`docs/DECISIONS.md`).
 *
 * Ten plik sprawdza WYŁĄCZNIE fundament: model, normalizację, CHECK-i
 * w bazie i seeder. Tagi w publikacji wpisu, strona tagu, obserwowanie
 * i promocja mają własne testy w kolejnych etapach.
 */
class TagiSchematTest extends TestCase
{
    use RefreshDatabase;

    public function test_nowy_tag_od_razu_zna_swoj_identyfikator(): void
    {
        // Ten sam błąd co dawniej przy `Topic` (usunięty już wraz z całym
        // Tematem, D-021 etap 4/5 — patrz historia `TematyWpisowTest.php`): bez
        // `HasUuids` `getKey()` po `create()` oddaje `null`, mimo że baza
        // sama nadała wierszowi identyfikator przez `DEFAULT gen_random_uuid()`.
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        $this->assertNotNull($tag->getKey(), 'Tag po zapisie nie zna własnego id.');
        $this->assertSame($tag->getKey(), $tag->fresh()->getKey());
    }

    // -----------------------------------------------------------------
    // NAJWAŻNIEJSZY TEST W TYM PLIKU: zurek i żurek to DWA różne tagi
    // -----------------------------------------------------------------

    public function test_wielkosc_liter_i_biale_znaki_nie_tworza_nowego_tagu(): void
    {
        $this->assertSame('sernik', Tag::znormalizujNazwe('Sernik'));
        $this->assertSame('sernik', Tag::znormalizujNazwe('  SERNIK  '));
        $this->assertSame('sernik', Tag::znormalizujNazwe('sernik'));
        $this->assertSame('zupa pomidorowa', Tag::znormalizujNazwe('  Zupa   pomidorowa '));
    }

    public function test_zurek_i_zurek_z_polskimi_znakami_to_dwa_rozne_tagi(): void
    {
        // To jest poprawka techniczna z D-021: normalizacja do UNIKALNOŚCI
        // nie może używać `unaccent`. `kuking_normalize()` (search/podpowiedzi)
        // usunęłaby różnicę między tymi dwoma ciągami — `Tag::znormalizujNazwe()`
        // MUSI jej zachować.
        $tanioZnormalizowane = Tag::znormalizujNazwe('zurek');
        $zPolskimiZnakami = Tag::znormalizujNazwe('żurek');

        $this->assertNotSame($tanioZnormalizowane, $zPolskimiZnakami);
        $this->assertSame('zurek', $tanioZnormalizowane);
        $this->assertSame('żurek', $zPolskimiZnakami);

        // I to samo na poziomie bazy: oba MUSZĄ dać się zapisać jako dwa
        // osobne wiersze, bez konfliktu na UNIQUE(normalized_name).
        $bezPolskich = Tag::create(['name' => 'Zurek', 'normalized_name' => $tanioZnormalizowane, 'slug' => 'zurek']);
        $zPolskimi = Tag::create(['name' => 'Żurek', 'normalized_name' => $zPolskimiZnakami, 'slug' => 'zurek-2']);

        $this->assertNotSame($bezPolskich->getKey(), $zPolskimi->getKey());
        $this->assertSame(2, Tag::whereIn('id', [$bezPolskich->getKey(), $zPolskimi->getKey()])->count());
    }

    public function test_dwa_tagi_o_tej_samej_znormalizowanej_nazwie_sa_odrzucane_przez_baze(): void
    {
        Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        $this->expectException(QueryException::class);

        Tag::create(['name' => 'SERNIK', 'normalized_name' => 'sernik', 'slug' => 'sernik-2']);
    }

    // -----------------------------------------------------------------
    // CHECK-i w bazie — walidacja w PHP jest dodatkiem, nie zamiennikiem
    // (AGENTS.md §6)
    // -----------------------------------------------------------------

    public function test_niepoprawny_status_jest_odrzucany_przez_check_w_bazie(): void
    {
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        $this->expectException(QueryException::class);

        $tag->forceFill(['status' => 'usuniety'])->save();
    }

    public function test_status_merged_bez_celu_scalenia_jest_odrzucany_przez_check_w_bazie(): void
    {
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        // `status = 'merged'` bez `merged_into_tag_id` narusza
        // `tags_merged_consistency_check` — jedno bez drugiego to niespójny
        // wiersz, którego żaden kod aplikacji nie powinien umieć wytworzyć.
        $this->expectException(QueryException::class);

        $tag->forceFill(['status' => Tag::STATUS_MERGED])->save();
    }

    public function test_kanonicznego_tagu_nie_da_sie_skasowac_gdy_sa_do_niego_scalone_tagi(): void
    {
        $kanoniczny = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        $scalony = Tag::create(['name' => 'Serniki', 'normalized_name' => 'serniki', 'slug' => 'serniki']);
        $scalony->forceFill(['status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $kanoniczny->getKey()])->save();

        // Domyślne zachowanie klucza obcego w PostgreSQL bez `ON DELETE`
        // to `NO ACTION` — baza SAMA blokuje skasowanie tagu kanonicznego,
        // dopóki są do niego przypięte tagi scalone (R1 §3).
        $this->expectException(QueryException::class);

        $kanoniczny->delete();
    }

    public function test_niepoprawny_slug_jest_odrzucany_przez_check_w_bazie(): void
    {
        $this->expectException(QueryException::class);

        Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'Sernik z Wielkiej Litery!']);
    }

    // -----------------------------------------------------------------
    // Aliasy
    // -----------------------------------------------------------------

    public function test_alias_prowadzi_do_tagu_kanonicznego(): void
    {
        $sernik = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        TagAlias::create([
            'tag_id' => $sernik->getKey(),
            'alias' => 'serniki',
            'normalized_alias' => 'serniki',
            'source' => TagAlias::SOURCE_SEED,
        ]);

        $this->assertSame(1, $sernik->aliases()->count());
        $this->assertSame($sernik->getKey(), TagAlias::where('normalized_alias', 'serniki')->first()?->tag_id);
    }

    public function test_ten_sam_alias_nie_moze_wskazywac_dwoch_roznych_tagow(): void
    {
        $sernik = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        $szarlotka = Tag::create(['name' => 'Szarlotka', 'normalized_name' => 'szarlotka', 'slug' => 'szarlotka']);

        TagAlias::create([
            'tag_id' => $sernik->getKey(), 'alias' => 'ciacho', 'normalized_alias' => 'ciacho',
            'source' => TagAlias::SOURCE_SEED,
        ]);

        $this->expectException(QueryException::class);

        TagAlias::create([
            'tag_id' => $szarlotka->getKey(), 'alias' => 'ciacho', 'normalized_alias' => 'ciacho',
            'source' => TagAlias::SOURCE_SEED,
        ]);
    }

    // -----------------------------------------------------------------
    // Promocja (D-021, „tag promowany — lista gospodarza")
    // -----------------------------------------------------------------

    public function test_tag_bez_promocji_nie_jest_na_liscie_promowanych(): void
    {
        Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        $this->assertSame(0, Tag::promowane()->count());
    }

    public function test_promowane_tagi_wychodza_w_kolejnosci_pozycji(): void
    {
        $b = Tag::create(['name' => 'Ciasta', 'normalized_name' => 'ciasta', 'slug' => 'ciasta']);
        $a = Tag::create(['name' => 'Zupy', 'normalized_name' => 'zupy', 'slug' => 'zupy']);

        TagPromotion::create(['tag_id' => $b->getKey(), 'position' => 5]);
        TagPromotion::create(['tag_id' => $a->getKey(), 'position' => 1]);

        $this->assertSame(['Zupy', 'Ciasta'], Tag::promowane()->pluck('name')->all());
    }

    public function test_promocja_znika_razem_z_tagiem(): void
    {
        $tag = Tag::create(['name' => 'Zupy', 'normalized_name' => 'zupy', 'slug' => 'zupy']);
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => 1]);

        $tag->delete();

        $this->assertSame(0, TagPromotion::count());
    }

    public function test_promocja_pilnuje_nieujemnej_pozycji(): void
    {
        $tag = Tag::create(['name' => 'Zupy', 'normalized_name' => 'zupy', 'slug' => 'zupy']);

        $this->expectException(QueryException::class);

        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => -1]);
    }

    // -----------------------------------------------------------------
    // post_tags i tag_follows — relacje, nie encje (bez własnego `id`)
    // -----------------------------------------------------------------

    public function test_post_tags_ma_klucz_glowny_na_parze(): void
    {
        $post = Post::factory()->create();
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        DB::table('post_tags')->insert(['post_id' => $post->getKey(), 'tag_id' => $tag->getKey(), 'position' => 0]);

        $this->expectException(QueryException::class);

        // Ten sam tag przypięty do tego samego wpisu drugi raz — baza sama
        // pilnuje, że to się nie uda (dokładnie `post_media`).
        DB::table('post_tags')->insert(['post_id' => $post->getKey(), 'tag_id' => $tag->getKey(), 'position' => 1]);
    }

    public function test_tag_follows_ma_klucz_glowny_na_parze(): void
    {
        $basia = $this->user('basia');
        $tag = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        DB::table('tag_follows')->insert(['user_id' => $basia->getKey(), 'tag_id' => $tag->getKey(), 'created_at' => now()]);

        $this->expectException(QueryException::class);

        DB::table('tag_follows')->insert(['user_id' => $basia->getKey(), 'tag_id' => $tag->getKey(), 'created_at' => now()]);
    }

    // -----------------------------------------------------------------
    // LimityTagow — jedno źródło liczb (AGENTS.md §7)
    // -----------------------------------------------------------------

    public function test_limity_tagow_czytaja_z_configu(): void
    {
        config(['kuking.tags.min_length' => 3, 'kuking.tags.max_length' => 20, 'kuking.tags.max_per_post' => 4]);

        $this->assertSame(3, LimityTagow::minZnakow());
        $this->assertSame(20, LimityTagow::maksZnakow());
        $this->assertSame(4, LimityTagow::maksTagowNaWpis());
    }

    public function test_dlugosc_tagu_jest_liczona_po_normalizacji(): void
    {
        $this->assertTrue(LimityTagow::dlugoscOk('sernik'));
        $this->assertFalse(LimityTagow::dlugoscOk('a'));
        $this->assertFalse(LimityTagow::dlugoscOk(str_repeat('a', 31)));
    }

    public function test_niedozwolone_znaki_w_nazwie_tagu_sa_odrzucane(): void
    {
        $this->assertTrue(LimityTagow::pasujeDoWzorca('zupa pomidorowa'));
        $this->assertTrue(LimityTagow::pasujeDoWzorca('bez glutenu'));
        $this->assertFalse(LimityTagow::pasujeDoWzorca('<script>'));
        $this->assertFalse(LimityTagow::pasujeDoWzorca("sernik\n"));
        $this->assertFalse(LimityTagow::pasujeDoWzorca('sernik@'));
    }

    // -----------------------------------------------------------------
    // Filtr wulgaryzmów — dopasowanie po CAŁYCH tokenach (efekt Scunthorpe)
    // -----------------------------------------------------------------

    public function test_filtr_wulgaryzmow_lapie_cale_slowo(): void
    {
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('kurwa'));
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('sernik kurwa'));
    }

    public function test_filtr_wulgaryzmow_nie_lapie_slowa_ktore_tylko_zawiera_zakazany_ciag(): void
    {
        // Efekt Scunthorpe: zakazany rdzeń jako FRAGMENT innego, niewinnego
        // słowa nie ma być blokowany — dopasowanie jest po całych tokenach,
        // nigdy przez `str_contains()`.
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('kurwiatko'));
    }

    // -----------------------------------------------------------------
    // Seeder
    // -----------------------------------------------------------------

    public function test_seeder_tworzy_duza_baze_tagow_i_jest_idempotentny(): void
    {
        $this->seed(TagSeeder::class);

        // Nie sztywne 1200 z SPEC — baza rośnie z czasem (patrz raport końcowy
        // implementacji) — ale ma być NA STARCIE realnie duża, nie garstka.
        $this->assertGreaterThan(300, Tag::count());
        $this->assertGreaterThan(100, TagAlias::count());

        // Wszystkie z seeda są oznaczone jako `is_seeded`.
        $this->assertSame(Tag::count(), Tag::where('is_seeded', true)->count());

        $ileTagow = Tag::count();
        $ileAliasow = TagAlias::count();

        $this->seed(TagSeeder::class);

        $this->assertSame($ileTagow, Tag::count(), 'Ponowne uruchomienie seedera zdublowało tagi.');
        $this->assertSame($ileAliasow, TagAlias::count(), 'Ponowne uruchomienie seedera zdublowało aliasy.');
    }

    public function test_seeder_dodaje_zurek_z_aliasem_zurek(): void
    {
        $this->seed(TagSeeder::class);

        $zurek = Tag::where('normalized_name', 'żurek')->first();
        $this->assertNotNull($zurek, 'Seeder nie utworzył kanonicznego tagu „żurek”.');

        $alias = TagAlias::where('normalized_alias', 'zurek')->first();
        $this->assertNotNull($alias, 'Seeder nie utworzył automatycznego aliasu „zurek”.');
        $this->assertSame($zurek->getKey(), $alias->tag_id);

        // I odwrotnie: nie ma osobnego kanonicznego tagu "zurek" bez polskich
        // znaków — to byłoby dokładnie to, czego D-021 zakazuje.
        $this->assertNull(Tag::where('normalized_name', 'zurek')->first());
    }

    public function test_seeder_nie_wprowadza_zadnego_wulgaryzmu(): void
    {
        $this->seed(TagSeeder::class);

        foreach (Tag::pluck('normalized_name') as $nazwa) {
            $this->assertFalse(
                FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($nazwa),
                "Seeder wprowadził niedozwolony tag: {$nazwa}",
            );
        }
    }
}

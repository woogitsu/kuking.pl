<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\Actions\MergeTags;
use App\Domain\Tags\Actions\ResolveTagsForPost;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TagAlias;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `App\Domain\Tags\Actions\MergeTags` — scalenie dwóch tagów (SPEC §1.8).
 *
 * CZEGO TU NIE BYŁO DO DZIŚ
 * Kolumny `tags.status = 'merged'` i `tags.merged_into_tag_id` istnieją od
 * migracji `create_tags_tables`, a mechanizm ICH CZYTANIA jest kompletny:
 * `TagController::show` przekierowuje ze scalonego tagu, `TagSuggester`
 * wyklucza go z podpowiedzi, `ResolveTagsForPost` rozwiązuje wpisaną nazwę
 * do tagu kanonicznego. Nie było natomiast ŻADNEJ drogi zapisu — komentarz
 * modelu `Tag` odsyłał do `MergeTags`, komentarz migracji przy indeksie
 * `tag_aliases.tag_id` też („potrzebny m.in. przez `MergeTags` przy
 * przepinaniu aliasów"), a klasy nie było. Jedyne, co ustawiało te kolumny,
 * to `forceFill` w testach.
 *
 * To jest ten sam kształt błędu, który zadanie #21 opisuje dla minutnika
 * kroku: kompletna funkcja za kolumną, której nikt nie umie zapisać. Tam
 * decyzja należy do właściciela, bo dotyczy funkcji produktowej; tutaj nie —
 * scalanie jest wprost w SPEC §1.8 i jest potrzebne `TagSeeder`owi do
 * rozstrzygnięcia kolizji ze starą bazą redakcyjną (D-026).
 *
 * CO MUSI ZOSTAĆ PRAWDĄ PO SCALENIU
 * Człowiek nie może stracić niczego, co zrobił: wpis oznaczony starym tagiem
 * zostaje oznaczony (nowym), obserwowanie starego tagu zostaje obserwowaniem
 * nowego, a stary adres nadal działa. Scalenie jest porządkowaniem
 * taksonomii, nie usuwaniem cudzej pracy.
 */
class ScalanieTagowTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $nazwa): Tag
    {
        return Tag::create([
            'name' => $nazwa,
            'normalized_name' => Tag::znormalizujNazwe($nazwa),
            'slug' => Tag::slugDlaNazwy($nazwa),
            'is_seeded' => true,
        ]);
    }

    private function wpis(User $autor, string $tresc = 'Obiad'): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
    }

    private function scal(Tag $zrodlo, Tag $cel): Tag
    {
        return app(MergeTags::class)->handle($zrodlo, $cel);
    }

    /** KONTROLA. Sam stan po scaleniu — bez tego nie wiadomo, czy cokolwiek się stało. */
    public function test_zrodlo_zostaje_w_bazie_ze_statusem_scalony(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        $this->scal($zrodlo, $cel);

        $zrodlo->refresh();

        // SPEC §1.8: „nie kasować źródłowego tagu twardo".
        $this->assertTrue(Tag::query()->whereKey($zrodlo->getKey())->exists(), 'Scalenie skasowało tag źródłowy.');
        $this->assertSame(Tag::STATUS_MERGED, $zrodlo->status);
        $this->assertSame($cel->getKey(), $zrodlo->merged_into_tag_id);
        $this->assertSame($cel->getKey(), $zrodlo->tagKanoniczny()->getKey());
    }

    /** Nazwa źródła staje się aliasem celu — kto zna starą nazwę, trafia na nowy tag. */
    public function test_nazwa_zrodla_staje_sie_aliasem_celu(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        $this->scal($zrodlo, $cel);

        $this->assertSame(
            $cel->getKey(),
            TagAlias::query()->where('normalized_alias', 'marchewka')->value('tag_id'),
        );

        $this->assertSame('marchew', app(ResolveTagsForPost::class)->handle(['marchewka'])[0]->normalized_name);
    }

    /** WŁAŚCIWY POMIAR. Wpis oznaczony starym tagiem zostaje oznaczony nowym. */
    public function test_wpisy_przechodza_na_tag_kanoniczny(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        $wpis = $this->wpis($this->user('basia'));
        $wpis->tags()->attach($zrodlo->getKey(), ['position' => 0]);

        $this->scal($zrodlo, $cel);

        $this->assertSame(
            [$cel->getKey()],
            DB::table('post_tags')->where('post_id', $wpis->getKey())->pluck('tag_id')->all(),
            'Wpis stracił tag albo został przypisany do złego.',
        );

        // Strona tagu kanonicznego pokazuje ten wpis — bo to jest jedyny
        // powód, dla którego przepinanie ma sens.
        $this->get('/tag/'.$cel->slug)->assertOk()->assertSee('Obiad');
    }

    /**
     * Wpis, który miał OBA tagi, nie może dostać tego samego `tag_id` dwa razy
     * (`PRIMARY KEY(post_id, tag_id)`). Zostaje z jednym — i to jest poprawne:
     * dwa razy „to samo" nie jest dwoma tagami.
     */
    public function test_wpis_z_obydwoma_tagami_konczy_z_jednym(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        $wpis = $this->wpis($this->user('basia'));
        $wpis->tags()->attach($cel->getKey(), ['position' => 0]);
        $wpis->tags()->attach($zrodlo->getKey(), ['position' => 1]);

        $this->scal($zrodlo, $cel);

        $this->assertSame(
            [$cel->getKey()],
            DB::table('post_tags')->where('post_id', $wpis->getKey())->pluck('tag_id')->all(),
        );
    }

    /** Obserwowanie starego tagu zostaje obserwowaniem nowego — bez duplikatu. */
    public function test_obserwujacy_przechodza_na_tag_kanoniczny(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        $tylkoStary = $this->user('ala');
        $oba = $this->user('bela');

        $tylkoStary->followedTags()->attach($zrodlo->getKey(), ['created_at' => now()]);
        $oba->followedTags()->attach($zrodlo->getKey(), ['created_at' => now()]);
        $oba->followedTags()->attach($cel->getKey(), ['created_at' => now()]);

        $this->scal($zrodlo, $cel);

        $this->assertSame(0, DB::table('tag_follows')->where('tag_id', $zrodlo->getKey())->count());
        $this->assertSame(2, DB::table('tag_follows')->where('tag_id', $cel->getKey())->count());
        $this->assertTrue($tylkoStary->refresh()->isFollowingTag($cel));
        $this->assertTrue($oba->refresh()->isFollowingTag($cel));
    }

    /** Aliasy źródła prowadzą teraz do celu — inaczej wskazywałyby tag scalony. */
    public function test_aliasy_zrodla_prowadza_do_celu(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        TagAlias::create([
            'tag_id' => $zrodlo->getKey(),
            'alias' => 'marchewki',
            'normalized_alias' => 'marchewki',
            'source' => TagAlias::SOURCE_SEED,
        ]);

        $this->scal($zrodlo, $cel);

        $this->assertSame($cel->getKey(), TagAlias::query()->where('normalized_alias', 'marchewki')->value('tag_id'));
        $this->assertSame(0, TagAlias::query()->where('tag_id', $zrodlo->getKey())->count());
    }

    /**
     * Alias źródła, który jest DOKŁADNIE nazwą celu, znika — alias
     * prowadzący do tagu o tej samej nazwie nie ma sensu, a zostawiony
     * blokowałby dopisanie nazwy źródła (`UNIQUE(normalized_alias)`).
     */
    public function test_alias_rowny_nazwie_celu_znika(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        TagAlias::create([
            'tag_id' => $zrodlo->getKey(),
            'alias' => 'marchew',
            'normalized_alias' => 'marchew',
            'source' => TagAlias::SOURCE_SEED,
        ]);

        $this->scal($zrodlo, $cel);

        $this->assertFalse(TagAlias::query()->where('normalized_alias', 'marchew')->exists());
        $this->assertTrue(TagAlias::query()->where('normalized_alias', 'marchewka')->exists());
    }

    /**
     * Promocja („tag promowany — lista gospodarza") przechodzi na cel, gdy
     * promowane było tylko źródło. Inaczej scalenie po cichu zdejmowałoby
     * pozycję z listy, której nikt nie ruszał.
     */
    public function test_promocja_przechodzi_na_tag_kanoniczny(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        TagPromotion::create(['tag_id' => $zrodlo->getKey(), 'position' => 3, 'note' => 'Marchewka na wiosnę.']);

        $this->scal($zrodlo, $cel);

        $promocja = TagPromotion::query()->where('tag_id', $cel->getKey())->first();

        $this->assertNotNull($promocja, 'Scalenie zdjęło tag z listy promowanych.');
        $this->assertSame(3, $promocja->position);
        $this->assertSame('Marchewka na wiosnę.', $promocja->note);
        $this->assertFalse(TagPromotion::query()->where('tag_id', $zrodlo->getKey())->exists());

        // Lista gospodarza pokazuje TAG KANONICZNY, nie scalony.
        $promowane = Tag::promowane()->pluck('normalized_name')->all();
        $this->assertSame(['marchew'], $promowane);
    }

    /** Gdy promowane były OBA, zostaje pozycja celu — to ona dotyczy tagu, który zostaje. */
    public function test_gdy_promowane_oba_zostaje_pozycja_celu(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        TagPromotion::create(['tag_id' => $zrodlo->getKey(), 'position' => 9]);
        TagPromotion::create(['tag_id' => $cel->getKey(), 'position' => 1]);

        $this->scal($zrodlo, $cel);

        $this->assertSame(1, TagPromotion::query()->where('tag_id', $cel->getKey())->value('position'));
        $this->assertSame(1, TagPromotion::query()->count());
    }

    /** Scalenie w tag, który sam jest scalony, idzie do JEGO kanonicznego — bez łańcucha. */
    public function test_scalenie_w_scalony_tag_idzie_do_kanonicznego(): void
    {
        $kanoniczny = $this->tag('marchew');
        $sredni = $this->tag('marchewka');
        $nowy = $this->tag('marchewki młode');

        $this->scal($sredni, $kanoniczny);
        $wynik = $this->scal($nowy, $sredni->refresh());

        $this->assertSame($kanoniczny->getKey(), $wynik->getKey());
        $this->assertSame($kanoniczny->getKey(), $nowy->refresh()->merged_into_tag_id, 'Powstał łańcuch scaleń zamiast jednego skoku.');
    }

    public function test_nie_da_sie_scalic_tagu_z_samym_soba(): void
    {
        $tag = $this->tag('marchew');

        $this->expectException(RuntimeException::class);

        $this->scal($tag, $tag);
    }

    /**
     * KONTROLA BAZY, nie kodu: usunięcie tagu kanonicznego, pod którym wisi
     * scalony, musi odmówić — self-FK bez `ON DELETE` (komentarz migracji:
     * „to jest zamierzona blokada", reguła egzekwowana w BAZIE).
     */
    public function test_baza_nie_pozwala_usunac_tagu_kanonicznego_ze_scalonymi(): void
    {
        $zrodlo = $this->tag('marchewka');
        $cel = $this->tag('marchew');

        $this->scal($zrodlo, $cel);

        try {
            DB::table('tags')->where('id', $cel->getKey())->delete();
            $this->fail('Baza pozwoliła usunąć tag kanoniczny, pod którym wisi tag scalony.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('foreign key', mb_strtolower($e->getMessage()));
        }
    }
}

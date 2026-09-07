<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\TagAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tagi wpisów — etap 2/5 D-021 (`docs/DECISIONS.md`).
 *
 * Ten plik sprawdza tagi w publikacji i edycji wpisu: limit, tworzenie
 * nowych tagów, ponowne użycie istniejących/aliasów, ratowanie wpisanych
 * tagów przy błędzie walidacji i ścieżkę bez JavaScriptu (Szukaj/Dodaj/Usuń
 * w tym samym formularzu co „Opublikuj").
 */
class TagiWpisowTest extends TestCase
{
    use RefreshDatabase;

    private function opublikuj(array $dane = []): TestResponse
    {
        return $this->post(route('posts.store'), array_merge([
            'body' => 'Rosół jak u babci.',
            'visibility' => 'public',
        ], $dane));
    }

    // -----------------------------------------------------------------
    // Publikacja
    // -----------------------------------------------------------------

    public function test_wpis_da_sie_oznaczyc_tagami(): void
    {
        $this->actingAs($this->user('basia'))
            ->opublikuj(['tag_names' => ['Sernik', 'Zupy']])
            ->assertRedirect();

        $post = Post::with('tags')->firstOrFail();
        $this->assertSame(['Sernik', 'Zupy'], $post->tags->pluck('name')->all());
    }

    public function test_wpis_bez_tagow_dalej_da_sie_opublikowac(): void
    {
        // Cel produktowy to poniżej 60 sekund od wejścia do opublikowania —
        // tag, który zatrzymuje publikację, jest gorszy niż brak tagu.
        $this->actingAs($this->user('basia'))->opublikuj()->assertRedirect();

        $this->assertSame(1, Post::count());
        $this->assertSame(0, Post::firstOrFail()->tags()->count());
    }

    public function test_nowy_tag_powstaje_w_bazie(): void
    {
        $this->actingAs($this->user('basia'))
            ->opublikuj(['tag_names' => ['Placki ziemniaczane']])
            ->assertRedirect();

        $tag = Tag::where('normalized_name', 'placki ziemniaczane')->first();
        $this->assertNotNull($tag, 'Nowy tag nie powstał w bazie.');
        $this->assertTrue($tag->isActive());
        $this->assertFalse($tag->is_seeded);
    }

    public function test_istniejacy_tag_jest_uzywany_ponownie_a_nie_duplikowany(): void
    {
        $sernik = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        $this->actingAs($this->user('basia'))
            ->opublikuj(['tag_names' => ['sernik']]) // inna wielkość liter
            ->assertRedirect();

        $this->assertSame(1, Tag::count());
        $this->assertSame($sernik->getKey(), Post::firstOrFail()->tags()->first()?->getKey());
    }

    public function test_alias_prowadzi_do_tagu_kanonicznego_zamiast_tworzyc_nowy(): void
    {
        $sernik = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        TagAlias::create([
            'tag_id' => $sernik->getKey(), 'alias' => 'serniki', 'normalized_alias' => 'serniki',
            'source' => TagAlias::SOURCE_SEED,
        ]);

        $this->actingAs($this->user('basia'))
            ->opublikuj(['tag_names' => ['serniki']])
            ->assertRedirect();

        $this->assertSame(1, Tag::count(), 'Wpisanie aliasu utworzyło nowy tag zamiast użyć kanonicznego.');
        $this->assertSame($sernik->getKey(), Post::firstOrFail()->tags()->first()?->getKey());
    }

    public function test_duplikat_wpisany_dwa_razy_liczy_sie_jako_jeden_tag(): void
    {
        $this->actingAs($this->user('basia'))
            ->opublikuj(['tag_names' => ['Sernik', 'SERNIK', ' sernik ']])
            ->assertRedirect();

        $this->assertSame(1, Post::firstOrFail()->tags()->count());
    }

    public function test_szesciu_tagow_odrzuca_publikacje_i_nie_gubi_tekstu(): void
    {
        $response = $this->actingAs($this->user('basia'))->opublikuj([
            'tag_names' => ['jeden', 'dwa', 'trzy', 'cztery', 'piec', 'szesc'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('tagi');
        $response->assertSessionHasInput('body', 'Rosół jak u babci.');
        $response->assertSessionHasInput('tag_names', ['jeden', 'dwa', 'trzy', 'cztery', 'piec', 'szesc']);
        $this->assertSame(0, Post::count(), 'Wpis z za dużą liczbą tagów nie powinien się opublikować.');
    }

    public function test_wulgarna_nazwa_nowego_tagu_jest_cicho_pomijana(): void
    {
        // Publikacja MA SIĘ UDAĆ — pomijamy zły tag, nie odmawiamy publikacji
        // z powodu jednej złej nazwy wśród dobrych (ten sam wybór co przy
        // nieznanym `topic_id` w PublishPost).
        $this->actingAs($this->user('basia'))
            ->opublikuj(['tag_names' => ['sernik', 'kurwa']])
            ->assertRedirect();

        $post = Post::with('tags')->firstOrFail();
        $this->assertSame(['sernik'], $post->tags->pluck('name')->all());
        $this->assertNull(Tag::where('normalized_name', 'kurwa')->first());
    }

    public function test_niepoprawna_nazwa_tagu_jest_cicho_pomijana(): void
    {
        $this->actingAs($this->user('basia'))
            ->opublikuj(['tag_names' => ['sernik', 'a', '<script>']])
            ->assertRedirect();

        $this->assertSame(['sernik'], Post::firstOrFail()->tags->pluck('name')->all());
    }

    // -----------------------------------------------------------------
    // Ścieżka bez JavaScriptu: Szukaj / Dodaj / Usuń w tym samym formularzu
    // -----------------------------------------------------------------

    public function test_szukaj_tagu_nie_publikuje_wpisu(): void
    {
        Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);

        $response = $this->actingAs($this->user('basia'))->opublikuj([
            'tag_query' => 'sern',
            'szukaj_tagu' => '1',
        ]);

        $response->assertRedirect();
        $this->assertSame(0, Post::count(), '„Szukaj tagów” nie powinno publikować wpisu.');
        $response->assertSessionHasInput('body', 'Rosół jak u babci.');
    }

    public function test_dodaj_tag_dodaje_do_listy_roboczej_bez_publikacji(): void
    {
        $response = $this->actingAs($this->user('basia'))->opublikuj([
            'tag_names' => ['sernik'],
            'dodaj_tag' => 'zupy',
        ]);

        $response->assertRedirect();
        $this->assertSame(0, Post::count(), '„Dodaj” nie powinno publikować wpisu.');
        $response->assertSessionHasInput('tag_names', ['sernik', 'zupy']);
        // Sam tag NIE MA jeszcze powstać w bazie — to lista robocza, nie
        // publikacja. Powstaje dopiero przy prawdziwym „Opublikuj".
        $this->assertNull(Tag::where('normalized_name', 'zupy')->first());
    }

    public function test_dodaj_ten_sam_tag_drugi_raz_pokazuje_komunikat_i_nie_dubluje(): void
    {
        $response = $this->actingAs($this->user('basia'))->opublikuj([
            'tag_names' => ['sernik'],
            'dodaj_tag' => 'Sernik',
        ]);

        $response->assertSessionHasErrors('tagi');
        $response->assertSessionHasInput('tag_names', ['sernik']);
    }

    public function test_dodaj_szosty_tag_jest_odrzucany_z_komunikatem(): void
    {
        $response = $this->actingAs($this->user('basia'))->opublikuj([
            'tag_names' => ['jeden', 'dwa', 'trzy', 'cztery', 'piec'],
            'dodaj_tag' => 'szesc',
        ]);

        $response->assertSessionHasErrors('tagi');
        $response->assertSessionHasInput('tag_names', ['jeden', 'dwa', 'trzy', 'cztery', 'piec']);
    }

    public function test_usun_tag_zdejmuje_go_z_listy_roboczej(): void
    {
        $response = $this->actingAs($this->user('basia'))->opublikuj([
            'tag_names' => ['sernik', 'zupy'],
            'usun_tag' => 'sernik',
        ]);

        $response->assertRedirect();
        $this->assertSame(0, Post::count());
        $response->assertSessionHasInput('tag_names', ['zupy']);
    }

    // -----------------------------------------------------------------
    // Ratowanie danych przy błędzie walidacji (AGENTS.md §5)
    // -----------------------------------------------------------------

    public function test_bledna_widocznosc_nie_gubi_wpisanych_tagow(): void
    {
        $response = $this->actingAs($this->user('basia'))->opublikuj([
            'visibility' => 'sekretne-dla-nikogo',
            'tag_names' => ['sernik', 'zupy'],
        ]);

        $response->assertSessionHasErrors('visibility');
        $response->assertSessionHasInput('tag_names', ['sernik', 'zupy']);
        $this->assertSame(0, Post::count());
    }

    // -----------------------------------------------------------------
    // Edycja
    // -----------------------------------------------------------------

    public function test_edycja_wpisu_zapisuje_nowe_tagi(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => 'Wersja z tagami.',
            'visibility' => 'public',
            'tag_names' => ['sernik', 'zupy'],
        ])->assertRedirect(route('posts.show', $post));

        $this->assertSame(['sernik', 'zupy'], $post->fresh()->tags->pluck('name')->all());
    }

    public function test_edycja_wpisu_usuwa_tag_ktorego_juz_nie_ma_na_liscie(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $sernik = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        $post->tags()->attach($sernik->getKey(), ['position' => 0]);

        $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => 'Bez sernika.',
            'visibility' => 'public',
            'tag_names' => [],
        ])->assertRedirect();

        $this->assertSame(0, $post->fresh()->tags()->count());
        // Sam tag zostaje w bazie — usunięcie z JEDNEGO wpisu nie kasuje
        // tagu, którego mogą używać inne wpisy.
        $this->assertNotNull($sernik->fresh());
    }

    public function test_ekran_edycji_pokazuje_dzisiejsze_tagi_wpisu(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $sernik = Tag::create(['name' => 'Sernik', 'normalized_name' => 'sernik', 'slug' => 'sernik']);
        $post->tags()->attach($sernik->getKey(), ['position' => 0]);

        $this->actingAs($basia)
            ->get(route('posts.edit', $post))
            ->assertOk()
            ->assertSee('Sernik', false)
            ->assertSee('value="Sernik"', false);
    }

    public function test_edycja_odrzuca_szesciu_tagow(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);

        $response = $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => 'Nietknięty.',
            'visibility' => 'public',
            'tag_names' => ['jeden', 'dwa', 'trzy', 'cztery', 'piec', 'szesc'],
        ]);

        $response->assertSessionHasErrors('tagi');
        $this->assertSame(0, $post->fresh()->tags()->count());
    }
}

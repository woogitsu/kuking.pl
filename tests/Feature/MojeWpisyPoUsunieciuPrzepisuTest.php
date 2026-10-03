<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\MojeWpisy;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MojeWpisyPoUsunieciuPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private function zapowiedz(User $autor, Recipe $przepis, array $atrybuty = []): Post
    {
        return Post::factory()->create(array_replace([
            'author_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => null,
        ], $atrybuty));
    }

    /** @return list<string> */
    private function ids(string $html): array
    {
        preg_match_all('/data-klucz="moj-wpis-([0-9a-f-]+)"/', $html, $trafienia);

        return $trafienia[1];
    }

    public function test_prawdziwe_usuniecie_chowa_martwa_zapowiedz_z_listy_wyszukiwania_i_licznika_a_odzyskanie_oddaje_ja_autorowi(): void
    {
        $autor = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Barszcz rodzinny']);
        $zapowiedz = $this->zapowiedz($autor, $przepis);
        $komentarz = Comment::factory()->create(['post_id' => $zapowiedz->getKey()]);

        $this->actingAs($autor)->get(route('collections.own-posts'))->assertOk();
        $this->delete(route('recipes.destroy', $przepis->slug))->assertRedirect();

        $lista = $this->get(route('collections.own-posts'))->assertOk();
        $this->assertSame([], $this->ids($lista->getContent()), 'MOJE_WPISY_2870_MARTWA_ZAPOWIEDZ_NIE_WRACA');
        $this->assertSame(0, $lista->viewData('wpisy')->total());
        $lista->assertDontSee('Szukaj w moich wpisach')->assertSee('Nie masz jeszcze żadnego wpisu');
        $szukaj = $this->get(route('collections.own-posts', ['szukaj' => 'barszcz']))->assertOk();
        $this->assertSame(0, $szukaj->viewData('wpisy')->total());
        $this->assertDatabaseHas('posts', ['id' => $zapowiedz->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseHas('comments', ['id' => $komentarz->getKey()]);

        $this->post(route('collections.deleted-recipes.recover', $przepis->getKey()))->assertRedirect();
        $odzyskany = Recipe::query()->findOrFail($przepis->getKey());
        $this->assertSame(Recipe::STATUS_DRAFT, $odzyskany->status);
        $this->assertSame('private', $odzyskany->visibility);
        $this->assertSame([$zapowiedz->getKey()], $this->ids($this->get(route('collections.own-posts'))->assertOk()->getContent()));
    }

    public function test_pelna_strona_martwych_zapowiedzi_nie_wypycha_zywego_wpisu_ani_nie_falszuje_total_i_frazy(): void
    {
        $autor = $this->user('autorka');
        $this->actingAs($autor);
        $zywy = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Barszcz na dziś', 'published_at' => now()->subDays(2)]);
        for ($i = 0; $i < MojeWpisy::NA_STRONE; $i++) {
            $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
            $this->zapowiedz($autor, $przepis, ['published_at' => now()->subMinutes($i)]);
            $this->delete(route('recipes.destroy', $przepis->slug))->assertRedirect();
        }

        $pierwsza = $this->get(route('collections.own-posts'))->assertOk();
        $this->assertSame([$zywy->getKey()], $this->ids($pierwsza->getContent()), 'MOJE_WPISY_2870_PAGINACJA_PO_FILTRZE');
        $this->assertSame(1, $pierwsza->viewData('wpisy')->total());
        $this->get(route('collections.own-posts', ['page' => 2]))->assertRedirect(route('collections.own-posts'));
        $fraza = $this->get(route('collections.own-posts', ['szukaj' => 'barszcz']))->assertOk();
        $this->assertSame([$zywy->getKey()], $this->ids($fraza->getContent()));
        $this->assertSame(1, $fraza->viewData('wpisy')->total());
    }

    public function test_autorskie_szkice_ukryte_prywatne_i_wpisy_z_tekstem_lub_niegotowym_zdjeciem_zostaja(): void
    {
        $autor = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $szkic = $this->zapowiedz($autor, $przepis, ['status' => Post::STATUS_DRAFT, 'published_at' => null]);
        $ukryty = $this->zapowiedz($autor, $przepis, ['status' => Post::STATUS_HIDDEN]);
        $zTekstem = $this->zapowiedz($autor, $przepis, ['body' => 'Moje własne wspomnienie']);
        $zeZdjeciem = $this->zapowiedz($autor, $przepis);
        $zeZdjeciem->media()->attach(Media::factory()->pending()->create(['owner_id' => $autor->getKey()])->getKey(), ['position' => 0]);
        $prywatnyPrzepis = Recipe::factory()->draft()->create(['author_id' => $autor->getKey(), 'visibility' => 'private']);
        $prywatna = $this->zapowiedz($autor, $prywatnyPrzepis, ['visibility' => Post::VISIBILITY_PRIVATE]);

        $this->actingAs($autor)->delete(route('recipes.destroy', $przepis->slug))->assertRedirect();
        $lista = $this->get(route('collections.own-posts'))->assertOk();
        $this->assertEqualsCanonicalizing(
            [$szkic->getKey(), $ukryty->getKey(), $zTekstem->getKey(), $zeZdjeciem->getKey(), $prywatna->getKey()],
            $this->ids($lista->getContent()),
            'MOJE_WPISY_2870_WLASNA_TRESC_I_STANY_ZOSTAJA',
        );
        $this->assertSame(5, $lista->viewData('wpisy')->total());
    }
}

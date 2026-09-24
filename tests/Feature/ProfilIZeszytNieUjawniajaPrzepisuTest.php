<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfilIZeszytNieUjawniajaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public static function hiddenCases(): array
    {
        $cases = [];
        foreach (['profile', 'collection'] as $surface) {
            foreach (['private', 'followers', 'hidden', 'removed', 'deleted', 'blocked', 'blocking', 'banned', 'pending_delete'] as $state) {
                $cases[$surface.' '.$state] = [$surface, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('hiddenCases')]
    public function test_niedostepny_przepis_nie_wycieka_przez_karte(string $surface, string $state): void
    {
        [$owner, $author, $viewer, $collection, $recipe, $post, $public] = $this->scene();
        if (in_array($state, ['private', 'followers'], true)) {
            $recipe->forceFill(['visibility' => $state])->save();
        } elseif (in_array($state, ['hidden', 'removed'], true)) {
            $recipe->forceFill(['status' => $state])->save();
        } elseif ($state === 'deleted') {
            $recipe->delete();
        } elseif ($state === 'blocked') {
            $viewer->blocking()->attach($author->id, ['created_at' => now()]);
        } elseif ($state === 'blocking') {
            $author->blocking()->attach($viewer->id, ['created_at' => now()]);
        } else {
            $author->forceFill(['status' => $state])->save();
        }

        $response = $this->actingAs($viewer)->get($surface === 'profile'
            ? route('profile.show', $owner->profile->username)
            : route('collections.show', $collection))->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString($public->title, $html, 'Kontrola dodatnia: publiczna karta musi zostać.');
        $this->assertFalse(str_contains($html, $recipe->title), 'WYCIEK_1036: tytuł niedostępnego przepisu.');
        $this->assertSame(1, substr_count($html, '<article class="card post-card"'));
        $this->assertStringNotContainsString($recipe->slug, $html);
        $this->assertStringNotContainsString($recipe->hero_media_id, $html);
        $this->assertSame(1, $response->viewData('posts')->total());
        if ($surface === 'profile') {
            $this->assertSame(1, $response->viewData('stats')['posts']);
            $this->assertSame([(int) now()->year], $response->viewData('lata')->all());
        }
        $this->assertDatabaseHas('collection_items', ['collection_id' => $collection->id, 'post_id' => $post->id, 'note' => 'Moja notatka zostaje']);
    }

    public function test_autor_i_obserwujacy_zachowuja_dostep_na_obu_ekranach(): void
    {
        [$owner, $author, $viewer, $collection, $recipe] = $this->scene();
        $recipe->forceFill(['visibility' => 'followers'])->save();
        $viewer->following()->attach($author->id, ['created_at' => now()]);
        foreach ([$viewer, $author] as $person) {
            foreach ([route('profile.show', $owner->profile->username), route('collections.show', $collection)] as $url) {
                $response = $this->actingAs($person)->get($url)->assertOk();
                $this->assertSame(2, substr_count($response->getContent(), '<article class="card post-card"'));
                $response->assertSee($recipe->title)->assertSee($recipe->slug);
            }
        }
        $recipe->forceFill(['visibility' => 'private'])->save();
        foreach ([route('profile.show', $owner->profile->username), route('collections.show', $collection)] as $url) {
            $this->actingAs($author)->get($url)->assertOk()->assertSee($recipe->title);
        }
    }

    public function test_gosc_na_profilu_nie_widzi_prywatnej_zapowiedzi(): void
    {
        [$owner, , , , $recipe, , $public] = $this->scene();
        $recipe->forceFill(['visibility' => 'private'])->save();
        $this->get(route('profile.show', $owner->profile->username))->assertOk()
            ->assertSee($public->title)->assertDontSee($recipe->title)->assertDontSee($recipe->slug);
    }

    public function test_zwykly_wpis_i_dorobek_erased_zostaja_dostepne(): void
    {
        [$owner, $author, $viewer, $collection, $recipe] = $this->scene();
        $author->forceFill(['status' => 'erased', 'data_erased_at' => now(), 'delete_scope' => 'minimum'])->save();
        $ordinary = Post::factory()->create(['author_id' => $owner->id, 'body' => 'Obiad bez powiązanego przepisu']);
        $collection->posts()->attach($ordinary->id);
        foreach ([route('profile.show', $owner->profile->username), route('collections.show', $collection)] as $url) {
            $response = $this->actingAs($viewer)->get($url)->assertOk()->assertSee($recipe->title)->assertSee($ordinary->body);
            $this->assertSame(3, substr_count($response->getContent(), '<article class="card post-card"'));
        }
    }

    public function test_filtr_dziala_przed_paginacja(): void
    {
        [$owner, $author, $viewer, $collection, $recipe, , $public] = $this->scene();
        $recipe->forceFill(['visibility' => 'private'])->save();
        for ($i = 0; $i < 15; $i++) {
            $post = Post::factory()->create(['author_id' => $owner->id, 'recipe_id' => $recipe->id, 'body' => null]);
            $collection->posts()->attach($post->id);
        }
        foreach ([route('profile.show', $owner->profile->username), route('collections.show', $collection)] as $url) {
            $response = $this->actingAs($viewer)->get($url)->assertOk()->assertSee($public->title)->assertDontSee($recipe->title);
            $this->assertSame(1, $response->viewData('posts')->total());
            $this->assertSame(1, substr_count($response->getContent(), '<article class="card post-card"'));
        }
    }

    public function test_zapytania_o_przepisy_i_zdjecia_nie_rosna_z_liczba_kart(): void
    {
        [$owner, , $viewer, $collection] = $this->scene();
        $urls = [route('profile.show', $owner->profile->username), route('collections.show', $collection)];
        $this->actingAs($viewer);
        $measure = function (string $url, int $cards): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get($url)->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame($cards, substr_count($response->getContent(), '<article class="card post-card"'));
            foreach ($response->viewData('posts')->items() as $post) {
                $this->assertTrue($post->relationLoaded('recipe'));
                $this->assertTrue($post->recipe->relationLoaded('heroMedia'));
            }

            return count(array_filter($queries, fn ($query) => str_contains($query['query'], 'from "recipes"') || str_contains($query['query'], 'from "media"')));
        };
        $baseline = array_map(fn ($url) => $measure($url, 2), $urls);
        for ($i = 0; $i < 5; $i++) {
            $recipe = Recipe::factory()->create(['author_id' => $owner->id, 'hero_media_id' => Media::factory()->create(['owner_id' => $owner->id])->id]);
            $post = Post::factory()->create(['author_id' => $owner->id, 'recipe_id' => $recipe->id, 'body' => null]);
            $collection->posts()->attach($post->id);
        }
        foreach ($urls as $i => $url) {
            $this->assertSame($baseline[$i], $measure($url, 7), 'Zapytania rosną z liczbą kart.');
        }
    }

    private function scene(): array
    {
        $owner = $this->user('wlasciciel');
        $author = $this->user('autorprzepisu');
        $viewer = $this->user('widz');
        $collection = $owner->collections()->create(['name' => 'Publiczny zeszyt', 'visibility' => 'public']);
        // Cudzy przepis we wpisie jest dozwolony; status autora przepisu
        // nie może schować się za aktywnym kontem autora wpisu.
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Sekretny bigos próbny', 'hero_media_id' => Media::factory()->create(['owner_id' => $author->id])->id]);
        $post = Post::factory()->create(['author_id' => $owner->id, 'recipe_id' => $recipe->id, 'body' => null, 'published_at' => now()->subYears(2)]);
        $public = Recipe::factory()->create(['author_id' => $owner->id, 'title' => 'Publiczne naleśniki próbne', 'hero_media_id' => Media::factory()->create(['owner_id' => $owner->id])->id]);
        $positive = Post::factory()->create(['author_id' => $owner->id, 'recipe_id' => $public->id, 'body' => null]);
        $this->assertSame('public', $post->visibility);
        $collection->posts()->attach($post->id, ['note' => 'Moja notatka zostaje']);
        $collection->posts()->attach($positive->id);

        return [$owner, $author, $viewer, $collection, $recipe, $post, $public];
    }
}

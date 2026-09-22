<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection as Zeszyt;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Widoczność i użyteczne odnośniki w zastępczej szynie zdjęć profilu. */
class ProfilZdjeciaSzynyTest extends TestCase
{
    use RefreshDatabase;

    public function test_trzy_najnowsze_gotowe_zdjecia_sa_niezalezne_od_zakladki_roku_i_strony(): void
    {
        $owner = $this->user('fotokuchnia');
        $viewer = $this->user('fotowidz');
        $posts = [];
        for ($i = 0; $i < 5; $i++) {
            $posts[] = $this->zdjecie($owner, ['published_at' => now()->subDays(10 - $i)]);
        }
        // Nowsze, lecz niedopuszczalne, nie mogą zużyć limitu przed filtrowaniem.
        $this->zdjecie($owner, ['status' => Post::STATUS_HIDDEN]);
        $this->zdjecie($owner, ['status' => Post::STATUS_DRAFT, 'published_at' => null]);
        $this->zdjecie($owner, [], true);
        Post::factory()->for($owner, 'author')->create();
        $expected = [$posts[4], $posts[3], $posts[2]];
        foreach ([[], ['zakladka' => 'przepisy'], ['zakladka' => 'ugotowane'], ['rok' => 2001], ['page' => 2]] as $query) {
            $response = $this->actingAs($viewer)->get(route('profile.show', ['username' => 'fotokuchnia'] + $query))->assertOk();
            $this->sprawdz($response, $expected);
        }
    }

    public function test_gosc_obcy_i_obserwujacy_nie_dostaja_prywatnych_zdjec(): void
    {
        $owner = $this->user('fotokuchnia');
        $viewer = $this->user('fotowidz');
        $public = $this->zdjecie($owner, ['published_at' => now()->subDays(3)]);
        $followers = $this->zdjecie($owner, ['visibility' => 'followers', 'published_at' => now()->subDays(2)]);
        $private = $this->zdjecie($owner, ['visibility' => 'private']);
        $hidden = $this->zdjecie($owner, ['status' => Post::STATUS_HIDDEN]);
        $url = route('profile.show', 'fotokuchnia');
        $this->sprawdz($this->get($url)->assertOk(), [$public]);
        $this->sprawdz($this->actingAs($viewer)->get($url)->assertOk(), [$public]);
        $viewer->following()->attach($owner->getKey());
        $response = $this->actingAs($viewer)->get($url)->assertOk();
        $this->sprawdz($response, [$followers, $public]);
        foreach ([$private, $hidden] as $excluded) {
            $response->assertDontSee($excluded->getKey())->assertDontSee($excluded->media->first()->getKey());
        }
    }

    public function test_tagi_i_publiczny_zeszyt_kazde_osobno_maja_pierwszenstwo(): void
    {
        $owner = $this->user('fotokuchnia');
        $viewer = $this->user('fotowidz');
        $post = $this->zdjecie($owner);
        $tag = Tag::factory()->create();
        $post->tags()->attach($tag->getKey(), ['position' => 0]);
        foreach (['wszystko', 'przepisy', 'ugotowane'] as $tab) {
            $this->sprawdz($this->actingAs($viewer)->get(route('profile.show', ['username' => 'fotokuchnia', 'zakladka' => $tab, 'rok' => 2001]))->assertOk(), []);
        }
        $post->tags()->detach();
        $zeszyt = Zeszyt::create(['owner_id' => $owner->getKey(), 'name' => 'Publiczne zupy', 'visibility' => 'public']);
        foreach (['wszystko', 'przepisy', 'ugotowane'] as $tab) {
            $this->sprawdz($this->actingAs($viewer)->get(route('profile.show', ['username' => 'fotokuchnia', 'zakladka' => $tab]))->assertOk(), []);
        }
        $zeszyt->update(['visibility' => 'private']);
        $this->sprawdz($this->actingAs($viewer)->get(route('profile.show', 'fotokuchnia'))->assertOk(), [$post]);
    }

    public function test_wlasny_i_pusty_profil_nie_maja_fotograficznego_wypelniacza(): void
    {
        $owner = $this->user('fotokuchnia');
        $this->sprawdz($this->get(route('profile.show', 'fotokuchnia'))->assertOk(), []);
        $this->zdjecie($owner);
        $this->sprawdz($this->actingAs($owner)->get(route('profile.show', 'fotokuchnia'))->assertOk(), []);
    }

    public function test_blokada_w_obu_kierunkach_zachowuje_odmowe_i_nie_wypisuje_mediow(): void
    {
        $owner = $this->user('fotokuchnia');
        $viewer = $this->user('fotowidz');
        $post = $this->zdjecie($owner);
        foreach ([[$owner, $viewer], [$viewer, $owner]] as [$blocker, $blocked]) {
            $blocker->blocking()->attach($blocked->getKey());
            $this->actingAs($viewer)->get(route('profile.show', 'fotokuchnia'))->assertForbidden()
                ->assertDontSee($post->getKey())->assertDontSee($post->media->first()->getKey());
            $blocker->blocking()->detach($blocked->getKey());
        }
    }

    /** @param array<string, mixed> $attributes */
    private function zdjecie(User $owner, array $attributes = [], bool $pending = false): Post
    {
        $post = Post::factory()->for($owner, 'author')->create($attributes);
        $factory = Media::factory()->for($owner, 'owner');
        $media = ($pending ? $factory->pending() : $factory)->create();
        $post->media()->attach($media->getKey(), ['position' => 0]);

        return $post->load('media');
    }

    /** @param list<Post> $expected */
    private function sprawdz(TestResponse $response, array $expected): void
    {
        $data = $response->viewData('zdjeciaSzyny');
        $this->assertInstanceOf(Collection::class, $data);
        $this->assertSame(array_map(fn (Post $post) => $post->getKey(), $expected), $data->map(fn (Post $post) => $post->getKey())->all());
        foreach ($data as $post) {
            $this->assertInstanceOf(Post::class, $post);
        }
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);
        $section = '//section[@aria-labelledby="szyna-zdjecia-profilu"]';
        $this->assertSame($expected === [] ? 0 : 1, $xpath->query($section)->length);
        if ($expected === []) {
            return;
        }
        $this->assertSame(1, $xpath->query($section.'//*[@id="szyna-zdjecia-profilu"]')->length);
        $this->assertSame(count($expected), $xpath->query($section.'//img')->length);
        foreach ($expected as $post) {
            $this->assertGreaterThanOrEqual(1, $xpath->query($section.'//a[@href="'.$post->url().'"]')->length);
            $this->assertSame(1, $xpath->query($section.'//img[@src="'.$post->media->first()->url('thumb').'"]')->length);
        }
    }
}

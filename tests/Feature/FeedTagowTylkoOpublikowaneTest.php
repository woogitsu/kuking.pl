<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\FollowingFeed;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feed obserwowanych tagów oddaje tylko OPUBLIKOWANE wpisy (issue #1338).
 *
 * `Post::widoczneDla($widz)` ma świadomą furtkę „autor widzi swoje" — i ta
 * gałąź nie pyta o status ani `published_at`, bo służy archiwum autora.
 * `TagFeed` stał na samym `widoczneDla()`, więc własny wpis ukryty przez
 * moderację (albo szkic z tagiem) wchodził do strumienia na Starcie,
 * a `maTresci()` wybierał przez niego źródło `tagi` zamiast „Świeżo".
 * Strona tagu (`TagController::show()`) i `FollowingFeed` mają `published()`
 * od początku — teraz strumień tagów jest z nimi spójny.
 *
 * Od #1808 (D-277) feedu tagów nie ma jako osobnego źródła — tematy weszły
 * do `FollowingFeed` razem z osobami, a `maTresci()` zastąpiło `isEmptyFor()`.
 * Te same gwarancje sprawdzamy teraz na połączonej liście.
 *
 * Bezpośredni dostęp autora do własnej ukrytej treści (Policy) się nie
 * zmienia; chodzi wyłącznie o dystrybucję w feedzie.
 */
class FeedTagowTylkoOpublikowaneTest extends TestCase
{
    use RefreshDatabase;

    private function tag(): Tag
    {
        return Tag::create(['slug' => 'zupy', 'name' => 'Zupy', 'normalized_name' => 'zupy']);
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function wpis(Tag $tag, User $autor, string $tresc, array $atrybuty = []): Post
    {
        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now(),
            ...$atrybuty,
        ]);
        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return $post;
    }

    /** @return list<string> */
    private function widziane(User $widz): array
    {
        return collect(app(FollowingFeed::class)->paginate($widz)->items())
            ->map(fn (Post $wpis) => (string) $wpis->getKey())
            ->all();
    }

    public function test_wlasny_ukryty_i_szkic_nie_wchodza_do_feedu_tagow(): void
    {
        $zupy = $this->tag();
        $basia = $this->user('basia');
        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $opublikowany = $this->wpis($zupy, $basia, 'Moj opublikowany rosol');
        $cudzy = $this->wpis($zupy, $this->user('ola'), 'Pomidorowa od Oli');
        $ukryty = $this->wpis($zupy, $basia, 'Moj ukryty przez moderacje', ['status' => Post::STATUS_HIDDEN]);
        $szkic = $this->wpis($zupy, $basia, 'Moj szkic', ['status' => Post::STATUS_DRAFT, 'published_at' => null]);

        $widziane = $this->widziane($basia);

        // KONTROLA DODATNIA: własny opublikowany i cudzy publiczny nadal są.
        // Bez tego asercje niżej przechodziłyby na pustym feedzie.
        $this->assertContains((string) $opublikowany->getKey(), $widziane, 'Własny opublikowany wpis z obserwowanym tagiem zniknął z feedu tagów.');
        $this->assertContains((string) $cudzy->getKey(), $widziane, 'Cudzy publiczny wpis z obserwowanym tagiem zniknął z feedu tagów.');

        $this->assertNotContains((string) $ukryty->getKey(), $widziane, 'Własny wpis ukryty przez moderację wszedł do feedu tagów.');
        $this->assertNotContains((string) $szkic->getKey(), $widziane, 'Własny szkic z tagiem wszedł do feedu tagów.');
    }

    public function test_jedyny_ukryty_wlasny_wpis_nie_wybiera_zrodla_tagi(): void
    {
        $zupy = $this->tag();
        $basia = $this->user('basia');
        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $this->wpis($zupy, $basia, 'Moj ukryty przez moderacje', ['status' => Post::STATUS_HIDDEN]);
        // Coś do „Świeżo" — bez tagu, więc do feedu tagów nie wejdzie.
        Post::factory()->create([
            'author_id' => $this->user('ola')->getKey(),
            'body' => 'Swiezy wpis bez tagu',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now(),
        ]);

        $this->assertTrue(app(FollowingFeed::class)->isEmptyFor($basia), 'Ukryty własny wpis sprawił, że Start zameldował treść.');

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertViewHas('zrodloFeedu', 'odkrywanie')
            ->assertSee('Swiezy wpis bez tagu')
            ->assertDontSee('Moj ukryty przez moderacje');
    }

    public function test_cudzy_opublikowany_wpis_z_tematu_wybiera_obserwowanych(): void
    {
        $zupy = $this->tag();
        $basia = $this->user('basia');
        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        // KONTROLA DODATNIA dla wyboru źródła: własny opublikowany wpis stoi
        // na liście, ale sam jej nie „otwiera" (własne wpisy nie liczą się
        // w `isEmptyFor()` — ta sama reguła co dla osób) ...
        $this->wpis($zupy, $basia, 'Moj opublikowany rosol');
        $this->assertTrue(app(FollowingFeed::class)->isEmptyFor($basia));

        // ... a cudzy publiczny wpis z obserwowanego tematu — tak.
        $this->wpis($zupy, $this->user('ola'), 'Pomidorowa od Oli');
        $this->assertFalse(app(FollowingFeed::class)->isEmptyFor($basia));

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertViewHas('zrodloFeedu', 'obserwowani')
            ->assertSee('Moj opublikowany rosol')
            ->assertSee('Pomidorowa od Oli');
    }
}

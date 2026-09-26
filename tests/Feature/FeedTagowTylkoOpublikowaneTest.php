<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\FollowingFeed;
use App\Domain\Social\Actions\FollowUser;
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

    /**
     * Decyzja właściciela z 26 września (#1338): strona tagu pokazuje
     * KAŻDEMU — także autorowi — wyłącznie wpisy publiczne. Własne wpisy
     * „tylko dla obserwujących" i „tylko dla mnie" nie pojawiają się na
     * stronie tagu ani autorowi, ani osobie, która go obserwuje; autor ma
     * je w swoim profilu i w „Moje". Własny publiczny wpis stoi
     * chronologicznie między cudzymi. Ukryty przez moderację i szkic
     * zostają poza listą.
     *
     * Kontrola ujemna (sprawdzona przy pisaniu): w `TagController::show()`
     * bez `tylkoPubliczne()` (sam `published()` + `widoczneDla($widz)`,
     * stan sprzed poprawki) → test oblewa na pierwszej asercji.
     */
    public function test_strona_tagu_pokazuje_kazdemu_tylko_wpisy_publiczne_takze_autorowi(): void
    {
        $zupy = $this->tag();
        $basia = $this->user('basia');
        $ola = $this->user('ola');
        // Ola obserwuje Basię — gdyby lista szła per widz, wpis „tylko dla
        // obserwujących" wypłynąłby Oli.
        app(FollowUser::class)->handle($ola, $basia);

        $cudzyNowszy = $this->wpis($zupy, $ola, 'Pomidorowa od Oli', ['published_at' => now()->subMinutes(1)]);
        $mojPubliczny = $this->wpis($zupy, $basia, 'Moj publiczny rosol', ['published_at' => now()->subMinutes(2)]);
        $this->wpis($zupy, $basia, 'Moja zupa dla obserwujacych', [
            'visibility' => Post::VISIBILITY_FOLLOWERS,
            'published_at' => now()->subMinutes(3),
        ]);
        $this->wpis($zupy, $basia, 'Moja zupa tylko dla mnie', [
            'visibility' => Post::VISIBILITY_PRIVATE,
            'published_at' => now()->subMinutes(4),
        ]);
        $cudzyStarszy = $this->wpis($zupy, $ola, 'Barszcz od Oli', ['published_at' => now()->subMinutes(5)]);
        $this->wpis($zupy, $basia, 'Moj ukryty przez moderacje', ['status' => Post::STATUS_HIDDEN]);
        $this->wpis($zupy, $basia, 'Moj szkic', ['status' => Post::STATUS_DRAFT, 'published_at' => null]);

        $oczekiwane = array_map(fn (Post $p): string => (string) $p->getKey(), [$cudzyNowszy, $mojPubliczny, $cudzyStarszy]);

        $idy = function (?User $widz) use ($zupy): array {
            $zadanie = $widz === null ? $this : $this->actingAs($widz);

            return collect($zadanie->get(route('tags.show', $zupy))->assertOk()->viewData('posts')->items())
                ->map(fn (Post $wpis): string => (string) $wpis->getKey())->all();
        };

        $this->assertSame($oczekiwane, $idy($basia), 'Autor widzi na stronie tagu swój wpis niepubliczny — strona tagu ma pokazywać każdemu tylko wpisy publiczne.');
        $this->assertSame($oczekiwane, $idy($ola), 'Obserwująca osoba widzi na stronie tagu wpis „tylko dla obserwujących”.');

        // Kontrola dodatnia: gość widzi to samo — lista nie zależy od widza.
        auth()->logout();
        $this->assertSame($oczekiwane, $idy(null));
    }
}

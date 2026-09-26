<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\FollowingFeed;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1808 (D-277) — na Starcie obserwowane osoby i obserwowane tagi
 * stoją w jednej liście, chronologicznie, z nazwanym źródłem.
 *
 * Kontrole ujemne (sprawdzone przy pisaniu):
 *  - gałąź tagów bez `where('posts.visibility', public)` → wpis „tylko dla
 *    obserwujących" i prywatny z tagu przeciekają
 *    (`test_prywatne_i_dla_obserwujacych_z_tagu_nie_przeciekaja`);
 *  - gałąź tagów bez `widoczneDla()` → blokada nie odcina
 *    (`test_blokada_w_obie_strony_odcina_wpisy_z_tagu`);
 *  - `podpiszTematy()` bez warunku „autor spoza obserwowanych" → wpis
 *    znajomej dostaje podpis (`test_podpis_tylko_przy_wpisach_z_samego_tagu`).
 */
class StartOsobyITagiRazemTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $slug, string $nazwa): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
    }

    /** @param  array<string, mixed>  $inne */
    private function wpis(User $autor, string $tresc, int $minutTemu, ?Tag $tag = null, array $inne = []): Post
    {
        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'published_at' => now()->subMinutes($minutTemu),
            ...$inne,
        ]);
        $tag?->posts()->attach($post->getKey(), ['position' => 0]);

        return $post;
    }

    private function obserwuj(User $kto, User $kogo): void
    {
        DB::table('follows')->insert(['follower_id' => $kto->id, 'followed_id' => $kogo->id, 'created_at' => now()]);
    }

    /** @return list<string> */
    private function tresci(User $widz): array
    {
        return collect(app(FollowingFeed::class)->paginate($widz)->items())->pluck('body')->all();
    }

    public function test_osoby_i_tagi_razem_po_czasie_bez_duplikatow(): void
    {
        $widz = $this->user('widz');
        $znajoma = $this->user('znajoma');
        $obca = $this->user('obca');
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwuj($widz, $znajoma);
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $this->wpis($znajoma, 'Znajoma bez tagu', 5);
        $this->wpis($obca, 'Obca z tagu', 3, $zupy);
        $this->wpis($znajoma, 'Znajoma z tagiem', 1, $zupy);
        $this->wpis($widz, 'Mój wpis', 4);
        $this->wpis($obca, 'Obca bez tagu', 2);

        $this->assertSame(['Znajoma z tagiem', 'Obca z tagu', 'Mój wpis', 'Znajoma bez tagu'], $this->tresci($widz));
        $this->assertFalse(app(FollowingFeed::class)->isEmptyFor($widz));
    }

    public function test_podpis_tylko_przy_wpisach_z_samego_tagu(): void
    {
        $widz = $this->user('widz');
        $znajoma = $this->user('znajoma');
        $obca = $this->user('obca');
        $zupy = $this->tag('zupy', 'Zupy');
        $ciasta = $this->tag('ciasta', 'Ciasta');
        $this->obserwuj($widz, $znajoma);
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        // Wpis z dwoma tagami, z których obserwowany jest drugi — podpis
        // bierze OBSERWOWANY, nie pierwszy z brzegu.
        $zDwoma = $this->wpis($obca, 'Obca z dwoma tagami', 1, $ciasta);
        $zDwoma->tags()->attach($zupy->getKey(), ['position' => 1]);
        $this->wpis($znajoma, 'Znajoma z tagiem', 2, $zupy);
        $this->wpis($widz, 'Mój z tagiem', 3, $zupy);

        $posty = collect(app(FollowingFeed::class)->paginate($widz)->items())->keyBy('body');
        $this->assertSame('Zupy', $posty['Obca z dwoma tagami']->zrodloTematu?->name);
        $this->assertFalse($posty['Znajoma z tagiem']->relationLoaded('zrodloTematu'));
        $this->assertFalse($posty['Mój z tagiem']->relationLoaded('zrodloTematu'));

        $html = $this->actingAs($widz)->get(route('home'))->assertOk()->getContent();
        $this->assertSame(1, substr_count((string) $html, 'data-zrodlo-tematu'), 'Podpis „Z tagu” ma stać przy jednej karcie.');
        $this->assertStringContainsString('Z tagu: <a href="'.route('tags.show', $zupy).'">Zupy</a>', (string) $html);
    }

    public function test_prywatne_i_dla_obserwujacych_z_tagu_nie_przeciekaja(): void
    {
        $widz = $this->user('widz');
        $obca = $this->user('obca');
        $zupy = $this->tag('zupy', 'Zupy');
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $this->wpis($obca, 'Publiczny z tagu', 1, $zupy);
        $this->wpis($obca, 'Dla obserwujących z tagu', 2, $zupy, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $this->wpis($obca, 'Prywatny z tagu', 3, $zupy, ['visibility' => Post::VISIBILITY_PRIVATE]);
        $this->wpis($widz, 'Mój prywatny z tagu', 4, $zupy, ['visibility' => Post::VISIBILITY_PRIVATE]);

        $this->assertSame(['Publiczny z tagu'], $this->tresci($widz));
    }

    public function test_same_niewidoczne_wpisy_z_tagu_to_pusty_start(): void
    {
        $widz = $this->user('widz');
        $obca = $this->user('obca');
        $zupy = $this->tag('zupy', 'Zupy');
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);
        $this->wpis($obca, 'Dla obserwujących z tagu', 2, $zupy, ['visibility' => Post::VISIBILITY_FOLLOWERS]);

        // `isEmptyFor()` i `paginate()` zgodne: nic do pokazania → Odkrywanie.
        $this->assertTrue(app(FollowingFeed::class)->isEmptyFor($widz));
        $this->actingAs($widz)->get(route('home'))->assertOk()->assertViewHas('zrodloFeedu', 'odkrywanie');
    }

    public function test_blokada_w_obie_strony_odcina_wpisy_z_tagu(): void
    {
        $widz = $this->user('widz');
        $blokowana = $this->user('blokowana');
        $blokujaca = $this->user('blokujaca');
        $zwykla = $this->user('zwykla');
        $zupy = $this->tag('zupy', 'Zupy');
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);
        $this->wpis($blokowana, 'Od blokowanej', 1, $zupy);
        $this->wpis($blokujaca, 'Od blokującej', 2, $zupy);
        $this->wpis($zwykla, 'Od zwykłej', 3, $zupy);
        DB::table('blocks')->insert([
            ['blocker_id' => $widz->id, 'blocked_id' => $blokowana->id, 'created_at' => now()],
            ['blocker_id' => $blokujaca->id, 'blocked_id' => $widz->id, 'created_at' => now()],
        ]);

        $this->assertSame(['Od zwykłej'], $this->tresci($widz));
    }

    public function test_zawieszony_autor_i_ukryty_tag_nie_prowadza_na_start(): void
    {
        $widz = $this->user('widz');
        $zawieszona = $this->user('zawieszona');
        $obca = $this->user('obca');
        $zupy = $this->tag('zupy', 'Zupy');
        $ukryty = $this->tag('ukryty', 'Ukryty');
        $ukryty->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $widz->followedTags()->attach([$zupy->getKey(), $ukryty->getKey()], ['created_at' => now()]);
        $this->wpis($zawieszona, 'Od zawieszonej', 1, $zupy);
        $this->wpis($obca, 'Z ukrytego tagu', 2, $ukryty);
        $this->wpis($obca, 'Z aktywnego tagu', 3, $zupy);
        $zawieszona->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertSame(['Z aktywnego tagu'], $this->tresci($widz));
    }
}

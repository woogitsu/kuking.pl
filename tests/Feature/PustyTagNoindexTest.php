<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Puste strony tagów nie trafiają do indeksu wyszukiwarki (issue #1007).
 *
 * `TagSeeder` zakłada ~1400 aktywnych tagów, a spis `/tagi` linkuje do
 * każdego (D-087). Strona tagu bez publicznego wpisu odpowiadała `200`
 * z domyślnym `index, follow` — setki prawie identycznych stron „czeka na
 * pierwszy wpis" dla robota.
 *
 * Dwie granice naraz:
 *   - człowiek dalej widzi pusty tag, prawdziwe zero i link ze spisu,
 *   - robot dostaje `noindex, follow`, dopóki tag nie ma wpisu widocznego
 *     dla KAŻDEGO — ten sam zakres co licznik w spisie. Wpis prywatny albo
 *     tylko dla obserwujących nie może zrobić ze strony indeksowalnej.
 */
class PustyTagNoindexTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $slug = 'sernik'): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => $slug, 'normalized_name' => $slug]);
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function wpis(Tag $tag, array $atrybuty = []): Post
    {
        $post = Post::factory()->create(array_merge([
            'author_id' => User::factory()->create()->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ], $atrybuty));
        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return $post;
    }

    /** @return list<string> */
    private function dyrektywy(TestResponse $odp): array
    {
        preg_match_all('/<meta name="robots" content="([^"]*)">/', $odp->getContent(), $m);

        return $m[1];
    }

    /** `rel` linku do strony tagu w spisie A–Z, albo null, gdy linku nie ma. */
    private function relLinkuWSpisie(Tag $tag): ?string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$this->get(route('tags.index'))->assertOk()->getContent());
        libxml_clear_errors();

        $link = (new DOMXPath($dom))->query(sprintf(
            '//nav[@aria-label="Wszystkie tagi, alfabetycznie"]//a[@href="%s"]',
            route('tags.show', $tag),
        ))->item(0);

        return $link === null ? null : self::elementDom($link)->getAttribute('rel');
    }

    public function test_pusty_tag_dziala_dla_ludzi_ale_ma_noindex_follow(): void
    {
        $tag = $this->tag();

        $odp = $this->get(route('tags.show', $tag))->assertOk();
        $odp->assertSee('Tu jeszcze nikt nic nie ugotował', false);
        $odp->assertSee(route('posts.create', ['tag' => $tag->slug]), false);
        $this->assertSame(['noindex, follow'], $this->dyrektywy($odp));

        // Link ze spisu zostaje (D-087), tylko robot nie jest po nim zapraszany.
        $this->assertSame('nofollow', $this->relLinkuWSpisie($tag));
    }

    public function test_kontrola_dodatnia_tag_z_publicznym_wpisem_jest_indeksowany_i_linkowany(): void
    {
        $tag = $this->tag();
        $this->wpis($tag);

        $odp = $this->get(route('tags.show', $tag))->assertOk();
        $this->assertSame([], $this->dyrektywy($odp), 'Tag z publicznym wpisem ma zostać przy domyślnym index, follow.');
        $this->assertSame('', $this->relLinkuWSpisie($tag));
    }

    public function test_kontrola_ujemna_wpis_niepubliczny_nie_przelacza_na_index(): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        $this->wpis($tag, ['author_id' => $autor->getKey(), 'visibility' => Post::VISIBILITY_PRIVATE]);
        $this->wpis($tag, ['visibility' => Post::VISIBILITY_FOLLOWERS]);

        $this->assertSame(['noindex, follow'], $this->dyrektywy($this->get(route('tags.show', $tag))->assertOk()));

        // Dyrektywa nie zależy od widza: autor, który widzi swój prywatny wpis,
        // dostaje to samo co robot.
        $odpAutora = $this->actingAs($autor->fresh())->get(route('tags.show', $tag))->assertOk();
        $this->assertSame(['noindex, follow'], $this->dyrektywy($odpAutora));

        $this->assertSame('nofollow', $this->relLinkuWSpisie($tag));
    }

    public function test_dyrektywa_przelacza_sie_w_obie_strony_bez_starego_stanu(): void
    {
        $tag = $this->tag();
        $url = route('tags.show', $tag);

        $this->assertSame(['noindex, follow'], $this->dyrektywy($this->get($url)));

        $pierwszy = $this->wpis($tag);
        $this->assertSame([], $this->dyrektywy($this->get($url)), 'Pierwszy publiczny wpis ma od razu zdjąć noindex.');

        $pierwszy->forceFill(['status' => Post::STATUS_HIDDEN])->save();
        $this->assertSame(['noindex, follow'], $this->dyrektywy($this->get($url)), 'Ukrycie ostatniego publicznego wpisu ma przywrócić noindex.');

        $drugi = $this->wpis($tag);
        $this->assertSame([], $this->dyrektywy($this->get($url)));

        $drugi->delete();
        $this->assertSame(['noindex, follow'], $this->dyrektywy($this->get($url)), 'Usunięcie ostatniego publicznego wpisu ma przywrócić noindex.');
    }

    public function test_tag_ukryty_i_scalony_zachowuja_404_i_przekierowanie(): void
    {
        $ukryty = $this->tag('ukryty');
        $ukryty->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $this->get(route('tags.show', $ukryty))->assertNotFound();

        $cel = $this->tag('cel');
        $scalony = $this->tag('scalony');
        $scalony->forceFill(['status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $cel->getKey()])->save();
        $this->get(route('tags.show', $scalony))->assertRedirect(route('tags.show', $cel));
    }
}

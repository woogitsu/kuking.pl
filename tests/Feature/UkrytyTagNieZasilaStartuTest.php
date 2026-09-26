<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\TagFeed;
use App\Domain\Tags\Actions\MergeTags;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1824 — tag ukryty PO rozpoczęciu obserwowania nie zasila Startu.
 *
 * Ukrycie tagu przez moderację daje 404 na jego stronie i zdejmuje go
 * z katalogu, ale istniejący wiersz `tag_follows` zostaje (świadomie — da
 * się go zdjąć w „Twoich tagach", test #853). `TagFeed` brał wszystkie
 * obserwowane tagi bez pytania o status, więc niewidoczny temat dalej
 * dostarczał wpisy, a gdy był jedynym źródłem, Start wybierał „tagi"
 * zamiast odkrywania.
 *
 * Każdy test ma kontrolę dodatnią na tym samym tagu PRZED ukryciem — bez
 * niej asercje „nie ma" przechodziłyby na feedzie, który jest pusty z innego
 * powodu. Kontrola ujemna: bez warunku `tags.status = active`
 * w `TagFeed::obserwowaneTagi()` testy 1 i 2 oblewają.
 */
class UkrytyTagNieZasilaStartuTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $slug): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => ucfirst($slug), 'normalized_name' => $slug]);
    }

    private function wpis(Tag $tag, string $tresc): Post
    {
        $post = Post::factory()->create([
            'author_id' => $this->user()->getKey(),
            'body' => $tresc,
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now(),
        ]);
        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return $post;
    }

    private function obserwuje(User $widz, Tag $tag): void
    {
        $widz->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
    }

    private function ukryj(Tag $tag): void
    {
        $tag->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
    }

    /** @return list<string> */
    private function wFeedzieTagow(User $widz): array
    {
        return collect(app(TagFeed::class)->paginate($widz)->items())
            ->map(fn (Post $wpis) => (string) $wpis->getKey())
            ->all();
    }

    public function test_1_ukryty_tag_nie_dostarcza_wpisow_a_aktywny_dalej_tak(): void
    {
        $widz = $this->user();
        $zupy = $this->tag('zupy');
        $ciasta = $this->tag('ciasta');
        $this->obserwuje($widz, $zupy);
        $this->obserwuje($widz, $ciasta);
        $rosol = $this->wpis($zupy, 'Rosół z niedzieli');
        $sernik = $this->wpis($ciasta, 'Sernik babci');

        // KONTROLA DODATNIA: oba aktywne tagi zasilają feed.
        $przed = $this->wFeedzieTagow($widz);
        $this->assertContains((string) $rosol->getKey(), $przed);
        $this->assertContains((string) $sernik->getKey(), $przed);

        $this->ukryj($zupy);

        $po = $this->wFeedzieTagow($widz);
        $this->assertNotContains((string) $rosol->getKey(), $po, 'Wpis z ukrytego tagu dalej trafia do feedu tagów.');
        $this->assertContains((string) $sernik->getKey(), $po, 'Ukrycie jednego tagu zabrało wpisy z drugiego, aktywnego.');

        // Relacja zostaje w bazie — do zdjęcia w ustawieniach (#853).
        $this->assertTrue($widz->isFollowingTag($zupy));
    }

    public function test_2_ukryty_tag_jako_jedyne_zrodlo_przestawia_start_na_odkrywanie(): void
    {
        $widz = $this->user();
        $zupy = $this->tag('zupy');
        $this->obserwuje($widz, $zupy);
        $this->wpis($zupy, 'Pomidorowa z wczoraj');

        // KONTROLA DODATNIA: aktywny tag wybiera źródło „tagi".
        $this->assertTrue(app(TagFeed::class)->maTresci($widz));
        $this->actingAs($widz)->get(route('home'))->assertOk()->assertViewHas('zrodloFeedu', 'tagi');

        $this->ukryj($zupy);

        $this->assertFalse(app(TagFeed::class)->maTresci($widz), 'Ukryty tag dalej odpowiada „jest co pokazać".');
        $this->get(route('home'))->assertOk()->assertViewHas('zrodloFeedu', 'odkrywanie');
    }

    public function test_3_zastana_relacja_do_ukrytego_tagu_da_sie_zdjac(): void
    {
        $widz = $this->user();
        $zupy = $this->tag('zupy');
        $this->obserwuje($widz, $zupy);
        $this->ukryj($zupy);

        $this->actingAs($widz)->delete(route('tags.unfollow', $zupy));

        $this->assertFalse($widz->isFollowingTag($zupy), 'Poprawka feedu zabrała możliwość zdjęcia ukrytego tagu.');
    }

    public function test_4_po_scaleniu_tresci_aktywnego_celu_zostaja_na_starcie(): void
    {
        $widz = $this->user();
        $zrodlo = $this->tag('zupki');
        $cel = $this->tag('zupy');
        $this->obserwuje($widz, $zrodlo);
        $wpis = $this->wpis($zrodlo, 'Krupnik jak u mamy');

        app(MergeTags::class)->handle($zrodlo, $cel);

        $this->assertTrue($widz->isFollowingTag($cel->fresh()), 'Scalenie nie przeniosło obserwowania na cel.');
        $this->assertContains((string) $wpis->getKey(), $this->wFeedzieTagow($widz), 'Wpis scalonego tagu zniknął z feedu, choć cel jest aktywny.');
        $this->assertTrue(app(TagFeed::class)->maTresci($widz));
    }
}

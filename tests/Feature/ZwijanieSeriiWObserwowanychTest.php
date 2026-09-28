<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\ZbierzTresciDigestu;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1812 — zwijanie serii w Obserwowanych (kolejność bez zmian, nic nie
 * znika) i najwyżej jeden wpis na autora w tygodniowym liście.
 *
 * Kontrole ujemne (sprawdzone przy pisaniu):
 *  - `WIDOCZNE_Z_SERII = 1` → testy serii osoby, tagu i dwóch wpisów oblewają;
 *  - `where('posts.digest_na_autora', 1)` zdjęte → test listu dostaje trzy
 *    wpisy jednej osoby.
 */
class ZwijanieSeriiWObserwowanychTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, string $tresc, int $minutTemu, ?Tag $tag = null): Post
    {
        $post = Post::factory()->create(['author_id' => $autor->id, 'body' => $tresc, 'published_at' => now()->subMinutes($minutTemu)]);
        $tag?->posts()->attach($post->id, ['position' => 0]);

        return $post;
    }

    private function obserwuj(User $kto, User $kogo): void
    {
        DB::table('follows')->insert(['follower_id' => $kto->id, 'followed_id' => $kogo->id, 'created_at' => now()]);
    }

    /** @return array{kolejnosc: list<string>, zwiniete: list<list<string>>, podpisy: list<string>} */
    private function ekranStartu(User $widz): array
    {
        $html = (string) $this->actingAs($widz)->get(route('home'))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $x = new \DOMXPath($dom);

        $kolejnosc = [];
        foreach ($x->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' post-card ')]") as $karta) {
            if (preg_match('/(Anna|Basia|Zupa) \d+/u', $karta->textContent, $m) === 1) {
                $kolejnosc[] = $m[0];
            }
        }

        $zwiniete = [];
        $podpisy = [];
        foreach ($x->query('//details[@data-seria-wpisow]') as $seria) {
            $podpisy[] = trim(preg_replace('/\s+/u', ' ', $x->query('./summary', $seria)->item(0)->textContent));
            $w = [];
            foreach ($x->query(".//article[contains(concat(' ', normalize-space(@class), ' '), ' post-card ')]", $seria) as $karta) {
                preg_match('/(Anna|Basia|Zupa) \d+/u', $karta->textContent, $m);
                $w[] = $m[0];
            }
            $zwiniete[] = $w;
        }

        return ['kolejnosc' => $kolejnosc, 'zwiniete' => $zwiniete, 'podpisy' => $podpisy];
    }

    public function test_seria_jednej_osoby_zwija_sie_bez_zmiany_kolejnosci_i_bez_utraty(): void
    {
        $widz = $this->user('widz');
        $anna = $this->user('anna');
        $basia = $this->user('basia');
        $this->obserwuj($widz, $anna);
        $this->obserwuj($widz, $basia);
        foreach (range(1, 5) as $i) {
            $this->wpis($anna, "Anna {$i}", $i);
        }
        $this->wpis($basia, 'Basia 1', 10);

        $ekran = $this->ekranStartu($widz);

        // Wszystkie sześć kart, w kolejności listy — żadna nie zniknęła.
        $this->assertSame(['Anna 1', 'Anna 2', 'Anna 3', 'Anna 4', 'Anna 5', 'Basia 1'], $ekran['kolejnosc']);
        $this->assertSame([['Anna 3', 'Anna 4', 'Anna 5']], $ekran['zwiniete']);
        $this->assertCount(1, $ekran['podpisy']);
        $this->assertStringEndsWith(': jeszcze 3 wpisy — Pokaż', $ekran['podpisy'][0]);
        $this->assertStringStartsWith($anna->displayName().':', $ekran['podpisy'][0]);
    }

    public function test_dwa_wpisy_z_rzedu_nie_zwijaja_sie(): void
    {
        $widz = $this->user('widz');
        $anna = $this->user('anna');
        $this->obserwuj($widz, $anna);
        $this->wpis($anna, 'Anna 1', 1);
        $this->wpis($anna, 'Anna 2', 2);

        $ekran = $this->ekranStartu($widz);
        $this->assertSame(['Anna 1', 'Anna 2'], $ekran['kolejnosc']);
        $this->assertSame([], $ekran['zwiniete']);
    }

    public function test_seria_z_jednego_tagu_zwija_sie_tak_samo(): void
    {
        $widz = $this->user('widz');
        $zupy = Tag::create(['slug' => 'zupy', 'name' => 'Zupy', 'normalized_name' => 'zupy']);
        $widz->followedTags()->attach($zupy->id, ['created_at' => now()]);
        foreach (range(1, 4) as $i) {
            $this->wpis($this->user("obca{$i}"), "Zupa {$i}", $i, $zupy);
        }

        $ekran = $this->ekranStartu($widz);
        $this->assertSame(['Zupa 1', 'Zupa 2', 'Zupa 3', 'Zupa 4'], $ekran['kolejnosc']);
        $this->assertSame([['Zupa 3', 'Zupa 4']], $ekran['zwiniete']);
        $this->assertSame(['Tag Zupy: jeszcze 2 wpisy — Pokaż'], $ekran['podpisy']);
    }

    public function test_odkrywanie_nie_zwija(): void
    {
        $widz = $this->user('widz');
        $anna = $this->user('anna');
        foreach (range(1, 4) as $i) {
            $this->wpis($anna, "Anna {$i}", $i);
        }

        $this->actingAs($widz)->get(route('home'))->assertOk()->assertViewHas('zrodloFeedu', 'odkrywanie')
            ->assertDontSee('data-seria-wpisow', false);
    }

    public function test_tygodniowy_list_ma_najwyzej_jeden_wpis_na_autora(): void
    {
        $widz = $this->user('widz');
        $anna = $this->user('anna');
        $basia = $this->user('basia');
        $this->obserwuj($widz, $anna);
        $this->obserwuj($widz, $basia);
        foreach (range(1, 3) as $i) {
            $this->wpis($anna, "Anna {$i}", $i);
        }
        $this->wpis($basia, 'Basia 1', 60);

        $list = app(ZbierzTresciDigestu::class)->dlaJednej($widz->fresh(), now()->subDays(7));

        $this->assertSame(['Anna 1', 'Basia 1'], array_map(fn (Post $p) => $p->body, $list->wpisyObserwowanych));
    }
}

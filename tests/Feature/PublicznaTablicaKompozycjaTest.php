<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicznaTablicaKompozycjaTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_widzi_dania_przed_osobami_duze_zdjecie_i_jedno_zaproszenie(): void
    {
        foreach ([1, 2] as $i) {
            $autor = $this->user('kuchnia_publiczna'.$i);
            $post = Post::factory()->create(['author_id' => $autor->id, 'visibility' => 'public', 'published_at' => now()]);
            $media = Media::factory()->create(['owner_id' => $autor->id]);
            $post->media()->attach($media->id, ['position' => 0]);
        }

        $html = $this->tablica('landing');
        $this->assertStringContainsString('landing-tablica', $html);
        $this->assertStringContainsString($media->url('feed'), $html);
        $this->przed($html, 'kuking-board-posts', 'kuking-board-people');
        $this->assertSame(1, substr_count($html, 'Załóż konto, żeby obserwować'));
        $this->assertStringContainsString('Tu nie ma rankingu.', $html);
        $this->assertStringContainsString($post->url(), $html);

        $szyna = $this->tablica('discover');
        $this->assertStringNotContainsString('landing-tablica', $szyna);
        $this->assertStringContainsString($media->url('thumb'), $szyna);
        $this->przed($szyna, 'kuking-board-people', 'kuking-board-posts');
    }

    public function test_prywatne_danie_nie_trafia_do_publicznej_kompozycji(): void
    {
        $autor = $this->user('prywatna_kuchnia');
        $post = Post::factory()->create(['author_id' => $autor->id, 'visibility' => 'private', 'published_at' => now(), 'body' => 'Nie pokazuj tego dania gościowi']);
        $html = $this->tablica('landing');
        $this->assertStringNotContainsString($post->url(), $html);
        $this->assertStringNotContainsString('Nie pokazuj tego dania gościowi', $html);
        $this->assertStringContainsString('Dziś jeszcze nikogo nie wybraliśmy.', $html);
        $this->assertStringNotContainsString('landing-tablica-zaproszenie', $html);
    }

    private function tablica(string $route): string
    {
        $html = (string) $this->get(route($route))->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $node = (new \DOMXPath($dom))->query('//section[@aria-labelledby="kuking-na-dzis"]')->item(0);
        $this->assertNotNull($node);

        return (string) $dom->saveHTML($node);
    }

    private function przed(string $html, string $pierwsza, string $druga): void
    {
        $a = strpos($html, $pierwsza);
        $b = strpos($html, $druga);
        $this->assertIsInt($a);
        $this->assertIsInt($b);
        $this->assertLessThan($b, $a);
    }
}

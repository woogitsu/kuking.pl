<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HeroPick;
use App\Models\Media;
use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingKompozycjaFotografiiTest extends TestCase
{
    use RefreshDatabase;

    public function test_kroki_prowadza_do_publicznych_podgladow_a_potem_do_bloku_ugotowalem(): void
    {
        $dom = $this->landing();
        $steps = $dom->query('//*[@id="jak-dziala"]');
        $this->assertSame(1, $steps->length);
        $this->assertSame('Jak działa', $steps->item(0)->getAttribute('aria-label'));
        $heading = $dom->query('.//h2', $steps->item(0))->item(0);
        $this->assertMatchesRegularExpression('/^Zdjęcie\. Kilka słów\.\s*I rozmowa przy okazji\.$/u', $this->tekst($heading->textContent));
        $links = $dom->query('.//ol/li//a', $steps->item(0));
        $this->assertSame(3, $links->length);
        // #1289: landing widzi tylko gość — kroki prowadzą do publicznych
        // podglądów, nie do tras za logowaniem.
        $cele = [route('help').'#dodawanie-zdjecia', route('search', ['sekcja' => 'przepisy']), route('discover')];
        foreach ($cele as $i => $cel) {
            $this->assertSame($cel, $links->item($i)->getAttribute('href'));
        }
        $next = $dom->query('following-sibling::section[1]', $steps->item(0));
        $this->assertSame('ugotowalem', $next->item(0)?->getAttribute('id'));
        $this->assertMatchesRegularExpression('/^Twój przepis\.\s*Czyjś dobry obiad\.$/u', $this->tekst($dom->query('//*[@id="ugotowalem"]//h2')->item(0)?->textContent ?? ''));
        $this->assertSame(1, $dom->query('//*[@id="ugotowalem"]/following-sibling::section[1]//*[@id="kuking-na-dzis"]')->length);
        $this->assertSame(0, $dom->query('//*[@id="ugotowalem"]//figure')->length);
        $this->assertSame(0, $dom->query('//*[contains(@class,"cytat-ugotowalem")]')->length);
    }

    public function test_fotografia_i_podpis_sa_z_tego_samego_publicznego_zrodla_i_znikaja_po_prywatyzacji(): void
    {
        $first = null;
        $remaining = [];
        foreach (range(0, 3) as $i) {
            $author = $this->user('autor'.$i, ['display_name' => 'Autor fotografii '.$i]);
            $post = Post::factory()->create(['author_id' => $author->getKey(), 'visibility' => Post::VISIBILITY_PUBLIC, 'status' => Post::STATUS_PUBLISHED, 'published_at' => now()->subMinutes($i + 1)]);
            $photo = Media::factory()->for($author, 'owner')->create();
            $post->media()->attach($photo->getKey(), ['position' => 0]);
            HeroPick::create(['post_id' => $post->getKey(), 'media_id' => $photo->getKey(), 'position' => $i]);
            $first ??= [$post, $photo];
            $remaining[] = [$post, $photo];
        }
        $dom = $this->landing();
        $figures = $dom->query('//*[@id="ugotowalem"]//figure');
        $this->assertSame(1, $figures->length);
        $photo = $dom->query('.//img', $figures->item(0));
        $this->assertSame(1, $photo->length);
        $this->assertSame($first[1]->url('feed'), $photo->item(0)->getAttribute('src'));
        $this->assertSame('', $photo->item(0)->getAttribute('alt'));
        $this->assertSame('Zdjęcie: Autor fotografii 0', rtrim($this->tekst($dom->query('.//figcaption', $figures->item(0))->item(0)?->textContent ?? ''), '.'));
        $this->assertSame(0, $dom->query('ancestor-or-self::*[@aria-hidden="true"]', $figures->item(0))->length);
        $first[0]->update(['visibility' => Post::VISIBILITY_PRIVATE]);
        $dom = $this->landing();
        $figures = $dom->query('//*[@id="ugotowalem"]//figure');
        $this->assertSame(1, $figures->length);
        $this->assertSame($remaining[1][1]->url('feed'), $dom->query('.//img', $figures->item(0))->item(0)->getAttribute('src'));
        $this->assertSame('Zdjęcie: Autor fotografii 1', rtrim($this->tekst($dom->query('.//figcaption', $figures->item(0))->item(0)->textContent), '.'));
        foreach ($dom->query('//img') as $image) {
            $this->assertNotContains($image->getAttribute('src'), [$first[1]->url('feed'), $first[1]->url('thumb')]);
        }
        foreach ($remaining as [$post]) {
            $post->update(['visibility' => Post::VISIBILITY_PRIVATE]);
        }
        $this->assertSame(0, $this->landing()->query('//*[@id="ugotowalem"]//figure')->length);
        $first[0]->update(['visibility' => Post::VISIBILITY_PUBLIC]);
        $first[1]->update(['status' => Media::STATUS_PENDING]);
        $this->assertSame(0, $this->landing()->query('//*[@id="ugotowalem"]//figure')->length, 'Zdjęcie przed przetworzeniem nie może być publiczną fotografią sekcji.');
    }

    private function landing(): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$this->get('/')->assertOk()->getContent());

        return new DOMXPath($document);
    }

    private function tekst(string $text): string
    {
        return preg_replace('/\s+/u', ' ', trim($text));
    }
}

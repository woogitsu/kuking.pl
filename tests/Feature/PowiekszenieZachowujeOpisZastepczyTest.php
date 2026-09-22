<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #744 — powiększenie zdjęcia gubiło opis zastępczy przekazany do
 * miniatury w karuzeli i kolażu.
 *
 * `x-photo` liczyło `$alt ?: ($media->alt_text ?? '')` dla `<img>`, ale
 * `data-alt` i `aria-label` linku powiększenia czytały WYŁĄCZNIE
 * `$media->alt_text` — pomijając prop `$alt`. Karuzela (`karuzela-zdjec.
 * blade.php:51`) i kolaż (`kolaz-zdjec.blade.php:29`) podają przez `$alt`
 * zastępczy opis „Zdjęcie N z M w tym wpisie", kiedy autor nie wpisał
 * własnego opisu — miniatura go pokazywała, powiększenie (JS kopiuje
 * `data-alt` do `obraz.alt`) dostawało pusty `alt`.
 *
 * Test sprawdza DOM wysłanej odpowiedzi (nie renderuje JS-owego dialogu —
 * to jest granica opisana w PowiekszanieZdjeciaTest), ale sprawdza dokładnie
 * to pole (`data-alt`), które JS kopiuje 1:1 do obrazu w nakładce — nie samo
 * wystąpienie opisu gdziekolwiek w HTML-u, co byłoby fałszywie zielone dzięki
 * samej miniaturze.
 */
class PowiekszenieZachowujeOpisZastepczyTest extends TestCase
{
    use RefreshDatabase;

    private function zdjecie(string $ownerId, string $sufiks, ?string $altText): Media
    {
        return Media::create([
            'owner_id' => $ownerId,
            'disk' => 'public',
            'object_key' => 'incoming/'.$ownerId.'/2026/09/'.Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 1024,
            'width' => 1600,
            'height' => 1200,
            'status' => Media::STATUS_READY,
            'alt_text' => $altText,
            'metadata' => [
                'variants' => [
                    'thumb' => ['key' => 'media/'.$sufiks.'_thumb.webp', 'width' => 320, 'height' => 240],
                    'feed' => ['key' => 'media/'.$sufiks.'_feed.webp', 'width' => 960, 'height' => 720],
                    'large' => ['key' => 'media/'.$sufiks.'_large.webp', 'width' => 1600, 'height' => 1200],
                ],
            ],
        ]);
    }

    private function dataAltZLinkow(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $wartosci = [];
        foreach ($xpath->query('//a[@data-powieksz]') as $link) {
            $wartosci[] = [
                'data-alt' => $link->getAttribute('data-alt'),
                'aria-label' => $link->getAttribute('aria-label'),
            ];
        }

        return $wartosci;
    }

    public function test_karuzela_przekazuje_zastepczy_opis_do_linku_powiekszenia(): void
    {
        $autor = $this->user('karuzela_autorka');

        $pierwsze = $this->zdjecie($autor->getKey(), 'k1', null);
        $drugie = $this->zdjecie($autor->getKey(), 'k2', null);

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'body' => 'Dwa zdjęcia obiadu.',
            'display_mode' => Post::DISPLAY_CAROUSEL,
        ]);
        $post->media()->attach([$pierwsze->getKey() => ['position' => 0], $drugie->getKey() => ['position' => 1]]);

        $html = $this->get(route('posts.show', $post))->assertOk()->getContent();
        $linki = $this->dataAltZLinkow($html);

        $this->assertCount(2, $linki, 'Karuzela nie wyrenderowała dwóch linków powiększenia.');

        // Bez własnego alt_text miniatura (i teraz też powiększenie) dostają
        // zastępczy opis z numerem — nie pusty string.
        $this->assertSame('Zdjęcie 1 z 2 w tym wpisie', $linki[0]['data-alt']);
        $this->assertSame('Zdjęcie 2 z 2 w tym wpisie', $linki[1]['data-alt']);
        $this->assertStringContainsString('Zdjęcie 1 z 2 w tym wpisie', $linki[0]['aria-label']);
        $this->assertStringContainsString('Zdjęcie 2 z 2 w tym wpisie', $linki[1]['aria-label']);
    }

    public function test_kolaz_przekazuje_zastepczy_opis_do_linku_powiekszenia(): void
    {
        $autor = $this->user('kolaz_autor');

        $pierwsze = $this->zdjecie($autor->getKey(), 'l1', null);
        $drugie = $this->zdjecie($autor->getKey(), 'l2', null);

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'body' => 'Kolaż dwóch dań.',
            'display_mode' => Post::DISPLAY_COLLAGE,
        ]);
        $post->media()->attach([$pierwsze->getKey() => ['position' => 0], $drugie->getKey() => ['position' => 1]]);

        $html = $this->get(route('posts.show', $post))->assertOk()->getContent();
        $linki = $this->dataAltZLinkow($html);

        $this->assertCount(2, $linki, 'Kolaż nie wyrenderował dwóch linków powiększenia.');
        $this->assertSame('Zdjęcie 1 z 2 w tym wpisie', $linki[0]['data-alt']);
        $this->assertSame('Zdjęcie 2 z 2 w tym wpisie', $linki[1]['data-alt']);
    }

    public function test_wlasny_opis_zdjecia_przechodzi_niezmieniony_do_powiekszenia(): void
    {
        $autor = $this->user('wlasny_opis_autorka');

        $pierwsze = $this->zdjecie($autor->getKey(), 'm1', 'Rosół babci Zofii <z & koperkiem>"');
        $drugie = $this->zdjecie($autor->getKey(), 'm2', null);

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'body' => 'Dwa dania.',
            'display_mode' => Post::DISPLAY_CAROUSEL,
        ]);
        $post->media()->attach([$pierwsze->getKey() => ['position' => 0], $drugie->getKey() => ['position' => 1]]);

        $html = $this->get(route('posts.show', $post))->assertOk()->getContent();
        $linki = $this->dataAltZLinkow($html);

        $this->assertSame('Rosół babci Zofii <z & koperkiem>"', $linki[0]['data-alt']);
        $this->assertSame('Zdjęcie 2 z 2 w tym wpisie', $linki[1]['data-alt']);

        // Escapowane w samym HTML-u (znaki specjalne nie tworzą znaczników).
        $this->assertStringNotContainsString('<z & koperkiem>', $html);
    }
}

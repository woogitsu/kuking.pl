<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CzytaJsonLd;
use Tests\TestCase;

/**
 * Nazwa serwisu dla wyszukiwarki: `WebSite` na stronie głównej (#1008).
 *
 * Google bierze nazwę witryny przede wszystkim z `WebSite` na stronie
 * głównej. Do tej pory był tylko `og:site_name`. Blok ma być DOKŁADNIE
 * jeden, tylko na stronie głównej, bez `SearchAction` i bez niepotwierdzonych
 * profili `sameAs`.
 */
class WebSiteJsonLdNaStroniePowitalnejTest extends TestCase
{
    use CzytaJsonLd;
    use RefreshDatabase;

    public function test_strona_powitalna_ma_jeden_website_z_nazwa_i_kanonicznym_adresem(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $bloki = $this->blokiTypu($html, 'WebSite');

        $this->assertCount(1, $bloki, 'Strona główna musi mieć dokładnie jeden `WebSite`.');
        $website = $bloki[0];
        $this->assertSame('https://schema.org', $website['@context']);
        $this->assertSame('Kuking', $website['name']);
        $this->assertSame('pl-PL', $website['inLanguage']);
        $this->assertSame(route('landing'), $website['url']);
        $this->assertStringStartsWith(config('app.url'), $website['url']);

        // `url` to ten sam adres co `<link rel="canonical">` strony.
        $this->assertSame(1, preg_match('#<link rel="canonical" href="([^"]+)">#', $html, $kanoniczny));
        $this->assertSame($kanoniczny[1], $website['url']);

        $this->assertArrayNotHasKey('potentialAction', $website, 'Bez `SearchAction` — Google wycofał tę funkcję.');
        $this->assertArrayNotHasKey('sameAs', $website);
        $this->assertArrayNotHasKey('publisher', $website);
        $this->assertArrayNotHasKey('alternateName', $website);
    }

    public function test_podstrony_nie_powielaja_website(): void
    {
        $wpis = Post::factory()->create();
        $autor = $wpis->author;

        foreach ([
            route('discover'),
            route('posts.show', $wpis),
            route('profile.show', $autor->profile->username),
        ] as $adres) {
            $html = $this->get($adres)->assertOk()->getContent();

            $this->assertSame([], $this->blokiTypu($html, 'WebSite'), "Podstrona {$adres} powiela `WebSite`.");
        }
    }

    public function test_zalogowany_pod_adresem_glownym_dostaje_feed_bez_website(): void
    {
        // Pod `/` zalogowana osoba widzi swój feed, nie stronę powitalną.
        // To nie jest strona, którą Google indeksuje jako główną.
        $html = $this->actingAs(User::factory()->create()->fresh())->get(route('landing'))->assertOk()->getContent();

        $this->assertSame([], $this->blokiTypu($html, 'WebSite'));
    }
}

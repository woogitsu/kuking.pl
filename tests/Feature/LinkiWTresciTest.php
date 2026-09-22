<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Support\LinkiWTekscie;
use App\Support\ZapowiedzWpisu;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkiWTresciTest extends TestCase
{
    use RefreshDatabase;

    private function cel(string $tekst): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML((string) LinkiWTekscie::render($tekst));
        $link = $dom->getElementsByTagName('a')->item(0);
        $this->assertNotNull($link);
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);

        return Crypt::decryptString($query['cel']);
    }

    public function test_linkuje_adres_ze_zgloszenia_i_zachowuje_interpunkcje(): void
    {
        $this->assertSame('https://www.mojewypieki.com/przepis/marcinek', $this->cel('Ciasto Marcinek - https://www.mojewypieki.com/przepis/marcinek'));
        $this->assertSame('https://example.test/a(b)?x=1&y=2#krok', $this->cel('(https://example.test/a(b)?x=1&y=2#krok).'));
        $this->assertSame('https://www.example.test/przepis', $this->cel('www.example.test/przepis'));
        foreach (['„https://example.test/przepis”', '«https://example.test/przepis»', '‘https://example.test/przepis’'] as $tekst) {
            $this->assertSame('https://example.test/przepis', $this->cel($tekst));
        }
    }

    public function test_html_autora_pozostaje_tekstem(): void
    {
        $tekst = '<script>alert(1)</script> <img src=x onerror=alert(1)> & " https://example.test/?a=1&b=2';
        $html = (string) LinkiWTekscie::render($tekst);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertSame('https://example.test/?a=1&b=2', $this->cel($tekst));
    }

    public function test_tylko_pelny_origin_aplikacji_omija_ostrzezenie(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        $this->assertTrue(LinkiWTekscie::wewnetrzny('https://KUKING.pl:443/przepis'));
        $this->assertStringContainsString('href="https://kuking.pl/przepis"', (string) LinkiWTekscie::render('https://kuking.pl/przepis'));
        foreach (['https://kuking.pl.evil.test/', 'https://evil-kuking.pl/', 'https://kuking.pl:444/', 'http://kuking.pl/', 'https://www.kuking.pl/', 'https://kuking.pl./'] as $adres) {
            $this->assertFalse(LinkiWTekscie::wewnetrzny($adres));
            $this->assertSame($adres, $this->cel($adres));
        }
    }

    /**
     * Issue #763 — polski znak w ŚCIEŻCE (bez wcześniejszego kodowania
     * procentowego) MUSI stać się linkiem, dokładnie do tego samego celu co
     * jego wersja `%XX`. Kontrola ujemna: usunięcie kodowania w
     * `LinkiWTekscie::bezpiecznyAdres()` sprawia, że pierwsza asercja oblewa
     * (link znika), bo `FILTER_VALIDATE_URL` odrzuca surowy Unicode.
     */
    public function test_polskie_znaki_w_sciezce_staja_sie_linkiem_do_tego_samego_celu_co_wersja_procentowa(): void
    {
        $zUnicode = 'https://example.test/żurek';
        $zProcentem = 'https://example.test/%C5%BCurek';

        $this->assertStringContainsString('<a ', (string) LinkiWTekscie::render($zUnicode));
        $this->assertSame($this->cel($zProcentem), $this->cel($zUnicode));
        $this->assertSame('https://example.test/%C5%BCurek', LinkiWTekscie::bezpiecznyAdres($zUnicode));

        // Bez podwójnego kodowania: wersja już zakodowana wychodzi identyczna.
        $this->assertSame('https://example.test/%C5%BCurek', LinkiWTekscie::bezpiecznyAdres($zProcentem));
    }

    /**
     * To samo dla zapytania i fragmentu, nie tylko dla ścieżki — i kontrola,
     * że `/`, `?`, `&`, `=`, `#` nie zmieniają znaczenia przy okazji.
     */
    public function test_polskie_znaki_w_zapytaniu_i_fragmencie_tez_staja_sie_linkiem(): void
    {
        $adres = 'https://example.test/przepis?q=żurek&x=1#sekcja-żurek';

        $this->assertSame(
            'https://example.test/przepis?q=%C5%BCurek&x=1#sekcja-%C5%BCurek',
            LinkiWTekscie::bezpiecznyAdres($adres),
        );
    }

    /**
     * Granica z issue: domena (host) z polskim znakiem to OSOBNA kategoria
     * (IDNA), świadomie nieobjęta tą poprawką — nadal ma zostać odrzucona,
     * nie zamieniona po cichu na coś innego.
     */
    public function test_polski_znak_w_samej_domenie_nadal_jest_odrzucany(): void
    {
        $this->assertNull(LinkiWTekscie::bezpiecznyAdres('https://żurek.example/przepis'));
        $this->assertStringNotContainsString('<a ', (string) LinkiWTekscie::render('https://żurek.example/przepis'));
    }

    public function test_niedozwolone_adresy_nie_staja_sie_linkami(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,test', 'file:///etc/passwd', '//example.test', 'https://user:pass@example.test/', 'https://example.test\\@evil.test', 'https://example.test/%0d%0aLocation:x', "https://example.test/\u{202e}abc", 'https://example.test/…'] as $adres) {
            $this->assertNull(LinkiWTekscie::bezpiecznyAdres($adres), $adres);
            $this->assertStringNotContainsString('<a ', (string) LinkiWTekscie::render($adres), $adres);
        }
    }

    public function test_uciety_adres_w_zapowiedzi_nie_prowadzi_do_innego_celu(): void
    {
        $adres = 'https://example.test/'.str_repeat('a', 500);
        $this->assertStringNotContainsString('<a ', (string) LinkiWTekscie::render(ZapowiedzWpisu::skroc($adres)));
        $this->assertSame($adres, $this->cel($adres));
    }

    public function test_strona_ostrzega_i_wymaga_swiadomego_przejscia_bez_odpytywania_celu(): void
    {
        Http::preventStrayRequests();
        $adres = 'https://kuking.pl.evil.test/przepis?x=1&y=2';
        $response = $this->get(route('links.external', ['cel' => Crypt::encryptString($adres)]));
        $response->assertOk()->assertSee('kuking.pl.evil.test')->assertSee('Przejdź do strony')->assertSee('nie sprawdza bezpieczeństwa');
        $this->assertFalse($response->headers->has('Location'));
        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('referrerpolicy="no-referrer"', false);
        $response->assertSee('rel="nofollow ugc noopener noreferrer"', false);
        Http::assertNothingSent();
    }

    public function test_uszkodzony_token_i_niedozwolony_odszyfrowany_cel_sa_odrzucane(): void
    {
        foreach (['', 'uszkodzony', str_repeat('a', 6001), Crypt::encryptString('javascript:alert(1)')] as $token) {
            $this->get(route('links.external', ['cel' => $token]))->assertNotFound();
        }
        $this->get('/otworz-link?cel[]=x')->assertNotFound();
    }

    public function test_prawdziwa_karta_linkuje_bez_zmiany_tresci_w_bazie(): void
    {
        $autor = $this->user('autorka_linku');
        $body = 'Ciasto Marcinek - https://www.mojewypieki.com/przepis/marcinek';
        $post = Post::factory()->create(['author_id' => $autor->id, 'body' => $body, 'visibility' => Post::VISIBILITY_PUBLIC, 'status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);
        $comment = Comment::factory()->create(['post_id' => $post->id, 'author_id' => $autor->id, 'body' => 'https://example.test/komentarz']);
        Comment::factory()->create(['post_id' => $post->id, 'parent_id' => $comment->id, 'author_id' => $autor->id, 'body' => 'https://example.test/odpowiedz']);
        $response = $this->get(route('posts.show', $post))->assertOk()->assertSee('class="link-w-tresci"', false)->assertSee('/otworz-link?cel=', false);
        $this->assertSame(3, substr_count($response->getContent(), 'class="link-w-tresci"'));
        $this->assertSame($body, $post->fresh()->body);
    }
}

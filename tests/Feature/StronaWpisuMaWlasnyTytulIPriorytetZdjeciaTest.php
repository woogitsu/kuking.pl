<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Strona pojedynczego wpisu: własny tytuł i H1 (#967), zdjęcie LCP bez
 * leniwego ładowania (#1001).
 *
 * Asercje czytają ROZBITY HTML (`Dom\HTMLDocument`), nie szukają napisów:
 * `<title>`, `og:title`, liczba `<h1>` i atrybuty `<img>` to dokładnie to,
 * co widzi wyszukiwarka i przeglądarka.
 *
 * KONTROLE UJEMNE (sprawdzone ręcznie przy tej zmianie):
 *  • przywrócenie `displayName().' — wpis'` w `posts/show.blade.php` oblewa
 *    test dwóch wpisów jednej osoby;
 *  • usunięcie `:priority="true"` ze strony wpisu (albo `$loop->first`
 *    z karuzeli/kolażu) oblewa testy zdjęć.
 */
class StronaWpisuMaWlasnyTytulIPriorytetZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // #967 — tytuł, H1 i opis
    // -----------------------------------------------------------------

    public function test_dwa_wpisy_jednej_osoby_maja_rozne_opisowe_tytuly(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zupa = $this->wpis($basia, "Zupa ogórkowa jak u mamy\nOgórki, ziemniaki, koper.");
        $ciasto = $this->wpis($basia, 'Szarlotka na kruchym spodzie');

        $dokumentZupy = $this->strona($zupa);
        $dokumentCiasta = $this->strona($ciasto);

        // Kontrola dodatnia: to naprawdę strony tych wpisów.
        $this->assertStringContainsString('Ogórki, ziemniaki, koper.', $dokumentZupy->body->textContent);

        $this->assertSame('Zupa ogórkowa jak u mamy — Kuking', $this->tytul($dokumentZupy));
        $this->assertSame('Szarlotka na kruchym spodzie — Kuking', $this->tytul($dokumentCiasta));
        $this->assertSame($this->tytul($dokumentZupy), $this->meta($dokumentZupy, 'og:title'));
        $this->assertNotSame($this->tytul($dokumentZupy), $this->tytul($dokumentCiasta));
        $this->assertStringNotContainsString('— wpis', $this->tytul($dokumentZupy));
    }

    public function test_strona_wpisu_ma_jeden_h1_z_trescia(): void
    {
        $wpis = $this->wpis($this->user('ola'), 'Pierogi ruskie z cebulką');

        $naglowki = $this->strona($wpis)->querySelectorAll('h1');

        $this->assertCount(1, $naglowki);
        $this->assertSame('Pierogi ruskie z cebulką', trim($naglowki->item(0)->textContent));
    }

    public function test_dlugi_tekst_jest_skrocony_na_granicy_wyrazu(): void
    {
        $tresc = 'Gulasz wołowy duszony powoli przez trzy godziny z papryką, cebulą i odrobiną majeranku';
        $dokument = $this->strona($this->wpis($this->user('ela'), $tresc));

        $tytul = $this->tytul($dokument);
        $samTytul = mb_substr($tytul, 0, -mb_strlen(' — Kuking'));

        $this->assertStringEndsWith('…', $samTytul);
        $this->assertLessThanOrEqual(60, mb_strlen($samTytul));
        $bezWielokropka = mb_substr($samTytul, 0, -1);
        // Całe wyrazy: ucięty początek jest prefiksem treści, a zaraz po nim
        // w treści stoi spacja albo przecinek — nie środek wyrazu.
        $this->assertStringStartsWith($bezWielokropka, $tresc);
        $this->assertMatchesRegularExpression('/^[\s,]/u', mb_substr($tresc, mb_strlen($bezWielokropka)));
        $this->assertSame('Gulasz wołowy duszony powoli przez trzy godziny z papryką…', $samTytul);

        $opis = $this->meta($dokument, 'description');
        $this->assertSame($tresc, $opis, 'Krótszy niż limit opis zostaje w całości.');
    }

    public function test_znaki_specjalne_nie_wchodza_do_metadanych_jako_znaczniki(): void
    {
        $tresc = '<b>Placki</b> & "sos" <script>alert(1)</script>';
        $wpis = $this->wpis($this->user('ania'), $tresc);

        $html = (string) $this->get($wpis->url())->assertOk()->getContent();
        $dokument = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        $this->assertSame($tresc.' — Kuking', $this->tytul($dokument));
        $this->assertSame($tresc.' — Kuking', $this->meta($dokument, 'og:title'));
        $this->assertStringNotContainsString('<b>Placki', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_wpis_bez_tekstu_dostaje_autora_i_date_a_nie_wspolny_opis(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 12)->setTime(12, 0));
        $autor = $this->user('zenek', ['display_name' => 'Zenek']);
        $wpis = $this->wpis($autor, null, zdjec: 1);

        $dokument = $this->strona($wpis);

        $this->assertSame('Zdjęcie od Zenek, 12 września 2026 — Kuking', $this->tytul($dokument));
        $this->assertSame('Zdjęcie od Zenek, 12 września 2026', trim($dokument->querySelector('h1')->textContent));
        $this->assertNotSame('Zdjęcie z Kuking', $this->meta($dokument, 'description'));
        $this->assertStringContainsString('Zenek', $this->meta($dokument, 'description'));
    }

    public function test_prywatny_wpis_zostaje_noindex(): void
    {
        $autor = $this->user('iza');
        $wpis = $this->wpis($autor, 'Tajny sernik', widocznosc: Post::VISIBILITY_PRIVATE);

        $this->actingAs($autor);
        $dokument = $this->strona($wpis);

        $this->assertStringContainsString('noindex', $this->meta($dokument, 'robots'));
        auth()->logout();
        $this->assertNotSame(200, $this->get($wpis->url())->getStatusCode(), 'Gość nie może zobaczyć prywatnego wpisu ani jego tytułu.');
    }

    // -----------------------------------------------------------------
    // #1001 — zdjęcie LCP
    // -----------------------------------------------------------------

    public function test_pojedyncze_zdjecie_wpisu_ma_wysoki_priorytet(): void
    {
        $wpis = $this->wpis($this->user('jola'), 'Obiad', zdjec: 1);

        $zdjecia = $this->zdjeciaKarty($this->strona($wpis));

        $this->assertCount(1, $zdjecia);
        $this->assertPriorytet($zdjecia[0]);
        // Bez regresji wymiarów i srcset.
        $this->assertNotSame('', $zdjecia[0]->getAttribute('width') ?? '');
        $this->assertNotSame('', $zdjecia[0]->getAttribute('height') ?? '');
        $this->assertNotNull($zdjecia[0]->getAttribute('srcset'));
    }

    public function test_zwykly_uklad_wielu_zdjec_priorytet_ma_tylko_pierwsze(): void
    {
        $this->assertTylkoPierwszeZPriorytetem(Post::DISPLAY_NORMAL);
    }

    public function test_karuzela_priorytet_ma_tylko_pierwsze_zdjecie(): void
    {
        $this->assertTylkoPierwszeZPriorytetem(Post::DISPLAY_CAROUSEL);
    }

    public function test_kolaz_priorytet_ma_tylko_pierwsze_zdjecie(): void
    {
        $this->assertTylkoPierwszeZPriorytetem(Post::DISPLAY_COLLAGE);
    }

    public function test_wpis_wskazujacy_przepis_nadaje_priorytet_zdjeciu_przepisu(): void
    {
        $autor = $this->user('ula');
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Bigos z cukinii',
            'visibility' => 'public',
            'hero_media_id' => $zdjecie->getKey(),
        ]);
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => null,
        ]);
        // Komentarz zatrzymuje stronę wpisu (bez niego przekierowanie na przepis).
        Comment::factory()->create([
            'author_id' => $this->user('czytelniczka')->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Robiłam, wyszło znakomicie.',
        ]);

        $dokument = $this->strona($wpis);
        $zdjecia = $this->zdjeciaKarty($dokument);

        $this->assertCount(1, $zdjecia);
        $this->assertPriorytet($zdjecia[0]);
        $this->assertStringStartsWith('Z przepisu „Bigos z cukinii”', $this->tytul($dokument));
    }

    public function test_karty_w_strumieniu_zostaja_leniwe(): void
    {
        $this->wpis($this->user('gosia'), 'Obiad w strumieniu', zdjec: 2);

        $html = (string) $this->get(route('discover'))->assertOk()->getContent();
        $dokument = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $zdjecia = iterator_to_array($dokument->querySelectorAll('.post-card img.post-photo'));

        $this->assertNotEmpty($zdjecia, 'Kontrola dodatnia: strumień pokazał zdjęcia wpisu.');
        foreach ($zdjecia as $img) {
            $this->assertSame('lazy', $img->getAttribute('loading'));
            $this->assertNull($img->getAttribute('fetchpriority'));
        }
    }

    // -----------------------------------------------------------------

    private function assertTylkoPierwszeZPriorytetem(string $tryb): void
    {
        $wpis = $this->wpis($this->user(), 'Obiad w kilku odsłonach', zdjec: 3, tryb: $tryb);

        $zdjecia = $this->zdjeciaKarty($this->strona($wpis));

        $this->assertCount(3, $zdjecia, 'Kontrola dodatnia: karta narysowała wszystkie zdjęcia.');
        $this->assertPriorytet($zdjecia[0]);

        foreach (array_slice($zdjecia, 1) as $img) {
            $this->assertSame('lazy', $img->getAttribute('loading'));
            $this->assertNull($img->getAttribute('fetchpriority'));
        }
    }

    private function assertPriorytet(Element $img): void
    {
        $this->assertSame('high', $img->getAttribute('fetchpriority'));
        $this->assertNull($img->getAttribute('loading'), 'Obraz LCP nie może być leniwy.');
    }

    private function wpis(
        User $autor,
        ?string $tresc,
        int $zdjec = 0,
        string $tryb = Post::DISPLAY_NORMAL,
        string $widocznosc = Post::VISIBILITY_PUBLIC,
    ): Post {
        $media = $zdjec > 0
            ? Media::factory()->count($zdjec)->create(['owner_id' => $autor->getKey()])->pluck('id')->all()
            : [];

        return app(PublishPost::class)->handle(
            author: $autor,
            body: $tresc,
            mediaIds: $media,
            visibility: $widocznosc,
            displayMode: $tryb,
        );
    }

    private function strona(Post $wpis): HTMLDocument
    {
        $html = (string) $this->get($wpis->url())->assertOk()->getContent();

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    private function tytul(HTMLDocument $dokument): string
    {
        return $dokument->querySelector('title')->textContent;
    }

    private function meta(HTMLDocument $dokument, string $nazwa): string
    {
        $meta = $dokument->querySelector('meta[name="'.$nazwa.'"], meta[property="'.$nazwa.'"]');
        $this->assertNotNull($meta, 'Brak <meta> '.$nazwa.'.');

        return (string) $meta->getAttribute('content');
    }

    /** @return list<Element> */
    private function zdjeciaKarty(HTMLDocument $dokument): array
    {
        return array_values(iterator_to_array($dokument->querySelectorAll('main .post-card img.post-photo')));
    }
}

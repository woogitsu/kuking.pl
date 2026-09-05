<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Powiększanie zdjęcia po kliknięciu.
 *
 * CO TU JEST NAPRAWDĘ TESTOWANE
 * Nie sam lightbox — nakładki nie da się sprawdzić bez przeglądarki. Testujemy
 * to, co musi być prawdą PRZED uruchomieniem JavaScriptu, bo od tego zależy,
 * czy funkcja działa dla kogoś, komu skrypt się nie wczytał:
 *
 *   1. w HTML jest zwykły `<a href>` do dużego wariantu — czyli kliknięcie
 *      działa bez skryptu i otwiera zdjęcie na osobnej stronie;
 *   2. link mówi, CO SIĘ STANIE (`aria-label`), bo sam tekst alternatywny
 *      zdjęcia tego nie zdradza;
 *   3. nie powstają zagnieżdżone `<a>` tam, gdzie zdjęcie jest już linkiem —
 *      to nieprawidłowy HTML i psuje obsługę klawiaturą.
 *
 * Trzeci punkt to jedyne miejsce, gdzie ta zmiana mogła coś zepsuć, więc ma
 * własny test.
 */
class PowiekszanieZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    private function wpisZeZdjeciem(): Post
    {
        $autor = $this->user('autorka');

        $media = Media::create([
            'owner_id' => $autor->getKey(),
            'disk' => 'public',
            'object_key' => 'incoming/'.$autor->getKey().'/2026/09/'.Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 1024,
            'width' => 1600,
            'height' => 1200,
            'status' => Media::STATUS_READY,
            'alt_text' => 'Rosół w garnku',
            'metadata' => [
                'variants' => [
                    'thumb' => ['key' => 'media/a_thumb.webp', 'width' => 320, 'height' => 240],
                    'feed' => ['key' => 'media/a_feed.webp', 'width' => 960, 'height' => 720],
                    'large' => ['key' => 'media/a_large.webp', 'width' => 1600, 'height' => 1200],
                ],
            ],
        ]);

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'body' => 'Niedzielny rosół.',
        ]);

        $post->media()->attach($media->getKey(), ['position' => 0]);

        return $post;
    }

    public function test_zdjecie_jest_linkiem_do_duzego_wariantu(): void
    {
        $post = $this->wpisZeZdjeciem();

        $html = $this->get(route('posts.show', $post))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $linki = $xpath->query('//a[@data-powieksz]');

        $this->assertGreaterThan(
            0,
            $linki->length,
            'Zdjęcie nie jest linkiem. Bez JavaScriptu nie da się go wtedy powiększyć wcale.',
        );

        $link = $linki->item(0);

        $this->assertStringContainsString(
            'a_large.webp',
            $link->getAttribute('href'),
            'Link prowadzi do innego wariantu niż duży — powiększenie pokazałoby to samo, co miniatura.',
        );

        $this->assertStringContainsString(
            'Powiększ',
            $link->getAttribute('aria-label'),
            'Link nie mówi, co się stanie po kliknięciu. Sam tekst alternatywny zdjęcia tego nie zdradza.',
        );
    }

    public function test_nie_ma_zagniezdzonych_linkow(): void
    {
        // MUSI to być strona renderująca KARTĘ PRZEPISU — tam zdjęcie jest już
        // w środku linku do przepisu i tylko tam może powstać zagnieżdżenie.
        //
        // Pierwsza wersja tego testu sprawdzała stronę wpisu, która kart
        // przepisu w ogóle nie renderuje. Przechodziła zawsze, także po
        // zdjęciu `:zoom="false"` — czyli asercja była pusta i nie pilnowała
        // niczego. Sprawdzone jawnie: po usunięciu zabezpieczenia test dalej
        // był zielony.
        $autor = $this->user('autorka');

        $media = Media::create([
            'owner_id' => $autor->getKey(),
            'disk' => 'public',
            'object_key' => 'incoming/'.$autor->getKey().'/2026/09/'.Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 1024,
            'width' => 1600,
            'height' => 1200,
            'status' => Media::STATUS_READY,
            'alt_text' => 'Zdjęcie przepisu',
            'metadata' => [
                'variants' => [
                    'thumb' => ['key' => 'media/b_thumb.webp', 'width' => 320, 'height' => 240],
                    'feed' => ['key' => 'media/b_feed.webp', 'width' => 960, 'height' => 720],
                    'large' => ['key' => 'media/b_large.webp', 'width' => 1600, 'height' => 1200],
                ],
            ],
        ]);

        Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'title' => 'Rosół z kartą',
            'slug' => 'rosol-z-karta-'.Str::lower(Str::random(6)),
            'hero_media_id' => $media->getKey(),
        ]);

        $html = $this->get(route('profile.show', 'autorka').'?zakladka=przepisy')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'Rosół z kartą',
            $html,
            'Karta przepisu się nie wyrenderowała — test sprawdza co innego, niż zakłada.',
        );

        // NIE parsujemy tu DOM-u — i to jest sedno tego testu.
        //
        // Zagnieżdżone `<a>` to nieprawidłowy HTML, a parsery „naprawiają" go
        // po cichu, zamykając zewnętrzny znacznik. Druga wersja tego testu
        // pytała XPathem o `//a//a` i była zielona ZAWSZE — także wtedy, gdy
        // zagnieżdżenie realnie występowało w wysłanym HTML-u. Sprawdzone
        // jawnie: po zdjęciu zabezpieczenia test dalej przechodził.
        //
        // Skoro przeglądarka naprawia to na własną rękę i w różny sposób,
        // jedynym miejscem, gdzie widać prawdę, jest surowa odpowiedź.
        // Liczymy więc głębokość znaczników sami.
        preg_match_all('~<(/?)a\b~i', $html, $trafienia, PREG_OFFSET_CAPTURE);

        $glebokosc = 0;
        $maksymalna = 0;

        foreach ($trafienia[1] as $znacznik) {
            $glebokosc += $znacznik[0] === '/' ? -1 : 1;
            $maksymalna = max($maksymalna, $glebokosc);
        }

        $this->assertLessThanOrEqual(
            1,
            $maksymalna,
            'W wysłanym HTML-u są zagnieżdżone linki (głębokość '.$maksymalna.'). '
            .'Sprawdź, czy gdzieś nie owinięto <x-photo> linkiem bez ustawienia '
            .':zoom="false". Przeglądarka to „naprawi", ale obsługa klawiaturą '
            .'przestanie być przewidywalna.',
        );
    }

    public function test_strona_ma_gotowa_nakladke_z_przyciskiem_zamknij(): void
    {
        $html = $this->get(route('landing'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $okno = $dom->getElementById('powiekszenie');

        $this->assertNotNull($okno, 'Brak elementu <dialog> na powiększone zdjęcie.');
        $this->assertSame('dialog', $okno->tagName, 'Nakładka nie jest natywnym <dialog>.');

        // AGENTS.md: ikona nigdy sama. „×" bez napisu jest dla części osób
        // nieczytelne, a to jest jedyne wyjście z nakładki obsługiwane myszą.
        $this->assertStringContainsString(
            'Zamknij',
            $okno->textContent,
            'Nakładka nie ma przycisku z napisem „Zamknij".',
        );
    }
}

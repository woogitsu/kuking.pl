<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Models\Post;
use App\Models\Tag;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `#tag` NAPISANY W TREŚCI JEST ODNOŚNIKIEM DO STRONY TAGU (issue #737).
 *
 * SKĄD TO ZGŁOSZENIE
 * Właściciel opublikował wpis „Rolada #rolada #ciasto #nasłodko", kliknął
 * hashtag w treści i nic się nie stało. Tagowanie działało w całości —
 * relacja powstawała, chipsy pod zdjęciem prowadziły na właściwe strony,
 * `/tag/rolada` zwracało 200. Brakowało wyłącznie zamiany `#tag` w treści
 * na odnośnik: `.post-card-body` renderował się jako czysty tekst.
 *
 * CZEGO TEN PLIK PILNUJE — I DLACZEGO AKURAT TEGO
 *
 * 1. ODNOŚNIK POWSTAJE Z RELACJI, NIE Z TEKSTU. Adres złożony z tego, co
 *    ktoś napisał, prowadziłby przy literówce („#roladda") na stronę, której
 *    nie ma. Dlatego jest tu test na słowo z kratką, które tagiem wpisu NIE
 *    jest — musi zostać zwykłym tekstem. Bez niego cały pakiet przeszedłby
 *    także na implementacji linkującej wszystko, co ma `#` (§3b
 *    `docs/PULAPKI_TESTOW.md`).
 *
 * 2. UCIECZKA HTML NIE SŁABNIE. Odnośnik jest DODATKIEM do escapowanego
 *    tekstu, nigdy interpretacją HTML-a autora — stąd test z `<script>`,
 *    cudzysłowem i tagiem obok siebie.
 *
 * 3. SKRÓCONA ZAPOWIEDŹ SIĘ NIE ROZJEŻDŻA. Karta w feedzie pokazuje początek
 *    długiego wpisu (`App\Support\ZapowiedzWpisu`). Tag, który wypadł poza
 *    zapowiedź, nie ma prawa być w niej odnośnikiem — a ten sam tag na
 *    stronie wpisu odnośnikiem być musi.
 *
 * ASERCJE PATRZĄ W TREŚĆ KARTY, NIE W CAŁY DOKUMENT. Chipsy pod zdjęciem
 * prowadzą na te same adresy, więc `assertSee(route('tags.show', ...))` na
 * całym HTML-u przechodziłoby także wtedy, gdyby treść znów była czystym
 * tekstem. Stąd XPath ograniczony do `.post-card-body`.
 */
class TagiWTresciWpisuTest extends TestCase
{
    use RefreshDatabase;

    /** Adresy odnośników stojących W TREŚCI wpisu, w kolejności od góry. */
    private function odnosnikiWTresci(string $html): array
    {
        return array_map(
            static fn (DOMElement $a): array => [$a->textContent, $a->getAttribute('href')],
            $this->tresc($html),
        );
    }

    /** @return list<DOMElement> */
    private function tresc(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);

        $ciala = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' post-card-body ')]");
        $this->assertNotFalse($ciala);
        $this->assertGreaterThan(0, $ciala->length, 'Na stronie nie ma treści wpisu — nie ma czego sprawdzać.');

        $odnosniki = $xpath->query('.//a', $ciala->item(0));
        $this->assertNotFalse($odnosniki);

        return iterator_to_array($odnosniki);
    }

    private function tekstTresci(string $html): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $ciala = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' post-card-body ')]");
        $this->assertNotFalse($ciala);
        $this->assertGreaterThan(0, $ciala->length);

        return (string) $ciala->item(0)?->textContent;
    }

    private function stronaWpisu(Post $post): string
    {
        return (string) $this->actingAs($post->author)
            ->get(route('posts.show', $post))
            ->assertOk()
            ->getContent();
    }

    /** Strona główna widziana przez autora — feed zawiera własne wpisy. */
    private function glowna(Post $post): string
    {
        return (string) $this->actingAs($post->author)
            ->get(route('home'))
            ->assertOk()
            ->getContent();
    }

    // -----------------------------------------------------------------
    // 1. Odnośnik powstaje z relacji wpisu
    // -----------------------------------------------------------------

    public function test_tag_przypiety_do_wpisu_jest_odnosnikiem_w_tresci(): void
    {
        $post = app(PublishPost::class)->handle($this->user('basia'), 'Rolada #rolada #ciasto #nasłodko');

        $this->assertSame(
            ['rolada', 'ciasto', 'nasłodko'],
            $post->fresh()->tags->pluck('normalized_name')->all(),
            'Zgłoszenie mówiło, że tagowanie działa — jeśli relacja nie powstała, to inny błąd niż ten.',
        );

        $this->assertSame([
            ['#rolada', route('tags.show', 'rolada')],
            ['#ciasto', route('tags.show', 'ciasto')],
            // Polskie znaki: widoczna etykieta zostaje taka, jak napisał
            // człowiek, a adres idzie po slugu bez diakrytyków.
            ['#nasłodko', route('tags.show', 'naslodko')],
        ], $this->odnosnikiWTresci($this->stronaWpisu($post)));
    }

    public function test_slowo_z_kratka_ktore_nie_jest_tagiem_wpisu_zostaje_tekstem(): void
    {
        // Relacja, nie tekst: przypinamy JEDEN tag, a w treści stoją trzy
        // słowa z kratką. Literówka i rocznik mają zostać zwykłym tekstem,
        // bo `/tag/roladda` i `/tag/2024` to strony, których nie ma.
        $post = Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'body' => 'Rolada #rolada, nie #roladda, pieczona w #2024',
        ]);
        $post->tags()->attach(Tag::factory()->create([
            'name' => 'rolada', 'normalized_name' => 'rolada', 'slug' => 'rolada',
        ]), ['position' => 0, 'dodany_recznie' => false]);

        $html = $this->stronaWpisu($post);

        $this->assertSame(
            [['#rolada', route('tags.show', 'rolada')]],
            $this->odnosnikiWTresci($html),
        );
        $this->assertStringContainsString('#roladda', $this->tekstTresci($html));
        $this->assertStringContainsString('#2024', $this->tekstTresci($html));
    }

    public function test_tag_ukryty_nie_jest_odnosnikiem_ani_chipsem(): void
    {
        // Ukryta relacja zostaje w modelu, ale publiczna karta nie ma prawa
        // do niej linkować — ani chipsem, ani z treści.
        $post = Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'body' => 'Rolada #rolada',
        ]);
        $post->tags()->attach(Tag::factory()->hidden()->create([
            'name' => 'rolada', 'normalized_name' => 'rolada', 'slug' => 'rolada',
        ]), ['position' => 0, 'dodany_recznie' => false]);

        $html = $this->stronaWpisu($post);

        $this->assertSame([], $this->odnosnikiWTresci($html));
        $this->assertStringNotContainsString(route('tags.show', 'rolada'), $html);
    }

    // -----------------------------------------------------------------
    // 2. Ucieczka HTML
    // -----------------------------------------------------------------

    public function test_html_autora_pozostaje_tekstem_obok_odnosnika_do_tagu(): void
    {
        $post = app(PublishPost::class)->handle(
            $this->user('basia'),
            '<script>alert("x")</script> "cytat" #rolada <img src=x onerror=alert(1)>',
        );

        $html = $this->stronaWpisu($post);

        $this->assertSame(
            [['#rolada', route('tags.show', 'rolada')]],
            $this->odnosnikiWTresci($html),
            'Odnośnik do tagu ma powstać mimo HTML-a obok — i tylko on.',
        );

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $skrypty = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' post-card-body ')]//script");
        $obrazki = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' post-card-body ')]//img");
        $this->assertNotFalse($skrypty);
        $this->assertNotFalse($obrazki);
        $this->assertSame(0, $skrypty->length, 'HTML autora został zinterpretowany jako HTML.');
        $this->assertSame(0, $obrazki->length, 'HTML autora został zinterpretowany jako HTML.');

        $tekst = $this->tekstTresci($html);
        $this->assertStringContainsString('<script>alert("x")</script>', $tekst);
        $this->assertStringContainsString('"cytat"', $tekst);
    }

    // -----------------------------------------------------------------
    // 3. Skrócona zapowiedź
    // -----------------------------------------------------------------

    public function test_tag_poza_skrocona_zapowiedzia_nie_jest_w_niej_odnosnikiem(): void
    {
        // Dziewięć wierszy przy progu ośmiu: zapowiedź kończy się na ósmym,
        // a `#ciasto` z dziewiątego w niej nie istnieje. Krótko znakami —
        // żeby to wiersze, nie znaki, decydowały o skróceniu.
        $tresc = "Rolada #rolada\n".implode("\n", array_fill(0, 7, 'Warstwa.'))."\nNa koniec #ciasto";
        $this->assertLessThan(400, mb_strlen($tresc));

        $post = app(PublishPost::class)->handle($this->user('basia'), $tresc);

        $zapowiedz = $this->odnosnikiWTresci($this->glowna($post));
        $this->assertSame([['#rolada', route('tags.show', 'rolada')]], $zapowiedz);

        // Kontrola dodatnia w tym samym teście: na stronie wpisu, gdzie nic
        // się nie skraca, `#ciasto` odnośnikiem JEST. Bez tego pierwsza
        // asercja przechodziłaby także wtedy, gdyby odnośniki zniknęły
        // w ogóle.
        $this->assertSame([
            ['#rolada', route('tags.show', 'rolada')],
            ['#ciasto', route('tags.show', 'ciasto')],
        ], $this->odnosnikiWTresci($this->stronaWpisu($post)));
    }
}

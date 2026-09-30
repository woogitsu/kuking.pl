<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kanaly\TresciKanalu;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Kanały Atom profilu, tagu i publicznego zeszytu (#2227, D-333).
 *
 * Każdy test czyta kanał PARSEREM (`DOMDocument` + XPath w przestrzeni
 * nazw Atom), nie szukaniem napisów: kanał z rozjechanym XML-em potrafi
 * zawierać wszystkie oczekiwane fragmenty i mimo to być odrzucony przez
 * czytnik w całości (ta sama uwaga co w `MapaStronyTest`).
 *
 * Asercje „czegoś nie ma” mają obok siebie kontrolę dodatnią z tego samego
 * kanału (docs/PULAPKI_TESTOW.md §4): pusty kanał przeszedłby każdą z nich.
 */
class KanalyAtomTest extends TestCase
{
    use RefreshDatabase;

    private const ATOM = 'http://www.w3.org/2005/Atom';

    // ── pomocnicze ──────────────────────────────────────────────────────

    private function atom(TestResponse $odpowiedz): DOMXPath
    {
        $odpowiedz->assertOk();
        $this->assertSame('application/atom+xml; charset=utf-8', $odpowiedz->headers->get('Content-Type'));

        $poprzedni = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $wczytany = $dom->loadXML((string) $odpowiedz->getContent());
        $bledy = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($poprzedni);

        $this->assertTrue($wczytany, 'Kanał nie jest poprawnym XML-em.');
        $this->assertSame([], $bledy, 'Parser XML zgłosił zastrzeżenia do kanału.');
        $this->assertSame('feed', $dom->documentElement->localName);
        $this->assertSame(self::ATOM, $dom->documentElement->namespaceURI, 'Korzeń musi być w przestrzeni nazw Atom 1.0.');

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('a', self::ATOM);

        $this->wymaganeElementyAtom($xpath);

        return $xpath;
    }

    /** RFC 4287 §4.1.1 i §4.1.2 — elementy obowiązkowe kanału i pozycji. */
    private function wymaganeElementyAtom(DOMXPath $x): void
    {
        foreach (['id', 'title', 'updated'] as $element) {
            $this->assertSame(1, $x->query("/a:feed/a:{$element}")->length, "Kanał musi mieć dokładnie jedno <{$element}>.");
            $this->assertNotSame('', trim($x->evaluate("string(/a:feed/a:{$element})")), "<{$element}> kanału nie może być puste.");
        }
        $this->assertSame(1, $x->query('/a:feed/a:link[@rel="self"]')->length, 'Kanał musi wskazywać sam siebie (rel="self").');
        $this->assertSame(1, $x->query('/a:feed/a:link[@rel="alternate"][@type="text/html"]')->length);
        $this->assertSame(1, $x->query('/a:feed/a:author/a:name')->length, 'Kanał musi mieć autora (RFC 4287 §4.1.1).');
        $this->assertDataAtom($x->evaluate('string(/a:feed/a:updated)'));

        foreach ($x->query('/a:feed/a:entry') as $pozycja) {
            foreach (['id', 'title', 'updated'] as $element) {
                $this->assertSame(1, $x->query("a:{$element}", $pozycja)->length, "Pozycja musi mieć dokładnie jedno <{$element}>.");
            }
            $this->assertStringStartsWith('urn:uuid:', $x->evaluate('string(a:id)', $pozycja));
            $this->assertSame(1, $x->query('a:link[@rel="alternate"]', $pozycja)->length, 'Pozycja bez treści musi mieć link rel="alternate".');
            $this->assertNotSame('', trim($x->evaluate('string(a:author/a:name)', $pozycja)));
            $this->assertDataAtom($x->evaluate('string(a:updated)', $pozycja));
        }
    }

    private function assertDataAtom(string $data): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $data, 'Data musi być w formacie RFC 3339.');
    }

    /** @return list<string> tytuły pozycji w kolejności kanału */
    private function tytuly(DOMXPath $x): array
    {
        $tytuly = [];
        foreach ($x->query('/a:feed/a:entry/a:title') as $wezel) {
            $tytuly[] = $wezel->textContent;
        }

        return $tytuly;
    }

    private function wpis(User $autor, string $tresc, array $atrybuty = []): Post
    {
        return Post::factory()->create(['author_id' => $autor->getKey(), 'body' => $tresc, ...$atrybuty]);
    }

    private function przepis(User $autor, string $tytul, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
            ...$atrybuty,
        ]);
    }

    private function zeszyt(User $wlasciciel, string $nazwa, string $widocznosc = 'public'): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
    }

    // ── profil ──────────────────────────────────────────────────────────

    public function test_kanal_profilu_ma_tylko_wpisy_publiczne_od_najnowszego(): void
    {
        $basia = $this->user('basiakanal');
        $this->wpis($basia, 'Pierogi ruskie na obiad', ['published_at' => now()->subDays(3)]);
        $this->wpis($basia, 'Zupa szczawiowa z jajkiem', ['published_at' => now()->subDay()]);
        $this->wpis($basia, 'Tylko dla obserwujących', ['published_at' => now()])->forceFill(['visibility' => Post::VISIBILITY_FOLLOWERS])->save();
        $this->wpis($basia, 'Prywatna notatka', ['visibility' => Post::VISIBILITY_PRIVATE]);
        Post::factory()->draft()->create(['author_id' => $basia->getKey(), 'body' => 'Szkic bez publikacji']);
        $this->wpis($basia, 'Ukryty przez moderację')->forceFill(['status' => Post::STATUS_HIDDEN])->save();
        // Zapowiedź PRYWATNEGO przepisu — wpis jest `public`, przepis nie.
        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => null,
            'recipe_id' => $this->przepis($basia, 'Sekretny sernik', ['visibility' => 'private'])->getKey(),
        ]);

        $x = $this->atom($this->get(route('kanaly.profil', 'basiakanal')));

        $this->assertSame(['Zupa szczawiowa z jajkiem', 'Pierogi ruskie na obiad'], $this->tytuly($x));
        $this->assertStringNotContainsString('Sekretny sernik', $x->document->saveXML());
        $this->assertSame(route('profile.show', 'basiakanal'), $x->evaluate('string(/a:feed/a:link[@rel="alternate"]/@href)'));
        $this->assertSame(route('kanaly.profil', 'basiakanal'), $x->evaluate('string(/a:feed/a:link[@rel="self"]/@href)'));
    }

    public function test_kanal_profilu_konta_zbanowanego_i_kasowanego_to_404(): void
    {
        $zbanowana = $this->user('kanalzbanowana');
        $this->wpis($zbanowana, 'Wpis sprzed bana');
        $kasowana = $this->user('kanalkasowana');
        $this->wpis($kasowana, 'Wpis sprzed prośby o usunięcie');

        // Kontrola dodatnia: przed zmianą stanu konta kanał działa.
        $this->atom($this->get(route('kanaly.profil', 'kanalzbanowana')));

        $zbanowana->ban();
        $kasowana->markForDeletion();

        $this->get(route('kanaly.profil', 'kanalzbanowana'))->assertNotFound()->assertDontSee('Wpis sprzed bana');
        $this->get(route('kanaly.profil', 'kanalkasowana'))->assertNotFound();
        $this->get(route('kanaly.profil', 'nie-ma-takiej-osoby'))->assertNotFound();
    }

    public function test_kanal_nie_wycieka_tresci_zalogowanemu_autorowi(): void
    {
        // Kanał jest zawsze oczami gościa — także gdy otwiera go sam autor.
        $autorka = $this->user('kanalautorka');
        $this->wpis($autorka, 'Publiczny wpis autorki');
        $this->wpis($autorka, 'Prywatny wpis autorki', ['visibility' => Post::VISIBILITY_PRIVATE]);

        $x = $this->atom($this->actingAs($autorka)->get(route('kanaly.profil', 'kanalautorka')));

        $this->assertSame(['Publiczny wpis autorki'], $this->tytuly($x));
    }

    public function test_limit_pozycji_w_kanale(): void
    {
        $autor = $this->user('kanallimit');
        for ($i = 1; $i <= TresciKanalu::LIMIT + 3; $i++) {
            $this->wpis($autor, "Wpis numer {$i}", ['published_at' => now()->subMinutes(100 - $i)]);
        }

        $tytuly = $this->tytuly($this->atom($this->get(route('kanaly.profil', 'kanallimit'))));

        $this->assertCount(TresciKanalu::LIMIT, $tytuly);
        // Zostają NAJNOWSZE — najstarsze trzy odpadają.
        $this->assertSame('Wpis numer '.(TresciKanalu::LIMIT + 3), $tytuly[0]);
        $this->assertNotContains('Wpis numer 3', $tytuly);
        $this->assertContains('Wpis numer 4', $tytuly);
    }

    public function test_pusty_kanal_jest_poprawnym_atomem(): void
    {
        $this->user('kanalpusty');

        $x = $this->atom($this->get(route('kanaly.profil', 'kanalpusty')));

        $this->assertSame(0, $x->query('/a:feed/a:entry')->length);
    }

    public function test_znaki_specjalne_i_sterujace_nie_psuja_xml(): void
    {
        $autor = $this->user('kanalznaki');
        $autor->profile->forceFill(['display_name' => 'Ala & "Ola" <kucharki>'])->save();
        $this->wpis($autor, "Ser <b>żółty</b> & \"masło\" ]]> \x01koniec\nDruga linia");

        $x = $this->atom($this->get(route('kanaly.profil', 'kanalznaki')));
        $surowy = $x->document->saveXML();

        $this->assertSame('Ser <b>żółty</b> & "masło" ]]> koniec', $x->evaluate('string(/a:feed/a:entry/a:title)'));
        $this->assertSame("Ser <b>żółty</b> & \"masło\" ]]> koniec\nDruga linia", $x->evaluate('string(/a:feed/a:entry/a:summary)'));
        $this->assertSame('Ala & "Ola" <kucharki>', $x->evaluate('string(/a:feed/a:entry/a:author/a:name)'));
        $this->assertSame(0, $x->query('//b')->length, 'Znacznik z treści nie może stać się elementem XML.');
        $this->assertStringContainsString('&lt;b&gt;', $surowy);
    }

    public function test_zdjecie_tylko_przez_publiczny_adres_i_tylko_widoczne(): void
    {
        $autor = $this->user('kanalzdjecia');
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $wpis = $this->wpis($autor, 'Wpis ze zdjęciem');
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 1]);
        $bezGotowego = $this->wpis($autor, 'Wpis ze zdjęciem w trakcie', ['published_at' => now()->subHour()]);
        $bezGotowego->media()->attach(Media::factory()->pending()->create(['owner_id' => $autor->getKey()])->getKey(), ['position' => 1]);

        $x = $this->atom($this->get(route('kanaly.profil', 'kanalzdjecia')));

        $zalaczniki = $x->query('/a:feed/a:entry/a:link[@rel="enclosure"]');
        $this->assertSame(1, $zalaczniki->length, 'Zdjęcie gotowe — jest; niegotowe — nie ma.');
        $zalacznik = $zalaczniki->item(0);
        if (! $zalacznik instanceof DOMElement) {
            $this->fail('Załącznik kanału nie jest elementem XML.');
        }
        $adres = $zalacznik->getAttribute('href');
        $this->assertStringStartsWith(url('/zdjecia/'.$zdjecie->getKey().'/'), $adres, 'Zdjęcie idzie trasą aplikacji (DostepDoZdjecia), nie adresem bucketu.');
        $this->assertStringNotContainsString($zdjecie->object_key, $x->document->saveXML());
    }

    public function test_kanal_nie_ujawnia_adresu_email(): void
    {
        $autor = $this->user('kanalemail');
        $this->wpis($autor, 'Zwykły wpis');

        $odpowiedz = $this->get(route('kanaly.profil', 'kanalemail'));
        $this->atom($odpowiedz);

        $this->assertStringContainsString('Zwykły wpis', (string) $odpowiedz->getContent());
        $this->assertStringNotContainsString((string) $autor->email, (string) $odpowiedz->getContent());
        $this->assertStringNotContainsString('<email>', (string) $odpowiedz->getContent());
    }

    // ── nagłówki cache ─────────────────────────────────────────────────

    public function test_etag_daje_304_a_last_modified_nie_jest_wysylany(): void
    {
        $autor = $this->user('kanalcache');
        $wpis = $this->wpis($autor, 'Wpis do cache', ['published_at' => now()->subDay(), 'updated_at' => now()->subDay()]);

        $pierwsza = $this->get(route('kanaly.profil', 'kanalcache'));
        $this->atom($pierwsza);
        $etag = (string) $pierwsza->headers->get('ETag');

        $this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{64}"$/', $etag);
        $this->assertFalse($pierwsza->headers->has('Last-Modified'), 'Last-Modified nie zmienia się, gdy pozycja znika — kanał go nie wysyła.');
        $this->assertSame('no-cache, private', $pierwsza->headers->get('Cache-Control'));
        $this->assertSame([], $pierwsza->headers->getCookies(), 'Kanał nie zakłada sesji ani nie stawia ciasteczek.');

        $this->get(route('kanaly.profil', 'kanalcache'), ['If-None-Match' => $etag])
            ->assertStatus(304)
            ->assertContent('');
        // Regresja (audyt): samo `If-Modified-Since` z daleka w przyszłości
        // nie może dać 304 — dawniej czytnik po zniknięciu pozycji dostawał
        // 304 i dalej pokazywał wycofaną treść.
        $this->get(route('kanaly.profil', 'kanalcache'), ['If-Modified-Since' => now()->addYear()->toRfc7231String()])
            ->assertOk()
            ->assertSee('Wpis do cache');

        // Pozycja znika (ukryta moderacją) — pytanie tylko po dacie dostaje świeżą treść.
        $wpis->forceFill(['updated_at' => now()->subDays(2)])->save();
        $wpis->delete();
        $this->get(route('kanaly.profil', 'kanalcache'), ['If-Modified-Since' => now()->toRfc7231String()])
            ->assertOk()
            ->assertDontSee('Wpis do cache');

        // Nowy wpis zmienia ETag — stary już nie pasuje.
        $this->wpis($autor, 'Świeży wpis');
        $this->get(route('kanaly.profil', 'kanalcache'), ['If-None-Match' => $etag])->assertOk()->assertSee('Świeży wpis');
    }

    public function test_cache_brzegu_idzie_za_polityka_html_goscia(): void
    {
        $this->user('kanalbrzeg');
        config(['kuking.html_cache.edge_seconds' => 120]);

        $this->get(route('kanaly.profil', 'kanalbrzeg'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=120');

        // Górna granica 300 s z `PublicznyHtmlGoscia::MAKS_SEKUND`.
        config(['kuking.html_cache.edge_seconds' => 99999]);
        $this->get(route('kanaly.profil', 'kanalbrzeg'))
            ->assertHeader('Cache-Control', 'max-age=0, public, s-maxage=300');
    }

    // ── tag ─────────────────────────────────────────────────────────────

    public function test_kanal_tagu_ma_tylko_publiczne_wpisy_aktywnych_autorow(): void
    {
        $tag = Tag::factory()->create(['name' => 'Zupy']);
        $ala = $this->user('kanaltagala');
        $zbanowany = $this->user('kanaltagban');
        $widoczny = $this->wpis($ala, 'Żurek na zakwasie', ['published_at' => now()->subHour()]);
        $nowszy = $this->wpis($ala, 'Barszcz czerwony');
        $prywatny = $this->wpis($ala, 'Prywatna zupa', ['visibility' => Post::VISIBILITY_PRIVATE]);
        $odZbanowanego = $this->wpis($zbanowany, 'Zupa zbanowanego');
        $bezTagu = $this->wpis($ala, 'Wpis bez tego tagu');
        foreach ([$widoczny, $nowszy, $prywatny, $odZbanowanego] as $wpis) {
            $wpis->tags()->attach($tag->getKey());
        }
        $zbanowany->ban();

        $x = $this->atom($this->get(route('kanaly.tag', $tag->slug)));

        $this->assertSame(['Barszcz czerwony', 'Żurek na zakwasie'], $this->tytuly($x));
        $this->assertNotContains($bezTagu->body, $this->tytuly($x));
    }

    public function test_kanal_tagu_ukrytego_to_404_a_scalonego_301(): void
    {
        $ukryty = Tag::factory()->create(['name' => 'Ukryty']);
        $ukryty->forceFill(['status' => Tag::STATUS_HIDDEN])->save();
        $kanoniczny = Tag::factory()->create(['name' => 'Kanoniczny']);
        $scalony = Tag::factory()->create(['name' => 'Scalony']);
        $scalony->forceFill(['status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $kanoniczny->getKey()])->save();

        $this->get(route('kanaly.tag', $ukryty->slug))->assertNotFound();
        $this->get(route('kanaly.tag', 'nie-ma-takiego-tagu'))->assertNotFound();
        $this->get(route('kanaly.tag', $scalony->slug))->assertStatus(301)->assertRedirect(route('kanaly.tag', $kanoniczny->slug));
        $this->atom($this->get(route('kanaly.tag', $kanoniczny->slug)));
    }

    // ── zeszyt ─────────────────────────────────────────────────────────

    public function test_kanal_zeszytu_publicznego_bez_tresci_prywatnych_i_notatek(): void
    {
        $wlascicielka = $this->user('kanalzeszyt');
        $zeszyt = $this->zeszyt($wlascicielka, 'Niedzielne obiady');
        $starszy = $this->przepis($wlascicielka, 'Rosół babci Hani');
        $prywatny = $this->przepis($wlascicielka, 'Sekretny sernik', ['visibility' => 'private']);
        $wpis = $this->wpis($this->user('kanalzeszytinna'), 'Kotlet od sąsiadki');
        $zeszyt->recipes()->attach($starszy->getKey(), ['created_at' => now()->subDays(2), 'note' => 'Notatka tylko dla mnie']);
        $zeszyt->recipes()->attach($prywatny->getKey(), ['created_at' => now()->subDay()]);
        $zeszyt->posts()->attach($wpis->getKey(), ['created_at' => now()]);

        $x = $this->atom($this->get(route('kanaly.zeszyt', $zeszyt)));

        // Chronologia po dacie dodania do zeszytu, jak na ekranie zeszytu.
        $this->assertSame(['Kotlet od sąsiadki', 'Rosół babci Hani'], $this->tytuly($x));
        $xml = $x->document->saveXML();
        $this->assertStringNotContainsString('Sekretny sernik', $xml);
        $this->assertStringNotContainsString('Notatka tylko dla mnie', $xml);
        $this->assertSame(route('recipes.show', $starszy->slug), $x->evaluate('string(/a:feed/a:entry[2]/a:link[@rel="alternate"]/@href)'));
    }

    public function test_kanal_prywatnego_domyslnego_i_zbanowanego_zeszytu_to_404(): void
    {
        $wlascicielka = $this->user('kanalzeszytpryw');
        $prywatny = $this->zeszyt($wlascicielka, 'Tylko moje', 'private');
        $domyslny = $wlascicielka->defaultCollection();
        $zbanowanej = $this->zeszyt($this->user('kanalzeszytban'), 'Zeszyt po banie');
        $publiczny = $this->zeszyt($wlascicielka, 'Dla wszystkich');

        // Kontrola dodatnia: publiczny zeszyt tej samej osoby ma kanał.
        $this->atom($this->get(route('kanaly.zeszyt', $publiczny)));
        $this->atom($this->get(route('kanaly.zeszyt', $zbanowanej)));

        $zbanowanej->owner->ban();

        $this->get(route('kanaly.zeszyt', $prywatny))->assertNotFound()->assertDontSee('Tylko moje');
        // Właściciel też dostaje 404 — kanał nie zna sesji.
        $this->actingAs($wlascicielka)->get(route('kanaly.zeszyt', $prywatny))->assertNotFound();
        $this->get(route('kanaly.zeszyt', $domyslny))->assertNotFound();
        $this->get(route('kanaly.zeszyt', $zbanowanej))->assertNotFound();
        $this->get('/zeszyt/to-nie-uuid/kanal')->assertNotFound();
        $this->get(route('kanaly.zeszyt', (string) Str::uuid()))->assertNotFound();
    }

    // ── rel="alternate" na stronach ─────────────────────────────────────

    public function test_strony_wskazuja_swoj_kanal(): void
    {
        $osoba = $this->user('kanalalternate');
        $tag = Tag::factory()->create(['name' => 'Ciasta']);
        $publiczny = $this->zeszyt($osoba, 'Ciasta mamy');
        $prywatny = $this->zeszyt($osoba, 'Moje próby', 'private');

        foreach ([
            [route('profile.show', 'kanalalternate'), route('kanaly.profil', 'kanalalternate')],
            [route('tags.show', $tag), route('kanaly.tag', $tag->slug)],
            [route('collections.show', $publiczny), route('kanaly.zeszyt', $publiczny)],
        ] as [$strona, $kanal]) {
            $html = (string) $this->get($strona)->assertOk()->getContent();
            $glowa = Str::before($html, '</head>');
            $this->assertStringContainsString('rel="alternate" type="application/atom+xml"', $glowa, "Brak rel=alternate na {$strona}");
            $this->assertStringContainsString('href="'.$kanal.'"', $glowa, "Zły adres kanału na {$strona}");
        }

        // Prywatny zeszyt: właściciel stronę widzi, ale kanału (404) nie wskazujemy.
        $html = (string) $this->actingAs($osoba)->get(route('collections.show', $prywatny))->assertOk()->getContent();
        $this->assertStringNotContainsString('application/atom+xml', $html);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\TagFeed;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Feed tagów gubił dwie kolumny przepisu — i nic o tym nie mówiło.
 *
 * KLASA BŁĘDU, NIE JEDEN EKRAN. `with('rel:kolumny')` nie jest deklaracją
 * „te kolumny mnie interesują", tylko CIĘCIEM: kolumna spoza listy wraca jako
 * `null`, bez wyjątku, bez ostrzeżenia i bez śladu w dzienniku. Dwa skutki,
 * oba nieme:
 *
 * 1. `hero_media_id` poza selektem → `$post->recipe->heroMedia` doładowuje się
 *    po kluczu obcym, którego w modelu NIE MA, więc oddaje `null` → warunek
 *    `@if($post->media->isEmpty() && $post->recipe?->heroMedia)` w
 *    `post-card.blade.php` jest fałszywy → ZDJĘCIE PO PROSTU ZNIKA. Wpis
 *    wskazujący przepis (issue #368) nie ma własnych zdjęć, więc zostaje po
 *    nim sam tytuł.
 * 2. `visibility` poza selektem → `$post->recipe?->visibility ?? $post->visibility`
 *    schodzi do widoczności WPISU, a ta przy wpisie wskazującym przepis jest
 *    na stałe `public` (`WpisWskazujacyPrzepis::dopisz()`) → karta pisze
 *    autorowi „publicznie" pod przepisem, który widzą wyłącznie jego
 *    obserwujący.
 *
 * `FollowingFeed`, `DiscoverFeed` i `DailyBoard` biorą obie kolumny
 * i doładowują `recipe.heroMedia` od issue #368. `TagFeed` jako jedyny
 * z czterech został przy `recipe:id,title,slug`.
 *
 * DOBÓR DANYCH JEST CZĘŚCIĄ TESTU (docs/PULAPKI_TESTOW.md §1b i §4).
 * Asercje nie idą po całym dokumencie — tytuł przepisu stoi też w `<title>`
 * i w `<meta>`, a słowo „publicznie" pada pod KAŻDYM publicznym wpisem
 * w strumieniu. Każda asercja celuje więc w JEDNĄ kartę, wyciętą z `<main>`
 * po identyfikatorze wpisu. Do asercji „karta NIE mówi »publicznie«"
 * dołożona jest kontrola dodatnia: druga karta w tym samym strumieniu, pod
 * którą to słowo stać MA.
 *
 * DLACZEGO WIDZEM JEST AUTOR. `Post::scopeZWidocznymPrzepisem()` przepuszcza
 * autorowi własną treść niezależnie od widoczności („poprawne dane nigdy nie
 * znikają") — i to jest jedyne miejsce, w którym ktokolwiek ogląda w strumieniu
 * wpis do przepisu „tylko dla obserwujących". Czyli dokładnie ten człowiek,
 * któremu karta kłamała.
 */
class FeedTagowNieGubiKolumnPrzepisuTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    public function test_feed_tagow_pokazuje_zdjecie_przepisu_pod_wpisem_ktory_wlasnych_zdjec_nie_ma(): void
    {
        [$basia, $wpisPrzepisu, $wpisZwykly] = $this->strumienTagow();

        $karta = $this->kartaWpisu($this->actingAs($basia)->get(route('home'))->assertOk()->getContent(), $wpisPrzepisu);

        $this->assertStringContainsString(
            'Zdjęcie do przepisu: Bigos z kapusty kiszonej',
            $karta,
            'Wpis wskazujący przepis stoi w feedzie tagów bez zdjęcia głównego przepisu — '
            .'zawężenie kolumn zgubiło `hero_media_id`, więc relacja `heroMedia` oddała `null`.',
        );

        // KONTROLA DODATNIA dla samego mechanizmu zdjęć na tym ekranie:
        // zwykły wpis obok MA własne zdjęcie i ono się rysuje. Bez tego
        // asercja wyżej mogłaby oblewać z powodu, który ze zdjęciem przepisu
        // nie ma nic wspólnego (wyłączony komponent, pusty `<main>`).
        $this->assertStringContainsString(
            'Zdjęcie własne z niedzielnego obiadu',
            $this->kartaWpisu($this->actingAs($basia)->get(route('home'))->getContent(), $wpisZwykly),
            'Zwykły wpis też nie pokazał swojego zdjęcia — to nie jest usterka zawężenia kolumn.',
        );
    }

    public function test_feed_tagow_nie_pisze_publicznie_pod_przepisem_tylko_dla_obserwujacych(): void
    {
        [$basia, $wpisPrzepisu, $wpisZwykly] = $this->strumienTagow();

        $html = $this->actingAs($basia)->get(route('home'))->assertOk()->getContent();

        $kartaPrzepisu = $this->kartaWpisu($html, $wpisPrzepisu);
        $kartaZwykla = $this->kartaWpisu($html, $wpisZwykly);

        $this->assertStringContainsString(
            'Tylko dla obserwujących',
            $kartaPrzepisu,
            'Karta nie oddała widoczności PRZEPISU — zawężenie kolumn zgubiło `visibility`.',
        );

        // KONTROLA DODATNIA do asercji negatywnej niżej (pułapka §4):
        // słowo „publicznie" w ogóle na tym ekranie pada, tylko pod inną kartą.
        $this->assertStringContainsString(
            'publicznie',
            $kartaZwykla,
            'Pod publicznym wpisem nie ma plakietki widoczności — asercja negatywna niżej '
            .'przeszłaby wtedy dlatego, że tego napisu nie ma NIGDZIE.',
        );

        $this->assertStringNotContainsString(
            'publicznie',
            $kartaPrzepisu,
            'Karta napisała „publicznie" pod przepisem widocznym tylko dla obserwujących.',
        );
    }

    /**
     * `recipe.heroMedia` doładowane, a nie dociągane per wpis.
     *
     * TO JEST DRUGA POŁOWA POPRAWKI i pilnuje jej OSOBNY test, bo psuje się
     * osobno: same kolumny w selekcie wystarczą, żeby zdjęcie było widać —
     * brakujące doładowanie relacji nie gubi niczego, tylko dokłada jedno
     * zapytanie NA KAŻDY wpis. Zmierzone: po zdjęciu `'recipe.heroMedia'`
     * z `TagFeed` oba testy wyżej dalej przechodziły.
     *
     * METODA jest ta sama co w `MiniaturyBezWachlarzaZapytanTest` (audyt N03):
     * nie „ile zapytań wypada", tylko „czy liczba ROŚNIE z liczbą wpisów".
     * Próg na sztywno zestarzałby się przy pierwszej uzasadnionej zmianie.
     *
     * Mierzymy sam strumień, a nie `/home`: na tamtym ekranie stoi jeszcze
     * tablica dnia i szyna zeszytu, więc dołożenie wpisów zmieniałoby liczbę
     * zapytań także z powodów, które z tą poprawką nie mają nic wspólnego.
     * Pętla niżej robi dokładnie to, co karta wpisu w `post-card.blade.php`.
     */
    public function test_feed_tagow_nie_dociaga_zdjecia_przepisu_osobnym_zapytaniem_na_kazdy_wpis(): void
    {
        $tag = Tag::create(['slug' => 'obiady', 'name' => 'Obiady', 'normalized_name' => 'obiady']);

        $basia = $this->user('basia');
        $basia->followedTags()->attach($tag->getKey(), ['created_at' => now()]);

        $this->wpisyDoPrzepisow($basia, $tag, 2);
        $this->assertSame(2, Post::query()->whereNotNull('recipe_id')->count());
        $maloZapytan = $this->policzZapytaniaStrumienia($basia);

        // Czysty start dla drugiej próby — inaczej mierzylibyśmy 2 + 8,
        // a nie 8 (ten sam zabieg co w `MiniaturyBezWachlarzaZapytanTest`).
        Post::query()->forceDelete();
        Recipe::query()->forceDelete();

        $this->wpisyDoPrzepisow($basia, $tag, 8);
        $this->assertSame(8, Post::query()->whereNotNull('recipe_id')->count());
        $duzoZapytan = $this->policzZapytaniaStrumienia($basia);

        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą wpisów wskazujących przepis (N+1): {$maloZapytan} przy 2 wpisach, "
            ."{$duzoZapytan} przy 8. Zdjęcie przepisu jest dociągane osobno dla każdej karty.",
        );
    }

    // -----------------------------------------------------------------
    // Dane
    // -----------------------------------------------------------------

    /** Wpisy wskazujące przepis — każdy z tagiem i ze zdjęciem głównym przepisu. */
    private function wpisyDoPrzepisow(User $autor, Tag $tag, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $przepis = app(PublishRecipe::class)->handle(
                author: $autor,
                attributes: ['title' => 'Bigos numer '.$i, 'visibility' => 'public', 'source_type' => 'own'],
                ingredients: [['text' => 'kapusta kiszona']],
                steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
                publish: true,
            );

            $przepis->forceFill([
                'hero_media_id' => Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
            ])->save();

            Post::query()
                ->where('recipe_id', $przepis->getKey())
                ->firstOrFail()
                ->tags()
                ->attach($tag->getKey(), ['position' => 0]);
        }
    }

    /**
     * Zapytania wykonane przez strumień tagów RAZEM z tym, po co sięga karta
     * wpisu — czyli ze zdjęciem głównym przepisu.
     */
    private function policzZapytaniaStrumienia(User $widz): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        foreach (app(TagFeed::class)->paginate($widz->fresh())->items() as $wpis) {
            $wpis->recipe?->heroMedia;
        }

        return $ile;
    }

    /**
     * Strumień tagów z dwiema kartami: wpisem wskazującym przepis „tylko dla
     * obserwujących" i zwykłym wpisem publicznym z własnym zdjęciem.
     *
     * Basia nikogo nie obserwuje, więc `FeedController::home()` wybiera feed
     * tagów, a nie feed obserwowanych ani „Świeżo z Kuking".
     *
     * @return array{0: User, 1: Post, 2: Post}
     */
    private function strumienTagow(): array
    {
        $tag = Tag::create(['slug' => 'obiady', 'name' => 'Obiady', 'normalized_name' => 'obiady']);

        $basia = $this->user('basia');
        $basia->followedTags()->attach($tag->getKey(), ['created_at' => now()]);

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Bigos z kapusty kiszonej', 'visibility' => 'followers', 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $basia->getKey()])->getKey(),
        ])->save();

        // Kontrola założenia: cały ten test stoi na tym, że PRZEPIS jest
        // „tylko dla obserwujących", a WPIS wskazujący go — publiczny.
        // Gdyby `PublishRecipe` kiedykolwiek zaczął kopiować widoczność na
        // wpis, obie asercje niżej przechodziłyby z niewłaściwego powodu.
        $this->assertSame('followers', $przepis->fresh()->visibility);

        /** @var Post $wpisPrzepisu */
        $wpisPrzepisu = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();
        $wpisPrzepisu->tags()->attach($tag->getKey(), ['position' => 0]);

        $wpisZwykly = Post::factory()->create([
            'author_id' => $this->user('ola')->getKey(),
            'body' => 'Zwykły obiad, bez przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subMinute(),
        ]);
        $wpisZwykly->tags()->attach($tag->getKey(), ['position' => 0]);
        $wpisZwykly->media()->attach(
            Media::factory()->create([
                'owner_id' => $wpisZwykly->author_id,
                'alt_text' => 'Zdjęcie własne z niedzielnego obiadu',
            ])->getKey(),
            ['position' => 0],
        );

        return [$basia, $wpisPrzepisu, $wpisZwykly];
    }

    /**
     * JEDNA karta wpisu, wycięta z `<main>` po identyfikatorze wpisu.
     *
     * Asercja na całej odpowiedzi łapie to samo słowo z `<title>`, z belki,
     * ze stopki i z SĄSIEDNIEJ karty (docs/PULAPKI_TESTOW.md §1 i §1b) —
     * a tu obie karty stoją na tym samym ekranie i różnią się właśnie tym,
     * co sprawdzamy.
     */
    private function kartaWpisu(string $html, Post $wpis): string
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$this->trescEkranu($html), LIBXML_NOERROR | LIBXML_NOWARNING);

        $karty = (new DOMXPath($dokument))->query(
            '//article[contains(@class, "post-card")][.//a[contains(@href, "'.$wpis->getKey().'")]]',
        );

        $this->assertNotFalse($karty);
        $this->assertSame(
            1,
            $karty->length,
            'W treści ekranu nie ma dokładnie jednej karty tego wpisu — strumień nie pokazał go wcale '
            .'albo pokazał dwa razy. Wtedy żadna asercja niżej nie mówi o tym, o czym myśli.',
        );

        $karta = $karty->item(0);
        $this->assertInstanceOf(DOMElement::class, $karta);

        return (string) $dokument->saveHTML($karta);
    }
}

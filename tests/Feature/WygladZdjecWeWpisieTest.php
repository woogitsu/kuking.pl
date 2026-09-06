<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kilka zdjęć we wpisie: zwykle, karuzela, kolaż (issue #92).
 *
 * CZEGO PILNUJE TEN PLIK
 * Nie tego, że karuzela „ładnie się przesuwa" — tego nie sprawdzi żadna
 * asercja. Pilnuje warunków, które w tym issue są twarde, a które najłatwiej
 * zgubić przy następnej przebudowie widoku:
 *
 * 1. KARUZELA DZIAŁA BEZ JAVASCRIPTU. Test przechodzi przez nią tak, jak
 *    zrobi to przeglądarka z wyłączonym skryptem: czyta HTML, znajduje
 *    odnośnik „Następne zdjęcie", skacze pod wskazany `#id` i sprawdza, czy
 *    naprawdę wylądował na kolejnym slajdzie — aż do ostatniego. Gdyby
 *    sterowanie było na `onclick`, ten test nie miałby czego kliknąć.
 * 2. PRZY JEDNYM ZDJĘCIU WYBORU NIE MA. Trzy przyciski przy jednym zdjęciu
 *    to decyzja bez znaczenia, dołożona w momencie, w którym chcemy, żeby
 *    człowiek po prostu wrzucił zdjęcie.
 * 3. KOLEJNOŚĆ ZMIENIA SIĘ PRZYCISKAMI, nie przeciąganiem (issue #13).
 * 4. WPIS SPRZED TEJ ZMIANY WYGLĄDA JAK DOTĄD, bez migracji danych.
 * 5. BAZA nie przyjmuje trybu, którego widok nie umie narysować.
 */
class WygladZdjecWeWpisieTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // 1. Karuzela bez JavaScriptu
    // -----------------------------------------------------------------

    public function test_karuzela_prowadzi_do_ostatniego_zdjecia_bez_javascriptu(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 4, Post::DISPLAY_CAROUSEL);

        $html = $this->get($wpis->url())->assertOk()->getContent();

        $slajdy = $this->slajdy($html);

        $this->assertCount(4, $slajdy, 'Karuzela nie narysowała wszystkich zdjęć jako slajdów.');

        // Wędrówka dokładnie taka, jaką zrobi przeglądarka bez skryptu:
        // odnośnik → `#id` → slajd o tym `id`.
        $biezacy = array_key_first($slajdy);
        $odwiedzone = [$biezacy];

        for ($krok = 0; $krok < 10; $krok++) {
            $nastepny = $this->celOdnosnika($slajdy[$biezacy], 'Następne zdjęcie');

            if ($nastepny === null) {
                break;
            }

            $this->assertArrayHasKey(
                $nastepny,
                $slajdy,
                'Przycisk „Następne zdjęcie" prowadzi pod kotwicę, której nie ma na stronie. '
                .'Bez JavaScriptu to jest ślepy zaułek.',
            );

            $biezacy = $nastepny;
            $odwiedzone[] = $biezacy;
        }

        $this->assertSame(
            array_keys($slajdy),
            $odwiedzone,
            'Przechodząc „Następne zdjęcie" bez JavaScriptu nie da się dojść do ostatniego zdjęcia '
            .'albo kolejność slajdów się nie zgadza.',
        );

        // Droga powrotna musi działać tak samo — karuzela, z której nie da
        // się wrócić, jest pułapką, nie karuzelą.
        $wstecz = [$biezacy];

        while (($poprzedni = $this->celOdnosnika($slajdy[$biezacy], 'Poprzednie zdjęcie')) !== null) {
            $biezacy = $poprzedni;
            $wstecz[] = $biezacy;
        }

        $this->assertSame(array_reverse(array_keys($slajdy)), $wstecz, 'Nie da się wrócić do pierwszego zdjęcia.');
    }

    public function test_karuzela_nie_wymaga_skryptu_ani_gestu(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 3, Post::DISPLAY_CAROUSEL);

        $html = $this->get($wpis->url())->assertOk()->getContent();

        $karuzela = $this->fragment($html, 'karuzela');

        $this->assertStringNotContainsString('onclick', $karuzela, 'Sterowanie karuzelą wisi na JavaScripcie.');
        $this->assertStringNotContainsString('<button', $karuzela, 'Karuzela używa przycisku zamiast odnośnika — bez skryptu przycisk nic nie zrobi.');

        // Przyciski mają TEKST, nie samą strzałkę: „ikona nigdy nie jest
        // jedynym opisem ważnej akcji" (AGENTS.md §5).
        $this->assertStringContainsString('Poprzednie zdjęcie', $karuzela);
        $this->assertStringContainsString('Następne zdjęcie', $karuzela);
    }

    public function test_karuzela_oglasza_slajd_i_kazde_zdjecie_ma_tekst_alternatywny(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 3, Post::DISPLAY_CAROUSEL);

        $html = $this->get($wpis->url())->assertOk()->getContent();
        $karuzela = $this->fragment($html, 'karuzela');

        $this->assertStringContainsString('aria-live="polite"', $karuzela, 'Karuzela nie ma obszaru ogłaszającego zmianę slajdu.');

        // Obszar ogłoszeń ma być schowany przed wzrokiem, a nie „schowany"
        // klasą, której nie ma w arkuszu. Pierwsza wersja używała `sr-only` —
        // klasy, której Kuking nie definiuje — więc pusty akapit zostawał
        // w układzie strony. Strażnik, nie dowód.
        $this->assertMatchesRegularExpression(
            '~<p class="visually-hidden" aria-live="polite"~',
            $karuzela,
            'Obszar ogłaszający slajd nie używa klasy chowającej z arkusza.',
        );
        $this->assertStringContainsString(
            '.visually-hidden {',
            (string) file_get_contents(resource_path('css/app.css')),
        );

        // Numer slajdu jest widoczny BEZ skryptu — inaczej po pierwszym
        // przewinięciu nie wiadomo, na którym się jest.
        $this->assertStringContainsString('Zdjęcie 1 z 3', $karuzela);
        $this->assertStringContainsString('Zdjęcie 3 z 3', $karuzela);

        preg_match_all('~<img\b[^>]*\balt="([^"]*)"~', $karuzela, $opisy);

        $this->assertCount(3, $opisy[1], 'Nie każdy slajd ma zdjęcie.');

        foreach ($opisy[1] as $opis) {
            $this->assertNotSame('', trim($opis), 'Slajd bez tekstu alternatywnego — czytnik ekranu nie ma czego przeczytać.');
        }
    }

    // -----------------------------------------------------------------
    // 2. Jedno zdjęcie = brak wyboru
    // -----------------------------------------------------------------

    public function test_przy_jednym_zdjeciu_wybor_trybu_sie_nie_pojawia(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 1, Post::DISPLAY_NORMAL);

        $html = $this->actingAs($autor)->get(route('posts.media.edit', $wpis))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-wybor-wygladu', $html, 'Przy jednym zdjęciu wybór trybu jednak się renderuje.');
        $this->assertStringNotContainsString('Przenieś w górę', $html, 'Przy jednym zdjęciu nie ma czego przenosić.');
        $this->assertStringContainsString('nie ma tu czego ustawiać', $html);
    }

    public function test_przy_dwoch_zdjeciach_wybor_trybu_jest_widoczny(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 2, Post::DISPLAY_NORMAL);

        $html = $this->actingAs($autor)->get(route('posts.media.edit', $wpis))->assertOk()->getContent();

        $this->assertStringContainsString('data-wybor-wygladu', $html);
        $this->assertDoesNotMatchRegularExpression(
            '~<fieldset class="wybor-wygladu"[^>]*\bhidden\b~',
            $html,
            'Wybór wyglądu jest ukryty mimo dwóch zdjęć.',
        );
        $this->assertStringContainsString('Karuzela', $html);
        $this->assertStringContainsString('Kolaż', $html);
    }

    public function test_formularz_publikacji_ukrywa_wybor_dopoki_nie_ma_drugiego_zdjecia(): void
    {
        $autor = $this->user('basia');

        $html = $this->actingAs($autor)->get(route('posts.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-wybor-wygladu', $html, 'Wybór wyglądu w ogóle nie trafił do formularza.');
        $this->assertMatchesRegularExpression(
            '~<fieldset class="wybor-wygladu"[^>]*\bhidden\b~',
            $html,
            'Wybór wyglądu jest widoczny, zanim w formularzu są dwa zdjęcia.',
        );
    }

    public function test_wpis_z_jednym_zdjeciem_zapisuje_tryb_zwykly_mimo_wyboru_karuzeli(): void
    {
        $autor = $this->user('basia');
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);

        $this->actingAs($autor)->post(route('posts.store'), [
            'body' => 'Rosół.',
            'visibility' => 'public',
            'media_ids' => [$zdjecie->getKey()],
            'display_mode' => Post::DISPLAY_CAROUSEL,
        ])->assertRedirect();

        $wpis = Post::firstOrFail();

        $this->assertSame(
            Post::DISPLAY_NORMAL,
            $wpis->display_mode,
            'Przy jednym zdjęciu w bazie zostaje deklaracja, której nie da się zobaczyć.',
        );
    }

    // -----------------------------------------------------------------
    // 3. Kolejność zdjęć przyciskami
    // -----------------------------------------------------------------

    public function test_zdjecie_da_sie_przeniesc_w_gore_i_w_dol(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 3, Post::DISPLAY_CAROUSEL);

        $poczatkowa = $wpis->media->pluck('id')->all();

        $this->actingAs($autor)
            ->post(route('posts.media.update', $wpis), [
                'display_mode' => Post::DISPLAY_CAROUSEL,
                'przenies_w_dol' => $poczatkowa[0],
            ])
            ->assertRedirect(route('posts.media.edit', $wpis));

        $this->assertSame(
            [$poczatkowa[1], $poczatkowa[0], $poczatkowa[2]],
            $wpis->fresh()->media->pluck('id')->all(),
            'Przycisk „Przenieś w dół" nie zamienił zdjęcia z sąsiadem.',
        );

        $this->actingAs($autor)
            ->post(route('posts.media.update', $wpis), [
                'display_mode' => Post::DISPLAY_CAROUSEL,
                'przenies_w_gore' => $poczatkowa[0],
            ])
            ->assertRedirect();

        $this->assertSame(
            $poczatkowa,
            $wpis->fresh()->media->pluck('id')->all(),
            'Przycisk „Przenieś w górę" nie cofnął zmiany.',
        );
    }

    public function test_kolejnosc_zmienia_kolejnosc_slajdow_w_karuzeli(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 3, Post::DISPLAY_CAROUSEL);

        $drugie = $wpis->media[1];

        $this->actingAs($autor)->post(route('posts.media.update', $wpis), [
            'display_mode' => Post::DISPLAY_CAROUSEL,
            'przenies_w_gore' => $drugie->getKey(),
        ])->assertRedirect();

        $html = $this->get($wpis->url())->assertOk()->getContent();
        $slajdy = $this->slajdy($html);

        $this->assertStringContainsString(
            (string) $drugie->url('feed'),
            reset($slajdy),
            'Kolejność zapisana przyciskami nie przełożyła się na kolejność slajdów.',
        );
    }

    public function test_enter_w_formularzu_zapisuje_zamiast_przesuwac_zdjecie(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 3, Post::DISPLAY_NORMAL);

        $html = $this->actingAs($autor)->get(route('posts.media.edit', $wpis))->assertOk()->getContent();

        preg_match('~<form[^>]*action="[^"]*zdjecia"[^>]*>(.*?)</form>~s', $html, $formularz);

        $this->assertNotEmpty($formularz, 'Nie znaleziono formularza kolejności zdjęć.');

        preg_match('~<button[^>]*type="submit"[^>]*>([^<]*)</button>~s', $formularz[1], $pierwszy);

        // Enter w formularzu wysyła go PIERWSZYM przyciskiem w kodzie strony.
        // Gdyby był nim „Przenieś w dół", osoba wybierająca tryb klawiaturą
        // przestawiałaby sobie kolejność zamiast zapisać wybór.
        $this->assertStringContainsString(
            'Zapisz',
            $pierwszy[1] ?? '',
            'Pierwszym przyciskiem formularza jest przesunięcie zdjęcia, a nie zapis.',
        );
    }

    public function test_kolejnosc_zdjec_zmienia_tylko_autor(): void
    {
        $autor = $this->user('basia');
        $ktosInny = $this->user('marek');
        $wpis = $this->wpisZeZdjeciami($autor, 2, Post::DISPLAY_CAROUSEL);

        $this->actingAs($ktosInny)->get(route('posts.media.edit', $wpis))->assertForbidden();

        $this->actingAs($ktosInny)->post(route('posts.media.update', $wpis), [
            'display_mode' => Post::DISPLAY_COLLAGE,
        ])->assertForbidden();

        $this->assertSame(Post::DISPLAY_CAROUSEL, $wpis->fresh()->display_mode);
    }

    public function test_kolejnosc_nie_lamie_unikalnosci_pozycji_w_bazie(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 4, Post::DISPLAY_COLLAGE);

        $identyfikatory = $wpis->media->pluck('id')->all();

        // Kilka przesunięć pod rząd — `UNIQUE (post_id, position)` odrzuciłoby
        // każdą zamianę robioną w jednym przebiegu.
        $this->actingAs($autor)->post(route('posts.media.update', $wpis), [
            'display_mode' => Post::DISPLAY_COLLAGE,
            'przenies_w_dol' => $identyfikatory[0],
        ])->assertRedirect();

        $this->actingAs($autor)->post(route('posts.media.update', $wpis), [
            'display_mode' => Post::DISPLAY_COLLAGE,
            'przenies_w_dol' => $identyfikatory[0],
        ])->assertRedirect();

        $pozycje = DB::table('post_media')->where('post_id', $wpis->getKey())->pluck('position')->all();
        sort($pozycje);

        $this->assertSame([0, 1, 2, 3], $pozycje, 'Pozycje zdjęć rozjechały się po przesunięciach.');
        $this->assertSame(
            [$identyfikatory[1], $identyfikatory[2], $identyfikatory[0], $identyfikatory[3]],
            $wpis->fresh()->media->pluck('id')->all(),
        );
    }

    // -----------------------------------------------------------------
    // 4. Stare wpisy i domyślny tryb
    // -----------------------------------------------------------------

    public function test_wpis_sprzed_zmiany_wyswietla_sie_jak_dotad(): void
    {
        $autor = $this->user('basia');

        // Wpis zapisany BEZ podania trybu — dokładnie tak, jak wyglądają
        // wiersze zapisane przed migracją.
        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Rosół sprzed zmiany.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        foreach (Media::factory()->count(3)->create(['owner_id' => $autor->getKey()]) as $pozycja => $zdjecie) {
            $wpis->media()->attach($zdjecie->getKey(), ['position' => $pozycja]);
        }

        $this->assertSame(Post::DISPLAY_NORMAL, $wpis->fresh()->display_mode);

        $html = $this->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString('photo-grid', $html, 'Stary wpis przestał się wyświetlać dotychczasowym układem.');
        $this->assertStringNotContainsString('karuzela-tasma', $html);
        $this->assertStringNotContainsString('kolaz-pole', $html);
    }

    public function test_kolaz_nie_kadruje_zdjec(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 4, Post::DISPLAY_COLLAGE);

        $html = $this->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString('kolaz-pole', $html);

        // Kadrowanie robi CSS, nie widok — więc pilnujemy arkusza. To jest
        // strażnik, nie dowód: sprawdza, że nikt nie zamienił `contain`
        // na `cover` „dla równych kafelków".
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '~\.kolaz-pole \.post-photo \{\s*object-fit: contain;~',
            $css,
            'Kolaż zaczął kadrować zdjęcia. Kadrowanie do kwadratu ucina to, co człowiek chciał pokazać.',
        );
    }

    // -----------------------------------------------------------------
    // 5. Baza pilnuje trybu
    // -----------------------------------------------------------------

    public function test_baza_odrzuca_tryb_spoza_listy(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 2, Post::DISPLAY_NORMAL);

        $this->expectException(QueryException::class);

        DB::table('posts')->where('id', $wpis->getKey())->update(['display_mode' => 'mozaika']);
    }

    public function test_tryb_spoza_listy_z_formularza_konczy_sie_komunikatem_po_polsku(): void
    {
        $autor = $this->user('basia');
        $wpis = $this->wpisZeZdjeciami($autor, 2, Post::DISPLAY_NORMAL);

        $this->actingAs($autor)
            ->from(route('posts.media.edit', $wpis))
            ->post(route('posts.media.update', $wpis), ['display_mode' => 'mozaika'])
            ->assertSessionHasErrors(['display_mode' => 'Zaznacz, jak mają się wyświetlić zdjęcia: zwykle, karuzela czy kolaż.']);
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function wpisZeZdjeciami(User $autor, int $ile, string $tryb): Post
    {
        $zdjecia = Media::factory()->count($ile)->create(['owner_id' => $autor->getKey()]);

        return app(PublishPost::class)->handle(
            author: $autor,
            body: 'Obiad w kilku odsłonach.',
            mediaIds: $zdjecia->pluck('id')->all(),
            visibility: Post::VISIBILITY_PUBLIC,
            displayMode: $tryb,
        );
    }

    /**
     * Slajdy karuzeli: `id` slajdu → jego HTML.
     *
     * @return array<string, string>
     */
    private function slajdy(string $html): array
    {
        preg_match_all(
            '~<li class="karuzela-slajd" id="([^"]+)"[^>]*>(.*?)</li>~s',
            $html,
            $trafienia,
            PREG_SET_ORDER,
        );

        $slajdy = [];

        foreach ($trafienia as $trafienie) {
            $slajdy[$trafienie[1]] = $trafienie[2];
        }

        return $slajdy;
    }

    /** Kotwica (bez `#`), pod którą prowadzi odnośnik o danym napisie. */
    private function celOdnosnika(string $html, string $napis): ?string
    {
        $wzorzec = '~<a\b[^>]*href="#([^"]+)"[^>]*>\s*'.preg_quote($napis, '~').'\s*</a>~s';

        return preg_match($wzorzec, $html, $trafienie) === 1 ? $trafienie[1] : null;
    }

    /**
     * Sama karuzela, bez reszty strony.
     *
     * Wycinamy DOKŁADNIE blok karuzeli, a nie „kawałek strony wokół" —
     * inaczej asercja „nie ma tu przycisku" łapałaby przyciski z paska akcji
     * karty albo z nawigacji na dole i świeciłaby na czerwono bez powodu
     * (albo, co gorsza, na zielono z powodu, którego nie badamy).
     */
    private function fragment(string $html, string $klasa): string
    {
        return preg_match('~<div class="'.preg_quote($klasa, '~').'".*?</ol>~s', $html, $trafienie) === 1
            ? $trafienie[0]
            : '';
    }
}

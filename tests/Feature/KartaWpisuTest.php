<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Karta wpisu i feed (UI kit v2, etap B).
 *
 * CZEGO PILNUJE TEN PLIK
 * Nie tego, że karta „ładnie wygląda" — tego nie sprawdzi żadna asercja.
 * Pilnuje trzech rzeczy, które są w Kuking regułą produktu, a nie kwestią
 * gustu, i które najłatwiej zgubić przy następnym przestylowaniu:
 *
 * 1. „Ugotowałem" jest GŁÓWNĄ akcją — stoi pierwsze i jako jedyne ma pełny
 *    kolor marki. To najcenniejszy sygnał w serwisie: realne wykonanie
 *    przepisu przez innego człowieka (AGENTS.md §1).
 * 2. Karta działa BEZ JAVASCRIPTU — same odnośniki i formularze.
 * 3. Żadna akcja nie chowa się za najechaniem myszą ani gestem.
 */
class KartaWpisuTest extends TestCase
{
    use RefreshDatabase;

    private function kartaZPrzepisem(): string
    {
        $autor = $this->user('autorka');

        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => 'Rosół na niedzielę.',
        ]);

        return $this->actingAs($this->user('czytelniczka'))
            ->get($post->url())
            ->assertOk()
            ->getContent();
    }

    public function test_ugotowalem_stoi_przed_komentarzem(): void
    {
        $html = $this->kartaZPrzepisem();

        $ugotowalem = strpos($html, 'Ugotowałem');
        $komentarz = strpos($html, 'Napisz komentarz');

        $this->assertNotFalse(
            $ugotowalem,
            'Na karcie wpisu powstałego z przepisu nie ma „Ugotowałem". '
            .'To jest główna akcja produktu, nie ozdobnik.',
        );
        $this->assertNotFalse($komentarz);

        $this->assertLessThan(
            $komentarz,
            $ugotowalem,
            'Komentarz wyprzedził „Ugotowałem". Kolejność w kodzie jest zarazem '
            .'kolejnością dla klawiatury i dla czytnika ekranu, więc waga '
            .'wizualna i waga w nawigacji muszą się zgadzać.',
        );
    }

    public function test_ugotowalem_jest_jedyna_akcja_w_pelnym_kolorze_marki(): void
    {
        $karta = $this->paskAkcji($this->kartaZPrzepisem());

        preg_match_all('~<a\b[^>]*class="([^"]*\bbtn\b[^"]*)"[^>]*>(.*?)</a>~s', $karta, $przyciski, PREG_SET_ORDER);

        $this->assertNotEmpty($przyciski, 'Pasek akcji karty nie ma ani jednego przycisku.');

        $wKolorzeMarki = [];

        foreach ($przyciski as [, $klasy, $wnetrze]) {
            if (str_contains($klasy, 'btn-primary')) {
                $wKolorzeMarki[] = trim(strip_tags($wnetrze));
            }
        }

        $this->assertSame(
            ['Ugotowałem'],
            array_map(fn (string $t) => trim(preg_replace('/\s+/', ' ', $t) ?? ''), $wKolorzeMarki),
            'Pełny kolor marki na karcie należy się WYŁĄCZNIE akcji „Ugotowałem". '
            .'Drugi przycisk w tym samym kolorze odbiera jej pierwszeństwo.',
        );
    }

    public function test_wpis_bez_przepisu_nie_obiecuje_ugotowalem(): void
    {
        $autor = $this->user('autorka');

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Zdjęcie bez przepisu.',
        ]);

        $karta = $this->paskAkcji(
            $this->actingAs($this->user('czytelniczka'))->get($post->url())->assertOk()->getContent(),
        );

        $this->assertStringNotContainsString(
            'Ugotowałem',
            $karta,
            'Karta wpisu bez przepisu proponuje „Ugotowałem". Nie ma czego '
            .'ugotować i nie ma kogo o tym powiadomić.',
        );
    }

    /**
     * BEZ JAVASCRIPTU (AGENTS.md §5).
     *
     * Powód nie jest ideologiczny: przy słabym zasięgu skrypt się nie dociąga,
     * a człowiek zostaje z kartą, która nic nie robi po kliknięciu.
     */
    public function test_wszystkie_akcje_karty_dzialaja_bez_skryptu(): void
    {
        $karta = $this->paskAkcji($this->kartaZPrzepisem());

        foreach (['onclick', 'x-on:click', '@click', 'wire:click', 'href="#"', 'href="javascript:'] as $zakazane) {
            $this->assertStringNotContainsString(
                $zakazane,
                $karta,
                "W pasku akcji karty pojawiło się „{$zakazane}”. Bez skryptu "
                .'ta akcja nie robi nic.',
            );
        }

        // Asercja pozytywna obok negatywnych: `assertStringNotContainsString`
        // przechodzi także na pustym łańcuchu, więc sama niczego nie dowodzi.
        $this->assertMatchesRegularExpression(
            '~<a\b[^>]*href="https?://[^"]+"~',
            $karta,
            'Pasek akcji nie zawiera ani jednego prawdziwego odnośnika.',
        );
    }

    public function test_zadna_akcja_nie_chowa_sie_za_najechaniem_myszy(): void
    {
        $sciezka = resource_path('css/app.css');
        $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($sciezka));

        $this->assertNotSame('', trim($css));

        // Reguła typu `.post-card:hover .cos { display: block }` znaczy: „ta
        // funkcja istnieje tylko dla myszy". Na telefonie i przy nawigacji
        // klawiaturą jest wtedy nieosiągalna (docs/UX_50_PLUS.md).
        $this->assertDoesNotMatchRegularExpression(
            '~\.post-card[^{}]*:hover[^{]*\{[^}]*(display\s*:\s*(block|flex|inline)|visibility\s*:\s*visible|opacity\s*:\s*1)~s',
            $css,
            'Na karcie wpisu jest element odsłaniany dopiero po najechaniu myszą. '
            .'Dla części naszych użytkowników taka funkcja po prostu nie istnieje.',
        );
    }

    /** Ikona na karcie jest dodatkiem do napisu, nie zamiast niego. */
    public function test_ikony_na_karcie_nigdy_nie_stoja_same(): void
    {
        $karta = $this->paskAkcji($this->kartaZPrzepisem());

        preg_match_all('~<a\b[^>]*class="[^"]*\bbtn\b[^"]*"[^>]*>(.*?)</a>~s', $karta, $przyciski);

        $this->assertNotEmpty($przyciski[1]);

        foreach ($przyciski[1] as $wnetrze) {
            $this->assertNotSame(
                '',
                trim(html_entity_decode(strip_tags($wnetrze))),
                'Przycisk na karcie wpisu został bez napisu — sama ikona nie jest '
                .'opisem akcji (AGENTS.md §5).',
            );
        }
    }

    /**
     * Zachęta do dodania wpisu jest ODNOŚNIKIEM, nie polem udającym formularz.
     *
     * W mockupach (01 i 05) wygląda jak miejsce, w którym się pisze. Gdyby
     * naprawdę było polem tekstowym, bez JavaScriptu nie robiłoby nic —
     * a publikacja wpisu ma działać bez skryptu.
     */
    public function test_zacheta_do_dodania_wpisu_jest_zwyklym_odnosnikiem(): void
    {
        $html = $this->actingAs($this->user('basia'))->get(route('home'))->assertOk()->getContent();

        $start = strpos($html, 'class="card composer"');

        $this->assertNotFalse($start, 'Na stronie głównej nie ma zachęty do dodania wpisu.');

        $poczatek = strrpos(substr($html, 0, $start), '<');

        $this->assertNotFalse($poczatek);

        $this->assertSame(
            '<a ',
            substr($html, $poczatek, 3),
            'Zachęta do dodania wpisu przestała być odnośnikiem. Pole tekstowe '
            .'w tym miejscu bez JavaScriptu nie robi po kliknięciu nic.',
        );

        $this->assertStringContainsString(route('posts.create'), substr($html, $start, 600));
    }

    public function test_nieznana_karta_nie_wybucha_bez_przepisu(): void
    {
        // Renderowanie komponentu wprost — bez trasy — łapie błędy, które
        // przy pustym feedzie nigdy by się nie ujawniły.
        $post = Post::factory()->create(['author_id' => $this->user('autorka')->getKey()]);

        $html = Blade::render('<x-post-card :post="$post" />', ['post' => $post->fresh()]);

        $this->assertStringContainsString('post-card', $html);
    }

    /** Sam pasek akcji karty — reszta strony nie ma tu nic do rzeczy. */
    private function paskAkcji(string $html): string
    {
        $start = strpos($html, '<div class="post-card-actions">');

        $this->assertNotFalse(
            $start,
            'Karta wpisu nie ma paska akcji. Zmieniła się jej struktura — '
            .'sprawdź resources/views/components/post-card.blade.php.',
        );

        $koniec = strpos($html, '</div>', $start);

        $this->assertNotFalse($koniec);

        return substr($html, $start, $koniec - $start);
    }

    // -----------------------------------------------------------------
    // Menu „…" nad wpisem (UI kit v2, ekran 01)
    // -----------------------------------------------------------------

    public function test_zglos_nie_zniknelo_razem_z_przeniesieniem_do_menu(): void
    {
        // „Zgłoś" zeszło z paska akcji do menu „…". Przeniesienie akcji
        // moderacyjnej w mniej widoczne miejsce jest o krok od jej zgubienia
        // — a zgłoszenie treści to jedyna droga, jaką ma człowiek, któremu
        // coś się na tej stronie stało.
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $html = (string) $this->actingAs($widz)->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString(
            route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]),
            $html,
            'Z karty wpisu nie da się już zgłosić treści.',
        );
    }

    public function test_menu_karty_otwiera_sie_bez_javascriptu(): void
    {
        $autor = $this->user('autor');
        $widz = $this->user('widz');
        Post::factory()->create(['author_id' => $autor->getKey()]);

        $html = (string) $this->actingAs($widz)->get(route('home'))->assertOk()->getContent();

        // `<details>`, nie przycisk sterowany skryptem. Menu, które bez
        // JavaScriptu nie otwiera się wcale, jest ozdobą udającą przycisk.
        $this->assertStringContainsString('<details class="post-card-menu">', $html);
        $this->assertMatchesRegularExpression(
            '~<details class="post-card-menu">.*?<summary~s',
            $html,
        );
    }

    public function test_autor_nie_zglasza_sam_siebie(): void
    {
        // Zgłoszenie własnego wpisu nie ma sensu i nie może się pojawić —
        // ani na pasku, ani w menu.
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $html = (string) $this->actingAs($autor)->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]),
            $html,
        );
    }

    public function test_karta_mowi_kto_widzi_ten_wpis(): void
    {
        // Kit pokazuje widoczność przy dacie („2 godz. temu · publicznie").
        // Autor ma wiedzieć jednym spojrzeniem, kto to widzi, a nie dopiero
        // po wejściu w edycję.
        $autor = $this->user('autor');
        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($autor)->get(route('home'))
            ->assertOk()
            ->assertSee('publicznie', escape: false);
    }
}

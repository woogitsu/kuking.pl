<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
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

        $przyciski = $this->przyciskiPaska($karta);

        $this->assertNotEmpty($przyciski, 'Pasek akcji karty nie ma ani jednego przycisku.');

        $wKolorzeMarki = [];

        foreach ($przyciski as [$klasy, $wnetrze]) {
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

        $przyciski = $this->przyciskiPaska($karta);

        $this->assertNotEmpty($przyciski);

        foreach ($przyciski as [, $wnetrze]) {
            $this->assertNotSame(
                '',
                trim(html_entity_decode(strip_tags($wnetrze))),
                'Przycisk na karcie wpisu został bez napisu — sama ikona nie jest '
                .'opisem akcji (AGENTS.md §5).',
            );
        }
    }

    /**
     * Przyciski paska akcji jako pary [klasy, wnętrze] — ODNOŚNIKI I `<button>`.
     *
     * Pierwsza wersja obu testów wyżej dopasowywała wyłącznie `<a class="btn …">`,
     * a „Zapisz" na tym pasku jest `<button>` w `<form method="POST">` (zapis
     * do zeszytu musi iść POST-em, więc odnośnikiem być nie może). Skutek:
     * zmierzone przez zepsucie `resources/views/components/post-card.blade.php` —
     * nadanie „Zapisz" klasy `btn-primary` ORAZ zabranie mu napisu zostawiało
     * OBA testy zielone, choć jeden pilnuje wyłączności koloru marki,
     * a drugi zakazu samotnej ikony (AGENTS.md §5).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function przyciskiPaska(string $karta): array
    {
        preg_match_all(
            '~<(?:a|button)\b[^>]*class="([^"]*\bbtn\b[^"]*)"[^>]*>(.*?)</(?:a|button)>~s',
            $karta,
            $trafienia,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $t): array => [$t[1], $t[2]],
            $trafienia,
        );
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

        // `kafel-akcji`, nie `card`: od rozdzielenia ról powierzchni zachęta
        // jest KAFLEM AKCJI (cała powierzchnia to odnośnik), a nie kartą
        // treści — `docs/design/ROLE_KART.md`. Test dalej pilnuje tego samego:
        // że to jest `<a>`, a nie pole tekstowe.
        $start = strpos($html, 'class="kafel-akcji composer"');

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

    /**
     * MENU WPISU MA WIDOCZNY NAPIS, NIE SAME TRZY KROPKI (AGENTS.md:176,
     * docs/UX_50_PLUS.md:27).
     *
     * Do 11 września 2026 całą treścią tego przycisku było
     * `<span aria-hidden="true">···</span>`: znak SCHOWANY przed czytnikiem
     * ekranu, a nazwa dostępna wyłącznie w `aria-label`. Oko dostawało znak
     * bez podpisu, czytnik ekranu podpis bez znaku. Za tymi kropkami stoją
     * „Edytuj wpis" i „Usuń wpis", czyli dwie z trzech rzeczy, jakie człowiek
     * może zrobić z własnym wpisem — `docs/UX_50_PLUS.md`:27 nazywa dokładnie
     * ten wzorzec („`♡ ⋮ ↗` bez podpisów") słabym.
     *
     * TEST SPRAWDZA TRZY RZECZY NARAZ, bo każda osobno przechodzi w złym
     * stanie:
     *   1. napis JEST w treści przycisku — inaczej wracamy do samego znaku;
     *   2. napis NIE jest schowany przed czytnikiem (`aria-hidden` w środku
     *      `<summary>` obejmuje tylko ikonę, nie tekst);
     *   3. nazwa dostępna zaczyna się od widocznego napisu — WCAG 2.2 AA
     *      2.5.3 (Label in Name). `aria-label` zostaje, bo tych przycisków
     *      jest na liście tyle, ile kart, a samo „Więcej" nie mówi, przy
     *      którym wpisie stoi.
     */
    public function test_menu_karty_ma_widoczny_napis_a_nie_same_kropki(): void
    {
        $autor = $this->user('autor');
        Post::factory()->create(['author_id' => $autor->getKey()]);

        $html = (string) $this->actingAs($autor)->get(route('home'))->assertOk()->getContent();

        $trafil = preg_match('~<summary\b([^>]*)>(.*?)</summary>~s', $html, $summary);

        $this->assertSame(1, $trafil, 'Na karcie wpisu nie ma menu `<summary>` — nie ma czego sprawdzać.');

        $atrybuty = $summary[1];
        $wnetrze = $summary[2];

        $this->assertStringContainsString(
            '>Więcej<',
            $wnetrze,
            'Przycisk menu wpisu nie ma widocznego napisu. Sam znak „···" jest dla oka '.
            'zagadką, a za menu stoją „Edytuj wpis" i „Usuń wpis" (AGENTS.md:176).',
        );

        $this->assertDoesNotMatchRegularExpression(
            '~<span[^>]*aria-hidden="true"[^>]*>\s*Więcej~',
            $wnetrze,
            'Widoczny napis „Więcej" jest schowany przed czytnikiem ekranu. `aria-hidden` '.
            'obejmuje w tym przycisku wyłącznie ikonę.',
        );

        $this->assertMatchesRegularExpression(
            '~aria-label="Więcej~',
            $atrybuty,
            'Nazwa dostępna przycisku nie zaczyna się od widocznego napisu „Więcej" — '.
            'to jest naruszenie WCAG 2.2 AA 2.5.3 (Label in Name): człowiek mówi do '.
            'sterowania głosem to, co widzi.',
        );

        $this->assertStringNotContainsString(
            '<span aria-hidden="true">···</span>',
            $html,
            'Wrócił przycisk zbudowany z samych kropek schowanych przed czytnikiem ekranu.',
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

    // -----------------------------------------------------------------
    // Tematy wpisu na karcie (docs/product/PROSTOTA_JAK_GARNEK.md)
    //
    // Garnek.pl pokazywał na stronie zdjęcia listę fotoforów, do których
    // ono trafiło. Autor u nas wybiera tagi przy publikacji od pierwszego
    // dnia (`x-tagi-formularz`), ale karta wpisu ich nie oddawała z
    // powrotem — jedyną drogą na stronę tagu był adres, który trzeba było
    // już znać. Te testy pilnują, żeby to zostało naprawione WSZĘDZIE,
    // gdzie karta stoi, i żeby naprawa nie kosztowała zapytania per wpis.
    // -----------------------------------------------------------------

    public function test_karta_pokazuje_tematy_wpisu_na_stronie_glownej(): void
    {
        $autor = $this->user('autorka_tagow');
        $tag = Tag::factory()->create(['name' => 'Zupy']);

        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

        // OBSERWOWANIE JEST TU WARUNKIEM SPRAWDZANIA CZEGOKOLWIEK.
        //
        // Bez niego `/home` nie pokazuje feedu obserwowanych, tylko awaryjne
        // „Świeżo z Kuking" — czyli DRUGI kod, z osobnym doładowaniem tagów.
        // Sprawdzone kontrolą ujemną: po usunięciu `tags:id,slug,name`
        // z `FollowingFeed` ten test przechodził dalej, bo w ogóle tamtej
        // ścieżki nie dotykał.
        $czytelniczka = $this->user('czytelniczka_tagow');
        DB::table('follows')->insert([
            'follower_id' => $czytelniczka->getKey(),
            'followed_id' => $autor->getKey(),
            'created_at' => now(),
        ]);

        $html = (string) $this->actingAs($czytelniczka)
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('tags.show', $tag),
            $html,
            'Wpis z tematem nie ma na karcie odnośnika do strony tego tematu — '
            .'dokładnie tak, jak Garnek.pl pokazywał fotofora na stronie zdjęcia.',
        );
        $this->assertStringContainsString('Zupy', $html);
    }

    public function test_karta_pokazuje_tematy_wpisu_w_swiezo_z_kuking(): void
    {
        // Świeżo z Kuking korzysta z osobnego zapytania (DiscoverFeed) —
        // eager loading tagów tam jest osobną linijką i osobnym ryzykiem
        // regresji niż na stronie głównej.
        $autor = $this->user('autorka_tagow_2');
        $tag = Tag::factory()->create(['name' => 'Zakwas']);

        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

        $this->get(route('discover'))
            ->assertOk()
            ->assertSee(route('tags.show', $tag), escape: false)
            ->assertSee('Zakwas');
    }

    public function test_karta_bez_tematow_nie_pokazuje_pustej_listy(): void
    {
        $autor = $this->user('autorka_bez_tagow');
        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Wpis bez tematu.',
        ]);

        $html = (string) $this->actingAs($this->user('czytelniczka_bez_tagow'))
            ->get(route('home'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            'Tematy tego wpisu',
            $html,
            'Wpis bez tematów nie powinien pokazywać pustej listy — to jest '
            .'zaproszenie do niczego.',
        );
    }

    /**
     * TRZECIA ŚCIEŻKA — archiwum profilu (`ProfileController::postsFor()`).
     *
     * Zwykłe `assertSee(route('tags.show', $tag))` na całej stronie profilu
     * DAJE FAŁSZYWY POZYTYW: `ProfileController::tagiDoSzyny()` zbiera do
     * sześciu tagów użytych w widocznych wpisach WŁAŚCICIELA i wystawia je
     * w prawej szynie (`x-szyna-profilu`) niezależnie od tego, czy karta
     * wpisu w ogóle pokazuje tematy. Test na całym HTML-u przechodziłby
     * więc identycznie, nawet gdyby ten PR nic nie zmienił w karcie —
     * dokładnie to złapała kontrola ujemna zewnętrznego przeglądu.
     *
     * Dwa zabezpieczenia przed tym samym błędem drugi raz:
     *   1. Widok WŁASNEGO profilu (`isOwner === true`) — wtedy
     *      `tagiSzyny` w kontrolerze to jawnie `collect()`, więc szyna nie
     *      pokazuje ŻADNEGO tematu i nie może dać fałszywego pozytywu.
     *   2. Asercja działa na WYCIĘTEJ karcie pierwszego wpisu
     *      (`<article class="card post-card">…</article>`), nie na całej
     *      stronie — tak samo jak `paskAkcji()` wycina tylko pasek akcji.
     */
    public function test_karta_pokazuje_tematy_wpisu_w_archiwum_profilu(): void
    {
        $autor = $this->user('autorka_tagow_profil');
        $tag = Tag::factory()->create(['name' => 'Naleśniki']);

        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

        $html = (string) $this->actingAs($autor)
            ->get(route('profile.show', $autor->profile->username))
            ->assertOk()
            ->getContent();

        $karta = $this->pierwszaKartaWpisu($html);

        $this->assertStringContainsString(
            route('tags.show', $tag),
            $karta,
            'Karta wpisu w archiwum profilu nie ma odnośnika do strony tematu '
            .'(sprawdzone WEWNĄTRZ karty, nie na całej stronie — prawa szyna '
            .'profilu pokazuje własne tagi i dałaby fałszywy pozytyw).',
        );
        $this->assertStringContainsString('Naleśniki', $karta);
    }

    /** Wycina HTML pierwszej karty wpisu ze strony — bez reszty strony wokół niej. */
    private function pierwszaKartaWpisu(string $html): string
    {
        $start = strpos($html, '<article class="card post-card">');

        $this->assertNotFalse($start, 'Na stronie nie ma ani jednej karty wpisu.');

        $koniec = strpos($html, '</article>', $start);

        $this->assertNotFalse($koniec);

        return substr($html, $start, $koniec - $start);
    }
}

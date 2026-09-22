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
     * MENU WPISU TO SAME KROPKI — NAZWANY WYJĄTEK OD „IKONA NIGDY SAMA"
     * (decyzja właściciela z 12 września 2026; `AGENTS.md` §5,
     * `docs/UX_50_PLUS.md`, sekcja „Jeden nazwany wyjątek").
     *
     * TEN TEST ODWRACA POPRZEDNI, I TO JEST ŚWIADOME. Do 12 września stało
     * tu `test_menu_karty_ma_widoczny_napis_a_nie_same_kropki`, które żądało
     * napisu „Więcej" w treści przycisku. Argument był prawdziwy — za tym
     * menu stoją „Edytuj wpis" i „Usuń wpis", a `docs/UX_50_PLUS.md`
     * wymienia „`♡ ⋮ ↗` bez podpisów" jako wzorzec słaby. Właściciel dostał
     * to ryzyko wprost i wybrał kropki: dla ludzi 50+, którzy przyszli
     * z Facebooka, trzy kropki w rogu wpisu nie są zagadką, tylko znakiem,
     * który już znają.
     *
     * WYJĄTEK JEST WPISANY, A NIE OBCHODZONY. Dotyczy WYŁĄCZNIE tego
     * jednego menu. Reguła „ikona nigdy nie jest jedynym opisem ważnej
     * akcji" obowiązuje w reszcie serwisu bez zmian i pilnują jej osobne
     * testy — m.in. `PasekGornyNaTelefonieTest`
     * (`test_kazda_pozycja_obu_paskow_ma_widoczny_napis`),
     * `PowiekszanieZdjeciaTest` i `LicznikiKolejekPaneluTest`. Ten test
     * NIE rozluźnia żadnego z nich.
     *
     * DLATEGO SPRAWDZA CZTERY RZECZY NARAZ — każda osobno przechodzi
     * w stanie, w którym wyjątek zaczyna kosztować:
     *   1. w treści przycisku NIE MA tekstu — inaczej wyjątek jest już tylko
     *      nieaktualnym komentarzem, a nie stanem kodu;
     *   2. `aria-label` ZOSTAJE — czytnik ekranu nie może stracić nic; to
     *      jest jedyne, co dzieli tę zmianę od stanu sprzed 11 września,
     *      gdy przycisk był trzema kropkami i niczym więcej;
     *   3. kropki rysuje `<x-ikona nazwa="more">` z `aria-hidden`, więc
     *      nazwą dostępną przycisku jest dokładnie `aria-label`, a nie
     *      znak sklejony z czymkolwiek;
     *   4. cel dotknięcia zostaje 48 × 48 px — to znikł napis, a nie
     *      przycisk. Arkusz jest czytany z pliku, bo to jedyne miejsce,
     *      w którym ta liczba żyje.
     */
    public function test_menu_karty_to_same_kropki_ale_czytnik_ekranu_nie_traci_nic(): void
    {
        $autor = $this->user('autor');
        Post::factory()->create(['author_id' => $autor->getKey()]);

        $html = (string) $this->actingAs($autor)->get(route('home'))->assertOk()->getContent();

        /*
         * Wzorzec zaczyna się od `<details class="post-card-menu">`, a nie od
         * samego `<summary>`. Od issue #344 PIERWSZYM `<summary>` w dokumencie
         * jest menu konta w pasku górnym — gołe `~<summary~` łapało więc
         * przycisk „Moje konto" i pytało o napis „Więcej" w cudzym elemencie.
         * To jest pułapka 1 z `docs/PULAPKI_TESTOW.md` w czystej postaci:
         * asercja mierzy coś innego, niż myśli.
         */
        $trafil = preg_match(
            '~<details class="post-card-menu">\s*<summary\b([^>]*)>(.*?)</summary>~s',
            $html,
            $summary,
        );

        $this->assertSame(1, $trafil, 'Na karcie wpisu nie ma menu `<summary>` — nie ma czego sprawdzać.');

        $atrybuty = $summary[1];
        $wnetrze = $summary[2];

        // 1. Sam znak, bez napisu. `strip_tags` zdejmuje `<svg>` ikony i to,
        //    co zostaje, ma być puste — spacje i znaki nowej linii z Blade'a
        //    nie liczą się jako napis.
        $this->assertSame(
            '',
            trim(html_entity_decode(strip_tags($wnetrze))),
            'W przycisku menu wpisu pojawił się widoczny napis. Wyjątek od „ikona nigdy sama" '.
            '(AGENTS.md §5) został nadany temu jednemu menu na to, żeby były tu SAME kropki; '.
            'jeśli napis ma wrócić, to jest decyzja właściciela i wtedy zmienia się także '.
            'AGENTS.md i docs/UX_50_PLUS.md, a nie sam ten test.',
        );

        // 2. Nazwa dostępna zostaje w całości.
        $this->assertStringContainsString(
            'aria-label="Więcej przy tym wpisie"',
            $atrybuty,
            'Przycisk menu wpisu stracił nazwę dostępną. Bez widocznego napisu `aria-label` jest '.
            'JEDYNĄ rzeczą, jaką dostaje czytnik ekranu — to jest dokładnie ten stan, który '.
            '11 września uznaliśmy za niedopuszczalny i którego wyjątek NIE obejmuje.',
        );

        // 3. Kropki są ozdobą, więc nazwą dostępną jest wyłącznie `aria-label`.
        $this->assertMatchesRegularExpression(
            '~<svg[^>]*aria-hidden="true"~',
            $wnetrze,
            'Ikona kropek nie jest schowana przed czytnikiem ekranu. Wtedy nazwa dostępna '.
            'przycisku przestaje być tym, co mówi `aria-label`.',
        );

        $this->assertStringNotContainsString(
            '···',
            $wnetrze,
            'Wróciły kropki wpisane z klawiatury. Kształt ma rysować `<x-ikona nazwa="more">`: '.
            'znak `···` zależy od czcionki systemu i nie da się go zmierzyć.',
        );

        // 4. Znikł napis, nie przycisk.
        $arkusz = (string) file_get_contents(base_path('resources/css/app.css'));

        $trafilCss = preg_match(
            '~\.post-card-menu\s*>\s*summary\s*\{(.*?)\}~s',
            $arkusz,
            $regula,
        );

        $this->assertSame(1, $trafilCss, 'W `resources/css/app.css` nie ma reguły `.post-card-menu > summary`.');

        foreach (['min-width', 'min-height'] as $wlasciwosc) {
            $this->assertMatchesRegularExpression(
                '~'.preg_quote($wlasciwosc, '~').'\s*:\s*var\(--control-height-min\)~',
                $regula[1],
                "Przycisk menu wpisu nie trzyma `{$wlasciwosc}: var(--control-height-min)`, czyli 48 px. ".
                'Razem z napisem miał zniknąć NAPIS, a nie cel dotknięcia — a to jest ta liczba, '.
                'która przy mniej pewnej ręce decyduje o trafieniu (docs/UX_50_PLUS.md).',
            );
        }
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

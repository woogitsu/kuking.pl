<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KAŻDA WAŻNA AKCJA STOI NA PRAWDZIWYM `<form method="POST">`, A NIE NA SKRYPCIE.
 *
 * PO CO TEN PLIK ISTNIEJE
 * `AGENTS.md` §5 po zmianie D-053 zakazuje jednej rzeczy bezwarunkowo:
 * MARTWEGO PRZYCISKU — czegoś, co bez skryptu wygląda na sprawne, a po
 * kliknięciu milczy. Pilnowały tego dotąd pojedyncze testy, każdy jednego
 * ekranu; całości nikt nie sprawdzał. Pomiar `scripts/bez-javascriptu.mjs`
 * przeszedł 16 ścieżek w przeglądarce z wyłączonym wykonywaniem skryptów
 * i zmierzył, że wszystkie dochodzą do końca. Ten plik pilnuje tego, co widać
 * w ŹRÓDLE HTML — czyli tego, czego pomiar w przeglądarce nie zatrzyma
 * w repozytorium, bo nie chodzi w `php artisan test`.
 *
 * CO DOKŁADNIE JEST SPRAWDZANE
 * Dla każdej akcji: czy w dokumencie stoi `<form>` z metodą POST i adresem
 * tej akcji, czy ma w środku `@csrf` i PRZYCISK WYSYŁKI. Trzy rzeczy naraz,
 * bo każda z nich osobno przechodzi przy zepsutej pozostałej dwójce:
 *
 *  - sam `<form action>` bez przycisku to formularz, którego bez klawiatury
 *    nie da się wysłać (a i z klawiatury nie zawsze),
 *  - sam przycisk bez formularza to dokładnie ten martwy przycisk,
 *  - formularz POST bez `_token` odbija się od CSRF na ekranie 419.
 *
 * CZEGO TEN PLIK NIE DOWODZI
 * Nie dowodzi, że akcja DZIAŁA — od tego są testy funkcjonalne każdej z nich
 * i pomiar w przeglądarce. Dowodzi tylko tego, że droga do niej nie prowadzi
 * przez JavaScript. To celowo wąski zakres: test szerszy, niż umie zmierzyć,
 * jest gorszy niż wąski (docs/PULAPKI_TESTOW.md §4).
 */
class WazneAkcjeMajaPrawdziwyFormularzTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Znajduje `<form>` o podanym adresie akcji i sprawdza trzy rzeczy naraz.
     *
     * Porównujemy po `action`, a nie po klasie CSS ani kolejności na stronie:
     * klasa jest ozdobą i wolno ją zmienić, a adres akcji jest tym, co
     * przeglądarka naprawdę wyśle.
     */
    private function sprawdzFormularz(string $html, string $akcja, string $czego): DOMElement
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $xpath = new DOMXPath($dokument);
        $formularze = $xpath->query(sprintf('//form[@action=%s]', self::cytat($akcja)));

        $this->assertNotFalse($formularze);
        $this->assertGreaterThan(
            0,
            $formularze->length,
            "Brak formularza o akcji {$akcja} — {$czego} nie da się wykonać bez JavaScriptu (AGENTS.md §5, D-053).",
        );

        /** @var DOMElement $formularz */
        $formularz = $formularze->item(0);

        $this->assertSame(
            'post',
            strtolower((string) $formularz->getAttribute('method')),
            "Formularz {$akcja} nie ma `method=\"POST\"`, więc przeglądarka wyśle GET i akcja nie zadziała.",
        );

        $przyciski = $xpath->query('.//button[translate(@type, "SUBMIT", "submit") = "submit" or not(@type)]', $formularz);

        $this->assertNotFalse($przyciski);
        $this->assertGreaterThan(
            0,
            $przyciski->length,
            "Formularz {$akcja} nie ma przycisku wysyłki — bez skryptu nie ma go czym wysłać.",
        );

        $token = $xpath->query('.//input[@name="_token"]', $formularz);

        $this->assertNotFalse($token);
        $this->assertGreaterThan(
            0,
            $token->length,
            "Formularz {$akcja} nie niesie `@csrf` — wysłanie skończyłoby się ekranem 419.",
        );

        return $formularz;
    }

    /** Bezpieczny literał XPath: adresy zawierają `@` i bywają z apostrofem. */
    private static function cytat(string $wartosc): string
    {
        if (! str_contains($wartosc, "'")) {
            return "'".$wartosc."'";
        }

        return 'concat("'.str_replace("'", '", \'\', "', $wartosc).'")';
    }

    private function wpisPubliczny(User $autor): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
    }

    private function przepisPubliczny(User $autor): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Rosół na niedzielę',
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------
    // GŁÓWNA AKCJA PRODUKTU
    // -----------------------------------------------------------------

    public function test_glowna_akcja_dodania_wpisu_to_prawdziwy_formularz_z_polem_pliku(): void
    {
        $ania = $this->user('ania');

        $html = $this->actingAs($ania)->get(route('posts.create'))->assertOk()->getContent();

        $formularz = $this->sprawdzFormularz($html, route('posts.store'), 'wpisu ze zdjęciem');

        // `enctype` jest tu warunkiem, nie ozdobą: bez niego przeglądarka
        // wysyła same nazwy plików i zdjęcie nigdy nie dochodzi do serwera.
        $this->assertSame(
            'multipart/form-data',
            $formularz->getAttribute('enctype'),
            'Formularz publikacji bez `enctype="multipart/form-data"` nie prześle zdjęcia.',
        );

        $this->assertStringContainsString(
            'type="file"',
            $formularz->ownerDocument?->saveHTML($formularz) ?: '',
            'Formularz publikacji nie ma pola pliku — zdjęcie dałoby się dodać wyłącznie skryptem.',
        );
    }

    public function test_komentarz_pod_wpisem_to_prawdziwy_formularz(): void
    {
        $basia = $this->user('basia');
        $ania = $this->user('ania');
        $wpis = $this->wpisPubliczny($basia);

        $html = $this->actingAs($ania)->get(route('posts.show', $wpis))->assertOk()->getContent();

        $this->sprawdzFormularz($html, route('posts.comment', $wpis), 'komentarza pod wpisem');
    }

    public function test_ugotowalem_to_prawdziwy_formularz(): void
    {
        $zofia = $this->user('zofia');
        $ania = $this->user('ania');
        $przepis = $this->przepisPubliczny($zofia);

        $html = $this->actingAs($ania)->get(route('cooked.create', $przepis->slug))->assertOk()->getContent();

        $this->sprawdzFormularz($html, route('cooked.store', $przepis->slug), '„Ugotowałem"');
    }

    // -----------------------------------------------------------------
    // SPOŁECZNOŚĆ
    // -----------------------------------------------------------------

    public function test_obserwowanie_osoby_to_prawdziwy_formularz(): void
    {
        $zofia = $this->user('zofia');
        $ania = $this->user('ania');

        $html = $this->actingAs($ania)->get(route('profile.show', 'zofia'))->assertOk()->getContent();

        $this->sprawdzFormularz($html, route('social.follow', 'zofia'), 'obserwowania osoby');
    }

    public function test_obserwowanie_tagu_to_prawdziwy_formularz(): void
    {
        $ania = $this->user('ania');
        Tag::create(['name' => 'Zupy', 'normalized_name' => 'zupy', 'slug' => 'zupy']);

        $html = $this->actingAs($ania)->get(route('tags.show', 'zupy'))->assertOk()->getContent();

        $this->sprawdzFormularz($html, route('tags.follow', 'zupy'), 'obserwowania tagu');
    }

    public function test_zapisanie_do_zeszytu_to_prawdziwy_formularz(): void
    {
        $basia = $this->user('basia');
        $ania = $this->user('ania');
        $wpis = $this->wpisPubliczny($basia);

        $html = $this->actingAs($ania)->get(route('posts.show', $wpis))->assertOk()->getContent();

        $this->sprawdzFormularz($html, route('collections.save-post', $wpis), 'zapisania wpisu do zeszytu');
    }

    public function test_zgloszenie_tresci_to_prawdziwy_formularz(): void
    {
        $basia = $this->user('basia');
        $ania = $this->user('ania');
        $wpis = $this->wpisPubliczny($basia);

        $html = $this->actingAs($ania)
            ->get(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]))
            ->assertOk()
            ->getContent();

        $this->sprawdzFormularz(
            $html,
            route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]),
            'zgłoszenia treści',
        );
    }

    // -----------------------------------------------------------------
    // USTAWIENIA
    // -----------------------------------------------------------------

    public function test_zmiana_profilu_to_prawdziwy_formularz(): void
    {
        $ania = $this->user('ania');

        $html = $this->actingAs($ania)->get(route('settings.profile'))->assertOk()->getContent();

        $formularz = $this->sprawdzFormularz($html, route('settings.profile'), 'zmiany profilu');

        // Trasa jest PUT, a przeglądarka umie tylko GET i POST — bez ukrytego
        // `_method` formularz trafiłby w trasę POST, której tam nie ma.
        $this->assertStringContainsString(
            'name="_method"',
            $formularz->ownerDocument?->saveHTML($formularz) ?: '',
            'Formularz profilu nie ma podmiany metody — bez skryptu nie trafi w trasę PUT.',
        );
    }

    public function test_przelacznik_motywu_to_prawdziwy_formularz(): void
    {
        $ania = $this->user('ania');

        $html = $this->actingAs($ania)->get(route('home'))->assertOk()->getContent();

        $this->sprawdzFormularz($html, route('theme.update'), 'zmiany motywu');
    }

    // -----------------------------------------------------------------
    // NAWIGACJA
    // -----------------------------------------------------------------

    /**
     * Oba menu stoją na `<details>`, a nie na przycisku sterowanym skryptem.
     *
     * `<details>` otwiera się w przeglądarce bez ani jednej linijki
     * JavaScriptu — i to jest jedyny powód, dla którego menu konta i menu
     * karty wpisu działają przy niedociągniętym skrypcie. Przepisanie ich na
     * `<button>` + skrypt zostawiłoby dwa martwe przyciski w najbardziej
     * ruchliwych miejscach serwisu.
     */
    public function test_menu_konta_i_menu_karty_wpisu_stoja_na_details(): void
    {
        $basia = $this->user('basia');
        $ania = $this->user('ania');
        $ania->following()->attach($basia->getKey(), ['created_at' => now()]);
        $this->wpisPubliczny($basia);

        $html = $this->actingAs($ania)->get(route('home'))->assertOk()->getContent();

        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $xpath = new DOMXPath($dokument);

        foreach ([
            'topbar-konto' => 'menu konta w belce',
            'post-card-menu' => 'menu „…" na karcie wpisu',
        ] as $klasa => $czego) {
            $menu = $xpath->query(sprintf('//details[contains(@class, %s)]', self::cytat($klasa)));

            $this->assertNotFalse($menu);
            $this->assertGreaterThan(
                0,
                $menu->length,
                "{$czego} nie jest znacznikiem <details> — bez skryptu zostałby martwy przycisk (AGENTS.md §5).",
            );

            $summary = $xpath->query('./summary', $menu->item(0));

            $this->assertNotFalse($summary);
            $this->assertGreaterThan(
                0,
                $summary->length,
                "{$czego} nie ma <summary>, więc nie ma czym go otworzyć bez skryptu.",
            );
        }
    }

    /**
     * Kreator przepisu JEST komponentem Livewire i bez skryptu nie zadziała —
     * i to wolno (D-053 punkt 2). Nie wolno tylko zostawić przy nim człowieka
     * bez wyjścia, dlatego droga bez skryptu ma być WIDOCZNA, a nie schowana
     * w `<noscript>`: przy niedociągniętym skrypcie `<noscript>` się nie
     * pokaże, bo JavaScript jest przecież włączony.
     */
    public function test_kreator_przepisu_zostawia_widoczne_wyjscie_bez_skryptu(): void
    {
        $ania = $this->user('ania');
        $przepis = $this->przepisPubliczny($ania);

        $html = $this->actingAs($ania)->get(route('recipes.details', $przepis->slug))->assertOk()->getContent();

        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $xpath = new DOMXPath($dokument);
        $wyjscie = $xpath->query(sprintf(
            '//main//a[@href=%s and not(ancestor::noscript)]',
            self::cytat(route('recipes.edit', $przepis->slug)),
        ));

        $this->assertNotFalse($wyjscie);
        $this->assertGreaterThan(
            0,
            $wyjscie->length,
            'Kreator przepisu nie ma widocznego (poza `<noscript>`) odnośnika do formularza na jednej stronie '
            .'— przy niedociągniętym skrypcie człowiek zostaje przed ekranem, który nic nie robi.',
        );
    }

    // -----------------------------------------------------------------
    // SKAN WIDOKÓW
    // -----------------------------------------------------------------

    /**
     * ŻADEN WIDOK NIE WIESZA AKCJI NA ATRYBUCIE ZDARZENIA.
     *
     * `onclick="…"` to najkrótsza droga do martwego przycisku: bez skryptu
     * nie robi nic, a przy naszej polityce CSP (`ApplySecurityHeaders`, bez
     * `unsafe-inline`) nie robi nic także ze skryptem — czyli psuje się
     * u WSZYSTKICH, nie tylko u osób bez JavaScriptu.
     *
     * PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md`: test skanujący pliki przechodzi
     * także wtedy, gdy nie znajdzie ŻADNEGO pliku — zero trafień jest dla
     * niego sukcesem. Dlatego niżej stoi asercja na minimalną liczbę
     * przeskanowanych widoków; bez niej przeniesienie katalogu wyłączyłoby
     * ten test bez jednego czerwonego przebiegu.
     */
    public function test_zaden_widok_nie_wiesza_akcji_na_atrybucie_zdarzenia(): void
    {
        $katalog = resource_path('views');

        $this->assertDirectoryExists($katalog, 'Katalog widoków zniknął — skan nie miałby czego czytać.');

        $pliki = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog)),
            '/\.blade\.php$/',
        );

        $przeskanowane = 0;
        $trafienia = [];

        foreach ($pliki as $plik) {
            $przeskanowane++;
            $tresc = (string) file_get_contents($plik->getPathname());

            // Atrybut zdarzenia w HTML-u: `onclick=`, `onsubmit=`, `onchange=`…
            // Wzorzec wymaga znaku `<`-owego kontekstu (spacja albo cudzysłów
            // przed `on…`), żeby nie łapać polskich słów w treści.
            if (preg_match_all('/[\s"\']on[a-z]+\s*=\s*["\']/i', $tresc, $dopasowania) > 0) {
                $trafienia[] = str_replace($katalog.'/', '', $plik->getPathname())
                    .': '.implode(', ', array_map('trim', $dopasowania[0]));
            }
        }

        $this->assertGreaterThan(
            100,
            $przeskanowane,
            "Skan przeczytał tylko {$przeskanowane} widoków — zła ścieżka? (pułapka 2)",
        );

        $this->assertSame(
            [],
            $trafienia,
            "Widok wiesza akcję na atrybucie zdarzenia — bez skryptu to martwy przycisk, a przy naszym CSP martwy także ze skryptem:\n"
            .implode("\n", $trafienia),
        );
    }
}

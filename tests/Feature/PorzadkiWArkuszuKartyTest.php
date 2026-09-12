<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Porządki w arkuszu karty wpisu — dwie sprawy, które żyją w tym samym pliku
 * (`resources/css/app.css`) i dlatego stoją w jednym teście.
 *
 * A. MARTWA SIATKA `.landing-wpisy`.
 *    Reguła została po decyzji „jeden wpis pod drugim" na stronie powitalnej
 *    (`resources/css/strony-publiczne.css`, przy `.landing-wpisy-kolumna`).
 *    Widoki przeszły na `landing-wpisy-kolumna` i `landing-wpisy-dwie`, a
 *    stara siatka stała w arkuszu dalej — z komentarzem opisującym ją jak
 *    żywą, więc przy następnym czytaniu wyglądała na kod w użyciu.
 *
 * B. CHIPSY Z TEMATAMI BEZ WCIĘCIA BOCZNEGO.
 *    Zmierzone przed poprawką (Chromium 1194,
 *    `scripts/wciecia-boczne-karty-wpisu.mjs`, `/home` i `/tag/{slug}`,
 *    okna 1512 px i 390 px; odległość od krawędzi karty do pierwszego piksela
 *    treści bloku):
 *
 *        post-card-head .....  20 px z lewej   20 px z prawej
 *        post-card-body .....  20 px           20 px
 *        post-card-recipe ...  20 px           20 px
 *        post-card-tagi .....   0 px            0 px   ← usterka
 *        post-card-actions ..  20 px           20 px
 *
 *    Po poprawce `.post-card-tagi` ma 20 px z obu stron w obu szerokościach.
 *
 * CZEGO TEN TEST PILNUJE
 * Nie wyglądu — od tego jest pomiar w przeglądarce. Pilnuje tego, co przy
 * następnym przestylowaniu zniknęłoby najciszej: że martwa reguła nie wraca,
 * że wcięcie chipsów jest zadeklarowane TOKENEM, i że blok, na którym to
 * wcięcie wisi, naprawdę stoi w wyrenderowanym dokumencie jako dziecko karty.
 *
 * KOMENTARZE WYCINAMY, ZANIM COKOLWIEK DOPASUJEMY — nauka z PR #400 i #405:
 * nad tymi regułami stoi kilkadziesiąt linii komentarza, w którym każdy
 * selektor pada z nazwy, a wzorzec „selektor, potem `{…}`" nie odróżnia
 * reguły od nazwy klasy WYMIENIONEJ W KOMENTARZU.
 */
class PorzadkiWArkuszuKartyTest extends TestCase
{
    use RefreshDatabase;

    /** Arkusze, w których szukamy reguł. */
    private const ARKUSZE = [
        'css/app.css',
        'css/strony-publiczne.css',
    ];

    /** Treść arkusza BEZ komentarzy — patrz opis klasy. */
    private function bezKomentarzy(string $sciezka): string
    {
        $tresc = (string) file_get_contents(resource_path($sciezka));

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    /**
     * Pary „arkusz → deklaracje" dla każdej reguły, której lista selektorów
     * wymienia klasę `$klasa` jako CAŁY token.
     *
     * `(?![\w-])` jest tu całą robotą: bez niego `.landing-wpisy` łapie się
     * na `.landing-wpisy-kolumna` i test przechodzi na cudzej regule.
     *
     * @return list<array{arkusz: string, selektory: string, deklaracje: string}>
     */
    private function regulyDlaKlasy(string $klasa): array
    {
        $znalezione = [];

        foreach (self::ARKUSZE as $arkusz) {
            preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $this->bezKomentarzy($arkusz), $reguly, PREG_SET_ORDER);

            foreach ($reguly as $regula) {
                $selektory = (string) preg_replace('/\s+/', ' ', trim($regula[1]));

                if (preg_match('/\.'.preg_quote($klasa, '/').'(?![\w-])/', $selektory) === 1) {
                    $znalezione[] = [
                        'arkusz' => $arkusz,
                        'selektory' => $selektory,
                        'deklaracje' => trim((string) preg_replace('/\s+/', ' ', $regula[2])),
                    ];
                }
            }
        }

        return $znalezione;
    }

    /**
     * Widoki, które używają `$klasa` jako TOKENU w atrybucie `class`.
     *
     * Tokenu, nie podciągu — inaczej `landing-wpisy-kolumna` udowadniałaby, że
     * `landing-wpisy` jest w użyciu, i martwy kod zostawałby w arkuszu
     * w nieskończoność, broniony przez własną nazwę.
     *
     * @return list<string>
     */
    private function widokiUzywajaceKlasy(string $klasa): array
    {
        $katalog = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        $uzywajace = [];

        foreach ($katalog as $plik) {
            if (! $plik instanceof \SplFileInfo || ! $plik->isFile()) {
                continue;
            }

            if (! str_ends_with($plik->getFilename(), '.blade.php')) {
                continue;
            }

            $tresc = (string) file_get_contents($plik->getPathname());

            if (preg_match_all('/class\s*=\s*(["\'])(.*?)\1/s', $tresc, $atrybuty) !== false) {
                foreach ($atrybuty[2] ?? [] as $wartosc) {
                    $tokeny = preg_split('/\s+/', trim($wartosc), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                    if (in_array($klasa, $tokeny, true)) {
                        $uzywajace[] = $plik->getPathname();
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($uzywajace));
    }

    // === A. Martwy kod ====================================================

    public function test_arkusz_nie_trzyma_regul_dla_klas_landing_wpisy_ktorych_zaden_widok_nie_uzywa(): void
    {
        // Cała rodzina, nie tylko usunięta klasa: `landing-wpisy-kolumna`
        // i `landing-wpisy-dwie` SĄ w widokach, więc ich reguły mają zostać,
        // a test sam się dostraja, gdy któraś z nich kiedyś odpadnie.
        $rodzina = ['landing-wpisy', 'landing-wpisy-kolumna', 'landing-wpisy-dwie'];

        $martwe = [];

        foreach ($rodzina as $klasa) {
            $reguly = $this->regulyDlaKlasy($klasa);

            if ($reguly === [] || $this->widokiUzywajaceKlasy($klasa) !== []) {
                continue;
            }

            foreach ($reguly as $regula) {
                $martwe[] = "{$regula['arkusz']}: `{$regula['selektory']}` (klasa `{$klasa}`)";
            }
        }

        $this->assertSame(
            [],
            $martwe,
            "W arkuszach stoją reguły dla klas, których nie używa ANI JEDEN widok Blade:\n  ".
            implode("\n  ", $martwe)."\n".
            'Tak zostało `.landing-wpisy` po przejściu strony powitalnej na jedną kolumnę: '.
            'reguła bez użycia, a nad nią komentarz opisujący ją jak żywą siatkę — czyli kod, '.
            'który przy następnym czytaniu wygląda na potrzebny. Albo klasa wraca do widoku, '.
            'albo reguła wychodzi z arkusza razem ze swoim komentarzem. '.
            '(Komentarz historyczny w `strony-publiczne.css`, który TŁUMACZY, co było, zostaje '.
            'i temu testowi nie przeszkadza — komentarze są wycinane przed dopasowaniem.)',
        );
    }

    public function test_szukanie_uzyc_liczy_tokeny_a_nie_podciagi(): void
    {
        // KONTROLA SAMEGO NARZĘDZIA. Gdyby `widokiUzywajaceKlasy()` szukało
        // podciągu, `landing-wpisy` „znalazłoby się" w `landing-wpisy-kolumna`
        // i test wyżej przechodziłby z martwą regułą w arkuszu — czyli
        // wyglądałby jak test, a nie pilnowałby niczego.
        $this->assertNotEmpty(
            $this->widokiUzywajaceKlasy('landing-wpisy-kolumna'),
            'Żaden widok nie używa `landing-wpisy-kolumna`, a ta klasa stoi w `pages/landing.blade.php`. '.
            'To znaczy, że szukanie użyć jest zepsute, a nie że klasa jest martwa.',
        );

        $this->assertSame(
            [],
            $this->widokiUzywajaceKlasy('landing-wpisy'),
            'Token `landing-wpisy` znalazł się w widokach. Albo klasa naprawdę wróciła '.
            '(wtedy dopisz jej regułę i popraw ten test), albo szukanie liczy PODCIĄGI '.
            'i myli ją z `landing-wpisy-kolumna` — a wtedy nic tu nie jest pilnowane.',
        );
    }

    // === B. Wcięcie boczne chipsów z tematami =============================

    public function test_chipsy_z_tematami_maja_zadeklarowane_wciecie_boczne(): void
    {
        $reguly = $this->regulyDlaKlasy('post-card-tagi');

        $this->assertNotEmpty(
            $reguly,
            'W arkuszu nie ma ani jednej reguły dla `.post-card-tagi`. Widok bierze dla tematów '.
            'gotowe `.chipsy`, a `.chipsy` nie ma wcięcia bocznego — powstało dla chipsów '.
            'stojących wprost w kolumnie strony, która wcięcie ma sama. Karta wpisu go nie ma '.
            '(`.post-card { padding: 0 }`, bo zdjęcie idzie od krawędzi do krawędzi), więc bez '.
            'tej reguły chipsy dotykają krawędzi karty: zmierzone 0 px z lewej i 0 px z prawej '.
            'na 1512 px i na 390 px, przy 20 px na każdym innym bloku karty.',
        );

        $maWciecie = false;

        foreach ($reguly as $regula) {
            $trafienia = [];

            // `padding-inline`, `padding-inline-start/end`, `padding-left/right`
            // — każdy z nich jest poprawnym zapisem tego samego wcięcia.
            preg_match_all(
                '/(?<![\w-])padding-(?:inline(?:-start|-end)?|left|right)\s*:\s*([^;}]+)/',
                $regula['deklaracje'],
                $dopasowania,
            );

            foreach ($dopasowania[1] ?? [] as $wartosc) {
                $trafienia[] = trim($wartosc);
            }

            foreach ($trafienia as $wartosc) {
                $this->assertMatchesRegularExpression(
                    '/^(?:var\(--spacing-\d+\)\s*)+$/',
                    $wartosc,
                    'Wcięcie boczne `.post-card-tagi` jest podane wartością spoza skali odstępów '.
                    "(zastane: `{$wartosc}`). Token, nie piksele: to wcięcie ma być tą samą liczbą, ".
                    'co wcięcie reszty karty, i ma rosnąć razem z pismem przy czcionce przeglądarki '.
                    '200 % (druga strona D-082/D-107).',
                );

                $this->assertStringNotContainsString(
                    'var(--spacing-0)',
                    $wartosc,
                    'Wcięcie boczne `.post-card-tagi` jest ustawione na zero. To jest dokładnie ten '.
                    'stan, przez który zgłoszono usterkę: chipsy z tematami dotykały krawędzi karty.',
                );

                $maWciecie = true;
            }
        }

        $this->assertTrue(
            $maWciecie,
            'Reguła dla `.post-card-tagi` istnieje, ale nie deklaruje wcięcia bocznego '.
            '(`padding-inline` albo `padding-left`/`padding-right`). Zastane deklaracje: '.
            implode(' | ', array_column($reguly, 'deklaracje')),
        );
    }

    public function test_blok_tematow_stoi_na_karcie_wpisu_jako_jej_wlasne_dziecko(): void
    {
        // CHIPSY RENDERUJĄ SIĘ WARUNKOWO (D-099, D-106): `post-card.blade.php`
        // pokazuje tematy tylko tam, gdzie relacja tagów jest DOŁADOWANA
        // (`$post->relationLoaded('tags')`). Strona tagu ją ładuje
        // (`TagController::show`), strona pojedynczego wpisu — nie. Dlatego
        // mierzony ekran to `/tag/{slug}`, a nie pierwszy lepszy adres z kartą.
        $autorka = $this->user('kucharka');

        $tag = Tag::factory()->create(['name' => 'Zupy jesienne']);

        $wpis = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'body' => 'Zupa z dyni wyszła gęsta i słodka.',
        ]);
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

        $html = $this->get(route('tags.show', $tag))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $bloki = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' post-card-tagi ')]");

        $this->assertInstanceOf(\DOMNodeList::class, $bloki);
        $this->assertGreaterThan(
            0,
            $bloki->length,
            'Na stronie tagu nie ma ani jednego bloku `.post-card-tagi`. Test nie sprawdziłby '.
            'wtedy niczego — pusty ekran przechodzi każdą asercję (D-099, D-106). Popraw '.
            'przygotowanie danych albo widok, nie asercję.',
        );

        $blok = $bloki->item(0);
        $this->assertInstanceOf(\DOMElement::class, $blok);

        $rodzic = $blok->parentNode;
        $klasyRodzica = $rodzic instanceof \DOMElement
            ? preg_split('/\s+/', trim($rodzic->getAttribute('class'))) ?: []
            : [];

        $this->assertContains(
            'post-card',
            $klasyRodzica,
            'Blok `.post-card-tagi` nie jest już bezpośrednim dzieckiem karty wpisu — jego '.
            'rodzicem jest '.
            ($rodzic instanceof \DOMElement
                ? '<'.$rodzic->tagName.' class="'.$rodzic->getAttribute('class').'">'
                : 'coś, co nie jest elementem').
            '. Wcięcie boczne wisi na tym bloku właśnie dlatego, że stoi on wprost w karcie, '.
            'która sama wcięcia nie ma. Owinięcie go czymkolwiek z własnym `padding` doda '.
            'wcięcie do wcięcia i chipsy uciekną do środka, nie ruszając ani jednej linii CSS-a.',
        );

        $this->assertContains(
            'chipsy',
            preg_split('/\s+/', trim($blok->getAttribute('class'))) ?: [],
            'Blok tematów stracił klasę `chipsy`. To ona daje układ i odstępy między chipami; '.
            '`post-card-tagi` niesie WYŁĄCZNIE wcięcie boczne karty i sama niczego nie układa.',
        );
    }
}

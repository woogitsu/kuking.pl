<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Świeżo z Kuking" (/odkryj) używa KOLUMNY SZYNY, zamiast zostawiać ją pustą.
 *
 * ZGŁOSZENIE WŁAŚCICIELA: „https://kuking.pl/odkryj tu się zepsuło albo nie
 * było naprawione, prawa kolumna pusta wszystko na środku".
 *
 * ZMIERZONE PRZED POPRAWKĄ (Chromium, dane demo, konto moderatora):
 *   * okno 1920 px: rama 1424, kolumna czytania 720, TRZECIA KOLUMNA PUSTA,
 *     pas pustki po prawej 656 px — przy 272 px na `/` i na stronie przepisu;
 *   * okno 1512 px: to samo, pustka 452 px — przy 68 px na `/`;
 *   * gość: cała strona zwinięta do 768 px, bo ekran bez szyny bierze
 *     `--container-strona-solo` (D-122).
 * PO: zalogowany 272 / 68 px (tyle co `/`), gość rama 1152 px, kolumna
 * czytania bez zmian — 688 px w obu stanach.
 *
 * DLACZEGO TEN TEST NIE SPRAWDZA `<x-slot:rail>`
 * Bo ten ekran świadomie go NIE używa — tak samo jak strona przepisu
 * (`StronaPrzepisuUzywaKolumnySzynyTest`, issue #365). Slot renderuje się
 * w kodzie ZA całym `<main>`, a tablica dnia stoi tu PRZED wpisami i to jest
 * jej miejsce na telefonie: pod slotem zjechałaby pod wszystkie karty wpisów
 * i przycisk „Pokaż więcej". Kolejność w kodzie zostaje kolejnością z telefonu,
 * w bok przesuwa blok dopiero siatka ekranu.
 *
 * Test pilnuje CZTERECH rzeczy — każda bez pozostałych nic nie dowodzi:
 *   1. ekran prosi o szerszą ramę (klasa na `.app-body`, a u gościa także na
 *      `<body>`, bo belka i stopka biorą szerokość stamtąd);
 *   2. tablica dnia jest BEZPOŚREDNIM dzieckiem siatki i stoi w kodzie PRZED
 *      wpisami;
 *   3. arkusz naprawdę daje tej siatce dwie kolumny, kolumna tekstu zostaje
 *      przy `--container-content`, a druga kolumna włącza się dopiero od
 *      80rem (czyli przy czcionce przeglądarki 200% nie włącza się wcale);
 *   4. pusty stan nie zabiera kolumny szyny — przy zerze wpisów tablica stoi
 *      obok `<x-empty-state>` tak samo jak obok pełnej listy.
 */
class OdkrywanieUzywaKolumnySzynyTest extends TestCase
{
    use RefreshDatabase;

    /** Klasa ramy: `<main>` bierze kolumnę czytania RAZEM z kolumną szyny. */
    private const KLASA_RAMY = 'app-body-tresc-z-szyna';

    /** Klasa na `<body>` — szerokość belki i stopki u gościa. */
    private const KLASA_BELKI = 'uklad-solo-z-szyna';

    private function wpis(): Post
    {
        return Post::factory()->for($this->user('gotujaca'), 'author')->create([
            'visibility' => 'public',
            'published_at' => now()->subHour(),
            'body' => 'Rosół z niedzieli, jak co tydzień.',
        ]);
    }

    /**
     * ARKUSZ BEZ KOMENTARZY.
     *
     * `MinimalnyRozmiarTekstuTest` oblał kiedyś dlatego, że wyrażenie
     * regularne trafiło na nazwę klasy WSPOMNIANĄ W KOMENTARZU i doczytało
     * ciało zupełnie innej reguły. W tym pliku komentarzy wymieniających
     * `.odkryj-uklad` i `.odkryj-szyna` jest dużo — więc wycinamy je, zanim
     * cokolwiek dopasujemy. Patrz `docs/PULAPKI_TESTOW.md`.
     */
    private function arkusz(): string
    {
        $css = (string) file_get_contents(resource_path('css/ekran-odkrywania.css'));
        $bezKomentarzy = preg_replace('#/\*.*?\*/#s', '', $css);

        $this->assertIsString($bezKomentarzy, 'Nie udało się wyciąć komentarzy z arkusza ekranu.');
        $this->assertStringNotContainsString(
            '/*',
            $bezKomentarzy,
            'W arkuszu zostały komentarze — dopasowania niżej mogą czytać tekst, a nie reguły.',
        );

        return $bezKomentarzy;
    }

    private function klasy(string $html, string $selektor): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $wezel = self::elementDom($xpath->query($selektor)->item(0), "W dokumencie nie ma elementu „{$selektor}” — układ strony się zmienił.");

        return ' '.preg_replace('/\s+/', ' ', (string) $wezel->getAttribute('class')).' ';
    }

    // --- HTML ------------------------------------------------------------

    public function test_odkrywanie_prosi_o_kolumne_szyny(): void
    {
        $this->wpis();

        $html = (string) $this->actingAs($this->user('czytelnik'))
            ->get(route('discover'))->assertOk()->getContent();

        $this->assertStringContainsString(
            ' '.self::KLASA_RAMY.' ',
            $this->klasy($html, "//div[contains(concat(' ', normalize-space(@class), ' '), ' app-body ')]"),
            'Rama „Świeżo z Kuking" nie niesie klasy, która oddaje jej kolumnę szyny — '
            .'kolumna zostaje pusta, a treść wygląda na wciśniętą w lewo (zgłoszenie właściciela).',
        );

        $this->assertStringContainsString(
            'odkryj-uklad',
            $html,
            'Brak siatki `.odkryj-uklad` — tablica dnia nie ma czym przejść do drugiej kolumny.',
        );
    }

    /**
     * U GOŚCIA LICZY SIĘ TAKŻE BELKA I STOPKA — `/odkryj` jest otwarte bez
     * konta (`FeedController::discover`), więc to nie jest przypadek
     * teoretyczny. Szerokość belki i stopki bierze się z klasy na `<body>`;
     * bez niej logotyp stanąłby na lewo od pierwszego słowa nagłówka.
     */
    public function test_goscia_belka_i_stopka_ida_za_trescia(): void
    {
        $this->wpis();

        $html = (string) $this->get(route('discover'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/<body class="[^"]*'.self::KLASA_BELKI.'/',
            $html,
            'Gość dostaje szerszą treść, ale belka i stopka zostają przy 768 px.',
        );

        // KONTROLA UJEMNA W DRUGĄ STRONĘ: ekran BEZ szyny tej klasy nie ma.
        // Bez tej połowy przeszłaby zmiana „daj wszystkim gościom szerszą
        // belkę", która rozjechałaby logowanie i rejestrację.
        $this->assertStringNotContainsString(
            self::KLASA_BELKI,
            (string) $this->get(route('login'))->assertOk()->getContent(),
            'Ekran logowania — bez szyny i bez szerokiej treści — dostał szerszą belkę.',
        );
    }

    /**
     * TABLICA JEST BEZPOŚREDNIM DZIECKIEM SIATKI I STOI W KODZIE PRZED WPISAMI.
     *
     * Dwie strony jednej decyzji: `grid-column` działa wyłącznie na
     * BEZPOŚREDNIM dziecku siatki (owijka wsunięta gdzieś głębiej cicho
     * przestałaby wchodzić w kolumnę szyny), a miejsce w kodzie jest tym,
     * co widzi telefon i czytnik ekranu. Gdyby tablica trafiła do
     * `<x-slot:rail>`, ten test oblałby na drugiej asercji — i o to chodzi.
     */
    public function test_tablica_stoi_w_siatce_i_w_kodzie_przed_wpisami(): void
    {
        $this->wpis();

        $html = (string) $this->actingAs($this->user('czytelniczka'))
            ->get(route('discover'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $szyna = $xpath->query(
            '//div[contains(concat(" ", normalize-space(@class), " "), " odkryj-uklad ")]'
            .'/div[contains(concat(" ", normalize-space(@class), " "), " odkryj-szyna ")]',
        )->item(0);

        $this->assertNotNull(
            $szyna,
            'Blok szyny nie jest bezpośrednim dzieckiem `.odkryj-uklad` — `grid-column` z arkusza '
            .'go nie dotyczy i zostaje w kolumnie czytania, choć obok stoi pusta kolumna szyny.',
        );

        $this->assertStringContainsString(
            'kuking-board',
            (string) $dom->saveHTML($szyna),
            'W kolumnie szyny nie ma tablicy „kuKINGi na dziś" — a ta kolumna nie jest miejscem '
            .'na cokolwiek innego dołożone po to, żeby nie było pusto.',
        );

        $tablica = strpos($html, 'kuking-na-dzis');
        $wpisy = strpos($html, 'Rosół z niedzieli, jak co tydzień.');

        $this->assertNotFalse($tablica, 'Na ekranie nie ma tablicy „kuKINGi na dziś".');
        $this->assertNotFalse($wpisy, 'Na ekranie nie ma wpisów.');
        $this->assertLessThan(
            $wpisy,
            $tablica,
            'Tablica dnia stoi w kodzie ZA wpisami. Na telefonie kolumn nie ma, więc znaczy to, '
            .'że widzi ją tylko ten, kto przewinie całą listę.',
        );
    }

    /**
     * PUNKT 4: PUSTY STAN TEŻ MA SĄSIADA.
     *
     * Ekran bez ani jednego wpisu jest tym, który najbardziej potrzebuje
     * tablicy dnia (docs/product/COLD_START.md) — a jednocześnie tym, na
     * którym najłatwiej zgubić ją warunkiem `@if`. Sprawdzamy oba stany
     * naraz: pusty stan JEST i siatka też JEST.
     */
    public function test_pusty_stan_nie_zabiera_kolumny_szyny(): void
    {
        $html = (string) $this->get(route('discover'))->assertOk()->getContent();

        $this->assertStringContainsString('empty-state', $html, 'Kontrola: to nie jest pusty stan.');
        $this->assertStringContainsString(
            'odkryj-szyna',
            $html,
            'Przy zerze wpisów ekran traci kolumnę szyny — zostaje samo zdanie „Jeszcze nic tu nie ma" '
            .'w lewej trzeciej części ekranu.',
        );
    }

    // --- ARKUSZ ----------------------------------------------------------

    public function test_arkusz_daje_ekranowi_dwie_kolumny(): void
    {
        $css = $this->arkusz();

        $this->assertSame(
            1,
            preg_match('/\.odkryj-uklad\s*\{([^}]*)\}/', $css, $siatka),
            'W `ekran-odkrywania.css` nie ma reguły `.odkryj-uklad` — klasa w HTML-u jest samym napisem.',
        );

        $this->assertMatchesRegularExpression(
            '/grid-template-columns:\s*minmax\(\s*0\s*,\s*var\(--container-content\)\s*\)\s+var\(--container-rail\)\s*;/',
            $siatka[1],
            'Siatka „Świeżo z Kuking" nie ma dwóch kolumn: kolumny czytania (`--container-content`) '
            .'i kolumny szyny (`--container-rail`).',
        );

        $this->assertSame(
            1,
            preg_match('/\.odkryj-szyna\s*\{([^}]*)\}/', $css, $szyna),
            'Brak reguły przenoszącej tablicę do drugiej kolumny — siatka jest, ale nic w nią nie wchodzi.',
        );

        $this->assertMatchesRegularExpression(
            '/grid-column:\s*2\s*;/',
            $szyna[1],
            'Tablica nie trafia do kolumny szyny.',
        );

        $this->assertMatchesRegularExpression(
            '/grid-row:\s*1\s*\/\s*span\s+\d\d+\s*;/',
            $szyna[1],
            'Tablica nie rozciąga się na wiersze siatki. Zostałaby w wierszu pierwszym i rozepchnęła go '
            .'do swojej wysokości — pod nagłówkiem zrobiłaby się dziura na kilkaset pikseli.',
        );
    }

    /**
     * DWIE KOLUMNY WŁĄCZAJĄ SIĘ DOPIERO OD 80rem — I TO JEST KONTROLA UJEMNA
     * DO CAŁEJ TEJ ZMIANY.
     *
     * 80rem to próg, od którego kolumna szyny w ogóle istnieje w `.app-body`.
     * Przy czcionce przeglądarki podkręconej do 200% ten próg to 2560 px,
     * więc się NIE załapuje — ekran wraca do jednego ciągu w kolejności
     * z kodu i nic się nie chowa. Próg niższy oznaczałby tablicę wciśniętą
     * w 352 px na tablecie albo przy podwojonym piśmie.
     */
    public function test_druga_kolumna_dopiero_od_progu_na_ktorym_ona_istnieje(): void
    {
        $css = $this->arkusz();

        $this->assertSame(
            1,
            preg_match('/@media\s*\(min-width:\s*(\d+)rem\)\s*\{\s*\.odkryj-uklad\s*\{/s', $css, $prog),
            'Siatka ekranu nie jest zamknięta w progu szerokości — dwie kolumny zostałyby także '
            .'na telefonie.',
        );

        $this->assertSame(
            80,
            (int) $prog[1],
            'Próg siatki rozjechał się z progiem, od którego kolumna szyny istnieje w `.app-body` '
            .'(80rem). Niżej tablica dostaje 352 px na tablecie; wyżej kolumna szyny stoi pusta '
            .'— czyli wraca zgłoszenie właściciela.',
        );
    }

    /**
     * ROŚNIE RAMA, NIE DŁUGOŚĆ WIERSZA.
     *
     * Oddanie ekranowi drugiej kolumny kusi, żeby przy okazji „wykorzystać
     * miejsce" i puścić listę na całą szerokość — a wiersz na 1400 px jest
     * nieczytelny niezależnie od wieku czytelnika (docs/UX_50_PLUS.md).
     * Zmierzone: kolumna czytania ma 688 px przed zmianą i 688 px po niej.
     */
    public function test_kolumna_czytania_nie_rosnie(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.odkryj-uklad\s*\{[^}]*minmax\(\s*0\s*,\s*var\(--container-content\)\s*\)/s',
            $this->arkusz(),
            'Pierwsza kolumna „Świeżo z Kuking" nie jest ograniczona do `--container-content`.',
        );

        $this->assertMatchesRegularExpression(
            '/\.app-main\s*\{[^}]*max-width:\s*var\(--container-content\)\s*;/s',
            (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/app.css'))),
            'Kolumna czytania przestała mieć sufit `--container-content` — tekst rozlewa się na całą ramę.',
        );
    }

    /**
     * NAGŁÓWEK STRONY NIE TRACI ODSTĘPU PRZEZ OWIJKĘ SIATKI.
     *
     * `tokens.css` daje ten odstęp regułą `.app-main > h1` — a od tej zmiany
     * nagłówek `/odkryj` nie jest już bezpośrednim dzieckiem `<main>`.
     * Zmierzone bez tej reguły: odstęp 24 → 0 px, czyli wracała usterka
     * zgłoszona przez właściciela 9 września („«Szukaj» tuż nad polem").
     */
    public function test_naglowek_zostaje_odsuniety_od_wstepu(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.odkryj-uklad\s*>\s*h1\s*\{[^}]*margin-bottom:\s*var\(--spacing-6\)\s*;/s',
            $this->arkusz(),
            'Nagłówek „Świeżo z Kuking" dotyka wstępu pod sobą: owijka siatki zabrała mu regułę '
            .'`.app-main > h1` z `tokens.css`, a nic jej nie zastąpiło.',
        );
    }
}

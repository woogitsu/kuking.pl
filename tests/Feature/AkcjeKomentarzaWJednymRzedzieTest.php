<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Układ akcji pod komentarzem — issue #433, zgłoszenie właściciela ze zrzutu
 * z telefonu: „odpowiedz i popraw jest jedno pod drugim, gdzie jest jednak
 * miejsce by dać obok siebie".
 *
 * CO BYŁO ZMIERZONE (Chromium, `scripts/uklad-komentarzy.mjs`, komentarz
 * o jednym zdaniu; przerwa od ostatniego piksela treści do ostatniego piksela
 * ostatniej akcji):
 *
 *     okno    własny komentarz         cudzy komentarz
 *     320 px  196,5 → 196,5 px (3 → 3 wiersze)   117 → 125 px (2 → 2)
 *     360 px  196,5 → 138 px   (3 → 2)           117 → 66,5 px (2 → 1)
 *     390 px  196,5 → 138 px   (3 → 2)           117 → 66,5 px (2 → 1)
 *     414 px  196,5 → 138 px   (3 → 2)           117 → 66,5 px (2 → 1)
 *
 * CZEGO TEN TEST PILNUJE, A CZEGO NIE
 * Nie pilnuje pikseli — od tego jest pomiar w przeglądarce, bo PHP nie widzi
 * układu. Pilnuje trzech rzeczy, które przy następnym przestylowaniu znikłyby
 * najciszej, a każda z nich cofa całe zgłoszenie albo łamie twardą regułę:
 *
 *  1. AKCJE ZWYKŁE SĄ W JEDNYM RODZICU. Rząd robi `flex` na
 *     `.akcje-komentarza`; akcja wyjęta z tego rodzica wraca do własnego
 *     wiersza, nie ruszając ani jednej linii CSS-a (nauka z D-158: reguła
 *     oparta na strukturze wymaga testu czytającego STRUKTURĘ, nie arkusz).
 *  2. „USUŃ" ZOSTAJE POZA TYM RZĘDEM, za kreską `.danger-zone`, i dalej
 *     wymaga potwierdzenia. AGENTS.md §5: akcja nieodwracalna jest odsunięta
 *     od zwykłych i pyta, zanim zrobi.
 *  3. RZĄD SIĘ ZAWIJA. Przy 320 px „Odpowiedz" (142,88 px) i „Popraw"
 *     (110,09 px) nie mieszczą się we wnętrzu karty (246 px). Bez
 *     `flex-wrap: wrap` wyjechałyby poza ekran zamiast się przełamać —
 *     a issue zabrania ratowania tego zwężaniem przycisków.
 *
 * ASERCJE DODATNIE IDĄ PO TREŚCI EKRANU (D-164), nie po całym dokumencie:
 * napisy „Odpowiedz" i „Usuń" stoją też poza `<main>`.
 */
class AkcjeKomentarzaWJednymRzedzieTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** Treść arkusza BEZ komentarzy — patrz `regulyDla()`. */
    private function css(string $plik): string
    {
        $tresc = (string) file_get_contents(resource_path('css/'.$plik));

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    /**
     * Deklaracje każdej reguły, której lista selektorów pasuje do wzorca.
     *
     * KOMENTARZE WYCINAMY, ZANIM COKOLWIEK DOPASUJEMY — nauka z PR #400
     * i z `OdstepPodZdjeciemNaKarcieWpisuTest`: nad tymi regułami stoi
     * kilkadziesiąt linii komentarza, w którym każdy selektor pada z nazwy,
     * a wzorzec „selektor, potem `{…}`" nie odróżnia reguły od nazwy klasy
     * WYMIENIONEJ W KOMENTARZU.
     *
     * @return list<string>
     */
    private function regulyDla(string $plik, string $wzorzecSelektora): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $this->css($plik), $reguly, PREG_SET_ORDER);

        $znalezione = [];

        foreach ($reguly as $regula) {
            $selektory = (string) preg_replace('/\s+/', ' ', trim($regula[1]));

            if (preg_match($wzorzecSelektora, $selektory) === 1) {
                $znalezione[] = $regula[2];
            }
        }

        return $znalezione;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    /**
     * Sekcja komentarzy — i to NIE jest ozdobnik.
     *
     * Strona wpisu ma WŁASNĄ `.danger-zone` z „Usuń ten wpis", i stoi ona
     * NAD komentarzami. Pierwsza wersja tego testu brała `.danger-zone`
     * z całego ekranu, więc pytała o blok kasujący WPIS, a nie komentarz —
     * asercja o potwierdzeniu przechodziła wtedy, mierząc cudzy przycisk.
     * Złapane przez czerwony test, nie przez przeczytanie kodu.
     */
    private function sekcjaKomentarzy(DOMXPath $xpath): DOMElement
    {
        $sekcje = $xpath->query('//section[@aria-labelledby="komentarze"]');

        $this->assertInstanceOf(DOMNodeList::class, $sekcje);
        $this->assertGreaterThan(
            0,
            $sekcje->length,
            'Na tym ekranie nie ma sekcji komentarzy. Pusty ekran przechodzi każdą asercję.',
        );

        $sekcja = $sekcje->item(0);
        $this->assertInstanceOf(DOMElement::class, $sekcja);

        return $sekcja;
    }

    /** Element o danej klasie WEWNĄTRZ sekcji komentarzy. */
    private function jedyny(DOMXPath $xpath, string $klasa, string $opis): DOMElement
    {
        $trafienia = $xpath->query(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$klasa} ')]",
            $this->sekcjaKomentarzy($xpath),
        );

        $this->assertInstanceOf(DOMNodeList::class, $trafienia);
        $this->assertGreaterThan(
            0,
            $trafienia->length,
            "Na tym ekranie nie ma ani jednego elementu `.{$klasa}` ({$opis}). ".
            'Pusty ekran przechodzi każdą asercję — popraw przygotowanie danych, nie asercję.',
        );

        $element = $trafienia->item(0);
        $this->assertInstanceOf(DOMElement::class, $element);

        return $element;
    }

    /**
     * Napisy celów dotykowych stojących WEWNĄTRZ danego elementu.
     *
     * Bierzemy `<summary>` i `<a>`/`<button>` z klasą `btn`, czyli dokładnie
     * to, w co człowiek celuje palcem.
     *
     * @return list<string>
     */
    private function akcjeW(DOMXPath $xpath, DOMElement $element): array
    {
        $trafienia = $xpath->query(
            './/*[self::summary or self::a or self::button]'.
            "[contains(concat(' ', normalize-space(@class), ' '), ' btn ')]",
            $element,
        );

        $this->assertInstanceOf(DOMNodeList::class, $trafienia);

        $napisy = [];

        foreach ($trafienia as $cel) {
            $napisy[] = trim((string) preg_replace('/\s+/', ' ', (string) $cel->textContent));
        }

        return $napisy;
    }

    /** Wpis z jednym komentarzem — autor wpisu i autor komentarza osobno. */
    private function wpisZKomentarzem(string $autorWpisu, string $autorKomentarza): array
    {
        $gospodarz = $this->user($autorWpisu);
        $piszacy = $autorWpisu === $autorKomentarza ? $gospodarz : $this->user($autorKomentarza);

        $wpis = Post::factory()->create(['author_id' => $gospodarz->getKey()]);

        $komentarz = Comment::factory()->create([
            'author_id' => $piszacy->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Pysznie wygląda',
            // W OKNIE 15 MINUT, bo inaczej „Popraw" w ogóle się nie renderuje
            // i test mierzyłby układ, w którym zgłoszonej pary nie ma.
            'created_at' => now()->subMinute(),
        ]);

        return [$gospodarz, $piszacy, $wpis, $komentarz];
    }

    public function test_wlasny_komentarz_ma_odpowiedz_i_popraw_w_jednym_rzedzie(): void
    {
        // Ten sam człowiek jest autorem wpisu i komentarza: widzi „Odpowiedz",
        // „Popraw" i „Usuń", czyli układ dokładnie ze zrzutu właściciela.
        [$gospodarz, , $wpis] = $this->wpisZKomentarzem('gospodyni', 'gospodyni');

        $ekran = $this->trescEkranu(
            (string) $this->actingAs($gospodarz)->get(route('posts.show', $wpis))->assertOk()->getContent(),
        );

        $xpath = $this->xpath($ekran);
        $rzad = $this->jedyny($xpath, 'akcje-komentarza', 'rząd akcji pod komentarzem');
        $akcje = $this->akcjeW($xpath, $rzad);

        $this->assertContains(
            'Odpowiedz',
            $akcje,
            'W rzędzie `.akcje-komentarza` nie ma „Odpowiedz". Rząd robi `flex` na tym '.
            'jednym rodzicu, więc akcja wyjęta poza niego wraca do WŁASNEGO WIERSZA — '.
            'czyli do stanu ze zgłoszenia #433, bez zmiany jednej linii w arkuszu. '.
            'Zastane akcje w rzędzie: '.(implode(', ', $akcje) ?: '(brak)'),
        );

        $this->assertContains(
            'Popraw',
            $akcje,
            'W rzędzie `.akcje-komentarza` nie ma „Popraw" — a to była DRUGA połowa '.
            'zgłoszenia: „odpowiedz i popraw jest jedno pod drugim". Zmierzone przy '.
            '390 px: obok siebie blok akcji ma 138 px zamiast 196,5 px. '.
            'Zastane akcje w rzędzie: '.(implode(', ', $akcje) ?: '(brak)'),
        );
    }

    public function test_cudzy_komentarz_ma_odpowiedz_i_zglos_w_jednym_rzedzie(): void
    {
        // Widz nie jest ani autorem wpisu, ani autorem komentarza: nie ma
        // „Popraw" ani „Usuń", ma „Odpowiedz" i „Zgłoś". To jest druga gałąź
        // tego ekranu i osobny układ — nie da się jej zmierzyć przy okazji
        // pierwszej (D-099, D-106).
        [, , $wpis] = $this->wpisZKomentarzem('gospodyni', 'piszaca');
        $widz = $this->user('postronna');

        $ekran = $this->trescEkranu(
            (string) $this->actingAs($widz)->get(route('posts.show', $wpis))->assertOk()->getContent(),
        );

        $xpath = $this->xpath($ekran);
        $rzad = $this->jedyny($xpath, 'akcje-komentarza', 'rząd akcji pod cudzym komentarzem');
        $akcje = $this->akcjeW($xpath, $rzad);

        $this->assertContains('Odpowiedz', $akcje, 'Cudzy komentarz stracił „Odpowiedz" w rzędzie akcji.');

        $this->assertContains(
            'Zgłoś',
            $akcje,
            '„Zgłoś" wypadło z rzędu akcji zwykłych. Jest akcją ODWRACALNĄ, więc stoi obok '.
            '„Odpowiedz", a nie pod kreską przy „Usuń" — przed #433 renderowało się PO '.
            '`.danger-zone` i w jedynym stanie, w którym obie są naraz (autor treści ogląda '.
            'cudzy komentarz), kreska przestawała cokolwiek oddzielać. '.
            'Zastane akcje w rzędzie: '.(implode(', ', $akcje) ?: '(brak)'),
        );

        $this->assertNotContains(
            'Popraw',
            $akcje,
            'Ktoś postronny dostał „Popraw" przy cudzym komentarzu. To jest sprawa '.
            'CommentPolicy, nie układu — ale gdyby układ ją obszedł, test miałby o tym '.
            'krzyczeć, a nie milczeć.',
        );
    }

    public function test_usun_stoi_poza_rzedem_akcji_zwyklych_i_za_kreska(): void
    {
        [$gospodarz, , $wpis] = $this->wpisZKomentarzem('gospodyni', 'gospodyni');

        $ekran = $this->trescEkranu(
            (string) $this->actingAs($gospodarz)->get(route('posts.show', $wpis))->assertOk()->getContent(),
        );

        $xpath = $this->xpath($ekran);
        $rzad = $this->jedyny($xpath, 'akcje-komentarza', 'rząd akcji pod komentarzem');
        $strefa = $this->jedyny($xpath, 'danger-zone', 'blok odsunięty z akcją nieodwracalną');

        $this->assertNotContains(
            'Usuń',
            $this->akcjeW($xpath, $rzad),
            '„Usuń" wpadło do rzędu akcji zwykłych, ramię w ramię z „Popraw". '.
            'AGENTS.md §5: akcja destrukcyjna jest ODSUNIĘTA od zwykłych — inaczej trafia '.
            'się w nią przez pomyłkę. Ma zostać w `.danger-zone`, pod kreską.',
        );

        $this->assertContains(
            'Usuń',
            $this->akcjeW($xpath, $strefa),
            'W `.danger-zone` nie ma „Usuń" — kreska oddziela teraz pustkę.',
        );

        // KOLEJNOŚĆ TEŻ JEST CZĘŚCIĄ REGUŁY: kreska ma stać POD rzędem akcji
        // zwykłych, nie nad nim. `preceding::` czyta dokument, a nie tekst.
        $przedStrefa = $xpath->query('preceding::*[contains(concat(" ", normalize-space(@class), " "), " akcje-komentarza ")]', $strefa);
        $this->assertInstanceOf(DOMNodeList::class, $przedStrefa);
        $this->assertGreaterThan(
            0,
            $przedStrefa->length,
            'Kreska z „Usuń" stoi PRZED rzędem akcji zwykłych. Odsunięcie ma sens tylko '.
            'wtedy, gdy nieodwracalna akcja jest tą DALSZĄ od kciuka, a nie pierwszą na drodze.',
        );
    }

    public function test_usun_dalej_wymaga_potwierdzenia(): void
    {
        [$gospodarz, , $wpis] = $this->wpisZKomentarzem('gospodyni', 'gospodyni');

        $odpowiedz = $this->actingAs($gospodarz)->get(route('posts.show', $wpis))->assertOk();
        $ekran = $this->trescEkranu((string) $odpowiedz->getContent());

        $this->assertStringContainsString(
            'Na pewno usunąć ten komentarz?',
            $ekran,
            'Zniknęło pytanie potwierdzające przy „Usuń". Potwierdzenie idzie przez '.
            '`<details>` (`x-confirm-button`), nie przez `confirm()` — czyli działa bez '.
            'JavaScriptu i przeżyje zaostrzenie CSP.',
        );

        $xpath = $this->xpath($ekran);
        $strefa = $this->jedyny($xpath, 'danger-zone', 'blok odsunięty z akcją nieodwracalną');

        // FORMULARZ KASUJĄCY MUSI SIEDZIEĆ W ŚRODKU `<details>`, a nie obok
        // niego. Formularz wyjęty poza `<details>` kasuje po JEDNYM kliknięciu
        // i nadal przechodziłby asercję na samym tekście pytania.
        $formularze = $xpath->query('.//form[not(ancestor::details)]', $strefa);
        $this->assertInstanceOf(DOMNodeList::class, $formularze);
        $this->assertSame(
            0,
            $formularze->length,
            'W `.danger-zone` stoi formularz kasujący POZA `<details>` — czyli „Usuń" '.
            'kasuje za jednym kliknięciem, bez pytania. AGENTS.md §5 wymaga potwierdzenia.',
        );

        $wDetails = $xpath->query('.//details//form', $strefa);
        $this->assertInstanceOf(DOMNodeList::class, $wDetails);
        $this->assertGreaterThan(
            0,
            $wDetails->length,
            'W `.danger-zone` nie ma już formularza kasującego w środku `<details>` — '.
            'nie ma czym usunąć komentarza.',
        );
    }

    public function test_rzad_akcji_zawija_sie_zamiast_wyjezdzac_poza_ekran(): void
    {
        $reguly = $this->regulyDla('app.css', '/(?<![\w-])\.akcje-komentarza\s*$/');

        $this->assertNotEmpty(
            $reguly,
            'W resources/css/app.css nie ma reguły dla `.akcje-komentarza`. Bez niej akcje '.
            'wracają do trzech wierszy — dokładnie do stanu ze zgłoszenia #433.',
        );

        $deklaracje = (string) preg_replace('/\s+/', ' ', implode(' ', $reguly));

        $this->assertMatchesRegularExpression(
            '/(?<![\w-])display\s*:\s*flex/',
            $deklaracje,
            'Rząd akcji przestał być `flex`, więc każda akcja znów bierze własny wiersz. '.
            "Zastane deklaracje: {$deklaracje}",
        );

        $this->assertMatchesRegularExpression(
            '/(?<![\w-])flex-wrap\s*:\s*wrap/',
            $deklaracje,
            'Rząd akcji stracił `flex-wrap: wrap`. ZMIERZONE przy oknie 320 px: wnętrze '.
            'karty ma 246 px, a „Odpowiedz" (142,88 px) i „Popraw" (110,09 px) to razem '.
            '252,97 px plus odstęp. Bez zawijania ta para wyjeżdża poza kartę — a issue '.
            'zabrania ratowania tego zwężaniem przycisków: „mają się zawijać, a nie kurczyć". '.
            "Zastane deklaracje: {$deklaracje}",
        );
    }
}

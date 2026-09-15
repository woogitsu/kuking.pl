<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ZGŁOSZENIE WŁAŚCICIELA: „trzeba zrobić miejsce pomiędzy logowaniem Google fb
 * a normalnym".
 *
 * Na `/register` karta „Nie chcesz wymyślać hasła? Załóż konto przez Google
 * albo Facebooka" kończyła się, a karta ze zwykłym formularzem zaczynała się
 * dokładnie w tym samym miejscu. Zmierzone w Chromium przed poprawką: 0 px —
 * przy 360 px, przy 1280 px i przy czcionce przeglądarki 200%. To samo było na
 * `/login`, bo obie strony stawiają ten sam komponent `x-wejscia-zewnetrzne`
 * nad tym samym `form.panel-formularza`.
 *
 * POPRAWKA JEST REGUŁĄ UKŁADU, NIE MARGINESEM W JEDNYM WIDOKU
 * `resources/css/tokens.css`, sekcja warstw powierzchni: dwie sąsiadujące
 * powierzchnie stojące bezpośrednio w kolumnie głównej (`main.app-main`)
 * dostają `margin-top: var(--spacing-6)`. Dlaczego nie `.stack` i dlaczego
 * akurat ten token — w komentarzu przy tej regule.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TEN PLIK SPRAWDZA TRZY RZECZY, A NIE JEDNĄ
 * ══════════════════════════════════════════════════════════════════════
 *
 * Bo każda z osobna przechodzi z niewłaściwego powodu:
 *
 *  1. Sama reguła w arkuszu przechodzi też wtedy, gdy w HTML-u te dwie karty
 *     przestaną być sąsiadującym rodzeństwem w `main.app-main` — wtedy reguła
 *     jest martwą literą, a odstęp wraca do zera.
 *  2. Sama obecność reguły przechodzi też wtedy, gdy PRZYKRYJE ją coś
 *     mocniejszego. Dwie realne drogi przykrycia to (a) inna reguła w naszych
 *     arkuszach ustawiająca `margin-top` na tych klasach, (b) klasa pomocnicza
 *     `mt-…` w widoku — `@layer utilities` stoi w Tailwindzie 4 PO
 *     `components`, więc wygrywa niezależnie od specyficzności. Test pilnuje
 *     obu.
 *  3. Sama asercja o HTML-u przechodzi też przy wyłączonej regule.
 *
 * Ten podział ma w repozytorium dwa wzorce i z tego samego powodu:
 * `OdstepPodNaglowkiemStronyTest` i `OdstepyWFormularzachTest`.
 *
 * CZEGO TEN TEST NIE DOWODZI: ile dokładnie pikseli widzi człowiek. Piksele
 * mierzy się w przeglądarce (wzorzec: `scripts/warstwy-pomiar.mjs`); zmierzone
 * po poprawce: 24 px przy 360 i 1280 px, 48 px przy czcionce przeglądarki
 * 200%. Tutaj pilnujemy, żeby nikt nie skasował reguły ani nie odciął jej od
 * miejsca, w którym ma zadziałać.
 */
class OdstepMiedzyDrogamiWejsciaTest extends TestCase
{
    use RefreshDatabase;

    /** Sześć warstw powierzchni (sekcja 2.1 w `tokens.css`) plus podsumowanie błędów. */
    private const POWIERZCHNIE = [
        '.card',
        '.panel-formularza',
        '.sekcja-strony',
        '.ramka-pomocnicza',
        '.kafel-akcji',
        '.error-summary',
    ];

    /**
     * Ekrany, na których obie drogi wejścia stoją jedna nad drugą.
     *
     * @return array<string, array{0: string}>
     */
    public static function ekranyZObiemaDrogami(): array
    {
        return [
            'rejestracja' => ['register'],
            'logowanie' => ['login'],
        ];
    }

    /**
     * Wejścia kontem Google i Facebooka renderują się wyłącznie wtedy, gdy
     * droga NAPRAWDĘ działa (`App\Support\Google::dziala()`) — czyli przy
     * kluczach. W testach kluczy nie ma i tak ma być, więc podstawiamy je tu
     * jawnie. Bez tego cały blok nie istnieje, a test sprawdzałby pustkę.
     */
    private function wlaczObieDrogi(): void
    {
        config([
            'kuking.google.wlaczone' => true,
            'kuking.google.identyfikator_klienta' => 'test-google',
            'kuking.google.sekret_klienta' => 'sekret-testowy',
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => 'test-facebook',
            'kuking.facebook.sekret_klienta' => 'sekret-testowy',
        ]);
    }

    #[Test]
    public function test_arkusz_ma_regule_odstepu_miedzy_sasiadujacymi_powierzchniami(): void
    {
        $css = (string) file_get_contents(resource_path('css/tokens.css'));
        $this->assertStringContainsString('.app-main .marka-wejscie-karta', $css, 'Odstęp musi obejmować nową kartę wejścia, nie tylko bezpośrednie dzieci main.');

        $trafil = preg_match(
            '/\.app-main\s*>\s*:is\(([^)]*)\)\s*\+\s*:is\(([^)]*)\)\s*\{([^}]*)\}/s',
            $css,
            $dopasowanie,
        );

        $this->assertSame(
            1,
            $trafil,
            'W `resources/css/tokens.css` nie ma reguły odstępu między sąsiadującymi '.
            'powierzchniami w kolumnie głównej (`.app-main > :is(…) + :is(…)`). '.
            'Bez niej karta „Załóż konto przez Google albo Facebooka" styka się '.
            'z kartą formularza — zgłoszenie właściciela, zmierzone 0 px.',
        );

        [, $pierwsza, $druga, $tresc] = $dopasowanie;

        foreach (['.sekcja-strony', '.panel-formularza'] as $klasa) {
            $this->assertStringContainsString(
                $klasa,
                $pierwsza,
                "Reguła odstępu nie obejmuje `{$klasa}` jako bloku POPRZEDZAJĄCEGO — ".
                'a na `/register` i `/login` to jest dokładnie ta para (karta wejść '.
                'Google/Facebooka nad panelem formularza).',
            );
            $this->assertStringContainsString(
                $klasa,
                $druga,
                "Reguła odstępu nie obejmuje `{$klasa}` jako bloku NASTĘPUJĄCEGO.",
            );
        }

        $this->assertMatchesRegularExpression(
            '/margin-top:\s*var\(--spacing-\d+\)/',
            $tresc,
            'Odstęp musi brać wartość z tokenu (`var(--spacing-N)`), nie liczbę '.
            'wpisaną z palca. Tokeny odstępu są w `rem`, więc odstęp rośnie razem '.
            'z powiększoną czcionką przeglądarki — a liczba w pikselach by nie rosła.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/margin-top:\s*0(?![.\d])/',
            $tresc,
            'Reguła odstępu ustawia `margin-top: 0` — to jest dokładnie stan ze '.
            'zgłoszenia właściciela (dwie karty sklejone w jeden blok).',
        );
    }

    /**
     * KONTROLA PRZYKRYCIA PO STRONIE ARKUSZY.
     *
     * Asercja „w arkuszu jest reguła X" przeszłaby także wtedy, gdyby ktoś
     * dopisał gdzie indziej regułę o wyższej specyficzności, która ten margines
     * zeruje. Dlatego przechodzimy po WSZYSTKICH naszych arkuszach i pilnujemy,
     * żeby margines pionowy na klasach powierzchni ustawiała tylko ta jedna
     * reguła z `.app-main`.
     */
    #[Test]
    public function test_zadna_inna_regula_nie_ustawia_marginesu_na_powierzchniach(): void
    {
        $pliki = glob(resource_path('css/*.css')) ?: [];

        $this->assertGreaterThan(
            3,
            count($pliki),
            'Skan nie czyta arkuszy — zła ścieżka? Przy zerze plików ten test '.
            'przeszedłby, nie sprawdzając niczego (docs/PULAPKI_TESTOW.md, pułapka 2).',
        );

        $sprawdzoneReguly = 0;
        $winne = [];

        foreach ($pliki as $plik) {
            $css = (string) file_get_contents($plik);

            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $reguly, PREG_SET_ORDER);

            foreach ($reguly as [, $selektor, $tresc]) {
                $sprawdzoneReguly++;

                // Liczy się TYLKO margines nakładany na samą powierzchnię, czyli
                // klasa w OSTATNIM członie selektora. `.panel-formularza .field`
                // ustawia margines polu w środku i z tym odstępem nie ma nic
                // wspólnego.
                $dotyczyPowierzchni = false;

                foreach (explode(',', $selektor) as $pojedynczy) {
                    $czlony = preg_split('/[\s>+~]+/', trim($pojedynczy)) ?: [];
                    $ostatni = (string) end($czlony);

                    foreach (self::POWIERZCHNIE as $klasa) {
                        // Kropka jest granicą sama w sobie, więc PRZED nią może stać
                        // nazwa znacznika (`form.panel-formularza`) albo inna klasa.
                        // Pilnujemy tylko końca: `.card` to nie `.card-header`.
                        if (preg_match('/'.preg_quote($klasa, '/').'(?![\w-])/', $ostatni) === 1) {
                            $dotyczyPowierzchni = true;
                            break 2;
                        }
                    }
                }

                if (! $dotyczyPowierzchni) {
                    continue;
                }

                if (! preg_match('/(?:^|[\s;{])margin(?:-top|-block|-block-start)?\s*:/m', $tresc)) {
                    continue;
                }

                // Ta jedna reguła ma prawo to robić — to jest nasza reguła odstępu.
                if (str_contains($selektor, '.app-main') && str_contains($selektor, ':is(')) {
                    continue;
                }

                // Nowa rama panelu ma własny slot; ta reguła nie może objąć logowania.
                $czystySelektor = trim(preg_replace('/\s+/', ' ', preg_replace('~/\*.*?\*/~s', '', $selektor) ?? '') ?? '');
                if ($czystySelektor === '[data-marka-panel] .marka-panel-tresc > '
                    .':is(.card, .panel-formularza, .sekcja-strony, .ramka-pomocnicza, .kafel-akcji, .error-summary) '
                    .'+ :is(.card, .panel-formularza, .sekcja-strony, .ramka-pomocnicza, .kafel-akcji, .error-summary)') {
                    continue;
                }

                $winne[] = trim(preg_replace('/\s+/', ' ', $selektor) ?? '')
                    .' { '.trim(preg_replace('/\s+/', ' ', $tresc) ?? '').' } — '.basename($plik);
            }
        }

        $this->assertGreaterThan(
            200,
            $sprawdzoneReguly,
            'Skan przeczytał podejrzanie mało reguł CSS — prawdopodobnie nie czyta '.
            'tego, co powinien.',
        );

        $this->assertSame(
            [],
            $winne,
            "Margines pionowy na klasie powierzchni ustawia jeszcze coś poza regułą\n".
            "odstępu z `.app-main` — a to może ją przykryć i odstęp między kartą\n".
            "wejść Google/Facebooka a formularzem wróci do zera:\n".
            implode("\n", $winne),
        );
    }

    /**
     * Reguła `.app-main > … + …` działa tylko wtedy, gdy te dwie karty
     * naprawdę są SĄSIADUJĄCYM rodzeństwem w kolumnie głównej. Owinięcie
     * którejkolwiek z nich dodatkowym `<div>`-em albo wstawienie między nie
     * czegokolwiek, co nie jest powierzchnią, wyłącza regułę bez jednego
     * czerwonego przebiegu w arkuszu.
     */
    #[Test]
    #[DataProvider('ekranyZObiemaDrogami')]
    public function test_karta_wejsc_i_panel_formularza_sa_sasiadujacym_rodzenstwem(string $trasa): void
    {
        $this->wlaczObieDrogi();

        $html = $this->get(route($trasa))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.(string) $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $panele = $xpath->query(
            "//main[contains(concat(' ', normalize-space(@class), ' '), ' app-main ')]".
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' marka-wejscie-karta ')]".
            "/*[contains(concat(' ', normalize-space(@class), ' '), ' panel-formularza ')]",
        );

        $this->assertNotFalse($panele);
        $this->assertSame(
            1,
            $panele->length,
            "Trasa „{$trasa}”: panel formularza nie jest bezpośrednim dzieckiem ".
            '`<div class="marka-wejscie-karta">` — reguła odstępu z `tokens.css` nic tu nie '.
            'zrobi, a karty znów się skleją.',
        );

        $panel = $panele->item(0);
        $this->assertNotNull($panel);

        $poprzednie = $xpath->query('preceding-sibling::*[1]', $panel);
        $this->assertNotFalse($poprzednie);
        $this->assertSame(
            1,
            $poprzednie->length,
            "Trasa „{$trasa}”: nad panelem formularza nie stoi nic — karta wejść ".
            'kontem Google i Facebooka zniknęła z tego ekranu?',
        );

        $sasiad = $poprzednie->item(0);
        $this->assertInstanceOf(\DOMElement::class, $sasiad);

        $klasy = ' '.trim((string) $sasiad->getAttribute('class')).' ';

        $this->assertStringContainsString(
            ' sekcja-strony ',
            $klasy,
            "Trasa „{$trasa}”: bezpośrednio nad panelem formularza stoi element bez ".
            "klasy `sekcja-strony` (jest: „{$klasy}”). Reguła odstępu łączy dwie ".
            'POWIERZCHNIE — element spoza tej listy przerywa parę i odstęp wraca do zera.',
        );

        // KONTROLA DODATNIA: to naprawdę jest karta wejść kontem u dostawcy,
        // a nie przypadkowa inna sekcja, która akurat stanęła w tym miejscu.
        $wejscia = $xpath->query(
            ".//a[contains(@href, '/wejdz/google')] | .//a[contains(@href, '/wejdz/facebook')]",
            $sasiad,
        );
        $this->assertNotFalse($wejscia);
        $this->assertSame(
            2,
            $wejscia->length,
            "Trasa „{$trasa}”: sekcja nad formularzem nie zawiera obu wejść ".
            '(Google i Facebook) — mierzymy odstęp nie od tej karty, o którą '.
            'chodziło w zgłoszeniu.',
        );
    }

    /**
     * KONTROLA PRZYKRYCIA PO STRONIE WIDOKU.
     *
     * `@layer utilities` stoi w Tailwindzie 4 PO `@layer components`, więc
     * jedno `mt-0` albo `mt-1` dopisane do panelu w widoku przykryłoby regułę
     * odstępu niezależnie od specyficzności — i żaden test arkusza by tego nie
     * zauważył.
     */
    #[Test]
    #[DataProvider('ekranyZObiemaDrogami')]
    public function test_panel_formularza_nie_ma_wlasnej_klasy_marginesu_gornego(string $trasa): void
    {
        $this->wlaczObieDrogi();

        $html = $this->get(route($trasa))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.(string) $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $panele = $xpath->query(
            "//main[contains(concat(' ', normalize-space(@class), ' '), ' app-main ')]".
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' marka-wejscie-karta ')]".
            "/*[contains(concat(' ', normalize-space(@class), ' '), ' panel-formularza ')]",
        );

        $this->assertNotFalse($panele);
        $panel = $panele->item(0);
        $this->assertInstanceOf(\DOMElement::class, $panel);

        $klasy = preg_split('/\s+/', trim((string) $panel->getAttribute('class'))) ?: [];

        foreach ($klasy as $klasa) {
            $this->assertDoesNotMatchRegularExpression(
                '/^-?(?:mt|my|m)-/',
                $klasa,
                "Trasa „{$trasa}”: panel formularza ma własną klasę marginesu ".
                "(„{$klasa}”). Utility wygrywa z regułą odstępu z `tokens.css`, ".
                'więc odstęp między kartą wejść Google/Facebooka a formularzem '.
                'przestaje być tym, co mówi reguła układu.',
            );
        }
    }
}

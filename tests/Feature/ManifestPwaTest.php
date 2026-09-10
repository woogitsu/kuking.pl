<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manifest aplikacji (PWA) — treść, nie samo istnienie pliku.
 *
 * DLACZEGO TEN PLIK POWSTAŁ
 * `public/manifest.webmanifest` miał `"orientation": "portrait-primary"`,
 * czyli po instalacji aplikacji system operacyjny obracał ją w pion
 * i trzymał tam wbrew człowiekowi (audyt SEO/PWA-01). To jest naruszenie
 * WCAG 2.2, kryterium 1.3.4 Orientation (AA): treść i obsługa nie mogą być
 * ograniczone do jednej orientacji, chyba że konkretna orientacja jest
 * NIEZBĘDNA. W Kuking nie jest niezbędna nigdzie — nie ma tu ani gry, ani
 * pianina, ani czytnika kodów.
 *
 * Realny scenariusz z naszej grupy 50–75: tablet stojący POZIOMO na
 * podstawce przy blacie, bo tak się z niego czyta przepis przy gotowaniu.
 * Ta osoba dostawała aplikację obróconą w pion.
 *
 * DLACZEGO TEST CZYTA PLIK, A NIE ODPOWIEDŹ HTTP
 * Wada była widoczna DOPIERO PO INSTALACJI aplikacji — w przeglądarce
 * strona zachowuje się normalnie, bo `orientation` dotyczy zainstalowanej
 * aplikacji, nie karty. Żaden test przechodzący przez `$this->get()` by
 * tego nie złapał, a i człowiek klikający po serwisie tego nie zobaczy.
 * Jedynym miejscem, w którym ta usterka istnieje, jest TREŚĆ manifestu —
 * więc tam trzeba patrzeć.
 *
 * Ten plik pilnuje przy okazji drugiej rzeczy z tego samego audytu
 * (SEO/PWA-05): manifest nie może nazywać funkcji inaczej niż nawigacja.
 */
class ManifestPwaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wartości `orientation`, które ZAMYKAJĄ aplikację w jednej orientacji.
     *
     * `natural` jest w tym spisie celowo, choć wygląda niewinnie: znaczy
     * „naturalna orientacja urządzenia", czyli na telefonie pion, a na
     * części tabletów poziom. To nadal jedna narzucona orientacja, tylko
     * wybrana za człowieka przez producenta sprzętu.
     */
    private const ORIENTACJE_BLOKUJACE = [
        'portrait',
        'portrait-primary',
        'portrait-secondary',
        'landscape',
        'landscape-primary',
        'landscape-secondary',
        'natural',
    ];

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $sciezka = public_path('manifest.webmanifest');

        $this->assertFileExists($sciezka, 'Nie ma manifestu — PWA nie da się zainstalować.');

        $tresc = (string) file_get_contents($sciezka);
        $dane = json_decode($tresc, true);

        // ASERCJA KONTROLNA. Bez niej `$dane['orientation'] ?? null` byłoby
        // `null` także dla manifestu USZKODZONEGO — a wtedy cały ten plik
        // świeciłby na zielono nad plikiem, którego przeglądarka nie czyta.
        $this->assertIsArray(
            $dane,
            'Manifest nie jest poprawnym JSON-em ('.json_last_error_msg().'). '
            .'Przeglądarka odrzuca wtedy CAŁY plik, razem z ikonami i skrótami.',
        );

        return $dane;
    }

    public function test_manifest_nie_wymusza_zadnej_orientacji(): void
    {
        $manifest = $this->manifest();

        $orientacja = $manifest['orientation'] ?? null;

        $this->assertNotContains(
            $orientacja,
            self::ORIENTACJE_BLOKUJACE,
            'Manifest wymusza orientację „'.(string) $orientacja.'". Po instalacji PWA system '
            .'obraca aplikację wbrew człowiekowi — a WCAG 2.2 §1.3.4 (AA) pozwala na to tylko '
            ."wtedy, gdy dana orientacja jest NIEZBĘDNA. W Kuking nie jest.\n"
            .'Wpisz `"orientation": "any"` albo usuń to pole (D-073).',
        );

        // Nie sam brak wartości blokujących, ale DOKŁADNIE jedna z dwóch
        // dopuszczonych postaci — inaczej literówka („any-primary", „ANY")
        // przeszłaby przez asercję wyżej, a przeglądarka i tak by jej nie
        // zrozumiała i wróciłaby do zachowania domyślnego platformy.
        $this->assertTrue(
            $orientacja === null || $orientacja === 'any',
            'Pole `orientation` ma wartość „'.var_export($orientacja, true).'". '
            .'Dopuszczamy tylko „any" albo brak pola.',
        );
    }

    /**
     * Ta sama rzecz ma jedną nazwę — w manifeście i w nawigacji (D-073).
     *
     * Rozjazd, który to złapało: manifest mówił „Mój zeszyt", nawigacja
     * „Moje". Po instalacji aplikacji system operacyjny pokazywał więc
     * INNĄ nazwę tej samej funkcji niż interfejs, do którego ta nazwa
     * prowadzi. Dla osoby przenoszącej się ze starego serwisu jedna nazwa
     * jest ważniejsza od kreatywności.
     *
     * DLACZEGO ASERCJA WCHODZI DO ŚRODKA ELEMENTU `<a>`
     * Sprawdzanie słowa „Zeszyt" w całym dokumencie nic nie znaczy: to
     * słowo stoi na tej stronie w kilku miejscach naraz (nagłówek szyny,
     * teksty pomocnicze). Wycinamy więc konkretny odnośnik z konkretnego
     * paska i porównujemy JEGO podpis.
     */
    public function test_skrot_w_manifescie_nazywa_sie_tak_samo_jak_pozycja_menu(): void
    {
        $manifest = $this->manifest();

        $skroty = collect($manifest['shortcuts'] ?? [])
            ->filter(fn ($skrot) => ($skrot['url'] ?? null) === '/zeszyt');

        $this->assertCount(1, $skroty, 'Manifest nie ma dokładnie jednego skrótu do `/zeszyt`.');

        /** @var array<string, mixed> $skrot */
        $skrot = $skroty->first();

        $nazwaWManifescie = (string) ($skrot['name'] ?? '');

        $html = $this->actingAs($this->user('basia'))->get('/home')->assertOk()->getContent();

        foreach (['bottom-nav' => 'pasek dolny', 'side-nav' => 'nawigacja boczna'] as $klasa => $opis) {
            $podpis = $this->podpisOdnosnikaDoZeszytu((string) $html, $klasa);

            $this->assertSame(
                $nazwaWManifescie,
                $podpis,
                'Manifest mówi „'.$nazwaWManifescie.'”, a '.$opis.' — „'.$podpis.'”. '
                .'Po instalacji aplikacji człowiek widzi dwie nazwy tej samej rzeczy: '
                .'jedną od systemu, drugą w serwisie.',
            );
        }

        // Kontrola nad kontrolą: gdyby ktoś wyczyścił `name` w manifeście,
        // dwie puste wartości też byłyby „takie same".
        $this->assertNotSame('', $nazwaWManifescie, 'Skrót w manifeście nie ma nazwy.');
    }

    /** Podpis odnośnika prowadzącego do `/zeszyt` w pasku o podanej klasie. */
    private function podpisOdnosnikaDoZeszytu(string $html, string $klasa): string
    {
        $start = strpos($html, '<nav class="'.$klasa);
        $this->assertNotFalse($start, "Nie znalazłem <nav class=\"{$klasa}\"> na stronie.");

        $koniec = strpos($html, '</nav>', $start);
        $this->assertNotFalse($koniec, "Znacznik <nav class=\"{$klasa}\"> nie jest domknięty.");

        $fragment = substr($html, $start, $koniec - $start);

        preg_match_all('~<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>~s', $fragment, $linki, PREG_SET_ORDER);

        foreach ($linki as $link) {
            if (! str_ends_with(rtrim($link[1], '/'), '/zeszyt')) {
                continue;
            }

            return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($link[2]))));
        }

        $this->fail("W <nav class=\"{$klasa}\"> nie ma odnośnika do `/zeszyt`.");
    }
}

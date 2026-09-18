<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Wspólna podstawa testów wyglądu i wydruku paczki z danymi (#492).
 *
 * NIE JEST TESTEM — nazwa bez końcówki `Test`, więc PHPUnit tego pliku nie
 * zbiera. Trzyma dwie rzeczy, których obie klasy potrzebują:
 *
 *  1. zbudowanie PRAWDZIWEJ paczki przez `GenerateUserExport` i wyjęcie
 *     z niej pliku — te testy mają patrzeć na to, co człowiek dostaje
 *     w archiwum, a nie na sam szablon Blade;
 *  2. sprawdzenie, czy reguła CSS z paczki NAPRAWDĘ DOTYCZY danego
 *     elementu. To jest sedno: test „w pliku jest napis `min-height: 48px`"
 *     przechodzi także wtedy, gdy ta reguła nie łapie żadnego elementu na
 *     stronie (pułapka 2 z `docs/PULAPKI_TESTOW.md` — skan, który niczego
 *     nie znajduje, jest dla testu sukcesem). Dlatego dopasowujemy selektor
 *     do konkretnego węzła DOM i pytamy o wartość, którą ta reguła mu nadaje
 *     — z zastrzeżeniem o kaskadzie opisanym niżej.
 *
 * CZEGO TEN DOPASOWYWACZ NIE UDAJE — I TO JEST WAŻNIEJSZE OD TEGO, CO UMIE.
 *
 * Nie jest silnikiem CSS i nie liczy kaskady. Rozumie tylko te kształty
 * selektora, które w `exports/styles.blade.php` naprawdę stoją (`tag`,
 * `.klasa`, `tag.klasa` i potomka rozdzielonego spacją), a przy konflikcie
 * bierze regułę PÓŹNIEJSZĄ W PLIKU — nie tę o wyższej wadze. Zmierzone
 * w recenzji: reguła `p.akcja a { min-height: 20px }` postawiona PRZED
 * regułą `.akcja a { min-height: 48px }` wygrywa w przeglądarce (waga 0,1,2
 * bije 0,1,1), a ten pomocnik zwróci 48 px. Nie widzi też tego, czy element
 * w ogóle jest rysowany — `display: none` przepuści.
 *
 * Dlatego pomiar geometrii w prawdziwej przeglądarce NIE JEST tu zbędnym
 * dodatkiem, tylko drugą połową dowodu: wysokości, brak przewijania w bok
 * i fokus są zmierzone Chromium i Firefoksem, a wyniki leżą
 * w `docs/design/evidence/eksport492/` (`ekran.json`, `zoom.json`,
 * `druk.json`, `klawiatura.json`). Ten pomocnik pilnuje, żeby reguła nie
 * zniknęła z arkusza i żeby dotyczyła właściwego węzła; tamte pliki
 * pilnują, co z tego wychodzi na ekranie.
 *
 * Na kształcie selektora, którego nie rozumie, OBLEWA z jego nazwą, zamiast
 * po cichu przepuścić — bo „nie wiem" nie jest tym samym co „w porządku"
 * (pułapka 5).
 */
abstract class EksportWygladStylPaczki extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    // -----------------------------------------------------------------
    // Paczka
    // -----------------------------------------------------------------

    protected function zbudujPaczke(User $user): DataExport
    {
        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return $export->refresh();
    }

    /** Zdjęcie z prawdziwym plikiem na udawanym dysku. */
    protected function zdjecieDla(User $owner, string $nazwa): Media
    {
        $key = "media/{$owner->getKey()}/{$nazwa}.webp";

        Storage::disk('public')->put($key, 'udawana-zawartosc-zdjecia');

        return Media::factory()->create([
            'owner_id' => $owner->getKey(),
            'disk' => 'public',
            'object_key' => $key,
            'status' => Media::STATUS_READY,
        ]);
    }

    /** @return list<string> */
    protected function plikiPaczki(DataExport $export): array
    {
        $zip = $this->otworzPaczke($export);

        $pliki = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $pliki[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $pliki;
    }

    protected function zPaczki(DataExport $export, string $plik): string
    {
        $zip = $this->otworzPaczke($export);
        $tresc = $zip->getFromName($plik);
        $zip->close();

        $this->assertIsString($tresc, "W paczce nie ma pliku {$plik}.");

        return $tresc;
    }

    private function otworzPaczke(DataExport $export): ZipArchive
    {
        $sciezka = Storage::disk('local')->path((string) $export->object_key);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka) === true, 'Nie udało się otworzyć archiwum.');

        return $zip;
    }

    // -----------------------------------------------------------------
    // DOM
    // -----------------------------------------------------------------

    protected function dokument(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        return new DOMXPath($dom);
    }

    /**
     * @return list<DOMElement>
     */
    protected function elementy(DOMXPath $xpath, string $wyrazenie): array
    {
        $wynik = [];

        foreach ($xpath->query($wyrazenie) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $wynik[] = $node;
            }
        }

        return $wynik;
    }

    // -----------------------------------------------------------------
    // CSS
    // -----------------------------------------------------------------

    /** Treść wszystkich `<style>` w dokumencie, bez komentarzy. */
    protected function arkusz(string $html): string
    {
        preg_match_all('/<style[^>]*>(.*?)<\/style>/s', $html, $dopasowania);

        $this->assertNotEmpty($dopasowania[1], 'Strona paczki nie ma wbudowanego arkusza stylów.');

        return (string) preg_replace('~/\*.*?\*/~s', '', implode("\n", $dopasowania[1]));
    }

    /** Reguły spoza jakiegokolwiek `@media` — czyli te, które działają zawsze. */
    protected function regulyPodstawowe(string $arkusz): string
    {
        return $this->bezBlokowMedia($arkusz);
    }

    /** Reguły z bloku `@media print`. */
    protected function regulyWydruku(string $arkusz): string
    {
        $pozycja = strpos($arkusz, '@media print');

        $this->assertNotFalse($pozycja, 'Arkusz paczki nie ma bloku `@media print`.');

        $start = strpos($arkusz, '{', $pozycja);
        $this->assertNotFalse($start);

        $glebokosc = 0;

        for ($i = $start, $koniec = strlen($arkusz); $i < $koniec; $i++) {
            if ($arkusz[$i] === '{') {
                $glebokosc++;
            } elseif ($arkusz[$i] === '}') {
                $glebokosc--;

                if ($glebokosc === 0) {
                    return substr($arkusz, $start + 1, $i - $start - 1);
                }
            }
        }

        $this->fail('Blok `@media print` nie jest domknięty.');
    }

    private function bezBlokowMedia(string $arkusz): string
    {
        $wynik = '';
        $dlugosc = strlen($arkusz);
        $i = 0;

        while ($i < $dlugosc) {
            if (substr($arkusz, $i, 6) === '@media') {
                $start = strpos($arkusz, '{', $i);

                if ($start === false) {
                    break;
                }

                $glebokosc = 0;
                $j = $start;

                for (; $j < $dlugosc; $j++) {
                    if ($arkusz[$j] === '{') {
                        $glebokosc++;
                    } elseif ($arkusz[$j] === '}') {
                        $glebokosc--;

                        if ($glebokosc === 0) {
                            break;
                        }
                    }
                }

                $i = $j + 1;

                continue;
            }

            $wynik .= $arkusz[$i];
            $i++;
        }

        return $wynik;
    }

    /**
     * Wartość właściwości, jaka wychodzi dla TEGO elementu z TYCH reguł.
     *
     * `null` znaczy „żadna reguła tej właściwości temu elementowi nie nadaje".
     * Wygrywa reguła późniejsza — tak jak w przeglądarce przy równej wadze.
     */
    protected function wartoscDla(string $reguly, DOMElement $element, string $wlasciwosc): ?string
    {
        $wynik = null;

        foreach ($this->rozbijNaReguly($reguly) as [$selektory, $deklaracje]) {
            if (! array_key_exists($wlasciwosc, $deklaracje)) {
                continue;
            }

            foreach ($selektory as $selektor) {
                if ($this->selektorPasuje($selektor, $element)) {
                    $wynik = $deklaracje[$wlasciwosc];

                    break;
                }
            }
        }

        return $wynik;
    }

    /**
     * @return list<array{0: list<string>, 1: array<string, string>}>
     */
    private function rozbijNaReguly(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $dopasowania, PREG_SET_ORDER);

        $reguly = [];

        foreach ($dopasowania as $regula) {
            $selektory = array_values(array_filter(array_map('trim', explode(',', $regula[1]))));
            $deklaracje = [];

            foreach (explode(';', $regula[2]) as $deklaracja) {
                if (! str_contains($deklaracja, ':')) {
                    continue;
                }

                [$nazwa, $wartosc] = explode(':', $deklaracja, 2);
                $deklaracje[strtolower(trim($nazwa))] = trim($wartosc);
            }

            if ($selektory !== [] && $deklaracje !== []) {
                $reguly[] = [$selektory, $deklaracje];
            }
        }

        return $reguly;
    }

    private function selektorPasuje(string $selektor, DOMElement $element): bool
    {
        $czesci = preg_split('/\s+/', trim($selektor)) ?: [];
        $czesci = array_values(array_filter($czesci, static fn (string $c): bool => $c !== ''));

        if ($czesci === []) {
            return false;
        }

        $ostatnia = array_pop($czesci);

        if (! $this->czescPasuje($ostatnia, $element)) {
            return false;
        }

        $przodek = $element->parentNode;

        while ($czesci !== []) {
            $szukana = array_pop($czesci);
            $znaleziono = false;

            while ($przodek instanceof DOMElement) {
                if ($this->czescPasuje($szukana, $przodek)) {
                    $znaleziono = true;
                    $przodek = $przodek->parentNode;

                    break;
                }

                $przodek = $przodek->parentNode;
            }

            if (! $znaleziono) {
                return false;
            }
        }

        return true;
    }

    private function czescPasuje(string $czesc, DOMElement $element): bool
    {
        if (! preg_match('/^(\*|[a-zA-Z][a-zA-Z0-9]*)?((?:\.[A-Za-z0-9_-]+)*)$/', $czesc, $dopasowanie)) {
            $this->fail(
                "Selektor `{$czesc}` ma kształt, którego ten test nie rozumie. ".
                'Dopasowywacz zna tylko `tag`, `.klasa`, `tag.klasa` i potomka po spacji. '.
                'Rozszerz go świadomie — nie kasuj asercji, bo „nie wiem" to nie jest „w porządku".',
            );
        }

        $tag = $dopasowanie[1] ?? '';

        if ($tag !== '' && $tag !== '*' && strtolower($tag) !== strtolower($element->tagName)) {
            return false;
        }

        if (($dopasowanie[2] ?? '') === '') {
            return true;
        }

        $klasy = preg_split('/\s+/', trim((string) $element->getAttribute('class'))) ?: [];

        foreach (explode('.', ltrim($dopasowanie[2], '.')) as $wymagana) {
            if (! in_array($wymagana, $klasy, true)) {
                return false;
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Paczka z danymi po ROZPAKOWANIU — czy naprawdę daje się z niej korzystać.
 *
 * Czego pilnuje ten plik, a czego nie pilnował żaden wcześniejszy:
 *
 *  - `DataExportTest::test_paczka_zawiera_index_czytelny_html_zdjecia_i_dane_json`
 *    sprawdza JEDEN plik przepisu i JEDEN łańcuch („../zdjecia/", brak
 *    „http://localhost"). To jest próbka, nie pomiar: martwy odnośnik
 *    w drugim przepisie, w `wpisy.html` albo w spisie treści przechodzi przez
 *    tamten test bez śladu.
 *  - Tutaj sprawdzamy KAŻDY `href` i KAŻDY `src` z KAŻDEGO pliku HTML
 *    w archiwum i pytamy o jedno: czy plik, na który ten odnośnik wskazuje,
 *    jest w tym samym archiwum. Paczka rozpakowana na pendrive nie ma
 *    dokąd pójść po nic innego.
 *
 * Czego ten test NIE dowodzi: że strona ŁADNIE WYGLĄDA i że wydruk jest
 * czytelny. To jest sprawdzane osobno (`resources/views/exports/*`), a tu
 * mierzymy wyłącznie to, czy odnośniki i zdjęcia prowadzą do istniejących
 * plików i czy nic w paczce nie potrzebuje Kuking ani sieci.
 */
class PaczkaDanychOtwieraSieBezKukingTest extends TestCase
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

    /**
     * REGRESJA: martwy odnośnik w rozpakowanej paczce.
     *
     * Nazwa pliku przepisu powstaje w `ExportFileNames::recipeFile()`,
     * a ścieżka do zdjęcia w `ExportPhotoPlan::pathFor()` — i obie są
     * liczone OSOBNO dla spisu treści, dla `dane.json` i dla dodania pliku
     * do archiwum. Rozjazd którejkolwiek z nich daje paczkę, która wygląda
     * na kompletną i otwiera się pustym „nie znaleziono pliku" u kogoś,
     * kto właśnie zamknął konto i nie ma już dokąd wrócić.
     */
    public function test_kazdy_odnosnik_w_paczce_wskazuje_na_plik_ktory_w_niej_jest(): void
    {
        $export = $this->paczkaBogategoKonta();
        $wpisy = $this->wpisyArchiwum($export);

        $htmle = array_values(array_filter($wpisy, fn (string $n): bool => str_ends_with($n, '.html')));

        // Pułapka 2 z docs/PULAPKI_TESTOW.md: skan, który nic nie znalazł,
        // przechodzi. Paczka bogatego konta MA mieć spis treści, wpisy
        // i cztery pliki przepisów.
        $this->assertGreaterThanOrEqual(6, count($htmle),
            'Skan nie widzi plików HTML — paczka zbudowała się inaczej, niż zakłada ten test.');

        $sprawdzonych = 0;
        $martwe = [];

        foreach ($htmle as $plik) {
            $katalog = trim(dirname($plik), '.');

            foreach ($this->odnosnikiZ($this->zArchiwum($export, $plik)) as [$tag, $atrybut, $wartosc]) {
                if ($wartosc === '' || str_starts_with($wartosc, '#')) {
                    continue;
                }

                if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $wartosc) === 1 || str_starts_with($wartosc, '//')) {
                    // Adresy z protokołem sprawdza osobny test niżej.
                    continue;
                }

                $sprawdzonych++;

                $cel = $this->rozwin($katalog, $wartosc);

                $istnieje = in_array($cel, $wpisy, true)
                    || $this->jestKatalogiem($wpisy, $cel);

                if (! $istnieje) {
                    $martwe[] = "{$plik}: <{$tag} {$atrybut}=\"{$wartosc}\"> → brak „{$cel}” w archiwum";
                }
            }
        }

        $this->assertGreaterThanOrEqual(15, $sprawdzonych,
            'Skan odnośników nie doliczył się niczego — zmienił się kształt paczki?');

        $this->assertSame([], $martwe,
            "Paczka po rozpakowaniu ma martwe odnośniki:\n".implode("\n", $martwe));
    }

    /**
     * Paczka ma działać dziesięć lat po tym, jak Kuking zniknie.
     *
     * Dlatego w żadnym jej pliku nie ma prawa być ani adresu z protokołem
     * (nasz serwer, CDN z czcionką, licznik odwiedzin), ani podpisanego
     * adresu pobrania, ani `<script>`. Każda z tych rzeczy zamienia „moje
     * przepisy na pendrivie" w „moje przepisy, dopóki ktoś utrzymuje serwer".
     */
    public function test_paczka_nie_odwoluje_sie_do_serwera_ani_do_sesji(): void
    {
        $export = $this->paczkaBogategoKonta();
        $wpisy = $this->wpisyArchiwum($export);

        $przeskanowane = 0;
        $zarzuty = [];

        foreach ($wpisy as $plik) {
            if (str_ends_with($plik, '.png') || str_ends_with($plik, '.webp') || str_ends_with($plik, '/')) {
                continue;
            }

            $tresc = $this->zArchiwum($export, $plik);
            $przeskanowane++;

            foreach ([
                'adres z protokołem' => '#https?://#i',
                'podpisany adres pobrania' => '#signature=#i',
                'adres z podpisem czasowym' => '#[?&]expires=#i',
                'znacznik sesji' => '#kuking-session|XSRF-TOKEN#i',
            ] as $opis => $wzor) {
                if (preg_match_all($wzor, $tresc, $trafienia) > 0) {
                    $zarzuty[] = "{$plik}: {$opis} — ".implode(', ', array_unique($trafienia[0]));
                }
            }

            if (str_ends_with($plik, '.html')) {
                $dokument = $this->dokument($tresc);
                $skrypty = (new DOMXPath($dokument))->query('//script');

                if ($skrypty !== false && $skrypty->length > 0) {
                    $zarzuty[] = "{$plik}: {$skrypty->length} × <script>";
                }
            }
        }

        $this->assertGreaterThanOrEqual(8, $przeskanowane,
            'Skan nie przeczytał plików paczki — zły filtr albo pusta paczka.');

        $this->assertSame([], $zarzuty,
            "Paczka wymaga Kuking albo sieci:\n".implode("\n", $zarzuty));
    }

    /**
     * `CZYTAJ-TO-NAJPIERW.txt` jest pierwszym i często jedynym plikiem, który
     * człowiek przeczyta. Liczby w nim muszą zgadzać się z tym, co naprawdę
     * leży w archiwum — inaczej paczka sama siebie podważa.
     */
    public function test_czytaj_to_najpierw_podaje_liczby_zgodne_z_zawartoscia_paczki(): void
    {
        $export = $this->paczkaBogategoKonta();
        $wpisy = $this->wpisyArchiwum($export);

        $przepisow = count(array_filter($wpisy, fn (string $n): bool => str_starts_with($n, 'przepisy/')));
        $zdjec = count(array_filter($wpisy, fn (string $n): bool => str_starts_with($n, 'zdjecia/')));

        // Kontrola dodatnia: paczka, w której nic nie ma, przepuściłaby
        // każdą liczbę (pułapka 4).
        $this->assertGreaterThan(0, $przepisow);
        $this->assertGreaterThan(0, $zdjec);

        $readme = $this->zArchiwum($export, 'CZYTAJ-TO-NAJPIERW.txt');

        $this->assertSame(1, preg_match('/Przepisów w paczce:\s*(\d+)/u', $readme, $m),
            'README nie podaje liczby przepisów.');
        $this->assertSame($przepisow, (int) $m[1],
            'README obiecuje inną liczbę przepisów, niż jest plików w katalogu przepisy/.');

        $this->assertSame(1, preg_match('/Zdjęć w paczce:\s*(\d+)/u', $readme, $m),
            'README nie podaje liczby zdjęć.');
        $this->assertSame($zdjec, (int) $m[1],
            'README obiecuje inną liczbę zdjęć, niż jest plików w katalogu zdjecia/.');
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    /**
     * Konto, na którym widać wszystkie rodzaje odnośników naraz: spis treści
     * → przepisy, przepis → `../zdjecia/` i `../index.html`, `wpisy.html`
     * → `zdjecia/`.
     */
    private function paczkaBogategoKonta(): DataExport
    {
        $basia = $this->user('basia', ['display_name' => 'Basia Żółwiowa']);

        $hero = $this->zdjecie($basia, 'rosol');
        $skan = $this->zdjecie($basia, 'skan');
        $krok = $this->zdjecie($basia, 'krok');

        $rosol = Recipe::factory()->for($basia, 'author')->create([
            'title' => 'Rosół z kury na niedzielę — żółciutki',
            'hero_media_id' => $hero->getKey(),
            'source_scan_media_id' => $skan->getKey(),
        ]);

        RecipeStep::create([
            'recipe_id' => $rosol->getKey(),
            'position' => 0,
            'instruction' => 'Włóż kurę do garnka.',
            'media_id' => $krok->getKey(),
        ]);

        // Szkic i przepis bez zdjęcia — obydwa dostają własny plik w spisie.
        Recipe::factory()->for($basia, 'author')->draft()->create(['title' => 'Niedokończony makowiec']);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Kompot z rabarbaru']);

        // Tytuł, z którego slug nie zostawia ani jednego znaku — nazwa pliku
        // spada wtedy na „przepis", a spis treści musi trafić i w nią.
        Recipe::factory()->for($basia, 'author')->create(['title' => '„?!”', 'slug' => '???']);

        $wpis = Post::factory()->for($basia, 'author')->create(['body' => 'Pierogi ruskie, 120 sztuk']);
        $wpis->media()->attach($this->zdjecie($basia, 'pierogi')->getKey(), ['position' => 0]);

        $cudzy = Recipe::factory()->for($this->user('zenek'), 'author')->create(['title' => 'Żurek']);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $basia->getKey(),
            'recipe_id' => $cudzy->getKey(),
        ]);
        $wykonanie->media()->attach($this->zdjecie($basia, 'zurek')->getKey());

        $export = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return $export->refresh();
    }

    private function zdjecie(User $owner, string $nazwa): Media
    {
        $key = "media/{$owner->getKey()}/{$nazwa}.png";

        Storage::disk('public')->put($key, 'udawana-zawartosc-zdjecia');

        return Media::factory()->create([
            'owner_id' => $owner->getKey(),
            'disk' => 'public',
            'object_key' => $key,
            'mime_type' => 'image/png',
            'status' => Media::STATUS_READY,
        ]);
    }

    /**
     * Wszystkie `href`/`src` z dokumentu, razem z nazwą znacznika.
     *
     * Świadomie przez DOM, a nie przez wyrażenie regularne: odnośnik
     * zapisany w treści użytkownika (a więc zescapowany) NIE jest
     * odnośnikiem i nie ma prawa się tu liczyć.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function odnosnikiZ(string $html): array
    {
        $xpath = new DOMXPath($this->dokument($html));
        $wezly = $xpath->query('//*[@href or @src]');

        $out = [];

        if ($wezly === false) {
            return $out;
        }

        foreach ($wezly as $wezel) {
            if (! $wezel instanceof DOMElement) {
                continue;
            }

            foreach (['href', 'src'] as $atrybut) {
                if ($wezel->hasAttribute($atrybut)) {
                    $out[] = [$wezel->tagName, $atrybut, trim($wezel->getAttribute($atrybut))];
                }
            }
        }

        return $out;
    }

    private function dokument(string $html): DOMDocument
    {
        $dokument = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        return $dokument;
    }

    /** Ścieżka względna rozwinięta względem katalogu pliku, bez „..". */
    private function rozwin(string $katalog, string $wartosc): string
    {
        $sciezka = $katalog === '' ? $wartosc : $katalog.'/'.$wartosc;

        $czesci = [];

        foreach (explode('/', $sciezka) as $czesc) {
            if ($czesc === '' || $czesc === '.') {
                continue;
            }

            if ($czesc === '..') {
                array_pop($czesci);

                continue;
            }

            $czesci[] = $czesc;
        }

        return implode('/', $czesci).(str_ends_with($wartosc, '/') ? '/' : '');
    }

    /** @param list<string> $wpisy */
    private function jestKatalogiem(array $wpisy, string $cel): bool
    {
        if (! str_ends_with($cel, '/')) {
            return false;
        }

        foreach ($wpisy as $wpis) {
            if (str_starts_with($wpis, $cel)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function wpisyArchiwum(DataExport $export): array
    {
        $zip = $this->otworz($export);

        $out = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nazwa = $zip->getNameIndex($i);

            if (is_string($nazwa)) {
                $out[] = $nazwa;
            }
        }

        $zip->close();

        return $out;
    }

    private function zArchiwum(DataExport $export, string $plik): string
    {
        $zip = $this->otworz($export);
        $tresc = $zip->getFromName($plik);
        $zip->close();

        $this->assertIsString($tresc, "W archiwum nie ma pliku {$plik}.");

        return $tresc;
    }

    private function otworz(DataExport $export): ZipArchive
    {
        $zip = new ZipArchive;

        $this->assertTrue(
            $zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true,
            'Nie udało się otworzyć paczki ZIP.',
        );

        return $zip;
    }
}

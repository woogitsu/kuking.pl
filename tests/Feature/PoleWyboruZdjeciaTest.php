<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Schowane pole wyboru pliku — decyzja właściciela D-035.
 *
 * CO SIĘ ZMIENIŁO I DLACZEGO TEN PLIK ISTNIEJE
 * Natywny `<input type="file">` rysuje przycisk „Choose File / No file chosen"
 * PO ANGIELSKU i nie zmienia tego żaden atrybut ani żadna reguła CSS. W środku
 * polskiego formularza był to jedyny angielski napis na ekranie. Właściciel
 * rozstrzygnął, że wolno je schować, a klikalna zostaje etykieta.
 *
 * Cała ta zmiana stoi jednak na jednym warunku: pole MUSI zostać w drzewie
 * dostępności i pod klawiaturą. `display: none` i `visibility: hidden` wyjmują
 * je z obu naraz — i wtedy zamiast usterki dla osób, które nie znają
 * angielskiego, mamy usterkę dla osób, które korzystają z klawiatury albo
 * z czytnika ekranu. W kodzie strony jedno od drugiego NIE RÓŻNI SIĘ NICZYM,
 * więc ten plik pilnuje trzech rzeczy naraz:
 *
 *   1. pole dalej JEST w HTML-u i ma dokładnie JEDNĄ powiązaną etykietę
 *      (`<label for>`), a nie zero i nie dwie;
 *   2. schowanie idzie klasą `.visually-hidden`, a ta klasa NIE UŻYWA
 *      `display: none` ani `visibility: hidden` — sprawdzane wprost
 *      w arkuszu stylów, nie na słowo honoru;
 *   3. `<input>` stoi BEZPOŚREDNIO PRZED swoją etykietą, bo obwódkę fokusu
 *      rysuje reguła sąsiedztwa
 *      `.pole-zdjecia-input:focus-visible + .pole-zdjecia`. Przestawienie
 *      tych dwóch elementów kasuje widoczny fokus i nic tego nie zgłosi.
 *
 * CZEGO TEN PLIK NIE SPRAWDZA (i nie da rady)
 * Że Tab naprawdę zatrzymuje się na polu i że obwódka naprawdę się rysuje —
 * to wymaga ułożonej strony w przeglądarce. Robi to `scripts/dostepnosc.mjs`,
 * blok „Wybór zdjęcia bez JavaScriptu".
 */
class PoleWyboruZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // -----------------------------------------------------------------
    // Ekrany
    // -----------------------------------------------------------------

    public function test_dodaj_zdjecie_ma_pole_pliku_z_jedna_etykieta(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('posts.create'))
            ->assertOk()
            ->getContent();

        $this->assertPoleMaEtykiete($html, 'f-photos', '/dodaj/zdjecie');
    }

    public function test_przepis_bez_javascriptu_ma_pole_pliku_z_jedna_etykieta_w_kazdym_miejscu(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('recipes.create.simple'))
            ->assertOk()
            ->getContent();

        // Zdjęcie gotowego dania, skan starej kartki i zdjęcie pierwszego kroku
        // — trzy różne pola na jednym ekranie, każde ze swoją etykietą.
        $this->assertPoleMaEtykiete($html, 'f-hero_photo', 'formularz przepisu');
        $this->assertPoleMaEtykiete($html, 'f-source_scan', 'formularz przepisu');
        $this->assertPoleMaEtykiete($html, 'f-steps-0-photo', 'formularz przepisu');
    }

    public function test_ugotowalem_ma_pole_pliku_z_jedna_etykieta(): void
    {
        $kucharz = $this->user('basia');
        $przepis = Recipe::factory()->for($this->user('autorprzepisu'), 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $html = $this->actingAs($kucharz)
            ->get(route('cooked.create', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertPoleMaEtykiete($html, 'f-photos', 'Ugotowałem');
    }

    public function test_zdjecie_profilowe_ma_pole_pliku_z_jedna_etykieta(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('settings.profile'))
            ->assertOk()
            ->getContent();

        $this->assertPoleMaEtykiete($html, 'f-avatar', 'zdjęcie profilowe');
    }

    public function test_kreator_livewire_ma_pole_pliku_z_jedna_etykieta(): void
    {
        $html = Livewire::actingAs($this->user('basia'))
            ->test('recipe-wizard')
            ->html();

        $this->assertPoleMaEtykiete($html, 'f-heroPhoto', 'kreator przepisu');
    }

    // -----------------------------------------------------------------
    // Warunki, których złamanie unieważnia całą decyzję D-035
    // -----------------------------------------------------------------

    /**
     * Klasa, która chowa pole, nie może go schować przed klawiaturą.
     *
     * `display: none` i `visibility: hidden` wyjmują element z kolejności
     * tabulacji I z drzewa dostępności. `.visually-hidden` chowa go inaczej:
     * `position: absolute`, prostokąt 1×1 px i `clip`. Pole zostaje wtedy
     * fokusowalne i czytelne dla czytnika ekranu.
     *
     * Sprawdzamy to w arkuszu, a nie w komentarzu, bo to jest jedna linijka
     * CSS-a od cichego zepsucia wszystkiego — i nic innego by tego nie złapało.
     */
    public function test_klasa_ktora_chowa_pole_nie_uzywa_display_none_ani_visibility_hidden(): void
    {
        $arkusz = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($arkusz);
        $this->assertSame(
            1,
            preg_match('/\.visually-hidden\s*\{([^}]*)\}/', $arkusz, $trafienia),
            'W resources/css/app.css nie ma reguły `.visually-hidden` — a to nią widoki chowają pole pliku.',
        );

        $regula = $trafienia[1];

        foreach (['display', 'visibility'] as $wlasnosc) {
            $this->assertDoesNotMatchRegularExpression(
                '/(^|;)\s*'.$wlasnosc.'\s*:/i',
                $regula,
                "Reguła `.visually-hidden` ustawia `{$wlasnosc}`. Pole wyboru pliku jest nią schowane "
                .'(D-035) i po takiej zmianie zniknęłoby z kolejności tabulacji oraz z drzewa dostępności '
                .'— nikt nie wybrałby zdjęcia z klawiatury. Napisz osobną klasę zamiast zmieniać tę.',
            );
        }
    }

    /**
     * Jeden wygląd wyboru pliku w całym serwisie.
     *
     * Zostawienie choćby jednego gołego `<input type="file">` znaczy, że
     * w jednym formularzu jest polski obszar „Dodaj zdjęcie", a w drugim
     * angielskie „Choose File" — czyli dwa różne wyglądy tej samej czynności.
     * Ten test przechodzi po WSZYSTKICH widokach, także tych, których dziś
     * jeszcze nie ma.
     */
    public function test_kazde_pole_pliku_w_widokach_uzywa_tego_samego_wzorca(): void
    {
        $winne = [];
        $znalezionych = 0;

        foreach ($this->wszystkieWidoki() as $sciezka) {
            $tresc = (string) file_get_contents($sciezka);

            // Komentarze Blade WYCINAMY, bo opisują wzorzec słowami i same
            // zawierają `<input type="file">`. Wyrażenia `{{ … }}` zamieniamy
            // na literę, bo strzałka w `{{ $loop->index }}` niesie znak `>`,
            // na którym `[^>]*` niżej urwałoby się w środku znacznika —
            // i pole po cichu wypadłoby ze sprawdzenia. Zmierzone: bez tego
            // ten test widział 8 pól z 9 i świecił na zielono.
            $tresc = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tresc);
            $tresc = (string) preg_replace('/\{!!.*?!!\}|\{\{.*?\}\}/s', 'X', $tresc);

            preg_match_all('/<input\b[^>]*>/s', $tresc, $tagi);

            foreach ($tagi[0] as $tag) {
                if (! str_contains($tag, 'type="file"')) {
                    continue;
                }

                $znalezionych++;

                if (! str_contains($tag, 'pole-zdjecia-input') || ! str_contains($tag, 'visually-hidden')) {
                    $winne[] = str_replace(base_path().'/', '', $sciezka);
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($winne)),
            'Pole wyboru pliku ma wszędzie wyglądać tak samo (D-035): schowane klasą `visually-hidden` '
            .'i oznaczone `pole-zdjecia-input`, z klikalną etykietą `.pole-zdjecia` obok. '
            .'Te widoki mają je inaczej.',
        );

        // KONTROLA, ŻEBY ZIELONY WYNIK COŚ ZNACZYŁ. Gdyby wyrażenie wyżej
        // przestało dopasowywać znaczniki (bo ktoś rozpisze atrybuty inaczej),
        // pętla nie znalazłaby ANI JEDNEGO pola i test przeszedłby, nie
        // sprawdzając niczego.
        $this->assertGreaterThanOrEqual(
            9,
            $znalezionych,
            'Test nie znalazł spodziewanej liczby pól pliku w widokach — sprawdź wyrażenie wyszukujące, '
            .'zanim uznasz ten zielony wynik za prawdziwy.',
        );
    }

    // -----------------------------------------------------------------
    // Narzędzia
    // -----------------------------------------------------------------

    /**
     * Pole o podanym `id` jest w HTML-u, jest polem pliku, ma DOKŁADNIE jedną
     * etykietę `<label for>` i stoi bezpośrednio przed nią.
     */
    private function assertPoleMaEtykiete(string $html, string $id, string $gdzie): void
    {
        $idWzorzec = preg_quote($id, '/');

        // 1. Pole istnieje i jest polem pliku.
        $this->assertSame(
            1,
            preg_match('/<input\b[^>]*\bid="'.$idWzorzec.'"[^>]*>/s', $html, $wejscie),
            'Na ekranie „'.$gdzie.'” nie ma pola o id="'.$id.'".',
        );

        $tagPola = $wejscie[0];

        $this->assertStringContainsString(
            'type="file"',
            $tagPola,
            'Pole „'.$id.'” przestało być polem pliku.',
        );

        // 2. Pole jest schowane dla oka, ale NIE atrybutem `hidden`, który
        //    wyjąłby je z kolejności tabulacji tak samo jak `display: none`.
        $this->assertStringContainsString(
            'visually-hidden',
            $tagPola,
            'Pole „'.$id.'” nie jest schowane klasą `.visually-hidden` (D-035).',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\shidden(\s|=|>)/',
            $tagPola,
            'Pole „'.$id.'” ma atrybut `hidden` — to wyjmuje je spod klawiatury.',
        );

        // 3. Dokładnie jedna etykieta wskazująca na to pole. Dwie to znany
        //    błąd (axe `form-field-multiple-labels`), zero to pole bez nazwy.
        $this->assertSame(
            1,
            preg_match_all('/<label\b[^>]*\bfor="'.$idWzorzec.'"[^>]*>/s', $html, $etykiety),
            'Pole „'.$id.'” na ekranie „'.$gdzie.'” ma mieć dokładnie jedną etykietę `<label for>`.',
        );

        $this->assertStringContainsString(
            'pole-zdjecia',
            $etykiety[0][0],
            'Etykieta pola „'.$id.'” nie jest dużym obszarem wyboru zdjęcia (`.pole-zdjecia`).',
        );

        // 4. Kolejność: `<input>` bezpośrednio przed etykietą. Tego wymaga
        //    reguła fokusu `.pole-zdjecia-input:focus-visible + .pole-zdjecia`
        //    — po przestawieniu obwódka fokusu przestaje się rysować, a nic
        //    innego tego nie zgłosi.
        $this->assertSame(
            1,
            preg_match(
                '/<input\b[^>]*\bid="'.$idWzorzec.'"[^>]*>\s*<label\b[^>]*\bfor="'.$idWzorzec.'"/s',
                $html,
            ),
            'Pole „'.$id.'” nie stoi bezpośrednio przed swoją etykietą — obwódka fokusu przestanie '
            .'się rysować (`.pole-zdjecia-input:focus-visible + .pole-zdjecia`).',
        );

        // 5. Nazwa dostępna jest złożona z elementów, które NAPRAWDĘ są na
        //    stronie. Literówka w `aria-labelledby` zostawia pole bez nazwy,
        //    a wygląda niewinnie.
        if (preg_match('/\baria-labelledby="([^"]+)"/', $tagPola, $wskazania) === 1) {
            foreach (preg_split('/\s+/', trim($wskazania[1])) as $wskazanyId) {
                $this->assertMatchesRegularExpression(
                    '/\bid="'.preg_quote($wskazanyId, '/').'"/',
                    $html,
                    'Pole „'.$id.'” wskazuje w `aria-labelledby` na id="'.$wskazanyId.'", którego nie ma na stronie.',
                );
            }
        }
    }

    /** @return list<string> */
    private function wszystkieWidoki(): array
    {
        $katalog = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        $pliki = [];

        foreach ($katalog as $plik) {
            if ($plik->isFile() && str_ends_with($plik->getFilename(), '.blade.php')) {
                $pliki[] = $plik->getPathname();
            }
        }

        sort($pliki);

        return $pliki;
    }
}

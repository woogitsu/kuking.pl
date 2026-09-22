<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Podgląd zdjęcia JESZCZE PRZED wysłaniem (issue #430).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Pytanie właściciela, 12 września 2026: „Nie można zrobić coś by z cache
 * przeglądarki pokazywało chwilowo a nie z serwera?".
 *
 * Odpowiedź na to pytanie ma dwie połowy i obie są w tej gałęzi:
 *   • PO opublikowaniu wpisu zdjęcie idzie z serwera — wariant `podglad`
 *     liczony synchronicznie (`PodgladOdRazu`), bo tylko to działa dla
 *     każdego, bez JavaScriptu i po odświeżeniu;
 *   • PRZED wysłaniem, przez te kilka sekund, w których 6,14 MB dopiero
 *     jedzie na serwer, zdjęcie idzie WPROST Z PAMIĘCI PRZEGLĄDARKI
 *     (`URL.createObjectURL` w `resources/js/app.js`) — bez ani jednego
 *     bajtu z sieci.
 *
 * CZEGO TEN TEST NIE ROBI I ROBIĆ NIE MOŻE
 * Wszystko, co widać, dzieje się w przeglądarce: zdarzenie `change`, adres
 * `blob:`, siatka CSS i `img-src` z nagłówka CSP. PHPUnit widzi HTML sprzed
 * wykonania skryptu. Pomiar tamtej strony robi
 * `scripts/podglad-przed-wyslaniem.mjs` (ZMIERZONE: źródło `blob:`, 63–103 ms
 * od wyboru pliku, 100% szerokości pojemnika na 320 / 360 / 390 / 414 px,
 * zero naruszeń CSP).
 *
 * Tu pilnujemy trzech rzeczy, które **cicho** cofają tamtą poprawkę i których
 * nie zauważy żaden inny test.
 */
class PodgladWybranegoZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    private function plik(string $sciezka): string
    {
        $pelna = base_path($sciezka);

        // Pułapka 2 z `docs/PULAPKI_TESTOW.md`: test skanujący plik przechodzi
        // także wtedy, gdy pliku nie ma.
        $this->assertFileExists($pelna);

        return (string) file_get_contents($pelna);
    }

    public function test_jedno_wybrane_zdjecie_bierze_cala_szerokosc(): void
    {
        $arkusz = $this->plik('resources/css/ekran-dodawania.css');

        /*
         * TO JEST TEST NA USTERKĘ, KTÓRA NAPRAWDĘ BYŁA.
         *
         * Siatka podglądu stała w skrypcie jako `repeat(auto-fill,
         * minmax(120px, 1fr))`. Przy JEDNYM zdjęciu i oknie 390 px dawało to
         * dwie kolumny, a obrazek zajmował jedną — ZMIERZONE 150 px z 308 px
         * pojemnika, czyli 49%. Człowiek, który właśnie wybrał zdjęcie swojego
         * obiadu, widział znaczek mniejszy niż połowa. Ta sama usterka co
         * w #432, tylko o jeden ekran wcześniej.
         */
        $this->assertMatchesRegularExpression(
            '/\.podglad-wyboru\[data-ile="1"\]\s*\{[^}]*grid-template-columns:\s*1fr/s',
            $arkusz,
            'Zniknęła reguła dająca jednemu zdjęciu całą szerokość — podgląd wraca do 49%.',
        );
    }

    public function test_skrypt_mowi_arkuszowi_ile_jest_zdjec_zamiast_wpisywac_style(): void
    {
        $skrypt = $this->plik('resources/js/app.js');

        // Bez tego arkusz nie ma po czym rozpoznać przypadku „jedno zdjęcie".
        $this->assertStringContainsString(
            'pojemnik.dataset.ile = String(pliki.length);',
            $skrypt,
            'Skrypt przestał mówić, ile zdjęć wybrano — reguła `[data-ile="1"]` nie ma na czym zadziałać.',
        );

        /*
         * NEGATYW: wartości układu wracają do skryptu.
         *
         * Dopóki siatka, odstępy i promień były wpisywane przez
         * `element.style`, istniały DWA źródła prawdy o wyglądzie tego bloku
         * — a to w skrypcie wygrywało z arkuszem i nie znało żadnego tokenu.
         * Reguła projektu (`resources/css/app.css`): żadnej wartości odstępu
         * ani promienia na sztywno.
         */
        foreach (['gridTemplateColumns', 'borderRadius', 'marginTop'] as $wlasciwosc) {
            $this->assertStringNotContainsString(
                '.style.'.$wlasciwosc,
                $skrypt,
                "Skrypt znowu wpisuje `{$wlasciwosc}` w locie — układ ma być w arkuszu, nie w dwóch miejscach.",
            );
        }
    }

    public function test_bez_javascriptu_nie_ma_pustego_pojemnika_na_ekranie(): void
    {
        $html = (string) $this->actingAs($this->user('ania'))
            ->get(route('posts.create'))
            ->assertOk()
            ->getContent();

        // Asercja kontrolna: to naprawdę ekran dodawania, a nie strona błędu.
        $this->assertStringContainsString('id="f-photos"', $html);

        /*
         * Pojemnik podglądu tworzy dopiero skrypt. Gdyby renderował go serwer,
         * osoba z wyłączonym JavaScriptem dostawałaby pusty `aria-live`, który
         * czytnik ekranu ogłasza jako obszar, w którym nic się nigdy nie
         * pojawi (AGENTS.md §5: ważne rzeczy działają bez JS, a reszta ma po
         * prostu NIE BYĆ).
         */
        $this->assertStringNotContainsString('f-photos-podglad', $html);
    }
}

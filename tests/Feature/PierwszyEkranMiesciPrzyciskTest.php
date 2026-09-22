<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hasło i akcja na PIERWSZYM EKRANIE strony powitalnej.
 *
 * NA CZYM POLEGAŁ BŁĄD
 * `STRONA-WWW.md:121` żąda, żeby hasło i akcja były widoczne bez przewijania.
 * Zmierzone w Chromium, jako gość bez sesji, przy skali tekstu 100%: dół
 * przycisku „Zostań kuKINGiem" stał przy oknie 320 × 568 px na 776,4 px,
 * czyli 208,4 px poniżej dolnej krawędzi. Człowiek wchodzący z telefonu
 * widział hasło i ani jednej drogi dalej.
 *
 * ZMIERZONE PO POPRAWCE (dół przycisku / wysokość okna, skala 100%):
 *
 *     320 × 568   776,4 → 541,5      360 × 640   653,9 → 511,6
 *     375 × 667   656,2 → 514,0      414 × 736   628,2 → 520,0
 *
 * Sam układ oddał przy 320 px 98,5 px, skrócenie akapitu kolejne 136,4 px.
 *
 * ZAKRES JEST DECYZJĄ WŁAŚCICIELA
 * Naprawiamy i strzeżemy WYŁĄCZNIE skali tekstu 100%. Przy 140% przewijanie
 * zostaje i jest zaakceptowane. Ten plik nie zawiera ani jednej asercji
 * o skali 140% — asercja zabetonowałaby zachowanie, którego nikt nie wybrał.
 *
 * DLACZEGO TEN TEST ISTNIEJE OBOK POMIARU W PRZEGLĄDARCE
 * Położenia przycisku nie da się stwierdzić ze źródła — to wynik ułożenia
 * strony i mierzy je `scripts/hero-nad-zgieciem.mjs`, który chodzi w CI
 * WARUNKOWO (job przeglądarkowy rusza tylko przy zmianie w warstwie widoku).
 * Ten plik chodzi w jobie `test`, czyli zawsze, i pilnuje trzech rzeczy,
 * które widać bez przeglądarki: że na ekranie stoi zdanie wybrane przez
 * `docs/brand/GLOS_MARKI.md`, że za skrócenie nie zapłacono pismem ani celem
 * dotknięcia, i że sam pomiar dalej istnieje, jest wołany i objęty zakresem CI.
 */
class PierwszyEkranMiesciPrzyciskTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Zdanie, które `docs/brand/GLOS_MARKI.md` §C1 (wiersz 418) wymienia jako
     * wzorcowe. Nie jest „jednym z wariantów" — jest rozstrzygnięciem
     * właściciela z 11 września 2026 i dlatego stoi tu dosłownie.
     */
    private const ZDANIE_Z_GLOSU_MARKI = 'Wrzuć zdjęcie i kilka słów, a pokażesz je komuś, kto dziś też gotował.';

    /**
     * Zdanie opisowe, które wypadło. Mówiło to samo, co nadtytuł „Gotujemy po
     * swojemu." i opis strony w `<head>`, a przy 320 px kosztowało 136,4 px —
     * czyli dokładnie tyle, ile brakowało przyciskowi.
     */
    private const OPIS_KTORY_WYPADL = 'to miejsce dla ludzi, którzy gotują codziennie';

    private function plik(string $sciezka): string
    {
        $tresc = file_get_contents(base_path($sciezka));

        $this->assertIsString($tresc, 'Nie udało się przeczytać: '.$sciezka);
        $this->assertNotSame('', trim($tresc), 'Pusty plik: '.$sciezka);

        return $tresc;
    }

    /** Akapit hasła z GOTOWEJ STRONY, nie ze źródła — komentarze Blade cytują
     *  teksty, których na ekranie już nie ma, i szukanie ich w źródle trafiałoby
     *  we własne uzasadnienie (`docs/PULAPKI_TESTOW.md`, pułapka 1). */
    private function akapitHasla(string $html): string
    {
        $trafione = preg_match('/<p class="[^"]*hero-lead[^"]*">(.*?)<\/p>/s', $html, $dopasowanie);

        $this->assertSame(1, $trafione, 'Na stronie powitalnej nie ma akapitu `.hero-lead`.');

        return trim(html_entity_decode(strip_tags($dopasowanie[1]), ENT_QUOTES | ENT_HTML5));
    }

    public function test_haslo_mowi_zdaniem_wybranym_przez_glos_marki(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(self::ZDANIE_Z_GLOSU_MARKI, $this->akapitHasla($html));
    }

    public function test_akapit_hasla_to_jedno_zdanie(): void
    {
        $akapit = $this->akapitHasla($this->get('/')->assertOk()->getContent());

        $this->assertSame(1, substr_count($akapit, '.'),
            'Akapit hasła ma więcej niż jedno zdanie — przy 320 px każde kolejne spycha przycisk poniżej krawędzi okna.');
        $this->assertStringNotContainsString(self::OPIS_KTORY_WYPADL, $akapit,
            'Opisowe zdanie wróciło na pierwszy ekran.');
    }

    /**
     * Najtańszą naprawą tej usterki byłoby zmniejszenie pisma albo przycisku.
     * Poprawka tego NIE ROBI i ten test jest po to, żeby następna też nie
     * zrobiła: reguły dołożone dla pierwszego ekranu ruszają wyłącznie odstępy.
     */
    public function test_skrocenie_nie_zostalo_oplacone_pismem_ani_celem_dotkniecia(): void
    {
        $arkusz = $this->plik('resources/css/marka-ekrany.css');
        $poczatek = strpos($arkusz, 'PIERWSZY EKRAN STRONY POWITALNEJ');

        $this->assertIsInt($poczatek,
            'W `marka-ekrany.css` nie ma bloku pierwszego ekranu — reguły zniknęły albo zmieniły nazwę.');

        $blok = substr($arkusz, $poczatek);

        foreach (['font-size', 'line-height', 'height:', 'min-height', 'transform', 'zoom'] as $zakazana) {
            $this->assertStringNotContainsString($zakazana, $blok,
                'Blok pierwszego ekranu rusza `'.$zakazana.'` — a miał ruszać wyłącznie odstępy.');
        }

        foreach (['padding-block-start', 'margin-bottom'] as $oczekiwana) {
            $this->assertStringContainsString($oczekiwana, $blok,
                'Blok pierwszego ekranu nie ustawia `'.$oczekiwana.'` — poprawka układu wyparowała.');
        }
    }

    /**
     * Pomiar może zniknąć, przestać być wołany albo wypaść z zakresu jobów
     * przeglądarkowych — i wtedy nic nie zaczerwieni się przy regresji.
     * Każdą z tych trzech dróg zamykamy osobno.
     */
    public function test_pomiar_pierwszego_ekranu_istnieje_jest_wolany_i_objety_zakresem_ci(): void
    {
        $pomiar = $this->plik('scripts/hero-nad-zgieciem.mjs');

        foreach ([[320, 568], [360, 640], [375, 667], [414, 736]] as [$szerokosc, $wysokosc]) {
            $this->assertMatchesRegularExpression(
                '/szerokosc:\s*'.$szerokosc.',\s*wysokosc:\s*'.$wysokosc.'\s*}/',
                $pomiar,
                "Pomiar nie zna okna {$szerokosc}×{$wysokosc} px.",
            );
        }

        $this->assertStringContainsString('const SKALA_STRZEZONA = 100;', $pomiar);
        $this->assertStringContainsString('const MIN_PISMO_PX = 18;', $pomiar);
        $this->assertStringContainsString('const MIN_CEL_PX = 48;', $pomiar);
        $this->assertStringContainsString('CTA_PONIZEJ_ZGIECIA', $pomiar,
            'Pomiar nie ma nazwanej przyczyny oblania — kontrola ujemna nie odróżni dowodu od awarii środowiska.');

        $port = $this->plik('scripts/port-projektu.mjs');
        $this->assertStringContainsString("from './hero-nad-zgieciem.mjs'", $port);
        $this->assertStringContainsString('await sprawdzHeroNadZgieciem({', $port,
            'Pomiar jest zaimportowany, ale nikt go nie woła.');

        $this->assertStringContainsString('hero-nad-zgieciem', $this->plik('.github/workflows/ci.yml'),
            'Zmiana samego przyrządu nie uruchomi jobów przeglądarkowych — dałoby się zepsuć miernik bez czerwieni.');
    }

    /**
     * KONTROLA DODATNIA dla wszystkiego powyżej (wzór:
     * `test_skan_naprawde_czyta_modele`). Skan, który nie znajduje ani jednego
     * pliku, przechodzi — więc osobno sprawdzamy, że pliki naprawdę są czytane
     * i że strona powitalna naprawdę się renderuje.
     */
    public function test_skan_naprawde_czyta_pliki_i_strone(): void
    {
        foreach ([
            'resources/css/marka-ekrany.css',
            'scripts/hero-nad-zgieciem.mjs',
            'scripts/port-projektu.mjs',
            '.github/workflows/ci.yml',
        ] as $sciezka) {
            $this->assertGreaterThan(500, strlen($this->plik($sciezka)),
                'Podejrzanie krótki plik — skan mógłby przejść, nie czytając niczego: '.$sciezka);
        }

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('hero-akcje', $html,
            'Na stronie powitalnej nie ma bloku akcji — asercje o haśle mierzyłyby pustkę.');
        $this->assertStringContainsString('btn-duzy', $html,
            'Na stronie powitalnej nie ma przycisku, o który w tym wszystkim chodzi.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cztery miejsca, w których pomiar UŁOŻONEJ strony pokazał złamanie twardych
 * reguł UX 50+ (AGENTS.md §5, docs/UX_50_PLUS.md) — audyt z 20 września 2026.
 *
 * CO ZMIERZONO, ZANIM POWSTAŁ TEN TEST
 * Przebieg `scripts/audyt-ux50plus.mjs`: 25 ekranów × 320/360/390/414/768/1440 px
 * × motyw jasny i ciemny × tekst 100% i 140% = 600 pomiarów, każdy czytający
 * `getComputedStyle()` i `getBoundingClientRect()` na ułożonej stronie.
 *
 *   • odnośniki stopki  — 16 px pisma, cel 74,6 × 20 px  (na KAŻDYM ekranie)
 *   • okruszki przepisu — 16 px pisma, cel 35 × 20 px
 *   • filtr na /pytania — goły `<select>`, cel 157 × 23 px, pismo przeglądarki
 *   • „Tu nie ma rankingu…" w szynie — 16 px (7 ekranów)
 *
 * DLACZEGO TEST CZYTA CSS, A NIE PRZEGLĄDARKĘ
 * Bo ma oblać w tej samej sekundzie, w której ktoś cofnie poprawkę — a nie
 * kilkanaście minut później, gdy skończy się przebieg Playwrighta. Pomiar
 * w przeglądarce zostaje tam, gdzie jest: w `scripts/` i w CI. Ten test
 * pilnuje DEKLARACJI, które ten pomiar wskazał jako przyczynę.
 *
 * Ograniczenie jest przy tym nazwane wprost: test sprawdza, że reguła mówi
 * „18 px" i „48 px", a nie że tyle wyszło po ułożeniu. Gdyby ktoś przykrył
 * te reguły inną, mocniejszą, ten test przeszedłby, a strona dalej byłaby
 * za mała — to łapie dopiero pomiar w przeglądarce.
 */
class StopkaOkruszkiIFiltrTrzymajaMinimaUxTest extends TestCase
{
    // Ostatni test w tej klasie wchodzi na `/pytania` prawdziwym żądaniem,
    // a ta strona czyta listę pytań z bazy. Reszta testów bazy nie dotyka.
    use RefreshDatabase;

    /** Treść pliku CSS bez komentarzy — nazwa klasy we wzmiance nie jest regułą. */
    private function css(string $plik): string
    {
        $tresc = (string) file_get_contents(resource_path('css/'.$plik));

        return (string) preg_replace('#/\*.*?\*/#s', '', $tresc);
    }

    public function test_stopka_pisze_tekstem_18_px_a_nie_pomoca_kontekstowa(): void
    {
        $css = $this->css('app.css');

        $this->assertSame(
            1,
            preg_match('/\.site-footer-inner\s*\{[^{}]*font-size\s*:\s*var\((--text-[\w-]+)\)/s', $css, $trafienie),
            'Nie znalazłem reguły `.site-footer-inner` z jawnym font-size. '.
            'Jeśli zmieniła nazwę, popraw ten test — nie usuwaj go.',
        );

        $this->assertSame(
            '--text-body',
            $trafienie[1],
            'Odnośniki stopki („Pomoc", „Zasady", „Prywatność", „Zgłoś nielegalną treść") '.
            'to CAŁA treść swoich elementów, nie dopisek przy większym tekście. '.
            'Zmierzone przed poprawką: 16 px na 25 ekranach. Minimum to --text-body (18 px).',
        );
    }

    public function test_odnosnik_w_stopce_ma_48_px_celu_dotkniecia(): void
    {
        $css = $this->css('app.css');

        $this->assertSame(
            1,
            preg_match('/\.site-footer-grupa\s+ul\s+a\s*\{([^{}]*)\}/s', $css, $trafienie),
            'Zniknęła reguła `.site-footer-grupa ul a`. Bez niej odnośniki stopki '.
            'wracają do 20 px wysokości (zmierzone).',
        );

        $this->assertMatchesRegularExpression(
            '/min-height\s*:\s*var\(--control-height-min\)/',
            $trafienie[1],
            'Odnośnik stopki musi mieć min-height 48 px (--control-height-min).',
        );

        // `display: flex` nie jest ozdobą: pionowy padding na elemencie inline
        // rysuje się poza pudełkiem wiersza i NIE powiększa celu dotknięcia.
        $this->assertMatchesRegularExpression(
            '/display\s*:\s*(inline-)?flex/',
            $trafienie[1],
            'Bez `display: flex` (albo inline-flex) min-height na elemencie inline nic nie da.',
        );
    }

    public function test_okruszki_przepisu_nie_schodza_ponizej_18_px_i_maja_48_px_celu(): void
    {
        $css = $this->css('app.css');

        $this->assertSame(
            1,
            preg_match('/(?:^|[},])\s*\.okruchy\s*\{([^{}]*)\}/m', $css, $lista),
            'Nie znalazłem reguły `.okruchy`.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/font-size\s*:\s*var\(--text-help\)/',
            $lista[1],
            'Okruszki są jedyną drogą powrotną dla kogoś, kto wszedł na przepis z wyszukiwarki. '.
            'Zmierzone przed poprawką: 16 px. --text-help wolno użyć tylko obok tekstu ≥ 18 px.',
        );

        $this->assertSame(
            1,
            preg_match('/\.okruchy\s+a\s*\{([^{}]*)\}/s', $css, $odnosnik),
            'Zniknęła reguła `.okruchy a` — bez niej cel dotknięcia wraca do 20 px (zmierzone).',
        );

        $this->assertMatchesRegularExpression(
            '/min-height\s*:\s*var\(--control-height-min\)/',
            $odnosnik[1],
            'Odnośnik w okruszkach musi mieć min-height 48 px.',
        );
    }

    public function test_zdanie_o_braku_rankingu_ma_18_px(): void
    {
        $css = $this->css('app.css');

        $this->assertSame(
            1,
            preg_match('/\.kuking-board-footer\s*\{([^{}]*)\}/s', $css, $trafienie),
            'Nie znalazłem reguły `.kuking-board-footer`.',
        );

        $this->assertMatchesRegularExpression(
            '/font-size\s*:\s*var\(--text-body\)/',
            $trafienie[1],
            '„Tu nie ma rankingu. Pokazujemy różne osoby, nie najlepsze." to całe zdanie '.
            'stojące samo pod linią, a nie dopisek. Zmierzone przed poprawką: 16 px na 7 ekranach.',
        );
    }

    public function test_zobacz_wszystko_w_szynie_ma_18_px_i_48_px_celu(): void
    {
        $css = $this->css('app.css');

        $this->assertSame(
            1,
            preg_match('/(?:^|[},])\s*\.szyna-wiecej\s*\{([^{}]*)\}/m', $css, $trafienie),
            'Nie znalazłem reguły `.szyna-wiecej`.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/font-size\s*:\s*var\(--text-help\)/',
            $trafienie[1],
            '„Zobacz wszystko" to jedyne wyjście z bloku szyny do pełnej listy, '.
            'a nie dopisek przy większym tekście. Zmierzone przed poprawką: 16 px.',
        );

        $this->assertMatchesRegularExpression(
            '/min-height\s*:\s*var\(--control-height-min\)/',
            $trafienie[1],
            'Zmierzone przed poprawką: 135 × 24,8 px. Cel dotknięcia ma mieć 48 px.',
        );
    }

    public function test_filtr_pytan_jest_polem_formularza_a_nie_golym_selectem(): void
    {
        $widok = (string) file_get_contents(
            resource_path('views/pages/questions/index.blade.php'),
        );

        $this->assertMatchesRegularExpression(
            '/<select[^>]*class="[^"]*\bfield-input\b[^"]*"/',
            $widok,
            'Filtr „Pokaż pytania" musi mieć klasę `field-input`. Goły <select> nie trafia '.
            'na żadną naszą regułę — zmierzone: 157 × 23 px i pismo przeglądarki, '.
            'czyli poniżej 48 px celu i poniżej 18 px pisma naraz.',
        );
    }

    public function test_ekran_pytan_ma_pole_filtra_w_ramce_field(): void
    {
        config()->set('kuking.questions.enabled', true);

        $odpowiedz = $this->get('/pytania');

        $odpowiedz->assertOk();
        // Sam CSS nie dowodzi, że klasa trafia na stronę — to sprawdza żądanie.
        $odpowiedz->assertSee('class="field-input" id="question-filter"', false);
    }
}

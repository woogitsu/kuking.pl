<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Twarde minimum 18 px dla tekstu, który stoi sam (issue #110).
 *
 * PO CO TO TESTOWAĆ
 * `docs/UX_50_PLUS.md` mówi „tekst podstawowy minimum 18 px" i to jest
 * obietnica produktu, nie preferencja. `docs/design/DESIGN_SYSTEM.md` dopuszcza
 * `--text-help` (16 px) w JEDNYM przypadku: gdy tuż obok stoi tekst ≥ 18 px,
 * który niesie treść. Ta różnica jest subtelna i łatwo ją zgubić — dolny pasek
 * nawigacji miał 16 px przez kilkanaście commitów i nikt tego nie zauważył,
 * bo na monitorze programisty 16 px czyta się bez wysiłku.
 *
 * Testujemy CSS jako tekst, nie przez przeglądarkę, bo automat dostępności
 * (`scripts/dostepnosc.mjs`) chodzi osobno i wolno. Ten test ma oblać w tej
 * samej sekundzie, w której ktoś wpisze `--text-help` w podpis nawigacji.
 */
class MinimalnyRozmiarTekstuTest extends TestCase
{
    /**
     * Selektory, w których tekst jest CAŁĄ treścią elementu albo jedynym
     * wyjaśnieniem stojącej obok liczby. Dla nich 16 px jest za mało.
     */
    private const SAMODZIELNE_ETYKIETY = [
        '.bottom-nav-item',        // podpis pod ikoną w dolnym pasku
        '.stat-label',             // „wpisów", „obserwujących" na profilu
        '.kuking-board-subtitle',  // <h3> nad listą, stoi sam
        '.site-footer-liczba',     // „23 kuKINGów" w stopce (issue #38)
        '.post-card-zapisy',       // „3 osoby zapisały to u siebie w zeszycie" (#275, D-081)
    ];

    /** Rozmiary, które wolno przypisać samodzielnej etykiecie. */
    private const DOZWOLONE_TOKENY = [
        '--text-body',
        '--text-body-lg',
        '--text-title-sm',
        '--text-title',
        '--text-title-lg',
    ];

    private function css(string $plik): string
    {
        return (string) file_get_contents(resource_path('css/'.$plik));
    }

    /**
     * Deklaracje jednej reguły, po nazwie selektora.
     *
     * Bierzemy pierwszy blok `{...}`, którego lista selektorów zawiera podany
     * selektor jako całe słowo — inaczej `.stat-label` trafiałoby też
     * w `.stat-label-mala`, gdyby ktoś taką dodał.
     */
    private function deklaracje(string $css, string $selektor): string
    {
        $wzorzec = '/(?:^|[},])\s*([^{}]*'.preg_quote($selektor, '/').'(?![\w-])[^{}]*)\{([^{}]*)\}/m';

        $this->assertSame(
            1,
            preg_match($wzorzec, $css, $trafienie),
            "Nie znalazłem reguły CSS dla selektora {$selektor}. ".
            'Jeśli selektor zmienił nazwę, zaktualizuj listę w tym teście — '.
            'nie usuwaj go, bo wtedy minimum 18 px przestaje być pilnowane.',
        );

        return $trafienie[2];
    }

    public function test_tokeny_rozmiaru_maja_wartosci_z_dokumentacji(): void
    {
        $tokeny = $this->css('tokens.css');

        // Cała reszta testu opiera się na tym, że `--text-body` to naprawdę
        // 18 px. Gdyby ktoś zmienił token na 1rem, asercje niżej dalej by
        // przechodziły, sprawdzając nic.
        $this->assertMatchesRegularExpression(
            '/--text-body:\s*calc\(1\.125rem\s*\*/',
            $tokeny,
            '--text-body musi zostać 1.125rem (18 px) — to jest minimum z docs/UX_50_PLUS.md.',
        );

        $this->assertMatchesRegularExpression(
            '/--text-help:\s*calc\(1rem\s*\*/',
            $tokeny,
            '--text-help to 16 px i wolno go użyć tylko obok tekstu ≥ 18 px.',
        );

        // Bazowy rozmiar `html` musi zostać przy domyślnym 16 px przeglądarki.
        // Gdyby ktoś dał `html { font-size: 87.5% }` (częsty trik „żeby 1rem
        // = 14px"), wszystkie tokeny w rem po cichu zjechałyby poniżej minimum.
        foreach (['tokens.css', 'app.css'] as $plik) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bhtml\s*\{[^{}]*font-size\s*:/',
                $this->css($plik),
                "Plik {$plik} zmienia bazowy font-size na html — to przesuwa KAŻDY rozmiar w rem.",
            );
        }
    }

    public function test_samodzielne_etykiety_maja_co_najmniej_18_px(): void
    {
        $css = $this->css('app.css');

        foreach (self::SAMODZIELNE_ETYKIETY as $selektor) {
            $deklaracje = $this->deklaracje($css, $selektor);

            $this->assertSame(
                1,
                preg_match('/font-size\s*:\s*var\((--[\w-]+)\)/', $deklaracje, $trafienie),
                "Reguła {$selektor} musi jawnie ustawiać font-size przez token, ".
                'żeby dało się to sprawdzić testem.',
            );

            $this->assertContains(
                $trafienie[1],
                self::DOZWOLONE_TOKENY,
                "Reguła {$selektor} używa {$trafienie[1]}. Ten tekst stoi sam (nie ma obok ".
                'siebie tekstu ≥ 18 px), więc musi mieć co najmniej --text-body.',
            );
        }
    }

    public function test_podpisy_w_dolnej_nawigacji_sa_krotkie(): void
    {
        // 18 px zamiast 16 px oznacza szersze podpisy. Przy pięciu pozycjach
        // i telefonie 360 px każde słowo dłuższe niż „Zeszyt" zaczyna zawijać
        // pasek do dwóch wierszy. To nie psuje się cicho — po prostu wygląda
        // źle — ale łatwo o tym zapomnieć przy dodawaniu szóstej pozycji.
        $layout = (string) file_get_contents(resource_path('views/components/layout.blade.php'));

        $this->assertSame(
            1,
            preg_match('/<nav[^>]*class="[^"]*bottom-nav[^"]*"[^>]*>(.*?)<\/nav>/s', $layout, $trafienie),
            'Nie znalazłem dolnej nawigacji w layoucie.',
        );

        $pozycje = preg_match_all('/class="bottom-nav-item[^"]*"/', $trafienie[1]);

        $this->assertLessThanOrEqual(
            5,
            $pozycje,
            'Nawigacja mobilna ma maksymalnie 5 pozycji (AGENTS.md §5).',
        );
    }
}

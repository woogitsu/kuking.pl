<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Układ kolumnowy znaku i odpięcie paska górnego stoją na tym samym progu.
 *
 * Przy tekście konta 125% i 140% znak „KuKing.pl" przechodzi w układ
 * kolumnowy przy `max-width: 16rem`, a pasek górny odpina się dopiero przy
 * `15rem`. W pasie 240–256 px pasek (238–274 px + margines) bywał wyższy niż
 * rezerwa `scroll-padding-top` (264 i 278 px), więc fokus po Tab mógł zajechać
 * pod przypięty pasek (WCAG 2.4.11). Wysokość mierzy przeglądarka (zob.
 * komentarz w `marka-rama.css`); ten test pilnuje tylko tego, co widać
 * w źródle: że oba progi nie rozjadą się po cichu.
 */
class PasekPrzyKolumnowymZnakuTest extends TestCase
{
    private const SKALE = '[data-text-scale="125"], [data-text-scale="140"]';

    private function css(): string
    {
        $tresc = (string) file_get_contents(resource_path('css/marka-rama.css'));
        $bez = (string) preg_replace('~/\*.*?\*/~s', '', $tresc);

        $this->assertNotSame('', trim($bez), 'Arkusz marka-rama.css jest pusty — test sprawdzałby pustkę.');

        return $bez;
    }

    /** Treść bloku `@media (max-width: 16rem) { ... }` — wszystkie takie bloki sklejone. */
    private function blokiPrzyProgu(string $css, string $prog): string
    {
        preg_match_all('~@media\s*\(max-width:\s*'.preg_quote($prog, '~').'\)\s*\{((?:[^{}]|\{[^{}]*\})*)\}~', $css, $trafienia);

        return implode("\n", $trafienia[1]);
    }

    public function test_znak_w_kolumnie_stoi_na_progu_16rem(): void
    {
        $bloki = $this->blokiPrzyProgu($this->css(), '16rem');

        $this->assertMatchesRegularExpression('~\.wordmark\s*\{[^}]*flex-direction:\s*column~', $bloki);
    }

    public function test_ten_sam_prog_odpina_pasek_i_zeruje_rezerwe_nad_nim(): void
    {
        $bloki = $this->blokiPrzyProgu($this->css(), '16rem');
        $skale = preg_quote(self::SKALE, '~');

        $this->assertMatchesRegularExpression(
            '~html:is\('.$skale.'\)\s*\[data-marka\]\s*\.marka-topbar\s*\{\s*position:\s*relative~',
            $bloki,
            'Przy progu układu kolumnowego znaku pasek ma przestać być przypięty.',
        );
        $this->assertMatchesRegularExpression(
            '~:root:is\('.$skale.'\):has\(body\[data-marka\]\)\s*\{\s*--rezerwa-nad-belka:\s*\.5rem~',
            $bloki,
            'Odpięty pasek nie ma rezerwy nad sobą — tak samo jak niżej 15rem.',
        );
    }
}

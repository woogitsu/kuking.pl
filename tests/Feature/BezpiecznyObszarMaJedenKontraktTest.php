<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Jeden kontrakt bezpiecznego obszaru: `viewport-fit=cover` + cztery tokeny (#987, D-257).
 *
 * Przy `cover` strona wchodzi pod wycięcie, zaokrąglone narożniki i wskaźnik
 * Home. Pojedyncze `env(safe-area-inset-bottom)` w trzech arkuszach —
 * stan sprzed tej zmiany — chroniło tylko dół. Test pilnuje trzech rzeczy:
 * meta viewport wybiera `cover`, insety czyta WYŁĄCZNIE `bezpieczny-obszar.css`
 * (wszystkie cztery), a każdy brzeg ma właściciela w CSS.
 */
class BezpiecznyObszarMaJedenKontraktTest extends TestCase
{
    public function test_wspolny_viewport_wybiera_cover(): void
    {
        $layout = (string) file_get_contents(resource_path('views/components/layout.blade.php'));

        $this->assertTrue(
            str_contains($layout, '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'),
            'Wspólny meta viewport musi wybierać viewport-fit=cover (D-257).',
        );
    }

    public function test_insety_czyta_tylko_plik_kontraktu_i_wszystkie_cztery(): void
    {
        $kontrakt = $this->bezKomentarzy('bezpieczny-obszar.css');
        foreach (['top', 'right', 'bottom', 'left'] as $brzeg) {
            $this->assertStringContainsString("--safe-{$brzeg}: env(safe-area-inset-{$brzeg}, 0px);", $kontrakt);
        }

        $obce = [];
        foreach (glob(resource_path('css/*.css')) as $plik) {
            if (basename($plik) !== 'bezpieczny-obszar.css' && str_contains($this->bezKomentarzy(basename($plik)), 'safe-area-inset')) {
                $obce[] = basename($plik);
            }
        }
        $this->assertSame([], $obce, 'Gołe env(safe-area-inset-*) poza bezpieczny-obszar.css — użyj var(--safe-*).');

        $app = $this->bezKomentarzy('app.css');
        $this->assertStringContainsString('@import "./bezpieczny-obszar.css";', $app);
    }

    public function test_kazdy_brzeg_ma_wlasciciela(): void
    {
        $kontrakt = $this->bezKomentarzy('bezpieczny-obszar.css');
        $this->assertStringContainsString('padding-left: var(--safe-left);', $kontrakt);
        $this->assertStringContainsString('padding-right: var(--safe-right);', $kontrakt);

        $app = $this->bezKomentarzy('app.css');
        $this->assertStringContainsString('padding-top: var(--safe-top);', $app);
        $this->assertStringContainsString('padding-bottom: var(--safe-bottom);', $app);

        $rama = $this->bezKomentarzy('marka-rama.css');
        $this->assertStringContainsString('top: calc(8px + var(--safe-top));', $rama);
        $this->assertStringContainsString('left: calc(8px + var(--safe-left));', $rama);
        $this->assertStringContainsString('right: calc(8px + var(--safe-right));', $rama);
        $this->assertStringContainsString('bottom: calc(8px + var(--safe-bottom));', $rama);
    }

    private function bezKomentarzy(string $plik): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/'.$plik)));
    }
}

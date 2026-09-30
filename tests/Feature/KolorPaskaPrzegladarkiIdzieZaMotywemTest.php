<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `<meta name="theme-color">` i `theme_color` manifestu (#2267).
 *
 * Jasny motyw jest domyślny (D-019), a do 30.09.2026 layout zawsze wypisywał
 * ciemne #151714 — jasna strona dostawała na telefonie czarny pasek
 * przeglądarki, a zainstalowana aplikacja czarny pasek przy jasnym ekranie
 * startowym. Kolor paska ma być tłem strony w AKTYWNYM motywie, czyli
 * `--color-surface` z `resources/css/tokens.css`.
 */
final class KolorPaskaPrzegladarkiIdzieZaMotywemTest extends TestCase
{
    use RefreshDatabase;

    private const JASNY = '#F3F4F1';

    private const CIEMNY = '#151714';

    public function test_gosc_bez_wyboru_i_z_jasnym_wyborem_dostaje_jasny_pasek(): void
    {
        $this->assertSame(self::JASNY, $this->kolorPaska($this->get('/o-kuking')->assertOk()->getContent()), 'Jasna strona bez wyboru ma jasny pasek przeglądarki.');

        $this->withCookie((string) config('kuking.theme.cookie'), 'light');
        $this->assertSame(self::JASNY, $this->kolorPaska($this->get('/o-kuking')->assertOk()->getContent()), 'Jasna strona bez wyboru ma jasny pasek przeglądarki.');
    }

    public function test_ciemny_wybor_goscia_albo_konta_daje_ciemny_pasek(): void
    {
        $this->withCookie((string) config('kuking.theme.cookie'), 'dark');
        $this->assertSame(self::CIEMNY, $this->kolorPaska($this->get('/o-kuking')->assertOk()->getContent()));

        $this->withCookie((string) config('kuking.theme.cookie'), 'light');
        $this->actingAs($this->user(null, ['theme' => 'dark']));
        $this->assertSame(self::CIEMNY, $this->kolorPaska($this->get('/o-kuking')->assertOk()->getContent()), 'Wybór konta ma pierwszeństwo przed ciasteczkiem.');
    }

    public function test_znacznik_niesie_oba_kolory_dla_podgladu_bez_przeladowania(): void
    {
        $html = (string) $this->get('/o-kuking')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<meta name="theme-color"[^>]*data-jasny="'.self::JASNY.'"[^>]*data-ciemny="'.self::CIEMNY.'"/', $html);
    }

    public function test_kolory_to_tlo_strony_z_tokenow_a_manifest_ma_jasny(): void
    {
        $tokeny = (string) file_get_contents(resource_path('css/tokens.css'));

        $this->assertMatchesRegularExpression('/@theme\s*\{[^}]*--color-surface:\s*'.self::JASNY.';/i', $tokeny, 'Jasny kolor paska rozjechał się z --color-surface.');
        $this->assertMatchesRegularExpression('/:root\[data-theme="dark"\][^{]*\{[^}]*--color-surface:\s*'.self::CIEMNY.';/i', $tokeny, 'Ciemny kolor paska rozjechał się z --color-surface.');

        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(self::JASNY, $manifest['theme_color'], 'Manifest ma kolor jasnego motywu — domyślnego (D-019).');
        $this->assertSame($manifest['background_color'], $manifest['theme_color'], 'Pasek i ekran startowy PWA w jednym kolorze.');
    }

    private function kolorPaska(string $html): ?string
    {
        return preg_match('/<meta name="theme-color" content="([^"]+)"/', $html, $m) === 1 ? $m[1] : null;
    }
}

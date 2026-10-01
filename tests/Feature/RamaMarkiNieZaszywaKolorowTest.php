<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Wspólna rama marki nie ma kolorów zaszytych w regułach (#492).
 *
 * `resources/css/marka-rama.css` miał osiem miejsc z gołym `#151714`,
 * `#FF9586`, `#CBD0C6`, `#262924`, `#737A70` i `#fff`. Zmiana palety w
 * `tokens.css` omijała więc ciemne powierzchnie marki: blok publikacji,
 * wstęp tablicy, kafel „Ugotowałem” i kółko dolnej nawigacji. Ciemne
 * powierzchnie są ciemne w OBU motywach, więc nie mogą brać `--color-surface`
 * (w jasnym motywie jest jasne) — mają własne tokeny `--marka-ciemny-*`,
 * zdefiniowane raz na górze pliku. Biały tekst to `--color-ink-inverse`.
 *
 * Dozwolony jest goły zapis koloru tylko w definicji tokenu `--marka-*`.
 *
 * KONTROLA DODATNIA: `test_miara_widzi_zaszyty_kolor`.
 */
class RamaMarkiNieZaszywaKolorowTest extends TestCase
{
    public function test_marka_rama_nie_zaszywa_kolorow_poza_definicja_tokenow(): void
    {
        $css = $this->bezKomentarzy((string) file_get_contents(resource_path('css/marka-rama.css')));

        $this->assertGreaterThan(3, count($this->zdefiniowaneTokeny($css)), 'Odczytano za mało tokenów ciemnych powierzchni — test nic by nie sprawdzał.');
        $this->assertSame([], $this->zaszyte($css), 'Te kolory są wpisane w regułę zamiast w token: '.implode(', ', $this->zaszyte($css)));
    }

    public function test_kazdy_uzyty_token_marki_jest_zdefiniowany(): void
    {
        $css = $this->bezKomentarzy((string) file_get_contents(resource_path('css/marka-rama.css')));

        preg_match_all('/var\(\s*(--marka-[\w-]+)/', $css, $uzyte);

        $this->assertSame([], array_values(array_diff(array_unique($uzyte[1]), $this->zdefiniowaneTokeny($css))));
    }

    public function test_miara_widzi_zaszyty_kolor(): void
    {
        $css = '[data-marka] { --marka-ciemny-tlo: #151714; } [data-marka] .a { background: #151714; color: #fff; border: 1px solid #737a70; }';

        $this->assertSame(['#151714', '#fff', '#737a70'], $this->zaszyte($css));
    }

    /** @return list<string> */
    private function zaszyte(string $css): array
    {
        $bezDefinicji = (string) preg_replace('/--marka-[\w-]+\s*:\s*[^;}]*;/', '', $css);
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $bezDefinicji, $m);

        return $m[0];
    }

    /** @return list<string> */
    private function zdefiniowaneTokeny(string $css): array
    {
        preg_match_all('/(--marka-[\w-]+)\s*:/', $css, $m);

        return array_values(array_unique($m[1]));
    }

    private function bezKomentarzy(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}

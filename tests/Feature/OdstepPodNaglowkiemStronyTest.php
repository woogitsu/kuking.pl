<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Odstep naglowkow: zwykle strony maja h1 pod main, Start ma wlasny header.
 * Strukture sprawdzamy w renderze, deklaracje odstepu w realnym arkuszu.
 * Pomiar kaskady i geometrii nalezy do przegladarki. */
class OdstepPodNaglowkiemStronyTest extends TestCase
{
    use RefreshDatabase;

    public function test_naglowek_strony_ma_odstep_w_arkuszu_stylow(): void
    {
        $css = (string) file_get_contents(resource_path('css/tokens.css'));

        $trafil = preg_match(
            '/\.app-main\s*>\s*h1\s*\{([^}]*)\}/',
            $css,
            $dopasowanie,
        );

        $this->assertSame(
            1,
            $trafil,
            'W `resources/css/tokens.css` nie ma reguły `.app-main > h1 { ... }`. '.
            'Nagłówek strony jest bezpośrednim dzieckiem `<main class="app-main">` '.
            'na każdej podstronie (patrz `components/layout.blade.php`) — to jedyne '.
            'miejsce, w którym da się jednym selektorem oddzielić `<h1>` od treści '.
            'pod nim na wszystkich widokach naraz.',
        );

        $tresc = $dopasowanie[1];

        $this->assertMatchesRegularExpression(
            '/margin-bottom:\s*var\(--spacing-\d+\)/',
            $tresc,
            '`.app-main > h1` musi mieć `margin-bottom` z tokenu odstępu '.
            '(`var(--spacing-N)`), nie liczbę na sztywno — inaczej tryb ciemny '.
            'i skala tekstu przestają być spójne z resztą serwisu (zasada '.
            'z nagłówka `app.css`: „żadnej wartości koloru, rozmiaru tekstu ani '.
            'odstępu na sztywno”).',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/margin-bottom:\s*0\b/',
            $tresc,
            '`.app-main > h1` ma zerowy `margin-bottom` — to dokładnie usterka '.
            'ze zgłoszenia właściciela (nagłówek stykający się z treścią pod nim).',
        );
    }

    /**
     * @return list<array{0: string}>
     */
    public static function stronyZeZgloszenia(): array
    {
        return [
            'ustawienia/profil' => ['settings.profile'],
            'strona główna' => ['home'],
            'szukaj' => ['search'],
        ];
    }

    /**
     * `.app-main > h1` w arkuszu naprawia coś tylko wtedy, gdy `<h1>` na
     * prawdziwej stronie faktycznie JEST bezpośrednim dzieckiem `.app-main`
     * w wyrenderowanym HTML-u — a nie np. owinięty w dodatkowy `<div>`,
     * który by tę regułę uczynił martwą literą.
     */
    #[DataProvider('stronyZeZgloszenia')]
    public function test_naglowek_jest_bezposrednim_dzieckiem_app_main(string $trasa): void
    {
        $user = $this->user();

        $html = $this->actingAs($user)->get(route($trasa))->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        if ($trasa === 'home') {
            $headers = $xpath->query("//main[contains(concat(' ', normalize-space(@class), ' '), ' app-main ')]/header[contains(concat(' ', normalize-space(@class), ' '), ' start-naglowek ')]");
            $this->assertSame(1, $headers->length);
            $this->assertSame(1, $xpath->query('.//h1', $headers->item(0))->length);
            $css = (string) file_get_contents(resource_path('css/marka-rama.css'));
            $this->assertSame(1, preg_match('/\.start-naglowek\s*\{([^}]*)\}/', $css, $rule));
            $this->assertSame(1, preg_match('/margin-bottom:\s*calc\(\s*([\d.]+)px\s*\*\s*var\(--user-layout-scale,\s*1\)\s*\)\s*;/', $rule[1], $margin));
            $this->assertGreaterThanOrEqual(24, (float) $margin[1], 'Nagłówek Start musi zachować odstęp od kafla.');

            return;
        }

        $bezposrednieH1 = $xpath->query(
            "//main[contains(concat(' ', normalize-space(@class), ' '), ' app-main ')]/h1",
        );

        $this->assertGreaterThan(
            0,
            $bezposrednieH1->length,
            "Trasa „{$trasa}”: `<h1>` nie jest bezpośrednim dzieckiem ".
            '`<main class="app-main">` — reguła `.app-main > h1` w '.
            '`tokens.css` nic tu nie da, a strona wróci do stykania się '.
            'nagłówka z treścią pod nim.',
        );
    }
}

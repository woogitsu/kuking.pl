<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zgłoszenie właściciela, 9 września: nagłówek strony (`<h1>`) prawie stykał
 * się z tym, co pod nim — „Twój profil” tuż nad kartą profilu
 * (`/ustawienia/profil`), „Dobry wieczór, Mateusz…” tuż nad kartą na stronie
 * głównej, „Szukaj” tuż nad polem wyszukiwania (`/szukaj`). Wszędzie, nie na
 * jednej stronie.
 *
 * PRZYCZYNA
 * Reset Tailwinda zeruje `margin` na wszystkich nagłówkach, a warstwa `base`
 * w `tokens.css` (reguła `h1, h2, h3 { ... }`) nigdy nie oddawała `h1`
 * żadnego `margin-bottom` — więc `<h1>` stał dosłownie `margin-bottom: 0`,
 * a odstęp, jaki było widać, to wyłącznie interlinia.
 *
 * NAPRAWIONE W JEDNYM MIEJSCU, NIE NA KAŻDEJ PODSTRONIE
 * Repozytorium nie ma osobnego komponentu „nagłówek strony” — każdy z ok. 50
 * widoków w `resources/views/pages/**` pisze `<h1>` sam, wprost w slocie
 * `x-layout`. Ten `<h1>` renderuje się jednak ZAWSZE jako bezpośrednie
 * dziecko `<main class="app-main">` (patrz `components/layout.blade.php`:
 * `{{ $slot }}` stoi wprost w `<main>`), więc jedna reguła CSS ze
 * selektorem `.app-main > h1` naprawia wszystkie te widoki naraz — bez
 * dotykania jednego z nich.
 *
 * Test ma dwie części: CZY reguła CSS istnieje (arkusz, bez przeglądarki) i
 * CZY na trzech konkretnych stronach z raportu `<h1>` naprawdę jest
 * bezpośrednim dzieckiem `.app-main` — inaczej ta jedna reguła CSS niczego
 * by tam nie robiła, mimo że test arkusza świeciłby na zielono.
 *
 * Test przechodzi PO poprawce i celowo NIE PRZECHODZIŁ przed nią (sprawdzone
 * ręcznie: cofnięcie `resources/css/tokens.css` do wersji sprzed tej zmiany
 * obala `test_naglowek_strony_ma_odstep_w_arkuszu_stylow`; zmierzony w
 * Chromium odstęp między `<h1>` a kartą pod spodem spadał wtedy do 0 px na
 * wszystkich trzech stronach niżej).
 */
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

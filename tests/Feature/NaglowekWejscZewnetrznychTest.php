<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Nagłówek bloku „wejdź kontem u dostawcy" obiecuje tylko tych dostawców,
 * których przycisk naprawdę stoi pod nim (issue #1300).
 *
 * Wcześniej nagłówek był stały: „Masz konto Google albo Facebooka?…" na
 * `/login` i „…Załóż konto przez Google albo Facebooka" na `/register`,
 * także wtedy, gdy działał tylko jeden dostawca. Test czyta DOM: nagłówek
 * `h2` i przyciski z TEGO SAMEGO bloku.
 */
class NaglowekWejscZewnetrznychTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool, bool, string|null, list<string>}>
     */
    public static function stany(): array
    {
        return [
            'logowanie, tylko Google' => ['login', true, false,
                'Masz konto Google? Zaloguj się przez nie', ['Wejdź kontem Google']],
            'logowanie, tylko Facebook' => ['login', false, true,
                'Masz konto Facebooka? Zaloguj się przez nie', ['Wejdź kontem Facebooka']],
            // Kontrola dodatnia: oba działają — zdanie wymienia oba.
            'logowanie, oba' => ['login', true, true,
                'Masz konto Google albo Facebooka? Zaloguj się przez nie',
                ['Wejdź kontem Google', 'Wejdź kontem Facebooka']],
            'rejestracja, tylko Google' => ['register', true, false,
                'Nie chcesz wymyślać hasła? Załóż konto przez Google', ['Wejdź kontem Google']],
            'rejestracja, tylko Facebook' => ['register', false, true,
                'Nie chcesz wymyślać hasła? Załóż konto przez Facebooka', ['Wejdź kontem Facebooka']],
            'rejestracja, oba' => ['register', true, true,
                'Nie chcesz wymyślać hasła? Załóż konto przez Google albo Facebooka',
                ['Wejdź kontem Google', 'Wejdź kontem Facebooka']],
            // Żaden nie działa — nie ma ani nagłówka, ani przycisków.
            'logowanie, żaden' => ['login', false, false, null, []],
            'rejestracja, żaden' => ['register', false, false, null, []],
        ];
    }

    /**
     * @param  list<string>  $przyciski
     */
    #[DataProvider('stany')]
    public function test_naglowek_wymienia_tylko_dzialajacych_dostawcow(
        string $trasa,
        bool $google,
        bool $facebook,
        ?string $naglowek,
        array $przyciski,
    ): void {
        config([
            'kuking.account.registration_open' => true,
            'kuking.google.wlaczone' => $google,
            'kuking.google.identyfikator_klienta' => 'google-testowy',
            'kuking.google.sekret_klienta' => 'sekret-testowy',
            'kuking.facebook.wlaczone' => $facebook,
            'kuking.facebook.identyfikator_klienta' => 'facebook-testowy',
            'kuking.facebook.sekret_klienta' => 'sekret-testowy',
        ]);

        $html = $this->get(route($trasa))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $bloki = $xpath->query('//p[contains(concat(" ", normalize-space(@class), " "), " wejscia-dostawcow ")]/..');

        if ($naglowek === null) {
            $this->assertSame(0, $bloki->length, 'Bez działającego dostawcy nie ma całego bloku.');

            return;
        }

        $this->assertSame(1, $bloki->length, 'Kontrola testu: blok dostawców musi być na stronie.');
        $blok = $bloki->item(0);

        $h2 = $xpath->query('.//h2', $blok);
        $this->assertSame(1, $h2->length);
        $this->assertSame($naglowek, preg_replace('/\s+/u', ' ', trim($h2->item(0)->textContent)));

        $napisy = [];
        foreach ($xpath->query('.//p[contains(@class, "wejscia-dostawcow")]/a', $blok) as $a) {
            $napisy[] = preg_replace('/\s+/u', ' ', trim($a->textContent));
        }
        $this->assertSame($przyciski, $napisy);
    }
}

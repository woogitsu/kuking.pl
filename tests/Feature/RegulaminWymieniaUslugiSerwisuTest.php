<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regulamin §2 „Czym jest Kuking” ma wymieniać usługi, które serwis naprawdę
 * świadczy (art. 8 ust. 3 pkt 1 lit. a ustawy o świadczeniu usług drogą
 * elektroniczną: rodzaje i zakres usług).
 *
 * Do 30.09.2026 wymieniał siedem funkcji z czasu MVP, a nie było w nim
 * „Poradźcie”, zeszytów wspólnych, planera, listy zakupów, „Co mam w domu”,
 * „Mój stół”, urodzin, podsumowania tygodnia, logowania Google i Facebooka
 * ani wczytania paczki (audyt prywatności Z9, issue #2283).
 *
 * Lista niżej nie jest przepisana z regulaminu, tylko z TRAS: funkcja wchodzi
 * do wymagań wtedy, gdy jej adres istnieje w aplikacji. Nowa funkcja nie
 * wejdzie tu sama — ten test chroni przed ZNIKNIĘCIEM opisu, nie przed
 * brakiem nowego (to jest zadanie przeglądu przy każdej nowej usłudze).
 */
class RegulaminWymieniaUslugiSerwisuTest extends TestCase
{
    /** Prefiks adresu funkcji → fraza, która musi paść w regulaminie §2. */
    private const USLUGI = [
        'pytania' => '„Poradźcie”',
        'zaproszenie-do-zeszytu' => 'wspólnych zeszytach',
        'planer' => 'plan posiłków na tydzień',
        'lista-zakupow' => 'listę zakupów',
        'co-mam-w-domu' => '„Co mam w domu”',
        'moj-stol' => '„Mój stół”',
        'ugotujmy-razem' => '„Ugotujmy razem”',
        'urodziny' => 'dzień urodzin',
        'wejdz/google' => 'kontem Google',
        'wejdz/facebook' => 'Facebooka',
    ];

    /** Ile funkcji z listy musi istnieć, żeby test w ogóle coś mierzył. */
    private const MINIMUM_ISTNIEJACYCH = 8;

    public function test_paragraf_2_wymienia_kazda_istniejaca_usluge(): void
    {
        $paragraf = $this->paragraf2();
        $adresy = collect(Route::getRoutes()->getRoutes())->map(fn ($trasa): string => $trasa->uri())->all();

        $istniejace = 0;
        foreach (self::USLUGI as $prefiks => $fraza) {
            $jest = collect($adresy)->contains(fn (string $uri): bool => $uri === $prefiks || str_starts_with($uri, $prefiks.'/'));
            if (! $jest) {
                continue;
            }

            $istniejace++;
            $this->assertStringContainsString($fraza, $paragraf, "Serwis ma /{$prefiks}, a regulamin §2 nie wymienia tej usługi (brak frazy {$fraza}).");
        }

        $this->assertGreaterThanOrEqual(self::MINIMUM_ISTNIEJACYCH, $istniejace, 'Test nie znalazł tras funkcji z listy — sprawdź prefiksy adresów.');
    }

    public function test_paragraf_2_wymienia_podsumowanie_i_paczke_danych(): void
    {
        $paragraf = $this->paragraf2();

        $this->assertStringContainsString('podsumowanie e-mailem', $paragraf, 'Regulamin §2 nie wymienia tygodniowego podsumowania.');
        $this->assertStringContainsString('wczytać ją z powrotem', $paragraf, 'Regulamin §2 nie wymienia wczytania paczki z danymi.');
        $this->assertStringContainsString('„Smakowicie wygląda”', $paragraf, 'Regulamin §2 nie wymienia reakcji pod wpisem.');
    }

    private function paragraf2(): string
    {
        $tresc = (string) file_get_contents(resource_path('legal/regulamin.md'));
        $start = mb_strpos($tresc, '## 2. Czym jest Kuking');
        $this->assertNotFalse($start, 'Regulamin nie ma punktu 2 „Czym jest Kuking”.');

        $reszta = mb_substr($tresc, $start);
        $koniec = mb_strpos($reszta, '### Jak dobieramy wpisy');
        $this->assertNotFalse($koniec, 'Regulamin §2 nie ma podpunktu „Jak dobieramy wpisy”.');

        return mb_substr($reszta, 0, $koniec);
    }
}

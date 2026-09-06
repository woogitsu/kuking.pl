<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Odbiornik zgłoszeń naruszeń CSP (issue #12).
 *
 * PO CO TO JEST
 * Nagłówek `Content-Security-Policy-Report-Only` stał w projekcie od dawna
 * z komentarzem „przejście na tryb wymuszający jest osobnym zadaniem —
 * z listą naruszeń zebranych z produkcji". Tyle że **nie było czym ich
 * zebrać**: polityka nie podawała adresu, pod który przeglądarka miałaby
 * zgłosić naruszenie, więc tryb Report-Only nie robił dosłownie nic.
 *
 * Zadanie „włącz CSP na podstawie danych" nie mogło ruszyć, bo danych nie
 * było i nigdy by nie przybyło. Ten kontroler to naprawia.
 *
 * CZEGO TU CELOWO NIE MA
 * Zapisu do bazy. Zgłoszenia CSP przychodzą od KAŻDEJ przeglądarki i są
 * hałaśliwe: rozszerzenia, wtyczki antywirusowe i tłumacze stron wstrzykują
 * własne skrypty i generują naruszenia, które nie mają nic wspólnego z naszym
 * kodem. Tabela zapełniałaby się śmieciem, a przy okazji dawałaby obcemu
 * możliwość zapisu do bazy bez logowania. Log wystarcza do policzenia,
 * co naprawdę psuje się na produkcji.
 */
class CspReportController extends Controller
{
    /** Dłuższych zgłoszeń nie czytamy — patrz uzasadnienie w metodzie. */
    private const LIMIT_BAJTOW = 8192;

    public function __invoke(Request $request): Response
    {
        // 204 zawsze i bezwarunkowo. Przeglądarka i tak nie pokaże człowiekowi
        // odpowiedzi, a każdy inny kod tylko zachęca do sondowania endpointu.
        $pusta = response('', 204);

        $tresc = $request->getContent();

        // Zgłoszenie CSP to kilkaset bajtów. Wszystko powyżej ośmiu kilobajtów
        // to albo pomyłka, albo próba zapchania logu — przyjmujemy grzecznie
        // i wyrzucamy do kosza, zamiast parsować.
        if ($tresc === '' || strlen($tresc) > self::LIMIT_BAJTOW) {
            return $pusta;
        }

        $dane = json_decode($tresc, true);

        if (! is_array($dane)) {
            return $pusta;
        }

        // Przeglądarki wysyłają DWA różne formaty i trzeba obsłużyć oba:
        // starszy `report-uri` pakuje wszystko w klucz `csp-report`, nowszy
        // `report-to` przysyła tablicę zgłoszeń z ciałem w `body`.
        $naruszenia = isset($dane['csp-report'])
            ? [$dane['csp-report']]
            : array_map(fn ($z) => $z['body'] ?? $z, array_is_list($dane) ? $dane : [$dane]);

        foreach ($naruszenia as $naruszenie) {
            if (! is_array($naruszenie)) {
                continue;
            }

            Log::info('Naruszenie CSP', [
                // Świadomie WYBRANE pola, a nie całe zgłoszenie. Ciało
                // naruszenia potrafi nieść fragment kodu ze strony
                // (`script-sample`), a ten fragment bywa treścią użytkownika.
                // Do policzenia, co psuje politykę, wystarczą trzy rzeczy.
                'dyrektywa' => $this->skroc($naruszenie['effective-directive']
                    ?? $naruszenie['effectiveDirective']
                    ?? $naruszenie['violated-directive']
                    ?? null),
                'zablokowane' => $this->skroc($naruszenie['blocked-uri'] ?? $naruszenie['blockedURL'] ?? null),
                'strona' => $this->skroc($naruszenie['document-uri'] ?? $naruszenie['documentURL'] ?? null),
            ]);
        }

        return $pusta;
    }

    private function skroc(mixed $wartosc): ?string
    {
        if (! is_string($wartosc) || $wartosc === '') {
            return null;
        }

        return mb_substr($wartosc, 0, 300);
    }
}

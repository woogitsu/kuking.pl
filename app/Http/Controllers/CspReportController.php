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
                'zablokowane' => $this->bezpiecznyAdres($naruszenie['blocked-uri'] ?? $naruszenie['blockedURL'] ?? null),
                'strona' => $this->bezpiecznyAdres($naruszenie['document-uri'] ?? $naruszenie['documentURL'] ?? null),
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

    /**
     * Adres BEZ sekretów — do logu trafia trasa, nie tożsamość ani token.
     *
     * DLACZEGO SAMO PRZYCIĘCIE NIE WYSTARCZAŁO (audyt W3-14)
     * Zgłoszenie CSP przysyła PEŁNY adres strony, na której doszło do
     * naruszenia. A adresy tego serwisu niosą sekrety wprost w ścieżce
     * i w zapytaniu:
     *
     *     /nowe-haslo/{token}
     *     /potwierdz-email/{id}/{hash}?expires=…&signature=…
     *
     * Naruszenie na takiej stronie — wystarczy wtyczka przeglądarki
     * blokująca skrypt — wysyłało więc token resetu hasła prosto do naszego
     * logu, gdzie zostawał. To jest ta sama klasa błędu, co logowanie hasła:
     * dane trafiają tam, gdzie nikt ich nie szuka, i leżą.
     *
     * Zapytanie i fragment wycinamy w CAŁOŚCI. Ścieżkę zostawiamy, ale
     * segmenty, które mogą być sekretem albo identyfikatorem, zastępujemy
     * znacznikiem. Do policzenia, KTÓRA STRONA psuje politykę, tyle wystarcza —
     * a po to jest ten log.
     */
    private function bezpiecznyAdres(mixed $wartosc): ?string
    {
        if (! is_string($wartosc) || $wartosc === '') {
            return null;
        }

        // Wartości nie-adresowe: `inline`, `eval`, `data`, `blob`.
        // Nie mają ścieżki i nic z nich nie wycieka.
        if (! str_contains($wartosc, '/')) {
            return $this->skroc($wartosc);
        }

        $czesci = parse_url($wartosc);

        if ($czesci === false) {
            // Nie da się rozłożyć — lepiej zapisać samą informację, że coś
            // przyszło, niż nierozpoznany ciąg z nieznaną zawartością.
            return '[NIEROZPOZNANY ADRES]';
        }

        $poczatek = '';

        if (isset($czesci['scheme'], $czesci['host'])) {
            $poczatek = $czesci['scheme'].'://'.$czesci['host'];
        } elseif (isset($czesci['host'])) {
            $poczatek = $czesci['host'];
        }

        $sciezka = $czesci['path'] ?? '';

        // `expires`, `signature`, `token` — całe zapytanie idzie do kosza.
        // Nie ma w nim niczego, co byłoby nam potrzebne do diagnozy.
        return $this->skroc($poczatek.$this->sciezkaBezSekretow($sciezka));
    }

    /**
     * Segmenty wyglądające na token albo identyfikator zastąpione znacznikiem.
     *
     * Nie lista tras, tylko KSZTAŁT segmentu: lista musiałaby rosnąć razem
     * z `routes/web.php`, a o dopisaniu do niej nikt by nie pamiętał przy
     * dodawaniu nowej trasy z tokenem. Kształt jest odporny na to zaniedbanie.
     */
    private function sciezkaBezSekretow(string $sciezka): string
    {
        $segmenty = array_map(static function (string $segment): string {
            if ($segment === '') {
                return $segment;
            }

            // UUID — identyfikator konta albo treści.
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
                return '[ID]';
            }

            // Długi ciąg bez spacji i bez myślników w środku wyrazu to token
            // resetu, skrót weryfikacyjny albo podpis. Slug przepisu ma
            // myślniki i polskie słowa, więc się tu nie łapie.
            if (mb_strlen($segment) >= 20 && preg_match('/^[A-Za-z0-9_-]+$/', $segment) === 1
                && ! str_contains($segment, '-')) {
                return '[UKRYTE]';
            }

            return $segment;
        }, explode('/', $sciezka));

        return implode('/', $segmenty);
    }
}

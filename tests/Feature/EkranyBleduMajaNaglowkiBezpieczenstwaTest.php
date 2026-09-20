<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplySecurityHeaders;
use App\Http\Middleware\NormalizeForwardedFor;
use App\Support\KomunikatZaDuzaWysylka;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

/**
 * EKRANY BŁĘDU TEŻ DOSTAJĄ NAGŁÓWKI BEZPIECZEŃSTWA.
 *
 * CO BYŁO NIE TAK (zmierzone 20 września 2026, `php artisan serve`,
 * APP_DEBUG=false). `ApplySecurityHeaders` stało WYŁĄCZNIE w grupie `web`,
 * a połowa ekranów błędu do tej grupy nigdy nie dochodzi. Wynik: 404, 419,
 * 429, 413 i 503 wychodziły BEZ JEDNEGO nagłówka bezpieczeństwa — bez
 * `Content-Security-Policy`, bez `X-Frame-Options`, bez
 * `X-Content-Type-Options`. Strona główna miała komplet, ekran błędu nie
 * miał nic.
 *
 * Pięć różnych przyczyn, jeden skutek:
 *   404  wyjątek rzuca ROUTER, zanim ruszy grupa `web`;
 *   419  `ValidateCsrfToken` stoi w grupie PRZED `ApplySecurityHeaders`;
 *   429  `ThrottleRequests` jest na liście `Kernel::$middlewarePriority`,
 *        więc sortowanie wynosi go przed wszystko spoza tej listy;
 *   413  `ValidatePostSize` jest middlewarem GLOBALNYM;
 *   503  `PreventRequestsDuringMaintenance` też jest globalny.
 *
 * DLACZEGO TO BOLI NAJBARDZIEJ WŁAŚNIE TAM. 419 i 429 to JEDYNE DWA EKRANY
 * w serwisie, które wypisują z powrotem TEKST WPISANY PRZEZ CZŁOWIEKA
 * (`App\Exceptions\OdzyskanyFormularz`). Strona wstawiająca cudzą treść do
 * HTML-a była dokładnie tą, która szła bez `script-src` i bez
 * `frame-ancestors`.
 *
 * NAPRAWA NIE RUSZYŁA USTALENIA W7-01 / SEC-01: `NormalizeForwardedFor`
 * zostaje PIERWSZY w stosie globalnym, a `ApplySecurityHeaders` wchodzi
 * zaraz ZA nim — czyli po normalizacji `X-Forwarded-For`, a przed
 * `ValidatePostSize` i `PreventRequestsDuringMaintenance`. Pilnuje tego
 * pierwszy test w tym pliku i dlatego stoi jako pierwszy.
 */
class EkranyBleduMajaNaglowkiBezpieczenstwaTest extends TestCase
{
    use RefreshDatabase;

    /** Nagłówki, których brakowało na każdym z tych ekranów. */
    private const WYMAGANE = [
        'Content-Security-Policy',
        'X-Frame-Options',
        'X-Content-Type-Options',
        'Referrer-Policy',
        'Cross-Origin-Opener-Policy',
    ];

    public function test_normalize_forwarded_for_zostaje_pierwszy_a_naglowki_sa_zaraz_za_nim(): void
    {
        $globalne = array_values(app(Kernel::class)->getGlobalMiddleware());

        $this->assertSame(
            NormalizeForwardedFor::class,
            $globalne[0] ?? null,
            'W7-01/SEC-01: `NormalizeForwardedFor` musi zostać PIERWSZY w stosie globalnym.',
        );

        $this->assertSame(
            ApplySecurityHeaders::class,
            $globalne[1] ?? null,
            '`ApplySecurityHeaders` musi stać DRUGI — przed `ValidatePostSize` '
            .'i `PreventRequestsDuringMaintenance`, inaczej 413 i 503 znów pójdą bez nagłówków.',
        );
    }

    public function test_404_z_routera_ma_komplet_naglowkow(): void
    {
        $odpowiedz = $this->get('/nie-ma-takiej-strony-'.uniqid());

        $odpowiedz->assertStatus(404);

        foreach (self::WYMAGANE as $naglowek) {
            $this->assertNotNull(
                $odpowiedz->headers->get($naglowek),
                "Ekran 404 wyszedł bez nagłówka {$naglowek}.",
            );
        }
    }

    public function test_429_ma_komplet_naglowkow_i_dalej_oddaje_naglowki_limitu(): void
    {
        $odpowiedz = null;

        // `szukaj` ma limiter 60 na minutę i nie wymaga konta — najtańsza
        // droga do prawdziwego 429 przechodzącego przez `ThrottleRequests`.
        for ($i = 0; $i < 70; $i++) {
            $odpowiedz = $this->get('/szukaj?q=rosol');

            if ($odpowiedz->getStatusCode() === 429) {
                break;
            }
        }

        $this->assertSame(429, $odpowiedz?->getStatusCode(), 'Nie udało się wywołać limitu zapytań.');

        foreach (self::WYMAGANE as $naglowek) {
            $this->assertNotNull(
                $odpowiedz->headers->get($naglowek),
                "Ekran 429 wyszedł bez nagłówka {$naglowek}.",
            );
        }

        // Nagłówki wyjątku nie mogą przy okazji przepaść — z nich widok wie,
        // na ile ta przerwa jest.
        $this->assertNotNull($odpowiedz->headers->get('Retry-After'));
    }

    public function test_obie_warstwy_uzywaja_tego_samego_podpisu(): void
    {
        // TU JEST CAŁE RYZYKO TEJ NAPRAWY. `ApplySecurityHeaders` stoi
        // w stosie dwa razy; gdyby warstwa wewnętrzna generowała WŁASNY
        // nonce, w nagłówku byłby inny ciąg niż w HTML-u i strona zostałaby
        // bez skryptów — bez jednej linijki w logu.
        //
        // Trasa doraźna, bo w środowisku testowym `@vite` nie wystawia
        // podpisanych znaczników. `errors._prosty` bierze podpis wprost
        // z `Vite::cspNonce()`, czyli z tego samego miejsca co widoki
        // serwisu, a `middleware('web')` przepuszcza żądanie przez OBIE
        // warstwy naraz.
        Route::middleware('web')->get('/_podpis-testowy', fn () => response()->view('errors._prosty', [
            'tytul' => 'Podpis',
            'naglowek' => 'Podpis',
            'akapity' => ['Strona istnieje wyłącznie na czas tego testu.'],
        ]));

        $odpowiedz = $this->get('/_podpis-testowy')->assertOk();

        preg_match("/'nonce-([^']+)'/", (string) $odpowiedz->headers->get('Content-Security-Policy'), $zNaglowka);
        preg_match('/nonce="([^"]+)"/', (string) $odpowiedz->getContent(), $zTresci);

        $this->assertNotEmpty($zNaglowka[1] ?? null, 'Polityka nie niesie podpisu.');
        $this->assertNotEmpty($zTresci[1] ?? null, 'W HTML-u nie ma podpisanego bloku stylu.');
        $this->assertSame(
            $zNaglowka[1],
            $zTresci[1],
            'Dwa różne podpisy: druga warstwa wygenerowała własny nonce i strona zostałaby bez stylów.',
        );
    }

    public function test_zwykla_strona_dostaje_dokladnie_jeden_komplet_naglowkow(): void
    {
        // `ApplySecurityHeaders` stoi teraz w stosie DWA RAZY. Gdyby warstwa
        // zewnętrzna dopisywała swoje, w odpowiedzi byłyby dwie polityki —
        // a przeglądarka stosuje wtedy CZĘŚĆ WSPÓLNĄ obu, czyli po cichu
        // ostrzejszą niż którakolwiek z nich.
        $odpowiedz = $this->get(route('landing'))->assertOk();

        $this->assertCount(1, $odpowiedz->headers->all('Content-Security-Policy'));
        $this->assertCount(1, $odpowiedz->headers->all('X-Frame-Options'));
    }

    public function test_405_mowi_po_polsku_zamiast_angielskiej_strony_frameworka(): void
    {
        $odpowiedz = $this->put('/dodaj/zdjecie');

        $odpowiedz->assertStatus(405);
        $odpowiedz->assertSee('Nie udało się otworzyć tej strony', escape: false);
        $odpowiedz->assertDontSee('Oops! An Error Occurred', escape: false);

        foreach (self::WYMAGANE as $naglowek) {
            $this->assertNotNull($odpowiedz->headers->get($naglowek), "405 bez nagłówka {$naglowek}.");
        }
    }

    public function test_ekran_413_podpisuje_swoj_blok_stylu_i_nie_idzie_do_indeksu(): void
    {
        // `ValidatePostSize` rzuca wyjątek, zanim PHP wypełni `$_POST`, więc
        // w teście HTTP nie da się tego wywołać uczciwie — sprawdzamy więc
        // samą odpowiedź, którą buduje `KomunikatZaDuzaWysylka`. Jej styl
        // musi mieć podpis, bo `style-src` nie ma `unsafe-inline` i blok bez
        // podpisu zostanie odrzucony: strona wyjdzie szara.
        Vite::useCspNonce('PODPIS-TESTOWY');

        $tresc = (string) KomunikatZaDuzaWysylka::odpowiedz(Request::create('/dodaj/zdjecie', 'POST'))
            ->getContent();

        $this->assertStringContainsString('<style nonce="PODPIS-TESTOWY">', $tresc);
        $this->assertStringContainsString('noindex, nofollow', $tresc);

        // Rozmiar w `px` nie reaguje na powiększenie czcionki w przeglądarce
        // (WCAG 1.4.4) — na ekranie błędu, na który trafia ktoś już
        // zdenerwowany, to jest najgorsze miejsce na taki wyjątek.
        $this->assertStringNotContainsString('font: 18px', $tresc);
        $this->assertStringContainsString('1.125rem', $tresc);
    }
}

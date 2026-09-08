<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Zaufane proxy — nagłówki `X-Forwarded-*` za Cloudflare i brzegiem Railway.
 *
 * DLACZEGO TO MA WŁASNY TEST
 * Brak zaufanych proxy nie wywala niczego lokalnie: w `php artisan serve`
 * aplikacja jest odpytywana bezpośrednio, więc wszystko działa. Awaria pojawia
 * się dopiero na produkcji i wygląda na dwa niezwiązane problemy — pętlę
 * przekierowań i „limity, które dziwnie się zachowują". Dlatego pilnujemy tego
 * testem, a nie pamięcią.
 */
class ZaufaneProxyTest extends TestCase
{
    private const SCIEZKA = '/_test/proxy';

    protected function setUp(): void
    {
        parent::setUp();

        // Trasa istnieje tylko na czas testu. Pytamy o to, co widzi aplikacja
        // PO przejściu przez globalny stos middleware — a tam żyje TrustProxies.
        Route::middleware('web')->get(self::SCIEZKA, fn () => response()->json([
            'secure' => request()->secure(),
            'ip' => request()->ip(),
            'url' => url('/przepisy'),
        ]));
    }

    public function test_naglowek_x_forwarded_proto_jest_respektowany(): void
    {
        $dane = $this->get(self::SCIEZKA, ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->json();

        $this->assertTrue(
            $dane['secure'],
            'Aplikacja nie uznała żądania za HTTPS mimo X-Forwarded-Proto. '
            .'Na produkcji znaczy to pętlę przekierowań: Laravel generuje '
            .'adresy po http://, a Cloudflare zawraca je na https.',
        );
    }

    public function test_generowane_adresy_sa_po_https(): void
    {
        $dane = $this->get(self::SCIEZKA, ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->json();

        // To jest realny objaw, który zobaczy użytkownik: link w mailu
        // aktywacyjnym albo cel formularza po http://.
        $this->assertStringStartsWith('https://', $dane['url']);
    }

    /**
     * CO TEN TEST ZNACZY PO SEC-01 — bo przegląd R5 §2.3 zarzucał mu wprost,
     * że „utrwala bypass", i zarzut trzeba było rozstrzygnąć, a nie przemilczeć.
     *
     * Jeden wpis w nagłówku to na produkcji wpis dopisany przez Cloudflare —
     * czyli adres odwiedzającego. Aplikacja MUSI go widzieć, inaczej wszyscy
     * dostają wspólny adres brzegu i jeden wspólny limit. Ten test pilnuje
     * właśnie tego i po naprawie zostaje bez zmian.
     *
     * Czego ten test NIE mówi: że wpisowi wolno wierzyć niezależnie od tego,
     * ile ich przyszło. Za to odpowiada `PodrobionyNaglowekProxyTest` —
     * sprawdza, że liczy się n-ty wpis OD KOŃCA (`config/proxy.php`), więc
     * cokolwiek klient dopisze z lewej, przesuwa wyłącznie własne śmieci.
     */
    public function test_widzimy_adres_uzytkownika_a_nie_brzegu_platformy(): void
    {
        $dane = $this->get(self::SCIEZKA, [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.7',
        ])->assertOk()->json();

        // Bez tego `$request->ip()` zwraca adres brzegu Railway — ten sam dla
        // wszystkich. Limity z config/kuking.php liczyłyby się wtedy wspólnie
        // dla całego serwisu: jedna osoba wyczerpuje limit i blokuje resztę.
        $this->assertSame(
            '203.0.113.7',
            $dane['ip'],
            'Aplikacja widzi adres proxy zamiast użytkownika — limity per IP '
            .'stają się jednym wspólnym limitem dla wszystkich.',
        );
    }
}

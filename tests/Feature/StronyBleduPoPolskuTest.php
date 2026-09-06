<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #81 — angielskie strony błędów i tekst znikający po wygaśnięciu sesji.
 *
 * Ważniejsza połowa to ta druga: człowiek pisze przepis pół godziny, odchodzi
 * do garnka, wraca, klika „Opublikuj" i dostaje angielskie „Page Expired"
 * oraz pusty formularz. AGENTS.md §5 mówi wprost: poprawnie wpisane dane
 * nigdy nie znikają.
 */
final class StronyBleduPoPolskuTest extends TestCase
{
    use RefreshDatabase;

    private const TEKST_WPISU = 'Rosół na niedzielę, z kaczki od sąsiada. Gotował się cztery godziny i wyszedł złoty.';

    // -----------------------------------------------------------------
    //  Strony błędów
    // -----------------------------------------------------------------

    public function test_404_jest_po_polsku_i_w_layoucie_serwisu(): void
    {
        $odpowiedz = $this->get('/nie-ma-takiego-adresu-w-kuking');

        $odpowiedz->assertStatus(404);

        // Najpierw dowód, że cokolwiek się wyrenderowało.
        $this->assertGreaterThan(500, mb_strlen($odpowiedz->getContent() ?: ''));

        $odpowiedz->assertSee('Nie znaleźliśmy tej strony');
        // Belka Kuking i droga powrotu — czyli layout serwisu, nie goła strona.
        $odpowiedz->assertSee('Przejdź do treści');
        $odpowiedz->assertSee('Strona główna');

        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_403_jest_po_polsku(): void
    {
        Route::get('/_test/403', fn () => abort(403))->middleware('web');

        $odpowiedz = $this->get('/_test/403');

        $odpowiedz->assertStatus(403);
        $odpowiedz->assertSee('Ta strona nie jest dla Ciebie');
        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_429_mowi_co_zrobic_po_polsku(): void
    {
        Route::get('/_test/429', fn () => abort(429))->middleware('web');

        $odpowiedz = $this->get('/_test/429');

        $odpowiedz->assertStatus(429);
        $odpowiedz->assertSee('Za dużo prób');
        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_500_jest_po_polsku_i_nie_pyta_bazy(): void
    {
        Route::get('/_test/500', fn () => throw new RuntimeException('awaria testowa'))->middleware('web');

        // Bez tego Laravel pokazałby stronę dla programisty, a nie dla człowieka.
        config(['app.debug' => false]);

        $odpowiedz = $this->get('/_test/500');

        $odpowiedz->assertStatus(500);
        $odpowiedz->assertSee('Coś się u nas zepsuło');
        $odpowiedz->assertDontSee('awaria testowa');
        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_503_mowi_ze_wrocimy(): void
    {
        $widok = $this->view('errors.503', ['exception' => null]);

        $tresc = (string) $widok;

        $this->assertGreaterThan(200, mb_strlen($tresc));
        $widok->assertSee('Wrócimy dziś');
        $this->assertStringNotContainsString('Service Unavailable', $tresc);
        $this->assertStringNotContainsString('Be right back', $tresc);
    }

    // -----------------------------------------------------------------
    //  419 — wygasła sesja nie zjada wpisanego tekstu
    // -----------------------------------------------------------------

    public function test_419_zwraca_polska_strone_z_wpisanym_tekstem(): void
    {
        $user = $this->user();

        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => 'token-sesji'])
            ->post('/dodaj/zdjecie', [
                '_token' => 'token-z-wygaslej-strony',
                'body' => self::TEKST_WPISU,
                'visibility' => 'followers',
            ]));

        $odpowiedz->assertStatus(419);

        $this->assertGreaterThan(500, mb_strlen($odpowiedz->getContent() ?: ''));

        $odpowiedz->assertSee('Ta strona była otwarta zbyt długo');
        $odpowiedz->assertSee('Twój tekst jest na miejscu');
        $odpowiedz->assertSee('Wyślij jeszcze raz');

        // Najważniejsze: treść wpisu wróciła w formularzu.
        $odpowiedz->assertSee(self::TEKST_WPISU, escape: false);
        $this->assertStringContainsString('name="visibility"', $odpowiedz->getContent() ?: '');
        $this->assertStringContainsString('value="followers"', $odpowiedz->getContent() ?: '');
        $this->assertStringContainsString('action="'.url('/dodaj/zdjecie').'"', $odpowiedz->getContent() ?: '');

        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_419_pozwala_wyslac_wpis_ponownie_jednym_kliknieciem(): void
    {
        $user = $this->user();

        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => 'token-sesji'])
            ->post('/dodaj/zdjecie', [
                '_token' => 'token-z-wygaslej-strony',
                'body' => self::TEKST_WPISU,
                'visibility' => 'public',
            ]));

        $odpowiedz->assertStatus(419);

        $swiezyToken = $this->tokenZFormularza($odpowiedz);

        $ponowna = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => $swiezyToken])
            ->post('/dodaj/zdjecie', [
                '_token' => $swiezyToken,
                'body' => self::TEKST_WPISU,
                'visibility' => 'public',
            ]));

        $ponowna->assertRedirect();
        $ponowna->assertSessionHasNoErrors();

        $this->assertDatabaseHas('posts', [
            'author_id' => $user->getKey(),
            'body' => self::TEKST_WPISU,
        ]);
    }

    /**
     * Naprawa nie może polegać na wyłączeniu ochrony. Żądanie BEZ tokenu
     * ma dalej odbijać się od CSRF — inaczej „naprawiliśmy" 419 przez
     * otwarcie formularzy na cudze strony.
     */
    public function test_zadanie_bez_tokenu_dalej_nie_przechodzi(): void
    {
        $user = $this->user();

        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => 'token-sesji'])
            ->post('/dodaj/zdjecie', [
                'body' => 'Wpis bez tokenu w ogóle.',
                'visibility' => 'public',
            ]));

        $odpowiedz->assertStatus(419);

        $this->assertDatabaseMissing('posts', ['body' => 'Wpis bez tokenu w ogóle.']);
    }

    public function test_zadna_trasa_poza_zgloszeniami_csp_nie_jest_wyjeta_spod_csrf(): void
    {
        $middleware = app(PreventRequestForgery::class);

        $klasa = new ReflectionClass(PreventRequestForgery::class);

        /** @var array<int, string> $wlasne */
        $wlasne = $klasa->getProperty('except')->getValue($middleware);
        /** @var array<int, string> $globalne */
        $globalne = $klasa->getProperty('neverVerify')->getValue();

        $wyjatki = array_values(array_unique([...$wlasne, ...$globalne]));

        $this->assertSame(['_csp'], $wyjatki);
    }

    /**
     * Hasło nie może wrócić w odzyskiwanym formularzu — ani jawnie,
     * ani w ukrytym polu. To jest jedyna treść, którą wolno stracić.
     */
    public function test_419_nie_odklada_hasla(): void
    {
        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->withSession(['_token' => 'token-sesji'])
            ->post('/login', [
                '_token' => 'token-z-wygaslej-strony',
                'login' => 'basia',
                'password' => 'TajneHaslo123!',
            ]));

        $odpowiedz->assertStatus(419);

        $tresc = $odpowiedz->getContent() ?: '';

        $this->assertStringNotContainsString('TajneHaslo123!', $tresc);
        $this->assertStringNotContainsString('name="password"', $tresc);
        // …ale nazwa użytkownika już tak — to nie jest dane wrażliwe.
        $this->assertStringContainsString('basia', $tresc);
    }

    // -----------------------------------------------------------------
    //  Narzędzia
    // -----------------------------------------------------------------

    /**
     * ValidateCsrfToken pomija sprawdzanie tokenu, gdy aplikacja działa
     * w środowisku „testing" (Application::runningUnitTests()). Bez tej
     * podmiany test 419 sprawdzałby własną atrapę, a nie to, co dzieje
     * się u człowieka.
     *
     * @template T
     *
     * @param  callable(): T  $czynnosc
     * @return T
     */
    private function zPrawdziwymCsrf(callable $czynnosc): mixed
    {
        $poprzednie = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            return $czynnosc();
        } finally {
            $this->app['env'] = $poprzednie;
        }
    }

    private function tokenZFormularza(TestResponse $odpowiedz): string
    {
        $znaleziono = preg_match(
            '/name="_token"\s+value="([^"]+)"/',
            $odpowiedz->getContent() ?: '',
            $dopasowanie,
        );

        $this->assertSame(1, $znaleziono, 'Formularz odzyskiwania nie ma świeżego tokenu CSRF.');

        return $dopasowanie[1];
    }

    private function assertBezAngielskiego(TestResponse $odpowiedz): void
    {
        $tresc = $odpowiedz->getContent() ?: '';

        $this->assertGreaterThan(200, mb_strlen($tresc), 'Odpowiedź jest pusta — nie ma czego sprawdzać.');

        foreach ([
            'Page Expired',
            'Not Found',
            'Server Error',
            'Forbidden',
            'Too Many Requests',
            'Service Unavailable',
            'Whoops',
            'Go Home',
        ] as $fraza) {
            $this->assertStringNotContainsString(
                $fraza,
                $tresc,
                "Na stronie błędu został angielski tekst: „{$fraza}”.",
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caddy i Laravel wysyłają te same wartości nagłówków bezpieczeństwa (W7-11).
 *
 * PO CO TO JEST
 * Nagłówki bezpieczeństwa ustawiane są w tym projekcie w dwóch warstwach:
 * `App\Http\Middleware\ApplySecurityHeaders` (aplikacja) i blok `header`
 * w `docker/Caddyfile` (serwer przed PHP). Przez pewien czas mówiły dwie
 * różne rzeczy: Laravel wysyłał `X-Frame-Options: DENY`, Caddy `SAMEORIGIN`.
 *
 * DLACZEGO TO JEST BŁĄD, A NIE DROBIAZG
 * Nie chodzi o to, że SAMEORIGIN jest luźniejszy — Kuking i tak nigdzie nie
 * osadza sam siebie w ramce, a CSP ma `frame-ancestors 'none'`, które nowsze
 * przeglądarki czytają zamiast `X-Frame-Options`. Chodzi o to, że przy dwóch
 * różnych wartościach NIKT NIE WIE, która dojdzie do przeglądarki: zależy to
 * od kolejności middleware'ów w Caddy, a nie od żadnej decyzji w tym
 * repozytorium. `docs/infra/DEPLOYMENT_RUNBOOK.md` notował jako wartość
 * oczekiwaną na produkcji `SAMEORIGIN`, czyli w praktyce wygrywał plik
 * konfiguracyjny serwera, a nie kod aplikacji — i nikt tego nie zauważył,
 * bo `SecurityTest` odpytuje aplikację bezpośrednio, z pominięciem Caddy.
 *
 * DLACZEGO TEST POROWNAWCZY, A NIE ASERCJA NA LITERAŁ
 * Test na sam literał „DENY" w Caddyfile złapałby dzisiejszą regresję i nic
 * poza nią. Ten test pilnuje REGUŁY: każdy nagłówek ustawiony w OBU miejscach
 * ma mieć w obu tę samą wartość. Dodanie jutro trzeciego nagłówka do jednej
 * warstwy z inną wartością niż w drugiej też go obleje. To jest ten sam
 * rodzaj usterki, który wraca w tym projekcie najczęściej: reguła istnieje
 * poprawnie w jednej warstwie, a druga implementuje ją inaczej.
 *
 * CZEGO TEN TEST NIE SPRAWDZA
 * Nie uruchamia Caddy — PHPUnit nie ma jak. Nie rozstrzyga więc, KTÓRA
 * warstwa faktycznie wygrywa na produkcji. Sprawdza tylko, że to pytanie
 * przestało mieć znaczenie, bo obie mówią to samo.
 */
class CaddySpojnyZNaglowkamiLaravelaTest extends TestCase
{
    // Test odpytuje `/`, a strona powitalna czyta wpisy z bazy. Bez tego
    // przechodziłby wyłącznie na bazie zmigrowanej wcześniej przez inny
    // test — czyli zielono z niewłaściwego powodu, a na świeżej bazie 500.
    use RefreshDatabase;

    public function test_kazdy_naglowek_ustawiony_w_obu_warstwach_ma_te_sama_wartosc(): void
    {
        $zCaddy = $this->naglowkiZCaddyfile();

        $this->assertNotEmpty(
            $zCaddy,
            'Nie udało się odczytać ani jednego nagłówka z bloku `header` '
            .'w docker/Caddyfile. Albo plik zmienił kształt, albo ten test '
            .'przestał cokolwiek sprawdzać — jedno i drugie wymaga poprawki tutaj.',
        );

        $odpowiedz = $this->get('/');
        $odpowiedz->assertOk();

        $wspolne = 0;

        foreach ($zCaddy as $nazwa => $wartoscCaddy) {
            $wartoscLaravela = $odpowiedz->headers->get($nazwa);

            if ($wartoscLaravela === null) {
                // Nagłówek żyje tylko w Caddy (np. usuwanie `-Server`).
                // To nie jest rozjazd — nie ma z czym porównywać.
                continue;
            }

            $wspolne++;

            $this->assertSame(
                $wartoscLaravela,
                $wartoscCaddy,
                "Nagłówek {$nazwa} ma inną wartość w docker/Caddyfile "
                ."(\"{$wartoscCaddy}\") niż w App\\Http\\Middleware\\ApplySecurityHeaders "
                ."(\"{$wartoscLaravela}\"). Przy dwóch różnych wartościach to, co dojdzie "
                .'do przeglądarki, zależy od kolejności middleware\'ów w Caddy, a nie od '
                .'decyzji w tym repozytorium. Zrównaj obie warstwy — jedna wartość, '
                .'w dwóch miejscach, ta sama.',
            );
        }

        $this->assertGreaterThan(
            0,
            $wspolne,
            'Żaden nagłówek nie jest ustawiany jednocześnie w Caddyfile i w Laravelu. '
            .'To prawie na pewno znaczy, że parser w tym teście przestał pasować do '
            .'pliku, a nie że rozjazd zniknął.',
        );
    }

    public function test_x_frame_options_to_deny_w_obu_warstwach(): void
    {
        // Osobno i wprost, bo to jest ten konkretny rozjazd z ustalenia W7-11
        // i nie chcemy, żeby zniknął razem z ewentualną przebudową testu wyżej.
        $this->assertSame('DENY', $this->naglowkiZCaddyfile()['X-Frame-Options'] ?? null);

        $this->get('/')->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * Nagłówki z BEZWARUNKOWEGO bloku `header` w Caddyfile, jako nazwa => wartość.
     *
     * Tylko z bezwarunkowego, czyli `header {`, a nie `header @dynamic {`
     * ani `header @viteAssets {`. Bloki z matcherem dotyczą wybranych ścieżek
     * i mają prawo mówić co innego niż aplikacja: `Cache-Control` na
     * `@dynamic` jest celowo ostrzejszy („no-store") niż frameworkowy domyślny
     * („no-cache, private") i to NIE jest rozjazd, tylko druga linia obrony
     * przed scache'owaniem strony zalogowanego użytkownika. Rozjazdem jest
     * dopiero sytuacja, gdy obie warstwy odpowiadają na to samo pytanie
     * globalnie i odpowiadają inaczej.
     *
     * Komentarze (`#`) są wycinane PRZED szukaniem, bo obok dyrektywy
     * `X-Frame-Options` stoi akapit tłumaczący, dlaczego nie ma tam
     * `SAMEORIGIN` — asercja na literał trafiłaby we własne uzasadnienie
     * zamiast w konfigurację.
     *
     * @return array<string, string>
     */
    private function naglowkiZCaddyfile(): array
    {
        $tresc = (string) file_get_contents(base_path('docker/Caddyfile'));

        $bezKomentarzy = (string) preg_replace('/^\s*#.*$/m', '', $tresc);

        if (preg_match('/^\s*header\s*\{(.*?)^\s*\}/ms', $bezKomentarzy, $blok) !== 1) {
            return [];
        }

        preg_match_all(
            '/^\s*([A-Za-z][A-Za-z0-9-]*)\s+"([^"]*)"\s*$/m',
            $blok[1],
            $trafienia,
            PREG_SET_ORDER,
        );

        $naglowki = [];

        foreach ($trafienia as $trafienie) {
            $naglowki[$trafienie[1]] = $trafienie[2];
        }

        return $naglowki;
    }
}

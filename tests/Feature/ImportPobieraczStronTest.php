<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Url\PobieraczStron;
use App\Domain\Import\Url\StraznikAdresow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MapaNazw;
use Tests\TestCase;

/**
 * Pobieranie strony do importu (D-300): przekierowania sprawdzane jedno po
 * drugim, `robots.txt`, limity rozmiaru i czasu, tylko HTML. Wszystko na
 * `Http::fake` — żaden test nie wychodzi do sieci.
 */
final class ImportPobieraczStronTest extends TestCase
{
    private const HTML = '<html><head><title>Sernik</title></head><body><p>Przepis</p>'
        .'<img src="https://przepisy.example.pl/zdjecie.jpg"></body></html>';

    private MapaNazw $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = (new MapaNazw)
            ->ustaw('przepisy.example.pl', '93.184.216.34')
            ->ustaw('inny.example.pl', '93.184.216.35')
            ->ustaw('wewnetrzny.example.pl', '10.0.0.5');
    }

    private function pobieracz(): PobieraczStron
    {
        return new PobieraczStron(new StraznikAdresow($this->dns));
    }

    private function odrzucenie(callable $akcja): ImportOdrzucony
    {
        try {
            $akcja();
        } catch (ImportOdrzucony $e) {
            return $e;
        }

        $this->fail('Pobranie przeszło, a nie powinno.');
    }

    public function test_pobiera_strone_uczciwym_user_agentem_i_nie_pobiera_zdjec(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik*' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $strona = $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik?utm_source=fb&porcje=4&fbclid=abc');

        $this->assertSame('https://przepisy.example.pl/sernik?porcje=4', $strona->url);
        $this->assertStringContainsString('Przepis', $strona->html);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->header('User-Agent')[0] ?? '', 'KukingImport/1.0 (+'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'zdjecie.jpg'));
    }

    public function test_robots_zabrania_naszemu_tokenowi_i_strona_nie_jest_pobierana(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response("User-agent: *\nAllow: /\n\nUser-agent: KukingImport\nDisallow: /przepisy/\n", 200),
            '*' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $e = $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/przepisy/sernik'));

        $this->assertSame(ImportOdrzucony::ROBOTS_ZABRANIA, $e->kod);
        Http::assertSentCount(1);
    }

    public function test_robots_z_zakazem_dla_wszystkich_obowiazuje_nas(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response("User-agent: *\nDisallow: /\n", 200),
            '*' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ROBOTS_ZABRANIA,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
    }

    public function test_robots_niedostepny_z_bledem_serwera_znaczy_nie_wolno(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 503),
            '*' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ROBOTS_ZABRANIA,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
    }

    public function test_przekierowanie_robots_na_adres_prywatny_znaczy_nie_wolno(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 302, ['Location' => 'http://127.0.0.1/robots.txt']),
            '*' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ROBOTS_ZABRANIA,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '127.0.0.1'));
    }

    public function test_przekierowanie_na_adres_prywatny_jest_zatrzymane_przed_zadaniem(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '*' => Http::response('sekret', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ADRES_NIEPUBLICZNY,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '169.254'));
    }

    public function test_przekierowanie_na_nazwe_rozwiazywana_do_sieci_prywatnej_jest_zatrzymane(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik' => Http::response('', 301, ['Location' => 'https://wewnetrzny.example.pl/panel']),
            '*' => Http::response('sekret', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ADRES_NIEPUBLICZNY,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'wewnetrzny'));
    }

    public function test_przekierowanie_na_ipv6_petle_jest_zatrzymane(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik' => Http::response('', 307, ['Location' => 'http://[::1]/']),
            '*' => Http::response('sekret', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ADRES_NIEPUBLICZNY,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
    }

    public function test_przekierowanie_publiczne_sprawdza_robots_nowego_hosta_i_zapisuje_adres_koncowy(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/s/1' => Http::response('', 301, ['Location' => 'https://inny.example.pl/sernik-babci?utm_medium=x']),
            'https://inny.example.pl/robots.txt' => Http::response("User-agent: *\nDisallow: /prywatne/\n", 200),
            'https://inny.example.pl/sernik-babci*' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $strona = $this->pobieracz()->pobierz('https://przepisy.example.pl/s/1');

        $this->assertSame('https://inny.example.pl/sernik-babci', $strona->url);
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://inny.example.pl/robots.txt');
    }

    public function test_wzgledne_przekierowanie_jest_rozwiazywane_wzgledem_biezacego_adresu(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/stary/sernik' => Http::response('', 302, ['Location' => 'nowy-sernik']),
            'https://przepisy.example.pl/stary/nowy-sernik' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            'https://przepisy.example.pl/stary/nowy-sernik',
            $this->pobieracz()->pobierz('https://przepisy.example.pl/stary/sernik')->url,
        );
    }

    public function test_wiecej_niz_trzy_przekierowania_to_odmowa(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/1' => Http::response('', 302, ['Location' => '/2']),
            'https://przepisy.example.pl/2' => Http::response('', 302, ['Location' => '/3']),
            'https://przepisy.example.pl/3' => Http::response('', 302, ['Location' => '/4']),
            'https://przepisy.example.pl/4' => Http::response('', 302, ['Location' => '/5']),
            '*' => Http::response(self::HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ZA_DUZO_PRZEKIEROWAN,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/1'))->kod,
        );
        Http::assertNotSent(fn (Request $r): bool => $r->url() === 'https://przepisy.example.pl/5');
    }

    public function test_za_duza_strona_wedlug_naglowka_jest_odrzucona(): void
    {
        config(['kuking.import.url.max_bajtow' => 1000]);

        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            '*' => Http::response(str_repeat('a', 500), 200, ['Content-Type' => 'text/html', 'Content-Length' => '5000000']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ZA_DUZA_STRONA,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
    }

    public function test_za_duza_strona_bez_naglowka_jest_odrzucona_po_przeczytaniu_limitu(): void
    {
        config(['kuking.import.url.max_bajtow' => 1000]);

        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            '*' => Http::response(str_repeat('<p>sernik</p>', 200), 200, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::ZA_DUZA_STRONA,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
    }

    public function test_plik_zamiast_strony_jest_odrzucony(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            '*' => Http::response('%PDF-1.4', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->assertSame(
            ImportOdrzucony::NIE_STRONA,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik.pdf'))->kod,
        );
    }

    public function test_przekroczony_czas_to_komunikat_o_zbyt_dlugiej_odpowiedzi(): void
    {
        // Jedna funkcja zamiast dwóch wzorców: `Http::fake` woła KAŻDĄ
        // zarejestrowaną funkcję dla każdego żądania, więc rzucająca funkcja
        // pod `*` przerwałaby też pobranie robots.txt.
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/robots.txt')
            ? Http::response('', 404)
            : throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds'));

        $this->assertSame(
            ImportOdrzucony::ZA_DLUGO,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
    }

    public function test_blad_strony_to_strona_niedostepna(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            '*' => Http::response('Nie ma', 404, ['Content-Type' => 'text/html']),
        ]);

        $this->assertSame(
            ImportOdrzucony::STRONA_NIEDOSTEPNA,
            $this->odrzucenie(fn () => $this->pobieracz()->pobierz('https://przepisy.example.pl/sernik'))->kod,
        );
    }

    public function test_strona_w_iso_8859_2_jest_zamieniana_na_utf8(): void
    {
        $html = (string) mb_convert_encoding('<p>Żurek z jajkiem</p>', 'ISO-8859-2', 'UTF-8');

        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            '*' => Http::response($html, 200, ['Content-Type' => 'text/html; charset=iso-8859-2']),
        ]);

        $this->assertStringContainsString('Żurek', $this->pobieracz()->pobierz('https://przepisy.example.pl/zurek')->html);
    }
}

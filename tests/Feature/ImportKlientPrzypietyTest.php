<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Url\KlientPrzypiety;
use App\Domain\Import\Url\SprawdzonyAdres;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * `KlientPrzypiety` na PRAWDZIWYM cURL-u i lokalnym serwerze `php -S`
 * (127.0.0.1) — bez `Http::fake`, bo atrapa podmienia właśnie tę warstwę,
 * której ten test pilnuje (#1978).
 *
 * Nazwa `przypiety.test` nie istnieje w żadnym DNS-ie (`.test` jest
 * zarezerwowane, RFC 2606). Jeśli żądanie do niej dochodzi do lokalnego
 * serwera, to wyłącznie przez przypięcie `CURLOPT_RESOLVE` — czyli cURL
 * naprawdę użył adresu sprawdzonego przez strażnika, a nie zapytał DNS-u
 * drugi raz. Przed #1978 to się nie działo wcale: `stream: true` kierowało
 * Guzzle do obsługi strumieniowej PHP, która opcję `curl` odrzuca.
 *
 * Żaden test nie wychodzi poza 127.0.0.1.
 *
 * @bez-kontroli-dodatniej base_path() wskazuje tylko router podprocesowi `php -S`, a file_get_contents czyta dziennik żądań zapisany przez ten serwer w trakcie testu — żadna asercja nie dotyczy tekstu źródła aplikacji; kontrole ujemne (bez CURLOPT_RESOLVE, ze `stream: true`, bez limitu bajtów) oblewają ten test.
 */
final class ImportKlientPrzypietyTest extends TestCase
{
    private const NAZWA = 'przypiety.test';

    /** @var resource|null */
    private $serwer = null;

    private int $port = 0;

    private string $dziennik = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->port = $this->wolnyPort();
        $this->dziennik = (string) tempnam(sys_get_temp_dir(), 'kuking-serwer-');

        $serwer = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, base_path('tests/Fixtures/import/serwer-stron.php')],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $rury,
            null,
            array_merge(getenv(), ['KUKING_DZIENNIK_SERWERA' => $this->dziennik]),
        );

        if (! is_resource($serwer)) {
            throw new RuntimeException('Nie udało się uruchomić lokalnego serwera testowego.');
        }

        $this->serwer = $serwer;
        $this->czekajNaSerwer();

        // Wąski wyjątek od `preventStrayRequests()`: tylko lokalny serwer
        // pod nazwą przypiętą do 127.0.0.1.
        Http::allowStrayRequests(['http://'.self::NAZWA.':'.$this->port.'/*']);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serwer)) {
            proc_terminate($this->serwer);
            proc_close($this->serwer);
        }

        if ($this->dziennik !== '' && is_file($this->dziennik)) {
            unlink($this->dziennik);
        }

        parent::tearDown();
    }

    private function adres(string $sciezka): SprawdzonyAdres
    {
        return new SprawdzonyAdres('http://'.self::NAZWA.':'.$this->port.$sciezka, 'http', self::NAZWA, $this->port, '127.0.0.1');
    }

    public function test_curl_laczy_sie_z_przypietym_adresem_nazwy_ktorej_nie_ma_w_dns(): void
    {
        $odpowiedz = (new KlientPrzypiety)->pobierz($this->adres('/sernik'), 'text/html', 100_000, 2.0, 5.0);

        $this->assertSame(200, $odpowiedz->status());
        $this->assertStringContainsString('Sernik z lokalnego serwera', $odpowiedz->body());

        // Serwer dostał żądanie z tą samą nazwą, którą przypięliśmy — host
        // w żądaniu i klucz przypięcia to ten sam napis.
        $this->assertSame([self::NAZWA.':'.$this->port.'|/sernik'], $this->zadaniaNaSerwerze());
    }

    public function test_strona_ponad_limit_jest_przerywana_w_trakcie_pobierania(): void
    {
        $odpowiedz = (new KlientPrzypiety)->pobierz($this->adres('/duza'), 'text/html', 100_000, 2.0, 5.0);

        // Klient oddaje to, co zdążyło przyjść — więcej niż limit (żeby
        // wołający wiedział, że limit przekroczono), ale nie całe 3 MB.
        $this->assertSame(200, $odpowiedz->status());
        $this->assertGreaterThan(100_000, strlen($odpowiedz->body()));
        $this->assertLessThan(3_000_000, strlen($odpowiedz->body()));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function paryAdresow(): array
    {
        return [
            'ten sam IPv4' => ['93.184.216.34', '93.184.216.34', true],
            'inny IPv4' => ['127.0.0.1', '93.184.216.34', false],
            'IPv4 zgłoszony jako IPv6-mapped' => ['::ffff:93.184.216.34', '93.184.216.34', true],
            'mapped innego adresu' => ['::ffff:127.0.0.1', '93.184.216.34', false],
            'IPv6 w dwóch zapisach' => ['2606:4700:4700:0:0:0:0:1111', '2606:4700:4700::1111', true],
            'IPv6 w nawiasach' => ['[2606:4700:4700::1111]', '2606:4700:4700::1111', true],
            'inny IPv6' => ['::1', '2606:4700:4700::1111', false],
            'pusty adres połączenia' => ['', '93.184.216.34', false],
            'śmieci' => ['nie-adres', '93.184.216.34', false],
        ];
    }

    #[DataProvider('paryAdresow')]
    public function test_polaczenie_z_adresem_innym_niz_sprawdzony_jest_przerywane(string $polaczony, string $sprawdzony, bool $zgodny): void
    {
        $this->assertSame($zgodny, KlientPrzypiety::tenSamAdres($polaczony, $sprawdzony));
    }

    /**
     * @return list<string>
     */
    private function zadaniaNaSerwerze(): array
    {
        $tresc = (string) file_get_contents($this->dziennik);

        return array_values(array_filter(explode("\n", $tresc), fn (string $wiersz): bool => $wiersz !== ''));
    }

    private function wolnyPort(): int
    {
        $gniazdo = stream_socket_server('tcp://127.0.0.1:0', $kod, $blad);

        if ($gniazdo === false) {
            throw new RuntimeException('Brak wolnego portu na 127.0.0.1: '.$blad);
        }

        $nazwa = (string) stream_socket_get_name($gniazdo, false);
        fclose($gniazdo);

        return (int) substr($nazwa, (int) strrpos($nazwa, ':') + 1);
    }

    private function czekajNaSerwer(): void
    {
        $koniec = microtime(true) + 10;

        while (microtime(true) < $koniec) {
            $polaczenie = @fsockopen('127.0.0.1', $this->port, $kod, $blad, 0.2);

            if (is_resource($polaczenie)) {
                fclose($polaczenie);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('Lokalny serwer testowy nie wstał w 10 s.');
    }
}

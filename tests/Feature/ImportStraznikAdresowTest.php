<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Url\StraznikAdresow;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MapaNazw;
use Tests\TestCase;

/**
 * Ochrona SSRF przy imporcie z adresu strony (D-300): czego serwer Kuking
 * NIGDY nie pobierze, niezależnie od tego, co wklei człowiek.
 */
final class ImportStraznikAdresowTest extends TestCase
{
    private MapaNazw $dns;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dns = (new MapaNazw)
            ->ustaw('przepisy.example.pl', '93.184.216.34')
            ->ustaw('ipv6.example.pl', '2606:4700:4700::1111')
            ->ustaw('rebind.example.pl', '93.184.216.34', '10.0.0.7')
            ->ustaw('metadane.example.pl', '169.254.169.254')
            ->ustaw('petla.example.pl', '127.0.0.1')
            ->ustaw('v6petla.example.pl', '::1')
            ->ustaw('v6ula.example.pl', 'fd00::1')
            ->ustaw('v6mapped.example.pl', '::ffff:127.0.0.1');
    }

    private function straznik(): StraznikAdresow
    {
        return new StraznikAdresow($this->dns);
    }

    public function test_publiczny_adres_przechodzi_i_przypina_sprawdzony_ip(): void
    {
        $adres = $this->straznik()->sprawdz('https://przepisy.example.pl/sernik#komentarze');

        $this->assertSame('https://przepisy.example.pl/sernik', $adres->url);
        $this->assertSame('93.184.216.34', $adres->ip);
        $this->assertSame('przepisy.example.pl:443:93.184.216.34', $adres->przypiecie());
    }

    public function test_publiczny_ipv6_przechodzi_w_nawiasach(): void
    {
        $adres = $this->straznik()->sprawdz('http://ipv6.example.pl/');

        $this->assertSame('ipv6.example.pl:80:[2606:4700:4700::1111]', $adres->przypiecie());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adresyNiepubliczne(): array
    {
        return [
            'pętla zwrotna IPv4' => ['http://127.0.0.1/'],
            'pętla zwrotna IPv4 inna' => ['http://127.8.9.10/admin'],
            'sieć 10.x' => ['http://10.0.0.1/'],
            'sieć 172.16/12' => ['http://172.20.1.1/'],
            'sieć 192.168' => ['https://192.168.1.1/'],
            'metadane chmury 169.254' => ['http://169.254.169.254/latest/meta-data/'],
            'CGNAT 100.64' => ['http://100.100.1.1/'],
            'zero' => ['http://0.0.0.0/'],
            'IPv6 pętla' => ['http://[::1]/'],
            'IPv6 unikalny lokalny' => ['http://[fd12:3456::1]/'],
            'IPv6 link-local' => ['http://[fe80::1]/'],
            'IPv4 w IPv6 (mapped)' => ['http://[::ffff:127.0.0.1]/'],
            'IPv4 w IPv6 (mapped, szesnastkowo)' => ['http://[::ffff:a00:1]/'],
            'NAT64 z adresem prywatnym' => ['http://[64:ff9b::a00:1]/'],
            'localhost' => ['http://localhost/'],
            'localhost z kropką' => ['http://localhost./'],
            'poddomena localhost' => ['http://cokolwiek.localhost/'],
            'sieć prywatna Railway' => ['http://postgres.railway.internal/'],
            'strefa .local' => ['http://drukarka.local/'],
            'IP jako liczba' => ['http://2130706433/'],
            'IP ósemkowo' => ['http://0177.0.0.1/'],
            'IP szesnastkowo' => ['http://0x7f.0.0.1/'],
            'IP skrócone' => ['http://127.1/'],
            'port inny niż 80 i 443' => ['https://przepisy.example.pl:8443/'],
            'port bazy danych' => ['http://przepisy.example.pl:5432/'],
            'DNS oddaje metadane' => ['http://metadane.example.pl/'],
            'DNS oddaje pętlę' => ['https://petla.example.pl/'],
            'DNS oddaje IPv6 pętlę' => ['https://v6petla.example.pl/'],
            'DNS oddaje IPv6 ULA' => ['https://v6ula.example.pl/'],
            'DNS oddaje IPv4 w IPv6' => ['https://v6mapped.example.pl/'],
            'jeden z adresów prywatny' => ['https://rebind.example.pl/'],
        ];
    }

    #[DataProvider('adresyNiepubliczne')]
    public function test_adres_niepubliczny_jest_odrzucony(string $url): void
    {
        try {
            $this->straznik()->sprawdz($url);
            $this->fail("Adres {$url} przeszedł kontrolę.");
        } catch (ImportOdrzucony $e) {
            $this->assertSame(ImportOdrzucony::ADRES_NIEPUBLICZNY, $e->kod, $url);
            $this->assertStringNotContainsString('127.0.0.1', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adresyNieprawidlowe(): array
    {
        return [
            'pusty' => [''],
            'bez schematu' => ['przepisy.example.pl/sernik'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://przepisy.example.pl/'],
            'gopher' => ['gopher://przepisy.example.pl/'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<h1>x</h1>'],
            'login w adresie' => ['https://uzytkownik:haslo@przepisy.example.pl/'],
            'nazwa jednoczłonowa' => ['http://postgres/'],
            'spacja w środku' => ['https://przepisy.example.pl/ser nik'],
            'znak nowej linii' => ["https://przepisy.example.pl/\r\nHost: x"],
            'za długi' => ['https://przepisy.example.pl/'.str_repeat('a', 2100)],
        ];
    }

    #[DataProvider('adresyNieprawidlowe')]
    public function test_adres_nieprawidlowy_jest_odrzucony_bez_pytania_dns(string $url): void
    {
        try {
            $this->straznik()->sprawdz($url);
            $this->fail("Adres {$url} przeszedł kontrolę.");
        } catch (ImportOdrzucony $e) {
            $this->assertSame(ImportOdrzucony::ADRES_NIEPRAWIDLOWY, $e->kod, $url);
        }

        $this->assertSame([], $this->dns->pytania);
    }

    public function test_nazwa_ktorej_nie_ma_w_dns_to_strona_niedostepna(): void
    {
        $this->expectExceptionObject(new ImportOdrzucony(ImportOdrzucony::STRONA_NIEDOSTEPNA));

        $this->straznik()->sprawdz('https://nie-ma-takiej.example.pl/');
    }

    public function test_komunikat_mowi_co_zrobic(): void
    {
        foreach (ImportOdrzucony::KOMUNIKATY as $kod => $komunikat) {
            $this->assertMatchesRegularExpression(
                '/(Sprawdź|Skopiuj|Otwórz|Spróbuj|Wybierz|Zapisz|Zrób|Jutro|pobierz)/u',
                $komunikat,
                "Komunikat {$kod} nie mówi, co zrobić.",
            );
        }
    }
}

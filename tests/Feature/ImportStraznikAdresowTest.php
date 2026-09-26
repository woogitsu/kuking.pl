<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Url\SprawdzonyAdres;
use App\Domain\Import\Url\StraznikAdresow;
use LogicException;
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
            ->ustaw('v6mapped.example.pl', '::ffff:127.0.0.1')
            ->ustaw('xn--przepisy-bbci-rsb.pl', '93.184.216.35');
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
     * #1978: każdy zapis hosta, który cURL albo resolver przeczyta inaczej niż
     * człowiek, ma trafić do żądania w JEDNEJ postaci — tej samej, dla której
     * strażnik zapytał DNS i zbudował przypięcie.
     *
     * @return array<string, array{string, string, string, ?string, ?string}>
     */
    public static function zapisyHosta(): array
    {
        return [
            // wklejony adres, adres dla cURL-a, host, przypięcie, pytanie do DNS-u
            'końcowa kropka' => ['https://przepisy.example.pl./sernik', 'https://przepisy.example.pl/sernik', 'przepisy.example.pl', 'przepisy.example.pl:443:93.184.216.34', 'przepisy.example.pl'],
            'wielkie litery' => ['https://PRZEPISY.Example.PL/Sernik?Kawalek=1', 'https://przepisy.example.pl/Sernik?Kawalek=1', 'przepisy.example.pl', 'przepisy.example.pl:443:93.184.216.34', 'przepisy.example.pl'],
            'wielkie litery i kropka' => ['http://PRZEPISY.EXAMPLE.PL./', 'http://przepisy.example.pl/', 'przepisy.example.pl', 'przepisy.example.pl:80:93.184.216.34', 'przepisy.example.pl'],
            'polskie znaki (IDN)' => ['https://przepisy-bąbci.pl/sernik', 'https://xn--przepisy-bbci-rsb.pl/sernik', 'xn--przepisy-bbci-rsb.pl', 'xn--przepisy-bbci-rsb.pl:443:93.184.216.35', 'xn--przepisy-bbci-rsb.pl'],
            'IDN wielkimi literami z kropką' => ['https://PRZEPISY-BĄBCI.PL./', 'https://xn--przepisy-bbci-rsb.pl/', 'xn--przepisy-bbci-rsb.pl', 'xn--przepisy-bbci-rsb.pl:443:93.184.216.35', 'xn--przepisy-bbci-rsb.pl'],
            'kropki pełnoszerokie' => ['https://przepisy。example。pl。/', 'https://przepisy.example.pl/', 'przepisy.example.pl', 'przepisy.example.pl:443:93.184.216.34', 'przepisy.example.pl'],
            'port domyślny wpisany' => ['https://przepisy.example.pl:443/sernik', 'https://przepisy.example.pl/sernik', 'przepisy.example.pl', 'przepisy.example.pl:443:93.184.216.34', 'przepisy.example.pl'],
            'port nie domyślny i kropka' => ['https://przepisy.example.pl.:80/', 'https://przepisy.example.pl:80/', 'przepisy.example.pl', 'przepisy.example.pl:80:93.184.216.34', 'przepisy.example.pl'],
            'bez ścieżki' => ['https://przepisy.example.pl.?x=1#y', 'https://przepisy.example.pl/?x=1', 'przepisy.example.pl', 'przepisy.example.pl:443:93.184.216.34', 'przepisy.example.pl'],
            'IP z końcową kropką' => ['http://93.184.216.34./', 'http://93.184.216.34/', '93.184.216.34', null, null],
            'IP jako liczba' => ['http://1572395042/przepis', 'http://93.184.216.34/przepis', '93.184.216.34', null, null],
            'IP ósemkowo' => ['http://0135.0270.0330.042/', 'http://93.184.216.34/', '93.184.216.34', null, null],
            'IP szesnastkowo' => ['http://0x5DB8D822/', 'http://93.184.216.34/', '93.184.216.34', null, null],
            'IP skrócone' => ['http://93.12113954/', 'http://93.184.216.34/', '93.184.216.34', null, null],
            'IPv6 pełny zapis' => ['http://[2606:4700:4700:0:0:0:0:1111]/', 'http://[2606:4700:4700::1111]/', '2606:4700:4700::1111', null, null],
            'IPv6 wielkie litery' => ['https://[2606:4700:4700::ABCD]/', 'https://[2606:4700:4700::abcd]/', '2606:4700:4700::abcd', null, null],
        ];
    }

    #[DataProvider('zapisyHosta')]
    public function test_host_trafia_do_curla_w_tej_samej_postaci_co_do_przypiecia(string $wklejony, string $url, string $host, ?string $przypiecie, ?string $pytanie): void
    {
        $adres = $this->straznik()->sprawdz($wklejony);

        $this->assertSame($url, $adres->url);
        $this->assertSame($host, $adres->host);
        $this->assertSame($przypiecie, $adres->przypiecie());
        $this->assertSame($pytanie === null ? [] : [$pytanie], $this->dns->pytania);

        // Sedno #1978: host, o który zapyta cURL (z adresu), jest DOKŁADNIE
        // kluczem przypięcia. `example.com.` w adresie i `example.com`
        // w przypięciu to dla cURL-a dwie różne nazwy.
        $hostWAdresie = trim((string) parse_url($adres->url, PHP_URL_HOST), '[]');
        $this->assertSame($adres->host, $hostWAdresie);

        if ($przypiecie !== null) {
            $this->assertStringStartsWith($hostWAdresie.':'.$adres->port.':', $przypiecie);
        }
    }

    public function test_adres_z_hostem_innym_niz_przypiety_nie_da_sie_zbudowac(): void
    {
        $this->expectException(LogicException::class);

        new SprawdzonyAdres('https://example.com./', 'https', 'example.com', 443, '93.184.216.34');
    }

    public function test_adres_z_hostem_zgodnym_z_przypieciem_da_sie_zbudowac(): void
    {
        $adres = new SprawdzonyAdres('https://example.com/', 'https', 'example.com', 443, '93.184.216.34');

        $this->assertSame('example.com:443:93.184.216.34', $adres->przypiecie());
        $this->assertSame('https://example.com', $adres->korzen());
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
            // #1978: te same cele w innym zapisie hosta
            'localhost wielkimi z kropką' => ['http://LOCALHOST./'],
            'Railway wielkimi z kropką' => ['http://Postgres.Railway.Internal./'],
            'pętla IPv4 z kropką' => ['http://127.0.0.1./'],
            'pętla jako liczba szesnastkowa' => ['http://0x7F000001/'],
            'pętla jako jedna liczba ósemkowa' => ['http://017700000001/'],
            'metadane skrócone' => ['http://169.254.43518/'],
            'IPv6 pętla pełnym zapisem' => ['http://[0:0:0:0:0:0:0:1]/'],
            'IPv4 w IPv6 wielkimi' => ['http://[::FFFF:127.0.0.1]/'],
            'DNS oddaje pętlę, nazwa wielkimi z kropką' => ['https://PETLA.Example.PL./'],
            'DNS oddaje metadane, nazwa z kropką' => ['http://metadane.example.pl./'],
            'człon liczbowy spoza IPv4' => ['http://1.2.3.999/'],
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
            // #1978
            'dwie końcowe kropki' => ['https://przepisy.example.pl../'],
            'sama kropka' => ['http://./'],
            'kodowanie procentowe w nazwie' => ['https://przepisy.example.pl%2e/'],
            'kodowana kropka w środku' => ['https://przepisy%2eexample.pl/'],
            'strefa IPv6' => ['http://[fe80::1%25eth0]/'],
            'nawias bez pary' => ['http://przepisy.example.pl]/'],
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

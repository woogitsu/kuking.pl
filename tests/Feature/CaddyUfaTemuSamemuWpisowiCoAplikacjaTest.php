<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Log Caddy i aplikacja wskazują tego samego klienta (issue #1306).
 *
 * CO BYŁO ZEPSUTE
 * `docker/Caddyfile` miał `trusted_proxies static 0.0.0.0/0 ::/0` bez
 * `trusted_proxies_strict`. Każdy peer był więc zaufany, a Caddy czyta
 * `X-Forwarded-For` domyślnie OD LEWEJ — z części, którą wypełnia klient.
 * Pole `client_ip` w logu JSON Caddy opisywało adres wpisany przez klienta,
 * a `NormalizeForwardedFor` w Laravelu brał wpis dopisany przez Cloudflare
 * (od prawej). Dwie warstwy dawały dwie różne odpowiedzi na pytanie „kto to
 * był", a ślad w logu prowadził tam, gdzie chciał napastnik.
 *
 * JAK TO SPRAWDZAMY
 * PHPUnit nie uruchomi Caddy. Test czyta więc z Caddyfile listę zaufanych
 * zakresów i tryb odczytu, odtwarza według nich regułę Caddy (dokumentacja:
 * https://caddyserver.com/docs/caddyfile/options#trusted-proxies) i porównuje
 * wynik z adresem, który aplikacja widzi PO przejściu przez cały stos
 * globalny. Reguła Caddy:
 *
 *   - peer spoza zaufanych → `client_ip` to adres peera, nagłówek się nie liczy;
 *   - bez `trusted_proxies_strict` → pierwszy (lewy) poprawny adres nagłówka;
 *   - z `trusted_proxies_strict` → od prawej pierwszy adres spoza zaufanych,
 *     a gdy wszystkie są zaufane — pierwszy od lewej.
 *
 * CZEGO TEN TEST NIE SPRAWDZA
 * Nie mierzy produkcyjnej topologii (ile wpisów dopisują Cloudflare i Railway
 * i z jakiego adresu brzeg łączy się z kontenerem). Zakłada to, co zakłada
 * `config/proxy.php`: jeden zaufany przeskok i peer z sieci prywatnej.
 * Nie rozstrzyga też pochodzenia żądania — to robi token krawędzi
 * (`TokenKrawedziTest`).
 */
class CaddyUfaTemuSamemuWpisowiCoAplikacjaTest extends TestCase
{
    private const SCIEZKA = '_test/adres-klienta-caddy';

    /** Brzeg Railway łączy się z kontenerem z sieci prywatnej. */
    private const PEER_BRZEGU = '10.250.3.7';

    private const KLIENT = '203.0.113.7';

    private const PODROBIONY = '198.51.100.66';

    /**
     * Rozwinięcie skrótu `private_ranges` według dokumentacji Caddy.
     */
    private const PRIVATE_RANGES = [
        '192.168.0.0/16', '172.16.0.0/12', '10.0.0.0/8', '127.0.0.1/8', 'fd00::/8', '::1',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['proxy.zaufane_przeskoki' => 1]);

        Route::get('/'.self::SCIEZKA, fn () => response()->json(['ip' => request()->ip()]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lancuchy(): array
    {
        return [
            'czysty ruch przez Cloudflare' => [self::KLIENT],
            'podrobiony prefiks' => [self::PODROBIONY.', '.self::KLIENT],
            'kilka podrobionych wpisów' => ['192.0.2.1, '.self::PODROBIONY.', '.self::KLIENT],
            'podrobiony adres prywatny z lewej' => ['10.0.0.1, '.self::KLIENT],
        ];
    }

    #[DataProvider('lancuchy')]
    public function test_client_ip_w_logu_caddy_to_ten_sam_adres_co_w_aplikacji(string $xff): void
    {
        $konfiguracja = $this->konfiguracjaZCaddyfile();

        $zCaddy = $this->clientIpCaddy($konfiguracja, self::PEER_BRZEGU, $xff);
        $zAplikacji = $this->adresWAplikacji(self::PEER_BRZEGU, $xff);

        $this->assertSame(self::KLIENT, $zAplikacji, 'Aplikacja przestała brać wpis dopisany przez Cloudflare.');
        $this->assertSame(
            $zAplikacji,
            $zCaddy,
            "Dla X-Forwarded-For „{$xff}” Caddy zapisze w logu {$zCaddy}, a aplikacja widzi {$zAplikacji}. "
            .'Sprawdź `trusted_proxies` i `trusted_proxies_strict` w bloku `servers` w docker/Caddyfile.',
        );
    }

    public function test_peer_spoza_sieci_prywatnej_nie_przenosi_do_logu_adresu_od_klienta(): void
    {
        $konfiguracja = $this->konfiguracjaZCaddyfile();
        $peerPubliczny = '192.0.2.200';

        $this->assertSame(
            $peerPubliczny,
            $this->clientIpCaddy($konfiguracja, $peerPubliczny, self::PODROBIONY),
            'Caddy ufa nagłówkowi od peera z adresu publicznego — log opisze adres wpisany przez klienta.',
        );
    }

    /**
     * @return array{zakresy: list<string>, scisle: bool}
     */
    private function konfiguracjaZCaddyfile(): array
    {
        $tresc = (string) file_get_contents(base_path('docker/Caddyfile'));

        $bezKomentarzy = implode("\n", array_map(
            static fn (string $linia): string => (string) preg_replace('/(^|\s)#.*$/', '', $linia),
            explode("\n", $tresc),
        ));

        $this->assertSame(
            1,
            preg_match('/^\s*servers\s*\{([^}]*)\}/m', $bezKomentarzy, $blok),
            'Nie znaleziono bloku `servers { … }` w docker/Caddyfile — test przestał cokolwiek sprawdzać.',
        );

        $this->assertSame(
            1,
            preg_match('/^\s*trusted_proxies\s+static\s+([^\n]+)$/m', $blok[1], $linia),
            'Brak `trusted_proxies static …` w bloku `servers` docker/Caddyfile.',
        );

        $zakresy = [];

        foreach (preg_split('/\s+/', trim($linia[1])) ?: [] as $wpis) {
            array_push($zakresy, ...($wpis === 'private_ranges' ? self::PRIVATE_RANGES : [$wpis]));
        }

        return [
            'zakresy' => $zakresy,
            'scisle' => preg_match('/^\s*trusted_proxies_strict\s*$/m', $blok[1]) === 1,
        ];
    }

    /**
     * @param  array{zakresy: list<string>, scisle: bool}  $konfiguracja
     */
    private function clientIpCaddy(array $konfiguracja, string $peer, string $xff): string
    {
        if (! $this->zaufany($peer, $konfiguracja['zakresy'])) {
            return $peer;
        }

        $adresy = array_values(array_filter(
            array_map('trim', explode(',', $xff)),
            static fn (string $adres): bool => filter_var($adres, FILTER_VALIDATE_IP) !== false,
        ));

        if ($adresy === []) {
            return $peer;
        }

        if (! $konfiguracja['scisle']) {
            return $adresy[0];
        }

        foreach (array_reverse($adresy) as $adres) {
            if (! $this->zaufany($adres, $konfiguracja['zakresy'])) {
                return $adres;
            }
        }

        return $adresy[0];
    }

    private function adresWAplikacji(string $peer, string $xff): string
    {
        $odpowiedz = $this->withServerVariables(['REMOTE_ADDR' => $peer])
            ->withHeaders(['X-Forwarded-For' => $xff])
            ->getJson('/'.self::SCIEZKA);

        $odpowiedz->assertOk();

        return (string) $odpowiedz->json('ip');
    }

    /**
     * @param  list<string>  $zakresy
     */
    private function zaufany(string $adres, array $zakresy): bool
    {
        foreach ($zakresy as $zakres) {
            if ($this->wZakresie($adres, $zakres)) {
                return true;
            }
        }

        return false;
    }

    private function wZakresie(string $adres, string $zakres): bool
    {
        [$siec, $maska] = str_contains($zakres, '/') ? explode('/', $zakres, 2) : [$zakres, null];

        $a = @inet_pton($adres);
        $s = @inet_pton($siec);

        $this->assertNotFalse($s, "Zakres „{$zakres}” w docker/Caddyfile nie jest adresem ani siecią CIDR.");

        if ($a === false || strlen($a) !== strlen($s)) {
            return false;
        }

        $bity = $maska === null ? strlen($s) * 8 : (int) $maska;
        $pelne = intdiv($bity, 8);

        if (substr($a, 0, $pelne) !== substr($s, 0, $pelne)) {
            return false;
        }

        $reszta = $bity % 8;

        if ($reszta === 0) {
            return true;
        }

        $m = (0xFF << (8 - $reszta)) & 0xFF;

        return (ord($a[$pelne]) & $m) === (ord($s[$pelne]) & $m);
    }
}

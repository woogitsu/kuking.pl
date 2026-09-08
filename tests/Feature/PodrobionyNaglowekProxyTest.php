<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Podrobiony `X-Forwarded-For` nie przesuwa adresu widzianego przez aplikację
 * ani nie zeruje limitu (ustalenie W7-01, test SEC-01 z audytu).
 *
 * CO BYŁO ZEPSUTE
 * `trustProxies(at: '*')` to w Laravelu `setTrustedProxies([REMOTE_ADDR])`,
 * a Symfony przy takiej liście oddaje z `X-Forwarded-For` jego OSTATNI wpis.
 * Dla żądania, przed którym nic nie stało, ostatnim wpisem jest to, co wpisał
 * sam klient — więc `$request->ip()` był wartością od klienta, a limity
 * liczone po adresie (`throttle:` i koszyki A/C z `App\Support\KluczeLimitow`)
 * zerowały się przy każdej próbie.
 *
 * CZEGO TEN TEST DOWODZI, A CZEGO NIE — WPROST, ŻEBY NIKT SIĘ NIE POMYLIŁ
 * Dowodzi, że wpis dopisany przez naszą infrastrukturę wygrywa z DOWOLNĄ
 * liczbą wpisów dopisanych przez klienta z lewej strony, bo aplikacja liczy
 * od PRAWEJ (`config/proxy.php`, `App\Http\Middleware\NormalizeForwardedFor`).
 * W teście rolę „wpisu od infrastruktury" gra ostatni element łańcucha, tak
 * jak na produkcji gra ją adres dopisany przez Cloudflare.
 *
 * NIE dowodzi, że da się rozpoznać żądanie, które w ogóle ominęło Cloudflare
 * i weszło wprost na `*.up.railway.app`. Takiego żądania nie odróżni żaden
 * kod w tym repozytorium — do tego służy token krawędziowy z Bloku B
 * (`docs/decyzje/PRZEGLAD_SPEC_9_DECYZJI.md`), którego nie da się wdrożyć bez
 * panelu Cloudflare.
 */
class PodrobionyNaglowekProxyTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = '/_test/adres-klienta';

    private const HASLO = 'zielonapietruszkarano';

    /** Adres, który „dopisała infrastruktura" — czyli prawdziwy klient. */
    private const PRAWDZIWY = '203.0.113.7';

    protected function setUp(): void
    {
        parent::setUp();

        // Trasa istnieje tylko na czas testu i pyta o to, co widzi aplikacja
        // PO przejściu przez cały stos globalny — a tam żyje najpierw
        // NormalizeForwardedFor, a zaraz po nim TrustProxies.
        Route::middleware('web')->get(self::SCIEZKA, fn () => response()->json([
            'ip' => request()->ip(),
        ]));
    }

    private function widzianyAdres(string $naglowek): string
    {
        return (string) $this->get(self::SCIEZKA, ['X-Forwarded-For' => $naglowek])
            ->assertOk()
            ->json('ip');
    }

    public function test_wpis_dopisany_przez_infrastrukture_wygrywa_z_wpisem_od_klienta(): void
    {
        // KONTROLA: sam nagłówek nadal działa. Bez tej asercji test niżej
        // przechodziłby także wtedy, gdyby aplikacja przestała czytać
        // `X-Forwarded-For` w ogóle — a to nie jest naprawa, tylko inna
        // awaria (wszyscy dostaliby wspólny adres brzegu i wspólny limit).
        $this->assertSame(
            self::PRAWDZIWY,
            $this->widzianyAdres(self::PRAWDZIWY),
            'Aplikacja przestała widzieć adres z X-Forwarded-For. Limity per adres stają się '
            .'wtedy jednym wspólnym limitem dla całego serwisu.',
        );

        $this->assertSame(
            self::PRAWDZIWY,
            $this->widzianyAdres('198.51.100.1, '.self::PRAWDZIWY),
            'Wpis podstawiony przez klienta z lewej strony przesunął adres widziany przez aplikację.',
        );
    }

    public function test_dowolnie_dlugi_podrobiony_prefiks_niczego_nie_zmienia(): void
    {
        $podrobione = [];

        for ($i = 1; $i <= 20; $i++) {
            $podrobione[] = '198.51.100.'.$i;
        }

        $podrobione[] = self::PRAWDZIWY;

        $this->assertSame(
            self::PRAWDZIWY,
            $this->widzianyAdres(implode(', ', $podrobione)),
            'Dwadzieścia podstawionych wpisów przesunęło odczyt adresu. Liczenie od prawej strony '
            .'nie działa — a to jest jedyna własność tego nagłówka, na której wolno polegać.',
        );
    }

    public function test_wpis_ktorego_nie_da_sie_odczytac_jako_adresu_nie_cofa_odczytu_w_lewo(): void
    {
        // Gdyby śmieć na końcu łańcucha powodował cofnięcie się o jeden wpis
        // w lewo, napastnik odzyskałby kontrolę nad wynikiem jednym „x".
        // Zamiast tego nagłówek przepada w całości i zostaje adres połączenia.
        $this->assertSame(
            '127.0.0.1',
            $this->widzianyAdres(self::PRAWDZIWY.', nie-adres'),
            'Nieczytelny ostatni wpis cofnął odczyt do wartości podstawionej przez klienta.',
        );
    }

    public function test_lancuch_krotszy_niz_konfiguracja_spada_na_adres_polaczenia(): void
    {
        config(['proxy.zaufane_przeskoki' => 2]);

        $this->assertSame(
            '127.0.0.1',
            $this->widzianyAdres(self::PRAWDZIWY),
            'Łańcuch krótszy, niż zakłada konfiguracja, został mimo to użyty — czyli aplikacja '
            .'zaufała wpisowi, którego nasza infrastruktura nie mogła dopisać.',
        );
    }

    public function test_dwa_zaufane_przeskoki_czytaja_wpis_przedostatni(): void
    {
        config(['proxy.zaufane_przeskoki' => 2]);

        // Tak wygląda łańcuch, gdyby brzeg Railway dopisywał adres Cloudflare:
        // [śmieć od klienta, prawdziwy klient (od Cloudflare), Cloudflare (od Railway)].
        $this->assertSame(
            self::PRAWDZIWY,
            $this->widzianyAdres('198.51.100.1, '.self::PRAWDZIWY.', 198.51.100.200'),
            'Przy dwóch zaufanych przeskokach aplikacja nie trafiła w adres klienta.',
        );
    }

    public function test_zero_przeskokow_wylacza_ten_naglowek_calkowicie(): void
    {
        config(['proxy.zaufane_przeskoki' => 0]);

        $this->assertSame(
            '127.0.0.1',
            $this->widzianyAdres(self::PRAWDZIWY),
            'Wyłącznik awaryjny nie działa: nagłówek jest czytany mimo zera zaufanych przeskoków.',
        );
    }

    /**
     * WŁAŚCIWY POMIAR SKUTKU: limit logowania liczony po adresie.
     *
     * Przed poprawką każda próba z innym prefiksem trafiała w inny koszyk,
     * więc limit nie odzywał się ani razu. Po poprawce prefiks jest ignorowany
     * i wszystkie próby lądują w jednym koszyku.
     */
    public function test_zmienny_podrobiony_prefiks_nie_resetuje_limitu_logowania(): void
    {
        $this->konto();

        $zablokowano = false;

        for ($i = 1; $i <= 8; $i++) {
            $odpowiedz = $this->zleLogowanie('198.51.100.'.$i.', '.self::PRAWDZIWY);

            if ($this->czyOdmowaZPowoduLimitu($odpowiedz)) {
                $zablokowano = true;
                break;
            }
        }

        $this->assertTrue(
            $zablokowano,
            'Osiem prób logowania ze zmiennym, podrobionym prefiksem X-Forwarded-For nie wywołało '
            .'żadnej blokady — limit liczony po adresie da się zerować jednym nagłówkiem.',
        );
    }

    /**
     * KONTROLA do testu wyżej. Osiem prób z ośmiu RÓŻNYCH adresów (czyli
     * z różnych wpisów dopisanych przez infrastrukturę) blokady wywołać nie
     * może — inaczej test wyżej mierzyłby limit konta, a nie limit adresu.
     */
    public function test_kontrola_osiem_prob_z_osmiu_roznych_adresow_nie_blokuje(): void
    {
        $this->konto();

        for ($i = 1; $i <= 8; $i++) {
            $odpowiedz = $this->zleLogowanie('203.0.113.'.$i);

            $this->assertFalse(
                $this->czyOdmowaZPowoduLimitu($odpowiedz),
                'Blokada zadziałała już przy różnych adresach — test wyżej nie mierzy tego, co myśli.',
            );
        }
    }

    private function konto(): User
    {
        return $this->user('basia', ['email' => 'basia@example.com']);
    }

    private function zleLogowanie(string $naglowek): TestResponse
    {
        return $this->from(route('login'))->post(route('login'), [
            'login' => 'basia@example.com',
            'password' => 'nie-to-haslo',
        ], ['X-Forwarded-For' => $naglowek]);
    }

    /**
     * Dwie drogi odmowy, obie liczą się jako blokada: middleware `throttle:`
     * na trasie odpowiada gołym 429, a koszyki w `LoginController` rzucają
     * `ValidationException` z komunikatem. Sprawdzanie tylko jednej z nich
     * dawałoby wynik zależny od tego, która bramka zadziałała pierwsza
     * (ten sam problem rozwiązuje `LimitLogowaniaNaKontoTest`).
     */
    private function czyOdmowaZPowoduLimitu(TestResponse $odpowiedz): bool
    {
        if ($odpowiedz->getStatusCode() === 429) {
            return true;
        }

        $bledy = $odpowiedz->getSession()->get('errors');

        $tekst = match (true) {
            $bledy instanceof ViewErrorBag, $bledy instanceof MessageBag => (string) $bledy->first('login'),
            is_array($bledy) => (string) json_encode($bledy, JSON_UNESCAPED_UNICODE),
            default => '',
        };

        return str_contains($tekst, 'Za dużo prób');
    }
}

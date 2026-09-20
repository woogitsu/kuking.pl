<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE #733 — „ZOBACZ" NIE MOŻE ODESŁAĆ POZA TEN SERWIS.
 *
 * `NotificationController::wlasnyAdres()` miał dwie luki:
 *
 *  1. Pierwsza gałąź przyjmowała każdy napis zaczynający się od
 *     pojedynczego `/`, w tym `/\example.invalid/proba`. Przeglądarki
 *     (parser WHATWG URL) traktują odwrócony ukośnik w adresie względnym
 *     jak zwykły `/` — taki napis w `Location` jest adresem do INNEGO
 *     hosta, nie ścieżką w tym serwisie.
 *  2. Druga gałąź porównywała wyłącznie host, pomijając schemat i port —
 *     `https://kuking.pl:444/x` i `http://kuking.pl/x` (przy HTTPS
 *     w `app.url`) miały inne origin, ale przechodziły walidację.
 *
 * Ten test wchodzi PRZEZ RZECZYWISTĄ TRASĘ `notifications.open`, żeby
 * mierzyć to, co naprawdę trafia w nagłówek `Location`, a nie sam kształt
 * parsera. Adresy testowe używają domeny `.invalid` — nic tam nie jedzie.
 */
class PowiadomienieWlasnyAdresPrzekierowaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dopuszczone_adresy_prowadza_do_celu(): void
    {
        $odbiorca = $this->user('basia');

        $this->assertRedirectDo($odbiorca, '/przepisy/test', '/przepisy/test');
        $this->assertRedirectDo(
            $odbiorca,
            'http://localhost:8000/przepisy/test',
            'http://localhost:8000/przepisy/test',
        );
    }

    public function test_dopuszczony_domyslny_port_dla_schematu(): void
    {
        Config::set('app.url', 'https://kuking.pl');
        $odbiorca = $this->user('basia');

        // Port domyślny dla https podany wprost ma być równoważny brakowi
        // portu w konfiguracji.
        $this->assertRedirectDo($odbiorca, 'https://kuking.pl:443/proba', 'https://kuking.pl:443/proba');
    }

    /**
     * @return list<array{string}>
     */
    public static function niedozwoloneAdresyProvider(): array
    {
        return [
            'podwojny ukosnik, host obcy' => ['//example.invalid/proba'],
            'odwrocony ukosnik udaje sciezke' => ['/\\example.invalid/proba'],
            'jawnie obcy host' => ['https://example.invalid/proba'],
            'inny port tego samego hosta' => ['http://localhost:9999/proba'],
            'userinfo w adresie' => ['http://uzytkownik:haslo@localhost:8000/proba'],
            'znak sterujacy w sciezce' => ["/przepisy/dobre\r\nX-Wstrzykniete: tak"],
            'schemat spoza http/https' => ['javascript://localhost:8000/proba'],
        ];
    }

    #[DataProvider('niedozwoloneAdresyProvider')]
    public function test_niedozwolone_adresy_nie_wychodza_poza_serwis(string $zlyAdres): void
    {
        $odbiorca = $this->user('basia');
        $powiadomienie = $this->powiadomienieZUrl($odbiorca, $zlyAdres);
        $powrot = route('notifications.index');

        $odpowiedz = $this->actingAs($odbiorca)
            ->from($powrot)
            ->post(route('notifications.open', $powiadomienie));

        $lokalizacja = (string) $odpowiedz->headers->get('Location');

        $this->assertSame(
            $powrot,
            $lokalizacja,
            "Niedozwolony adres „{$zlyAdres}” wyszedł jako Location poza origin aplikacji: {$lokalizacja}",
        );

        // Znacznik przeczytania mimo to jest ustawiony — odrzucenie adresu
        // nie ma cofać samego oznaczenia „widziane".
        $this->assertNotNull($powiadomienie->refresh()->read_at);
    }

    public function test_http_przy_https_w_konfiguracji_nie_przechodzi(): void
    {
        Config::set('app.url', 'https://kuking.pl');
        $odbiorca = $this->user('basia');

        $this->assertRedirectOdrzucony($odbiorca, 'http://kuking.pl/proba');
    }

    public function test_inny_port_tego_samego_schematu_nie_przechodzi(): void
    {
        Config::set('app.url', 'https://kuking.pl');
        $odbiorca = $this->user('basia');

        $this->assertRedirectOdrzucony($odbiorca, 'https://kuking.pl:444/proba');
    }

    private function assertRedirectDo(User $odbiorca, string $adres, string $oczekiwany): void
    {
        $powiadomienie = $this->powiadomienieZUrl($odbiorca, $adres);

        $this->actingAs($odbiorca)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect($oczekiwany);
    }

    private function assertRedirectOdrzucony(User $odbiorca, string $adres): void
    {
        $powiadomienie = $this->powiadomienieZUrl($odbiorca, $adres);
        $powrot = route('notifications.index');

        $odpowiedz = $this->actingAs($odbiorca)
            ->from($powrot)
            ->post(route('notifications.open', $powiadomienie));

        $this->assertSame($powrot, (string) $odpowiedz->headers->get('Location'));
    }

    /**
     * Powiadomienie spoza `match` w `adresDocelowy()` — cel liczy się
     * wprost z `data['url']`, tak jak dla przyszłych typów bez własnej
     * gałęzi. To jedyna droga, którą kontroler w ogóle widzi adres
     * absolutny albo dowolną ścieżkę zamiast wyliczonej trasy.
     */
    private function powiadomienieZUrl(User $odbiorca, string $adres): Notification
    {
        return Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => null,
            'type' => 'test.redirect',
            'data' => ['url' => $adres],
        ]);
    }
}

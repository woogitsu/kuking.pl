<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ISSUE #733 — „Zobacz" odsyła wyłącznie w obrębie serwisu.
 *
 * `Notification::adresDocelowy()` dla typów bez własnej gałęzi `match`
 * zwraca `data['url']` z bazy, a `NotificationController::open()` robi
 * z tego przekierowanie. Poprzedni strażnik przepuszczał m.in.
 * `https://<host aplikacji>//obcy.invalid` (po sprowadzeniu do ścieżki —
 * adres bez schematu), inny port tego samego hosta i `user@host`.
 *
 * Test idzie przez PRAWDZIWĄ trasę i patrzy na nagłówek `Location`, bo tylko
 * on mówi, dokąd przeglądarka pójdzie — sam parser URL tego nie rozstrzyga.
 * Obce domeny są w `.invalid`; nikt tam niczego nie wysyła.
 */
class ZobaczOdsylaTylkoWObrebieSerwisuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dostawcy danych nie widzą konfiguracji, więc adresy piszemy
     * z zastępnikami: `{app}` = origin aplikacji (`config('app.url')`),
     * `{host}` = jej host, `{port}` = efektywny port podany wprost.
     */
    private function rozwin(string $adres): string
    {
        $app = rtrim((string) config('app.url'), '/');
        $czesci = parse_url($app);
        $port = $czesci['port'] ?? (($czesci['scheme'] ?? 'http') === 'https' ? 443 : 80);

        return strtr($adres, [
            '{app}' => $app,
            '{host}' => (string) $czesci['host'],
            '{port}' => (string) $port,
            '{innyschemat}' => ($czesci['scheme'] ?? 'http') === 'https' ? 'http' : 'https',
        ]);
    }

    private function lista(): string
    {
        return route('notifications.index');
    }

    /** @return array<string, array{string}> */
    public static function adresyObce(): array
    {
        return [
            'bez schematu' => ['//obcy.invalid/proba'],
            'backslash' => ['/\\obcy.invalid/proba'],
            'backslash po ukośnikach' => ['/\\/obcy.invalid'],
            'jawny obcy host' => ['https://obcy.invalid/proba'],
            'obcy host z naszym jako poddomena' => ['http://{host}.obcy.invalid/proba'],
            'nasz host, inny port' => ['http://{host}:1/proba'],
            'nasz host, podwójny ukośnik w ścieżce' => ['{app}//obcy.invalid/proba'],
            'dane logowania' => ['http://obcy.invalid@{host}/proba'],
            'dane logowania z hasłem' => ['http://ktos:haslo@{host}/proba'],
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html,<b>x</b>'],
            'ftp z naszym hostem' => ['ftp://{host}/proba'],
            'tabulator' => ["/\t/obcy.invalid"],
            'nowa linia' => ["/\n/obcy.invalid"],
            'spacja' => ['/ /obcy.invalid'],
            // Bajtu zerowego nie ma w teście, bo PostgreSQL nie zapisze go
            // w `jsonb` (22P05) — taki adres nie może stać w `data.url`.
            'bez ukośnika na początku' => ['obcy.invalid/proba'],
        ];
    }

    #[DataProvider('adresyObce')]
    public function test_obcy_adres_nie_wyprowadza_poza_serwis(string $adres): void
    {
        [$odbiorca, $powiadomienie] = $this->powiadomienieZAdresem($this->rozwin($adres));

        $odpowiedz = $this->actingAs($odbiorca)
            ->from($this->lista())
            ->post(route('notifications.open', $powiadomienie));

        $odpowiedz->assertRedirect($this->lista());

        $this->assertNotNull(
            $powiadomienie->refresh()->read_at,
            'Odrzucenie adresu nie może zostawić powiadomienia nieprzeczytanego — człowiek kliknął.',
        );
    }

    /**
     * Zakodowane warianty (`%2F`, `%5C`) ZOSTAJĄ ścieżką u nas:
     * `redirect()->to()` dokleja je do adresu aplikacji, a przeglądarka nie
     * dekoduje `%2F` w ścieżce na granicę hosta. Sprawdzamy więc origin
     * w `Location`, nie odrzucenie.
     *
     * @return array<string, array{string}>
     */
    public static function adresyZakodowane(): array
    {
        return [
            'zakodowane ukośniki' => ['/%2F%2Fobcy.invalid'],
            'zakodowany backslash' => ['/%5Cobcy.invalid'],
            'zakodowany ukośnik na początku' => ['/%2Fobcy.invalid'],
        ];
    }

    #[DataProvider('adresyZakodowane')]
    public function test_zakodowany_wariant_zostaje_na_originie_serwisu(string $adres): void
    {
        [$odbiorca, $powiadomienie] = $this->powiadomienieZAdresem($adres);

        $location = (string) $this->actingAs($odbiorca)
            ->from($this->lista())
            ->post(route('notifications.open', $powiadomienie))
            ->headers->get('Location');

        $this->assertSame($this->rozwin('{app}').$adres, $location);
    }

    /** @return array<string, array{string, string}> */
    public static function adresyWlasne(): array
    {
        return [
            'ścieżka przepisu' => ['/przepisy/rosol', '{app}/przepisy/rosol'],
            'ścieżka z zapytaniem i kotwicą' => ['/przepisy/rosol?strona=2#komentarz-1', '{app}/przepisy/rosol?strona=2#komentarz-1'],
            'pełny adres aplikacji' => ['{app}/przepisy/rosol', '{app}/przepisy/rosol'],
            'port efektywny podany wprost' => ['http://{host}:{port}/przepisy/rosol', '{app}/przepisy/rosol'],
            // Historyczny inny schemat z NASZYM hostem nie jest odrzucany
            // (nie ma o tym decyzji), ale kończy się na originie aplikacji.
            'nasz host, inny schemat' => ['{innyschemat}://{host}/przepisy/rosol', '{app}/przepisy/rosol'],
            'sam adres aplikacji' => ['{app}', '{app}'],
        ];
    }

    #[DataProvider('adresyWlasne')]
    public function test_wlasny_adres_dalej_prowadzi_do_tresci(string $adres, string $oczekiwany): void
    {
        [$odbiorca, $powiadomienie] = $this->powiadomienieZAdresem($this->rozwin($adres));

        $this->actingAs($odbiorca)
            ->from($this->lista())
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect($this->rozwin($oczekiwany));
    }

    /** @return array{User, Notification} */
    private function powiadomienieZAdresem(string $adres): array
    {
        $odbiorca = $this->user('basia');

        // Typ spoza `match` w `adresDocelowy()` — dokładnie ta gałąź,
        // która bierze adres z `data['url']`.
        $powiadomienie = Notification::query()->create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => null,
            'type' => 'test.adres_z_bazy',
            'data' => ['url' => $adres],
        ]);

        $this->assertSame(
            $adres,
            $powiadomienie->adresDocelowy(),
            'Kontrola: adres nie przeszedł przez gałąź `data.url`, więc test nic nie mierzy.',
        );

        return [$odbiorca, $powiadomienie];
    }
}

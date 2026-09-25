<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Poczta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * `Poczta::dziala()` nie uznaje łańcucha kończącego się dziennikiem za pocztę
 * (issue #1084).
 *
 * CO BYŁO ZEPSUTE
 * Kontrola odrzucała tylko NAZWY `log`, `array` i pustą. `failover`
 * z listą `['smtp', 'log']` przechodził, choć po awarii SMTP list lądował
 * w dzienniku, a wysyłka zgłaszała sukces — więc `/health` był zielony,
 * a formularze obiecywały listy. Tak samo przechodził mailer pod własną
 * nazwą z `transport => log`.
 *
 * Bez sieci: pierwszy transport łańcucha to atrapa rzucająca wyjątek,
 * dostawca EmailLabs odpowiada przez `Http::fake()`. Żaden test nie wysyła
 * listu do dziennika — drugi transport w próbie wysyłki to `array`.
 */
class PocztaNieUznajeDziennikaZaZapasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::extend('zawodny', static fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Atrapa: pierwszy transport zawodzi.');
            }

            public function __toString(): string
            {
                return 'zawodny://';
            }
        });

        config([
            'mail.mailers.zawodny' => ['transport' => 'zawodny'],
            'mail.mailers.dziennik' => ['transport' => 'log'],
            'mail.mailers.pamiec' => ['transport' => 'array'],
        ]);
    }

    /**
     * SEDNO. Pierwszy transport rzuca, drugi niczego nie dostarcza — wysyłka
     * kończy się BEZ wyjątku, więc nic poza tą kontrolą tego nie złapie.
     */
    public function test_failover_z_niedostarczajacym_zapasem_nie_jest_poczta(): void
    {
        config([
            'mail.default' => 'zapas',
            'mail.mailers.zapas' => ['transport' => 'failover', 'mailers' => ['zawodny', 'pamiec']],
        ]);

        // Dowód, że wyjątku nie ma: bez kontroli konfiguracji wynik „wysłano".
        Mail::mailer('zapas')->raw('Treść próbna', fn ($m) => $m->to('basia@example.com')->subject('Próba'));

        $this->assertFalse(Poczta::dziala(), 'Łańcuch kończący się transportem `array` uznany za działającą pocztę.');
        $this->assertStringContainsString('pamiec', (string) Poczta::przeszkoda());
    }

    /** Domyślny kształt Laravela: `failover` → `smtp`, potem `log` (pod własną nazwą). */
    #[DataProvider('lancuchyZDziennikiem')]
    public function test_lancuch_z_dziennikiem_nie_jest_poczta(string $transport, array $skladowe): void
    {
        config([
            'mail.default' => 'zapas',
            'mail.mailers.zapas' => ['transport' => $transport, 'mailers' => $skladowe],
        ]);

        $this->assertFalse(Poczta::dziala());
        $this->assertStringContainsString('Zapasem ma być drugi dostawca', (string) Poczta::przeszkoda());
    }

    public static function lancuchyZDziennikiem(): array
    {
        return [
            'failover, dziennik pod nazwą log' => ['failover', ['smtp', 'log']],
            'failover, dziennik pod własną nazwą' => ['failover', ['smtp', 'dziennik']],
            'roundrobin, dziennik pod własną nazwą' => ['roundrobin', ['smtp', 'dziennik']],
        ];
    }

    /** Oblewa, gdy kontrola wróci do sprawdzania samych nazw. */
    public function test_wlasna_nazwa_mailera_z_transportem_log_nie_jest_poczta(): void
    {
        config(['mail.default' => 'dziennik']);

        $this->assertFalse(Poczta::dziala());
        $this->assertStringContainsString('transportu `log`', (string) Poczta::przeszkoda());
    }

    /** Zapas zagnieżdżony głębiej też się liczy. */
    public function test_zagniezdzony_dziennik_nie_jest_poczta(): void
    {
        config([
            'mail.default' => 'zewnetrzny',
            'mail.mailers.zewnetrzny' => ['transport' => 'failover', 'mailers' => ['smtp', 'wewnetrzny']],
            'mail.mailers.wewnetrzny' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'dziennik']],
        ]);

        $this->assertFalse(Poczta::dziala());
    }

    public function test_produkcyjny_health_nie_jest_zielony_przy_dzienniku_w_lancuchu(): void
    {
        Artisan::call('storage:link');

        config([
            'mail.default' => 'zapas',
            'mail.mailers.zapas' => ['transport' => 'failover', 'mailers' => ['smtp', 'dziennik']],
        ]);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('checks.poczta.ok', false)
            ->assertJsonPath('checks.poczta.error', 'poczta_nie_wysyla');
    }

    // ------------------------------------------------------------------
    //  Kontrola dodatnia
    // ------------------------------------------------------------------

    public function test_rzeczywisty_transport_jest_poczta(): void
    {
        config(['mail.default' => 'smtp']);

        $this->assertTrue(Poczta::dziala());
    }

    /**
     * Łańcuch z samych wysyłających transportów przechodzi — także domyślny
     * wpis `failover` z `config/mail.php` (EmailLabs, potem SMTP).
     */
    public function test_lancuch_wysylajacych_transportow_jest_poczta(): void
    {
        Http::fake();
        config([
            'mail.default' => 'failover',
            'services.emaillabs.key' => 'klucz-aplikacji-do-testu',
            'services.emaillabs.secret' => 'klucz-autoryzacyjny-do-testu',
            'services.emaillabs.smtp_account' => '1.kuking.smtp',
        ]);
        Mail::purge('emaillabs');

        $this->assertSame(['emaillabs', 'smtp'], config('mail.mailers.failover.mailers'));
        $this->assertTrue(Poczta::dziala(), (string) Poczta::przeszkoda());

        config(['mail.mailers.zapas' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'emaillabs']], 'mail.default' => 'zapas']);
        $this->assertTrue(Poczta::dziala(), (string) Poczta::przeszkoda());
    }
}

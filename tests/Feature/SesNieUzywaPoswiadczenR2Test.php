<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\PocztaServiceProvider;
use App\Support\Poczta;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Mail\Transport\SesV2Transport;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Tylko lokalna budowa transportu. Bez wysyłki, sieci, sekretów i bazy.
 *
 * @bez-kontroli-dodatniej base_path() podaje katalog podprocesowi i ładuje config przez require, a asercje tekstowe dotyczą wyjścia polecenia i komunikatu Poczta::przeszkoda(), nie treści źródła.
 */
class SesNieUzywaPoswiadczenR2Test extends TestCase
{
    public function test_same_zmienne_r2_nie_wypelniaja_zadnego_pola_ses(): void
    {
        $ses = $this->loadSesConfiguration([]);

        $this->assertNull($ses['key'], 'SES nie może dziedziczyć klucza R2.');
        $this->assertNull($ses['secret'], 'SES nie może dziedziczyć sekretu R2.');
        $this->assertSame('eu-central-1', $ses['region'], 'SES nie może dziedziczyć regionu R2.');
    }

    public function test_wlasne_zmienne_ses_wygrywaja_bez_mieszania_uslug(): void
    {
        $ses = $this->loadSesConfiguration([
            'MAIL_SES_KEY' => 'ses-test-key',
            'MAIL_SES_SECRET' => 'ses-test-secret',
            'MAIL_SES_REGION' => 'eu-west-1',
        ]);

        $this->assertSame(['key' => 'ses-test-key', 'secret' => 'ses-test-secret', 'region' => 'eu-west-1'], $ses);
    }

    #[DataProvider('missingCredentials')]
    public function test_odmawia_budowy_transportu_przy_braku_wlasnego_pola(string $driver, string $field, mixed $value): void
    {
        $this->configureSes($driver);
        config(["services.ses.{$field}" => $value]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($field === 'key' ? 'MAIL_SES_KEY' : 'MAIL_SES_SECRET');

        Mail::mailer('poczta-testowa')->getSymfonyTransport();
    }

    public static function missingCredentials(): array
    {
        $cases = [];
        foreach (['ses', 'ses-v2'] as $driver) {
            foreach (['key', 'secret'] as $field) {
                foreach ([null, '', '  ', '0'] as $index => $value) {
                    $cases["{$driver}-{$field}-{$index}"] = [$driver, $field, $value];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('drivers')]
    public function test_odmawia_startu_procesu_przed_przyjeciem_pracy(string $driver): void
    {
        $this->configureSes($driver);
        config(['mail.default' => 'poczta-testowa', 'services.ses.secret' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_SES_SECRET');

        (new PocztaServiceProvider($this->app))->boot();
    }

    #[DataProvider('compositeDrivers')]
    public function test_start_sprawdza_rowniez_zagniezdzone_ses(string $driver): void
    {
        $this->configureSes('ses-v2');
        config([
            'mail.default' => 'grupa-testowa',
            'mail.mailers.grupa-testowa' => ['transport' => $driver, 'mailers' => ['array', 'poczta-testowa']],
            'services.ses.key' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_SES_KEY');
        (new PocztaServiceProvider($this->app))->boot();
    }

    public static function compositeDrivers(): array
    {
        return [['failover'], ['roundrobin']];
    }

    #[DataProvider('drivers')]
    public function test_komplet_buduje_transport_z_wlasnym_klientem_ses(string $driver): void
    {
        $this->configureSes($driver);
        config(['mail.default' => 'poczta-testowa']);
        (new PocztaServiceProvider($this->app))->boot();

        $transport = Mail::mailer('poczta-testowa')->getSymfonyTransport();
        $this->assertInstanceOf($driver === 'ses' ? SesTransport::class : SesV2Transport::class, $transport);
        $client = $transport->ses();
        $credentials = $client->getCredentials()->wait();
        $this->assertSame('ses-test-key', $credentials->getAccessKeyId());
        $this->assertSame('ses-test-secret', $credentials->getSecretKey());
        $this->assertSame('eu-central-1', $client->getRegion());
    }

    public static function drivers(): array
    {
        return [['ses'], ['ses-v2']];
    }

    public function test_nowy_proces_artisan_odmawia_startu_bez_sekretu_ses(): void
    {
        $process = new Process([PHP_BINARY, 'artisan', 'list', '--raw'], base_path(), [
            'MAIL_MAILER' => 'ses',
            'MAIL_SES_KEY' => 'ses-test-key',
            'MAIL_SES_SECRET' => '',
            'AWS_ACCESS_KEY_ID' => 'r2-test-key',
            'AWS_SECRET_ACCESS_KEY' => 'r2-test-secret',
            'AWS_DEFAULT_REGION' => 'auto',
        ]);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('MAIL_SES_SECRET', $output);
        $this->assertStringNotContainsString('ses-test-key', $output);
        $this->assertStringNotContainsString('r2-test-secret', $output);
    }

    public function test_puste_nadpisanie_mailera_nie_wraca_do_wspolnego_klucza(): void
    {
        $this->configureSes('ses');
        config(['mail.mailers.poczta-testowa.key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_SES_KEY');
        Mail::mailer('poczta-testowa');
    }

    public function test_nieaktywny_ses_nie_blokuje_innego_mailera(): void
    {
        config(['mail.default' => 'array', 'services.ses.key' => null, 'services.ses.secret' => null]);
        (new PocztaServiceProvider($this->app))->boot();
        $this->assertInstanceOf(ArrayTransport::class, Mail::mailer()->getSymfonyTransport());
    }

    public function test_diagnostyka_wskazuje_brak_wlasnego_sekretu_i_nie_kolejkuje_listu(): void
    {
        $this->configureSes('ses');
        config(['mail.default' => 'poczta-testowa', 'services.ses.secret' => null]);
        Bus::fake();

        $this->assertFalse(Poczta::dziala());
        $this->assertStringContainsString('MAIL_SES_SECRET', Poczta::przeszkoda());
        $this->assertStringNotContainsString('ses-test-key', Poczta::przeszkoda());
        $this->artisan('kuking:sprawdz-poczte', ['adres' => 'test@example.com', '--kolejka' => true])
            ->expectsOutputToContain('MAIL_SES_SECRET')
            ->assertFailed();
        Bus::assertNothingDispatched();
    }

    private function configureSes(string $driver): void
    {
        config([
            'services.ses' => ['key' => 'ses-test-key', 'secret' => 'ses-test-secret', 'region' => 'eu-central-1'],
            'mail.mailers.poczta-testowa' => ['transport' => $driver],
        ]);
        Mail::purge('poczta-testowa');
    }

    /** Czyta rzeczywisty plik konfiguracji, nie kopię jego tablicy z testu. */
    private function loadSesConfiguration(array $values): array
    {
        $environment = Env::getRepository();
        $replacements = array_merge([
            'AWS_ACCESS_KEY_ID' => 'r2-test-key',
            'AWS_SECRET_ACCESS_KEY' => 'r2-test-secret',
            'AWS_DEFAULT_REGION' => 'auto',
            'MAIL_SES_KEY' => null,
            'MAIL_SES_SECRET' => null,
            'MAIL_SES_REGION' => null,
        ], $values);
        $previous = [];

        try {
            foreach ($replacements as $name => $value) {
                $previous[$name] = $environment->get($name);
                $environment->clear($name);
                if ($value !== null) {
                    $environment->set($name, $value);
                }
                $this->assertSame($value, $environment->get($name), 'Fixture środowiska musi naprawdę wejść.');
            }

            return (require base_path('config/services.php'))['ses'];
        } finally {
            foreach ($previous as $name => $value) {
                $environment->clear($name);
                if ($value !== null) {
                    $environment->set($name, $value);
                }
            }
        }
    }
}

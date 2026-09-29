<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\OcenaRetencjiLivewireR2;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * `kuking:sprawdz-retencje-livewire` (#2051) — tylko odczyt reguł lifecycle.
 *
 * Klient S3 dostaje własny `handler` (ostatnie ogniwo stosu AWS SDK): nic nie
 * wychodzi do sieci, a test widzi każde polecenie, które komenda wysłała.
 * Dzięki temu można sprawdzić też to, że komenda NIC nie zapisuje.
 *
 * @bez-kontroli-dodatniej Kontrola dodatnia jest w klasie: `test_dobra_regula_przechodzi` i `test_brak_reguly_oblewa` dają przeciwne kody na tym samym dysku, więc test nie przechodzi „bo nic nie sprawdza”.
 */
class SprawdzRetencjeLivewireTest extends TestCase
{
    /** @var list<string> nazwy poleceń S3 wysłanych przez komendę */
    private array $polecenia = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['livewire.temporary_file_upload.directory' => null]);
    }

    public function test_dobra_regula_przechodzi(): void
    {
        $this->dyskZReguly([$this->regula('livewire-tmp/', 1)]);

        $this->artisan('kuking:sprawdz-retencje-livewire', ['--dysk' => 'probny-r2'])
            ->expectsOutputToContain('jest, włączona, wygasza po najwyżej 1 dniu')
            ->assertExitCode(0);

        $this->assertSame(['GetBucketLifecycleConfiguration'], $this->polecenia, 'Komenda ma tylko czytać.');
    }

    public function test_brak_reguly_oblewa(): void
    {
        $this->dyskBezKonfiguracji();

        $this->artisan('kuking:sprawdz-retencje-livewire', ['--dysk' => 'probny-r2'])
            ->expectsOutputToContain('RETENCJA NIEPOTWIERDZONA')
            ->assertExitCode(1);
    }

    public function test_regula_z_pustym_prefiksem_to_alarm_nawet_gdy_jest_tez_dobra(): void
    {
        $this->dyskZReguly([$this->regula('livewire-tmp/', 1), $this->regula('', 30)]);

        $this->artisan('kuking:sprawdz-retencje-livewire', ['--dysk' => 'probny-r2'])
            ->expectsOutputToContain('PUSTY prefiks')
            ->assertExitCode(1);
    }

    public function test_regula_na_incoming_to_alarm(): void
    {
        $this->dyskZReguly([$this->regula('livewire-tmp/', 1), $this->regula('incoming/', 1)]);

        $this->artisan('kuking:sprawdz-retencje-livewire', ['--dysk' => 'probny-r2'])
            ->expectsOutputToContain('obejmuje także `incoming/`')
            ->assertExitCode(1);
    }

    public function test_zmieniony_katalog_livewire_wymaga_nowej_reguly(): void
    {
        config(['livewire.temporary_file_upload.directory' => 'uploady']);
        $this->dyskZReguly([$this->regula('livewire-tmp/', 1)]);

        $this->artisan('kuking:sprawdz-retencje-livewire', ['--dysk' => 'probny-r2'])
            ->expectsOutputToContain('Prefiks Livewire: uploady/')
            ->assertExitCode(1);
    }

    public function test_odmowa_odczytu_nie_znaczy_dobrze_i_nie_ujawnia_bucketu(): void
    {
        $this->dyskZBledem('AccessDenied');

        $this->artisan('kuking:sprawdz-retencje-livewire', ['--dysk' => 'probny-r2'])
            ->expectsOutputToContain('kod: AccessDenied')
            ->doesntExpectOutputToContain('kuking-oryginaly-probne')
            ->assertExitCode(1);
    }

    public function test_dysk_lokalny_nie_jest_sprawdzalny(): void
    {
        config(['livewire.temporary_file_upload.disk' => 'local']);

        $this->artisan('kuking:sprawdz-retencje-livewire')
            ->expectsOutputToContain('nie jest zdalnym magazynem')
            ->assertExitCode(1);
    }

    public function test_ocena_starszy_ksztalt_reguly_z_prefiksem_na_wierzchu(): void
    {
        $ocena = OcenaRetencjiLivewireR2::ocen(
            [['Status' => 'Enabled', 'Prefix' => 'livewire-tmp/', 'Expiration' => ['Days' => 1]]],
            'livewire-tmp/',
        );

        $this->assertTrue($ocena['dobra']);
    }

    /**
     * Reguła na CZĘŚĆ `incoming/` też kasuje oryginały. Kontrola dodatnia:
     * ta sama dobra reguła bez niej przechodzi.
     */
    public function test_ocena_regula_na_czesc_incoming_to_alarm(): void
    {
        $prefiks = 'livewire-tmp/';
        $dobra = $this->regula($prefiks, 1);

        $this->assertTrue(OcenaRetencjiLivewireR2::ocen([$dobra], $prefiks)['dobra']);

        $ocena = OcenaRetencjiLivewireR2::ocen([$dobra, $this->regula('incoming/2026/', 30)], $prefiks);

        $this->assertFalse($ocena['dobra']);
        $this->assertStringContainsString('`incoming/2026/` obejmuje także `incoming/`', implode(' ', $ocena['alarm']));
    }

    /** Reguła z kilkoma warunkami trzyma prefiks w `Filter.And`. */
    public function test_ocena_prefiks_w_filter_and(): void
    {
        $prefiks = 'livewire-tmp/';
        $regula = [
            'Status' => 'Enabled',
            'Filter' => ['And' => ['Prefix' => $prefiks, 'ObjectSizeGreaterThan' => 0]],
            'Expiration' => ['Days' => 1],
        ];

        $ocena = OcenaRetencjiLivewireR2::ocen([$regula], $prefiks);

        $this->assertTrue($ocena['dobra']);
        $this->assertSame([], $ocena['alarm'], 'Prefiks z `Filter.And` wzięty za pusty — fałszywy alarm „cały bucket”.');
    }

    public function test_ocena_odrzuca_wylaczona_za_dluga_i_po_dacie(): void
    {
        $prefiks = 'livewire-tmp/';

        $this->assertFalse(OcenaRetencjiLivewireR2::ocen([$this->regula($prefiks, 1, 'Disabled')], $prefiks)['dobra']);
        $this->assertFalse(OcenaRetencjiLivewireR2::ocen([$this->regula($prefiks, 7)], $prefiks)['dobra']);
        $this->assertFalse(OcenaRetencjiLivewireR2::ocen(
            [['Status' => 'Enabled', 'Filter' => ['Prefix' => $prefiks], 'Expiration' => ['Date' => '2027-01-01']]],
            $prefiks,
        )['dobra']);
        // Reguła bez wygasania (np. tylko przerwanie multipartów) nie jest retencją.
        $this->assertFalse(OcenaRetencjiLivewireR2::ocen(
            [['Status' => 'Enabled', 'Filter' => ['Prefix' => $prefiks], 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 1]]],
            $prefiks,
        )['dobra']);
    }

    /** @return array<string, mixed> */
    private function regula(string $prefiks, int $dni, string $status = 'Enabled'): array
    {
        return [
            'ID' => 'regula-'.md5($prefiks.$dni),
            'Status' => $status,
            'Filter' => ['Prefix' => $prefiks],
            'Expiration' => ['Days' => $dni],
        ];
    }

    /** @param  list<array<string, mixed>>  $reguly */
    private function dyskZReguly(array $reguly): void
    {
        $this->dysk(fn (CommandInterface $polecenie) => Create::promiseFor(new Result(['Rules' => $reguly])));
    }

    private function dyskBezKonfiguracji(): void
    {
        $this->dyskZBledem('NoSuchLifecycleConfiguration');
    }

    private function dyskZBledem(string $kod): void
    {
        $this->dysk(fn (CommandInterface $polecenie) => Create::rejectionFor(
            new AwsException('Błąd z nazwą kuking-oryginaly-probne.', $polecenie, ['code' => $kod]),
        ));
    }

    private function dysk(callable $odpowiedz): void
    {
        config([
            'livewire.temporary_file_upload.disk' => 'probny-r2',
            'filesystems.disks.probny-r2' => [
                'driver' => 'r2',
                'key' => 'probny-klucz',
                'secret' => 'probny-sekret',
                'region' => 'auto',
                'bucket' => 'kuking-oryginaly-probne',
                'endpoint' => 'https://0123456789abcdef0123456789abcdef.eu.r2.cloudflarestorage.com',
                'use_path_style_endpoint' => false,
                'throw' => true,
                'handler' => function (CommandInterface $polecenie, RequestInterface $zadanie) use ($odpowiedz): PromiseInterface {
                    $this->polecenia[] = $polecenie->getName();

                    return $odpowiedz($polecenie);
                },
            ],
        ]);

        Storage::forgetDisk('probny-r2');
    }
}

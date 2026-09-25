<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\HealthController;
use App\Support\Storage\DozwolonyHostR2;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Adres magazynu R2 za strażnikiem hostów (D-255, decyzja właściciela 24.09.2026).
 *
 * Dyski R2/S3 podpisują żądania kluczem z `AWS_*` i niosą oryginały z EXIF-em,
 * paczki RODO i kopie bazy. Wolno je wysłać WYŁĄCZNIE pod
 * `https://<32 hex>.eu.r2.cloudflarestorage.com` — segment `eu` przypina
 * dane do jurysdykcji UE (`docs/infra/LOKALIZACJA_DANYCH_R2.md`).
 *
 * Kontrola dodatnia (dobry host przechodzi i żądanie NAPRAWDĘ idzie pod
 * niego) stoi obok ujemnych, żeby strażnik „zawsze nie" nie udawał
 * działającego (`docs/PULAPKI_TESTOW.md`).
 */
class StraznikHostaR2Test extends TestCase
{
    use RefreshDatabase;

    private const KONTO = '0123456789abcdef0123456789abcdef';

    private const DOBRY = 'https://'.self::KONTO.'.eu.r2.cloudflarestorage.com';

    private const KLUCZ = 'klucz-ktory-nie-ma-prawa-wyjsc';

    private const SEKRET = 'sekret-ktory-nie-ma-prawa-wyjsc';

    /** @var list<RequestInterface> */
    private array $wyslane = [];

    public function test_poprawny_host_przechodzi(): void
    {
        foreach ([self::DOBRY, self::DOBRY.'/', strtoupper('https://'.self::KONTO).'.EU.R2.CLOUDFLARESTORAGE.COM'] as $adres) {
            $this->assertNull(DozwolonyHostR2::powod($adres, srodowiskoLokalne: false), $adres);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zleAdresy(): array
    {
        $k = self::KONTO;

        return [
            'bez jurysdykcji eu' => ["https://{$k}.r2.cloudflarestorage.com"],
            'cudza jurysdykcja' => ["https://{$k}.us.r2.cloudflarestorage.com"],
            'obcy host' => ['https://obcy.example.com'],
            'sufiks podszywający się' => ["https://{$k}.eu.r2.cloudflarestorage.com.obcy.pl"],
            'przedrostek przed kontem' => ["https://x.{$k}.eu.r2.cloudflarestorage.com"],
            'kropka na końcu' => ["https://{$k}.eu.r2.cloudflarestorage.com."],
            'konto za krótkie' => ['https://0123456789abcdef.eu.r2.cloudflarestorage.com'],
            'konto nie hex' => ['https://0123456789abcdef0123456789abcdeg.eu.r2.cloudflarestorage.com'],
            'http' => ["http://{$k}.eu.r2.cloudflarestorage.com"],
            'port' => ["https://{$k}.eu.r2.cloudflarestorage.com:8443"],
            'port 443 wpisany' => ["https://{$k}.eu.r2.cloudflarestorage.com:443"],
            'userinfo' => ["https://klucz:sekret@{$k}.eu.r2.cloudflarestorage.com"],
            'userinfo z obcym hostem' => ["https://{$k}.eu.r2.cloudflarestorage.com@obcy.pl"],
            'ścieżka' => ["https://{$k}.eu.r2.cloudflarestorage.com/bucket"],
            'zapytanie' => ["https://{$k}.eu.r2.cloudflarestorage.com/?x=1"],
            'fragment' => ["https://{$k}.eu.r2.cloudflarestorage.com/#x"],
            'odwrotny ukośnik' => ["https://obcy.pl\\@{$k}.eu.r2.cloudflarestorage.com"],
            'pusty adres' => [''],
            'localhost poza testami' => ['http://127.0.0.1:9000'],
            'zarezerwowana domena poza testami' => ['https://storage.invalid'],
        ];
    }

    #[DataProvider('zleAdresy')]
    public function test_straznik_r2_odrzuca_host_spoza_wzoru(string $adres): void
    {
        $this->assertNotNull(DozwolonyHostR2::powod($adres, srodowiskoLokalne: false), "Przepuszczony: {$adres}");
    }

    /**
     * W local/testing przechodzą adresy, które nie wychodzą z maszyny (MinIO,
     * zarezerwowane domeny) — ale obcy host i `.eu`-less R2 dalej nie.
     */
    public function test_srodowisko_testowe_dopuszcza_tylko_adresy_lokalne(): void
    {
        foreach (['', 'http://127.0.0.1:59310', 'http://localhost:9000', 'https://przyklad.invalid', 'https://konto.r2.example.test'] as $adres) {
            $this->assertNull(DozwolonyHostR2::powod($adres, srodowiskoLokalne: true), $adres);
        }

        foreach (['https://obcy.example.com', 'https://'.self::KONTO.'.r2.cloudflarestorage.com', 'http://klucz@localhost:9000'] as $adres) {
            $this->assertNotNull(DozwolonyHostR2::powod($adres, srodowiskoLokalne: true), $adres);
        }

        // Środowisko bierzemy z aplikacji, gdy nikt go nie poda.
        $this->assertNull(DozwolonyHostR2::powod('https://przyklad.invalid'));
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->assertNotNull(DozwolonyHostR2::powod('https://przyklad.invalid'));
    }

    /**
     * Zły host → dysk `r2` się nie buduje, a klient S3 nie dostaje ani
     * jednego żądania. Komunikat nazywa zmienną i sam host, bez sekretów.
     */
    #[DataProvider('dyskiZSekretami')]
    public function test_zly_host_nie_buduje_dysku_i_nic_nie_wysyla(string $dysk, string $sterownik): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $zly = 'https://'.self::KLUCZ.':'.self::SEKRET.'@obcy-magazyn.example.com/sciezka';
        $this->ustawDysk($dysk, $sterownik, 'https://obcy-magazyn.example.com');

        try {
            Storage::disk($dysk)->put('proba.txt', 'tresc');
            $this->fail("Dysk `{$dysk}` zbudował się na obcym hoście.");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('AWS_ENDPOINT', $e->getMessage());
            $this->assertStringContainsString('obcy-magazyn.example.com', $e->getMessage());
            $this->assertStringContainsString('host spoza R2 w jurysdykcji UE', $e->getMessage());
        }

        $this->assertSame([], $this->wyslane, 'Żądanie z kluczem wyszło pod obcy host.');

        // Adres z userinfo i ścieżką: w komunikacie sam host — bez klucza, sekretu, ścieżki.
        try {
            DozwolonyHostR2::wymus($zly);
            $this->fail('Adres z userinfo przeszedł.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString(self::KLUCZ, $e->getMessage());
            $this->assertStringNotContainsString(self::SEKRET, $e->getMessage());
            $this->assertStringNotContainsString('sciezka', $e->getMessage());
        }
    }

    /** KONTROLA DODATNIA: dobry host buduje dysk i żądanie idzie DOKŁADNIE pod niego. */
    #[DataProvider('dyskiZSekretami')]
    public function test_dobry_host_buduje_dysk_i_wysyla_pod_niego(string $dysk, string $sterownik): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->ustawDysk($dysk, $sterownik, self::DOBRY);

        Storage::disk($dysk)->put('proba.txt', 'tresc');

        $this->assertCount(1, $this->wyslane);
        $this->assertSame('kuking-test.'.self::KONTO.'.eu.r2.cloudflarestorage.com', $this->wyslane[0]->getUri()->getHost());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function dyskiZSekretami(): array
    {
        return [
            'r2 (zdjęcia)' => ['r2', 'r2'],
            'r2_eksporty' => ['r2_eksporty', 'r2'],
            'r2_kopie (sterownik s3)' => ['r2_kopie', 's3'],
            's3' => ['s3', 's3'],
        ];
    }

    public function test_health_zly_host_magazynu_to_blad_bez_hosta(): void
    {
        $this->produkcja();
        config([
            'filesystems.disks.r2_eksporty.key' => self::KLUCZ,
            'filesystems.disks.r2_eksporty.endpoint' => 'https://'.self::KONTO.'.r2.cloudflarestorage.com',
        ]);

        $odpowiedz = $this->zdrowieZeSzczegolami();

        $odpowiedz->assertOk()
            ->assertJsonPath('checks.magazyn.ok', false)
            ->assertJsonPath('checks.magazyn.error', 'magazyn_r2_zly_host');

        $this->assertContains('magazyn_r2_zly_host', HealthController::POWODY);
        $this->assertStringNotContainsString(self::KONTO, $odpowiedz->getContent());
        $this->assertStringNotContainsString(self::KLUCZ, $odpowiedz->getContent());
    }

    /** Kontrola ujemna sondy: dobry host (albo produkcja bez R2) — sonda milczy. */
    public function test_health_dobry_host_magazynu_przechodzi(): void
    {
        $this->produkcja();
        $this->zdrowieZeSzczegolami()->assertJsonPath('checks.magazyn.ok', true);

        config([
            'filesystems.disks.r2.key' => self::KLUCZ,
            'filesystems.disks.r2.endpoint' => self::DOBRY,
        ]);
        $this->zdrowieZeSzczegolami()->assertJsonPath('checks.magazyn.ok', true);

        // Klucz bez adresu: AWS SDK poszedłby do Amazona.
        config(['filesystems.disks.r2.endpoint' => '']);
        $this->zdrowieZeSzczegolami()->assertJsonPath('checks.magazyn.error', 'magazyn_r2_zly_host');
    }

    private function produkcja(): void
    {
        Artisan::call('storage:link');
        $this->app->detectEnvironment(static fn (): string => 'production');

        // Punkt wyjścia: produkcja bez R2 — żaden dysk nie ma klucza ani
        // adresu (środowisko uruchomieniowe testów potrafi wstrzyknąć
        // `AWS_ACCESS_KEY_ID`; test nie może od tego zależeć).
        foreach ((array) config('filesystems.disks') as $nazwa => $dysk) {
            if (in_array($dysk['driver'] ?? null, ['r2', 's3'], true)) {
                config(["filesystems.disks.{$nazwa}.key" => null, "filesystems.disks.{$nazwa}.endpoint" => null]);
            }
        }
    }

    private function ustawDysk(string $dysk, string $sterownik, string $endpoint): void
    {
        config(["filesystems.disks.{$dysk}" => [
            'driver' => $sterownik,
            'key' => self::KLUCZ,
            'secret' => self::SEKRET,
            'region' => 'auto',
            'bucket' => 'kuking-test',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => false,
            'throw' => true,
            // Ostatnie ogniwo stosu AWS SDK — żądanie podpisane, bez sieci.
            'handler' => function (CommandInterface $polecenie, RequestInterface $zadanie): PromiseInterface {
                $this->wyslane[] = $zadanie;

                return Create::promiseFor(new Result(['ETag' => '"etag"']));
            },
        ]]);

        Storage::forgetDisk($dysk);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

/**
 * Osobny token R2 dla każdego bucketu (#617, D-257).
 *
 * Aplikacja musi kasować w bucketach zdjęć i paczek (usuwanie danych jest
 * natychmiastowe), więc prawa DeleteObject odebrać jej nie można. Można
 * zawęzić jego zasięg: jeden bucket — jeden token. Test ładuje świeży
 * `config/filesystems.php` przy podstawionych zmiennych, bo sprawdzamy
 * regułę wyboru pary, a nie stan środowiska testowego.
 */
class PoswiadczeniaBucketowR2Test extends TestCase
{
    private const ZMIENNE = [
        'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY',
        'AWS_ORIGINALS_ACCESS_KEY_ID', 'AWS_ORIGINALS_SECRET_ACCESS_KEY',
        'AWS_PUBLIC_ACCESS_KEY_ID', 'AWS_PUBLIC_SECRET_ACCESS_KEY',
        'AWS_LEGACY_ACCESS_KEY_ID', 'AWS_LEGACY_SECRET_ACCESS_KEY',
        'AWS_EXPORTS_ACCESS_KEY_ID', 'AWS_EXPORTS_SECRET_ACCESS_KEY',
        'AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID', 'AWS_ZDJECIA_KOPIA_SECRET_ACCESS_KEY',
    ];

    /** @var array<string, string|false> */
    private array $poprzednie = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ZMIENNE as $nazwa) {
            $this->poprzednie[$nazwa] = getenv($nazwa);
            $this->ustaw($nazwa, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->poprzednie as $nazwa => $wartosc) {
            $this->ustaw($nazwa, $wartosc === false ? null : $wartosc);
        }

        parent::tearDown();
    }

    private function ustaw(string $nazwa, ?string $wartosc): void
    {
        if ($wartosc === null) {
            unset($_ENV[$nazwa], $_SERVER[$nazwa]);
            putenv($nazwa);

            return;
        }

        $_ENV[$nazwa] = $wartosc;
        $_SERVER[$nazwa] = $wartosc;
        putenv("{$nazwa}={$wartosc}");
    }

    /** @return array<string, array<string, mixed>> */
    private function dyski(): array
    {
        return (require base_path('config/filesystems.php'))['disks'];
    }

    public function test_kontrola_dodatnia_bez_par_kazdy_bucket_bierze_wspolny_token(): void
    {
        // Dokładnie stan sprzed zmiany: nikt nie musi niczego ustawiać,
        // żeby aplikacja działała jak wczoraj.
        $this->ustaw('AWS_ACCESS_KEY_ID', 'wspolny-klucz');
        $this->ustaw('AWS_SECRET_ACCESS_KEY', 'wspolny-sekret');

        $dyski = $this->dyski();

        foreach (['r2', 'r2_publiczne', 'r2_legacy', 'r2_eksporty'] as $nazwa) {
            $this->assertSame('wspolny-klucz', $dyski[$nazwa]['key'], $nazwa);
            $this->assertSame('wspolny-sekret', $dyski[$nazwa]['secret'], $nazwa);
        }
    }

    public function test_para_bucketu_wygrywa_tylko_dla_swojego_bucketu(): void
    {
        $this->ustaw('AWS_ACCESS_KEY_ID', 'wspolny-klucz');
        $this->ustaw('AWS_SECRET_ACCESS_KEY', 'wspolny-sekret');
        $this->ustaw('AWS_ORIGINALS_ACCESS_KEY_ID', 'klucz-oryginalow');
        $this->ustaw('AWS_ORIGINALS_SECRET_ACCESS_KEY', 'sekret-oryginalow');
        $this->ustaw('AWS_EXPORTS_ACCESS_KEY_ID', 'klucz-paczek');
        $this->ustaw('AWS_EXPORTS_SECRET_ACCESS_KEY', 'sekret-paczek');

        $dyski = $this->dyski();

        $this->assertSame(['klucz-oryginalow', 'sekret-oryginalow'], [$dyski['r2']['key'], $dyski['r2']['secret']]);
        $this->assertSame(['klucz-paczek', 'sekret-paczek'], [$dyski['r2_eksporty']['key'], $dyski['r2_eksporty']['secret']]);
        $this->assertSame('wspolny-klucz', $dyski['r2_publiczne']['key']);
        $this->assertSame('wspolny-klucz', $dyski['r2_legacy']['key']);
    }

    public function test_polowa_pary_zatrzymuje_konfiguracje_z_nazwa_brakujacej_zmiennej(): void
    {
        // Klucz jednego tokenu z sekretem drugiego nie podpisze żądania,
        // a cichy powrót do wspólnego tokenu dałby złudzenie zawężenia.
        $this->ustaw('AWS_ACCESS_KEY_ID', 'wspolny-klucz');
        $this->ustaw('AWS_SECRET_ACCESS_KEY', 'wspolny-sekret');
        $this->ustaw('AWS_PUBLIC_ACCESS_KEY_ID', 'klucz-wariantow');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ustaw AWS_PUBLIC_SECRET_ACCESS_KEY');

        $this->dyski();
    }

    public function test_kopia_zdjec_nigdy_nie_dziedziczy_tokenu_aplikacji(): void
    {
        // Gdyby dysk kopii brał wspólny token, aplikacja miałaby w kopii to
        // samo prawo kasowania, co w oryginałach — i kopia przestałaby
        // chronić przed czymkolwiek. (Pusty klucz i tak kończy się sięgnięciem
        // SDK po zmienne środowiska — dlatego `kuking:sprawdz-kopie-zdjec`
        // odmawia pracy bez własnej pary; test w KopiaZdjecSprawdzanaTylkoOdczytemTest.)
        $this->ustaw('AWS_ACCESS_KEY_ID', 'wspolny-klucz');
        $this->ustaw('AWS_SECRET_ACCESS_KEY', 'wspolny-sekret');

        $kopia = $this->dyski()['r2_kopia_zdjec'];

        $this->assertNotSame('wspolny-klucz', $kopia['key']);
        $this->assertNotSame('wspolny-sekret', $kopia['secret']);
    }
}

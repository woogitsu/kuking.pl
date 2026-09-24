<?php

declare(strict_types=1);

namespace Tests\Feature;

use Livewire\Component;
use Livewire\Exceptions\LivewireReleaseTokenMismatchException;
use Livewire\Features\SupportReleaseTokens\ReleaseToken;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Issue #977: `livewire.release_token` stało na 'a', więc karta otwarta przed
 * wdrożeniem wysyłała migawkę komponentu ze starego kodu, a Livewire przyjmował
 * ją jak swoją. Token ma iść za wdrożonym commitem (RAILWAY_GIT_COMMIT_SHA —
 * ta sama zmienna co wersja w stopce), a bez niej mieć stały zapas.
 *
 * Plik konfiguracji czytamy na świeżo (`require`), bo `config()` w teście
 * trzyma wartość z chwili startu aplikacji — tak samo jak `config:cache`
 * w entrypoincie trzyma wartość z chwili startu kontenera.
 */
class LivewireReleaseTokenZWydaniaTest extends TestCase
{
    private const ZMIENNA = 'RAILWAY_GIT_COMMIT_SHA';

    #[Test]
    public function rozne_wydania_daja_rozne_tokeny(): void
    {
        $a = $this->tokenPrzy('1a4ab54c4141bccb6cce9e1947cbfb0a227e4894');
        $b = $this->tokenPrzy('9f0e1d2c3b4a59687766554433221100ffeeddcc');

        $this->assertSame('1a4ab54c4141bccb6cce9e1947cbfb0a227e4894', $a);
        $this->assertNotSame($a, $b);
    }

    #[Test]
    public function ten_sam_sha_zapisany_inaczej_daje_ten_sam_token(): void
    {
        $this->assertSame(
            $this->tokenPrzy('1a4ab54c4141bccb6cce9e1947cbfb0a227e4894'),
            $this->tokenPrzy("  1A4AB54C4141BCCB6CCE9E1947CBFB0A227E4894\n"),
        );
    }

    #[Test]
    public function bez_sha_token_jest_stalym_zapasem(): void
    {
        $this->assertSame('lokalnie', $this->tokenPrzy(null));
        $this->assertSame('lokalnie', $this->tokenPrzy(''));
        $this->assertSame($this->tokenPrzy(null), $this->tokenPrzy(null));
    }

    #[Test]
    public function migawka_ze_starego_wydania_jest_odrzucana(): void
    {
        // Prawdziwy mechanizm Livewire 4.4.3: migawka z `memo.release` wydanego
        // przez poprzednie wydanie trafia do `ReleaseToken::verify()` po
        // wdrożeniu nowego — i musi dostać ten sam wyjątek (419), który
        // Livewire rzuca przy każdym żądaniu karty sprzed wdrożenia.
        Livewire::component('kuking-test-token-wydania', KomponentTokenuWydania::class);

        config(['livewire.release_token' => $this->tokenPrzy('1a4ab54c4141bccb6cce9e1947cbfb0a227e4894')]);
        $migawka = $this->migawka(ReleaseToken::generate(KomponentTokenuWydania::class));

        // Kontrola dodatnia: w tym samym wydaniu migawka przechodzi — inaczej
        // wyjątek niżej mógłby brać się z czegokolwiek, nie z tokenu.
        ReleaseToken::verify($migawka);

        config(['livewire.release_token' => $this->tokenPrzy('9f0e1d2c3b4a59687766554433221100ffeeddcc')]);

        try {
            ReleaseToken::verify($migawka);
            $this->fail('Migawka ze starego wydania przeszła weryfikację tokenu.');
        } catch (LivewireReleaseTokenMismatchException $e) {
            $this->assertSame(419, $e->getStatusCode());
        }
    }

    /** @return array{memo: array<string, mixed>, data: array<string, mixed>} */
    private function migawka(string $release): array
    {
        return [
            'memo' => ['name' => 'kuking-test-token-wydania', 'id' => 'test-id', 'release' => $release],
            'data' => [],
        ];
    }

    private function tokenPrzy(?string $sha): mixed
    {
        $poprzednia = $_ENV[self::ZMIENNA] ?? null;

        unset($_ENV[self::ZMIENNA], $_SERVER[self::ZMIENNA]);
        putenv(self::ZMIENNA);

        if ($sha !== null) {
            $_ENV[self::ZMIENNA] = $sha;
            $_SERVER[self::ZMIENNA] = $sha;
            putenv(self::ZMIENNA.'='.$sha);
        }

        try {
            $swiezy = require base_path('config/livewire.php');

            return $swiezy['release_token'];
        } finally {
            unset($_ENV[self::ZMIENNA], $_SERVER[self::ZMIENNA]);
            putenv(self::ZMIENNA);

            if ($poprzednia !== null) {
                $_ENV[self::ZMIENNA] = $poprzednia;
                $_SERVER[self::ZMIENNA] = $poprzednia;
                putenv(self::ZMIENNA.'='.$poprzednia);
            }
        }
    }
}

class KomponentTokenuWydania extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}

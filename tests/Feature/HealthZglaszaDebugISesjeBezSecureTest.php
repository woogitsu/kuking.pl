<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Produkcja z `APP_DEBUG=true` albo z ciasteczkiem sesji bez `Secure`
 * melduje `degraded` na `/health` (audyt B10-04) — wzorzec `turnstile_bez_kluczy`.
 *
 * Poprawne wartości ustawiał wyłącznie `.railway/railway.ts`. Serwis założony
 * ręcznie w panelu albo z `.env.example` wystawiał ślady stosu z sekretami
 * albo sesję po HTTP, a `/health` o tym milczał.
 */
class HealthZglaszaDebugISesjeBezSecureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function produkcja_z_debugiem_melduje_degraded_ale_nie_503(): void
    {
        $this->produkcja(debug: true, secure: true);

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.debug.ok', false)
            ->assertJsonPath('checks.debug.error', 'debug_wlaczony')
            ->assertJsonPath('checks.sesja.ok', true);
    }

    #[Test]
    public function produkcja_z_sesja_bez_secure_melduje_degraded(): void
    {
        $this->produkcja(debug: false, secure: false);

        $this->zdrowieZeSzczegolami()
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.sesja.ok', false)
            ->assertJsonPath('checks.sesja.error', 'sesja_bez_secure')
            ->assertJsonPath('checks.debug.ok', true);
    }

    #[Test]
    public function produkcja_z_poprawna_konfiguracja_nie_zglasza_niczego(): void
    {
        // Kontrola dodatnia: obie kontrole potrafią przejść.
        $this->produkcja(debug: false, secure: true);

        $this->zdrowieZeSzczegolami()
            ->assertJsonPath('checks.debug.ok', true)
            ->assertJsonPath('checks.sesja.ok', true);
    }

    #[Test]
    public function poza_produkcja_debug_i_sesja_po_http_to_stan_normalny(): void
    {
        config(['app.debug' => true, 'session.secure' => false]);

        $this->zdrowieZeSzczegolami()
            ->assertJsonPath('checks.debug.ok', true)
            ->assertJsonPath('checks.sesja.ok', true);
    }

    #[Test]
    public function na_produkcji_brak_zmiennej_daje_ciasteczko_secure(): void
    {
        $this->assertTrue($this->secureZKonfiguracji('production', null), 'Produkcja bez SESSION_SECURE_COOKIE wystawia sesję bez Secure.');
        // Jawna wartość dalej wygrywa, a poza produkcją domyślnie bez Secure
        // (lokalnie chodzi się po http://).
        $this->assertFalse($this->secureZKonfiguracji('production', 'false'));
        $this->assertFalse($this->secureZKonfiguracji('local', null));
    }

    private function produkcja(bool $debug, bool $secure): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config(['app.debug' => $debug, 'session.secure' => $secure]);
    }

    /**
     * Czyta `config/session.php` przy podanych zmiennych procesu — tak, jak
     * zrobi to kontener na starcie — i przywraca je po sobie.
     */
    private function secureZKonfiguracji(string $srodowisko, ?string $zmienna): bool
    {
        $nazwy = ['APP_ENV' => $srodowisko, 'SESSION_SECURE_COOKIE' => $zmienna];
        $zapisane = [];
        foreach (array_keys($nazwy) as $nazwa) {
            $zapisane[$nazwa] = [$_ENV[$nazwa] ?? null, $_SERVER[$nazwa] ?? null, getenv($nazwa)];
        }

        try {
            foreach ($nazwy as $nazwa => $wartosc) {
                $this->ustaw($nazwa, $wartosc, $wartosc, $wartosc);
            }

            return (bool) (require base_path('config/session.php'))['secure'];
        } finally {
            foreach ($zapisane as $nazwa => [$env, $server, $getenv]) {
                $this->ustaw($nazwa, $env, $server, $getenv === false ? null : $getenv);
            }
        }
    }

    private function ustaw(string $nazwa, ?string $env, ?string $server, ?string $getenv): void
    {
        if ($env === null) {
            unset($_ENV[$nazwa]);
        } else {
            $_ENV[$nazwa] = $env;
        }

        if ($server === null) {
            unset($_SERVER[$nazwa]);
        } else {
            $_SERVER[$nazwa] = $server;
        }

        putenv($getenv === null ? $nazwa : "{$nazwa}={$getenv}");
    }
}

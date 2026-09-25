<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audyt B10-02 — `@railway/cli` instalowany bez wersji, obok tokenu
 * produkcji/staginu.
 *
 * (a) `npm install -g @railway/cli` (bez `@X.Y.Z`) bierze cokolwiek jest
 * akurat na `latest` w chwili uruchomienia — bez locka, poza Dependabotem,
 * w jobie, który zaraz potem woła Railway z tokenem środowiska. Przejęta
 * albo zła wersja pakietu wykonuje się z tym tokenem w środowisku.
 *
 * (b) Token stał w `env:` na poziomie CAŁEGO JOBA, więc widziały go też
 * kroki, które go nie potrzebują — w tym właśnie instalacja pakietu z npm.
 * Token ma dostawać WYŁĄCZNIE krok, który faktycznie woła `railway`.
 *
 * Test czyta pliki workflow — GitHub Actions nie da się uruchomić z testu.
 */
class RailwayCliPrzypietaWersjaTest extends TestCase
{
    private const PLIKI = [
        '.github/workflows/deploy.yml',
        '.github/workflows/preview.yml',
    ];

    private function tresc(string $sciezka): string
    {
        $pelna = base_path($sciezka);

        $this->assertFileExists($pelna, "Nie ma {$sciezka}. Jeśli plik przeniesiono, popraw ścieżkę tutaj.");

        return (string) file_get_contents($pelna);
    }

    #[Test]
    public function kazda_instalacja_railway_cli_ma_przypieta_wersje(): void
    {
        foreach (self::PLIKI as $plik) {
            $tresc = $this->tresc($plik);
            $wiersze = preg_split('/\r\n|\n|\r/', $tresc) ?: [];

            foreach ($wiersze as $numer => $wiersz) {
                $bezKomentarza = preg_replace('/#.*$/', '', $wiersz) ?? '';

                if (! str_contains($bezKomentarza, '@railway/cli')) {
                    continue;
                }

                // dopuszczalne: @railway/cli@5.62.1 (albo dowolna inna dokładna
                // wersja semver) — niedopuszczalne: samo @railway/cli.
                $this->assertMatchesRegularExpression(
                    '/@railway\/cli@\d+\.\d+\.\d+/',
                    $bezKomentarza,
                    "{$plik}:".($numer + 1)." — `@railway/cli` bez przypiętej wersji. ".
                    'Bez `@X.Y.Z` npm instaluje `latest` w chwili uruchomienia, obok tokenu Railway. '.
                    'Przypnij: `npm install -g @railway/cli@<x.y.z>` (audyt B10-02).',
                );
            }
        }
    }

    #[Test]
    public function railway_token_nie_stoi_na_poziomie_calego_joba(): void
    {
        // W tych plikach klucze joba (`runs-on:`, `env:`, `steps:`, …) mają
        // DOKŁADNIE 4 spacje wcięcia; `env:` kroku, zagnieżdżony pod
        // `- name: …` w liście `steps:`, ma wcięcie większe (8 spacji).
        // Test celowo sprawdza wcięcie `env:` joba PRECYZYJNIE (4 spacje),
        // żeby nie pomylić go z krokiem — jeśli kiedyś zmieni się styl
        // wcięć w tych plikach, popraw stałą niżej razem z testem.
        $wciecieEnvJoba = 4;

        foreach (self::PLIKI as $plik) {
            $tresc = $this->tresc($plik);
            $wiersze = preg_split('/\r\n|\n|\r/', $tresc) ?: [];

            $wJobowymEnv = false;

            foreach ($wiersze as $numer => $wiersz) {
                if (preg_match('/^( *)env:\s*$/', $wiersz, $m)) {
                    $wJobowymEnv = strlen($m[1]) === $wciecieEnvJoba;

                    continue;
                }

                if (! $wJobowymEnv) {
                    continue;
                }

                $obecneWciecie = strlen($wiersz) - strlen(ltrim($wiersz));

                // Pusta linia nie kończy bloku; cokolwiek na wcięciu <= 4
                // (kolejny klucz joba albo koniec pliku) — kończy.
                if (trim($wiersz) === '') {
                    continue;
                }

                if ($obecneWciecie <= $wciecieEnvJoba) {
                    $wJobowymEnv = false;

                    continue;
                }

                $this->assertStringNotContainsString(
                    'RAILWAY_TOKEN:',
                    $wiersz,
                    "{$plik}:".($numer + 1).' — RAILWAY_TOKEN w `env:` na poziomie CAŁEGO JOBA. '.
                    'Token ma dostawać wyłącznie krok, który woła `railway` — inaczej widzą go też '.
                    'checkout, setup-node i instalacja CLI z npm, które go nie potrzebują (audyt B10-02).',
                );
            }
        }
    }
}

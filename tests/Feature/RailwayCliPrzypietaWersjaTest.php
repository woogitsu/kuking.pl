<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Audyt B10-02 — Railway CLI instalowany bez wersji, obok tokenu
 * produkcji/staginu; dopełnienie audytu — issue #1865 (suma kontrolna).
 *
 * (a) `npm install -g @railway/cli` (bez `@X.Y.Z`) bierze cokolwiek jest
 * akurat na `latest` w chwili uruchomienia — bez locka, poza Dependabotem,
 * w jobie, który zaraz potem woła Railway z tokenem środowiska. Przejęta
 * albo zła wersja pakietu wykonuje się z tym tokenem w środowisku.
 *
 * (b) Token stał w `env:` na poziomie CAŁEGO JOBA, więc widziały go też
 * kroki, które go nie potrzebują — w tym właśnie instalacja CLI. Token ma
 * dostawać WYŁĄCZNIE krok, który faktycznie woła `railway`.
 *
 * (c) SAM NUMER WERSJI PAKIETU NPM TO NIE BYŁA CAŁA OCHRONA (issue #1865).
 * `@railway/cli` w `postinstall.js` i tak tylko POBIERA prawdziwą binarkę
 * z GitHub Releases pod adresem zbudowanym z numeru wersji — i robi to bez
 * ŻADNEJ weryfikacji sumy kontrolnej (sprawdzone w źródle paczki, 26.09.2026:
 * `npm-install/postinstall.js` nie ma tam `sha256`/`integrity`). To właśnie
 * ta binarka, nie sam pakiet npm, dostaje potem `RAILWAY_TOKEN`. Instalacja
 * idzie więc dziś wprost z GitHub Releases (`curl` + `sha256sum -c`), bez
 * npm i bez Node'a — a ten test pilnuje, żeby WERSJA i SUMA KONTROLNA były
 * jawnymi zmiennymi środowiskowymi i żeby suma naprawdę była SPRAWDZANA
 * przed użyciem pobranego pliku, nie tylko obliczana i olewana.
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

    /**
     * @return list<array{plik: string, blok: string}>
     */
    private function krokiInstalacjiCli(): array
    {
        $znalezione = [];

        foreach (self::PLIKI as $plik) {
            $tresc = $this->tresc($plik);

            // Krok zaczyna się od `- name: Instalacja Railway CLI` i trwa aż
            // do następnego `- name:` na tym samym wcięciu (albo końca pliku).
            $liczbaZnaleziona = preg_match_all(
                '/^( *)- name: Instalacja Railway CLI\n(?:(?:\1 .*)?\n)*/m',
                $tresc,
                $dopasowania,
            );

            $this->assertGreaterThan(0, $liczbaZnaleziona, "{$plik} nie ma ani jednego kroku „Instalacja Railway CLI” — test stracił przedmiot.");

            foreach ($dopasowania[0] as $blok) {
                $znalezione[] = ['plik' => $plik, 'blok' => $blok];
            }
        }

        return $znalezione;
    }

    #[Test]
    public function kazda_instalacja_ma_przypieta_wersje_i_sume_kontrolna_ktora_naprawde_jest_sprawdzana(): void
    {
        $kroki = $this->krokiInstalacjiCli();

        $this->assertGreaterThanOrEqual(3, count($kroki), 'Spodziewane trzy kroki instalacji Railway CLI (deploy.yml + 2× preview.yml) — test stracił przedmiot.');

        foreach ($kroki as $krok) {
            $gdzie = $krok['plik'];
            $blok = $krok['blok'];

            // (a) numer wersji jawną zmienną środowiskową, dokładny semver —
            // nie `latest`, nie zakres, nie coś wyliczane w locie.
            $this->assertMatchesRegularExpression(
                '/RAILWAY_CLI_VERSION:\s*["\']?\d+\.\d+\.\d+["\']?/',
                $blok,
                "{$gdzie}: krok „Instalacja Railway CLI” nie ustawia `RAILWAY_CLI_VERSION` na dokładną wersję (audyt B10-02).",
            );

            // (b) suma kontrolna jawną zmienną, pełny SHA-256 (64 znaki hex) —
            // nie skrócona, nie pusta.
            $this->assertMatchesRegularExpression(
                '/RAILWAY_CLI_SHA256:\s*["\']?[0-9a-f]{64}["\']?/',
                $blok,
                "{$gdzie}: krok „Instalacja Railway CLI” nie ustawia pełnej sumy `RAILWAY_CLI_SHA256` (issue #1865).",
            );

            // (c) suma kontrolna NAPRAWDĘ jest sprawdzana przed użyciem pliku —
            // `sha256sum -c` odczytujący zmienną z (b), nie samo jej ustawienie
            // gdzieś w `env:` bez żadnego `sha256sum` w treści kroku.
            $this->assertMatchesRegularExpression(
                '/sha256sum -c/',
                $blok,
                "{$gdzie}: krok „Instalacja Railway CLI” ma `RAILWAY_CLI_SHA256`, ale nigdzie jej nie sprawdza (`sha256sum -c`) — ".
                'zmienna z sumą kontrolną, która niczego nie chroni, jest tym samym co jej brak (issue #1865).',
            );

            $this->assertStringContainsString(
                '${RAILWAY_CLI_SHA256}',
                $blok,
                "{$gdzie}: krok „Instalacja Railway CLI” sprawdza jakąś sumę, ale nie tę z `RAILWAY_CLI_SHA256`.",
            );

            // (d) instalacja idzie dziś wprost z GitHub Releases, nie przez
            // `npm install -g` — paczka npm pobiera tę samą binarkę BEZ
            // weryfikacji sumy kontrolnej (patrz opis klasy), więc powrót do
            // npm cichcem znosiłby ochronę z (b) i (c). Komentarze WYJAŚNIAJĄCE
            // tę historię (w treści kroku wspominają starą komendę) nie mają
            // się liczyć — liczy się tylko to, co NAPRAWDĘ wykona `run:`.
            $bezKomentarzy = implode("\n", array_filter(
                explode("\n", $blok),
                static fn (string $wiersz): bool => ! str_starts_with(ltrim($wiersz), '#'),
            ));

            $this->assertDoesNotMatchRegularExpression(
                '/npm install (-g|--global)/',
                $bezKomentarzy,
                "{$gdzie}: krok „Instalacja Railway CLI” znowu woła `npm install -g` — pakiet npm pobiera binarkę bez sumy kontrolnej (issue #1865), więc to cofa naprawę nawet z przypiętym numerem wersji.",
            );
        }
    }

    /**
     * KONTROLA UJEMNA klasyfikatora bloków: syntetyczny krok bez `sha256sum -c`
     * (suma ustawiona, ale nigdy nie sprawdzana) ma OBLEWAĆ — inaczej test
     * wyżej przechodziłby też przy pustej ochronie (pułapka 2/4).
     */
    #[Test]
    public function klasyfikator_wylapuje_sume_ktora_jest_tylko_ustawiona_a_nie_sprawdzana(): void
    {
        $env = 'RAILWAY_CLI_VERSION: "5.62.1"'."\n".'          RAILWAY_CLI_SHA256: "'.str_repeat('a', 64).'"';
        $blokZlamany = "        - name: Instalacja Railway CLI\n          env:\n            {$env}\n          run: |\n            curl -fsSL -o /tmp/r.tgz https://example.test/r.tgz\n            tar -xzf /tmp/r.tgz\n";

        $this->assertDoesNotMatchRegularExpression('/sha256sum -c/', $blokZlamany, 'Fixture kontroli ujemnej ma NIE zawierać weryfikacji — inaczej nic nie testuje.');
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

<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #952 — każdy obraz bazowy jest przypięty do digestu i objęty Dependabotem.
 *
 * `FROM node:22-bookworm-slim` pobiera to, co rejestr AKURAT pod tym tagiem
 * trzyma. Tag jest ruchomy: ten sam Dockerfile zbudowany w poniedziałek
 * i w piątek daje dwa różne obrazy produkcyjne, a przejęty albo pomylony tag
 * trafia na produkcję bez śladu w repozytorium. Digest (`@sha256:...`)
 * wskazuje dokładnie jeden obraz; zmienić go może tylko commit.
 *
 * Przypięcie bez Dependabota to zamrożenie — łatki bezpieczeństwa obrazów
 * przestają dochodzić. Dlatego test pilnuje obu połówek naraz.
 *
 * Czego ten test NIE sprawdza: czy digest ISTNIEJE w rejestrze. To wie tylko
 * rejestr, więc sprawdza to job „Build obrazu” w CI, który pobiera obrazy.
 */
final class ObrazyBazowePrzypieteDoDigestowTest extends TestCase
{
    private const DIGEST = '/@sha256:[0-9a-f]{64}$/';

    public function test_kazdy_from_w_kazdym_dockerfile_ma_digest(): void
    {
        $bezDigestu = [];
        $obrazow = 0;

        foreach (self::dockerfile() as $plik) {
            foreach (self::obrazyZPliku($plik) as [$linia, $obraz]) {
                $obrazow++;

                if (preg_match(self::DIGEST, $obraz) !== 1) {
                    $bezDigestu[] = self::wzgledna($plik).":$linia — $obraz";
                }
            }
        }

        // Parser, który nic nie znajduje, przepuszcza wszystko. Obecnie są
        // cztery obrazy zewnętrzne (node, composer, frankenphp, postgres).
        $this->assertGreaterThanOrEqual(4, $obrazow, 'Test przestał widzieć obrazy w liniach FROM — stracił przedmiot.');

        $this->assertSame([], $bezDigestu, implode("\n", [
            'Obraz bazowy bez digestu — tag jest ruchomy, więc build nie jest powtarzalny.',
            'Dopisz `@sha256:<digest>` za tagiem (tag zostaje), np.:',
            '  docker buildx imagetools inspect <obraz:tag>   # pole „Digest”',
            'Bez digestu:',
            ...$bezDigestu,
        ]));
    }

    public function test_copy_from_wskazuje_etap_a_nie_obraz_z_rejestru(): void
    {
        // Dependabot podbija digesty w liniach FROM. Obraz wpisany wprost
        // w `COPY --from=` jest dla niego niewidoczny — digest starzałby się
        // po cichu. Obraz z rejestru ma więc wejść przez własny etap `FROM ... AS`.
        $obce = [];

        foreach (self::dockerfile() as $plik) {
            $etapy = [];

            foreach (self::linie($plik) as $numer => $linia) {
                if (preg_match('/^FROM\s+\S+\s+AS\s+(\S+)/i', $linia, $m) === 1) {
                    $etapy[strtolower($m[1])] = true;
                }

                if (preg_match('/^COPY\s+(?:--\S+\s+)*--from=(\S+)/i', $linia, $m) === 1
                    && ! isset($etapy[strtolower($m[1])])) {
                    $obce[] = self::wzgledna($plik).':'.($numer + 1)." — --from={$m[1]}";
                }
            }
        }

        $this->assertSame([], $obce, "`COPY --from=` wskazuje obraz zamiast etapu:\n".implode("\n", $obce));
    }

    public function test_dependabot_obejmuje_katalog_kazdego_dockerfile(): void
    {
        $konfiguracja = (string) file_get_contents(self::korzen().'/.github/dependabot.yml');

        // Wpis `docker` od `package-ecosystem: "docker"` do następnego wpisu.
        $this->assertSame(
            1,
            preg_match('/package-ecosystem:\s*"docker"(.*?)(?=\n\s*- package-ecosystem:|\z)/s', $konfiguracja, $wpis),
            'Brak ekosystemu `docker` w .github/dependabot.yml — przypięte digesty nigdy by się nie odświeżyły.',
        );
        preg_match_all('/^\s*-\s*"([^"]+)"\s*$/m', $wpis[1], $katalogi);

        foreach (self::dockerfile() as $plik) {
            $katalog = '/'.ltrim(str_replace(self::korzen(), '', dirname($plik)), '/');

            $this->assertContains(
                $katalog,
                $katalogi[1],
                "Dependabot (`docker`) nie obejmuje katalogu `$katalog`, a leży w nim ".self::wzgledna($plik).'.',
            );
        }
    }

    /** @return list<string> */
    private static function dockerfile(): array
    {
        $pliki = array_merge(
            glob(self::korzen().'/Dockerfile*') ?: [],
            glob(self::korzen().'/docker/*/Dockerfile*') ?: [],
        );

        if ($pliki === []) {
            self::fail('Nie znaleziono żadnego Dockerfile — test stracił przedmiot.');
        }

        return $pliki;
    }

    /**
     * Obrazy z rejestru w liniach FROM: bez odwołań do wcześniejszych etapów
     * (`FROM vendor`) i bez `scratch`, który nie jest pobierany.
     *
     * @return list<array{int, string}>
     */
    private static function obrazyZPliku(string $plik): array
    {
        $etapy = ['scratch' => true];
        $obrazy = [];

        foreach (self::linie($plik) as $numer => $linia) {
            if (preg_match('/^FROM\s+(?:--platform=\S+\s+)?(\S+)(?:\s+AS\s+(\S+))?/i', $linia, $m) !== 1) {
                continue;
            }

            if (! isset($etapy[strtolower($m[1])])) {
                $obrazy[] = [$numer + 1, $m[1]];
            }

            if (isset($m[2])) {
                $etapy[strtolower($m[2])] = true;
            }
        }

        return $obrazy;
    }

    /** @return array<int, string> */
    private static function linie(string $plik): array
    {
        return array_map('trim', file($plik, FILE_IGNORE_NEW_LINES) ?: []);
    }

    private static function wzgledna(string $plik): string
    {
        return ltrim(str_replace(self::korzen(), '', $plik), '/');
    }

    private static function korzen(): string
    {
        return dirname(__DIR__, 2);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regresja #1085 — etap `assets` w Dockerfile MUSI mieć każdy plik, który
 * `npm run build` podaje do `node --test`.
 *
 * Dlaczego to nie jest sprawdzane samym budowaniem obrazu: Node, dostając
 * do `--test` ścieżkę, której nie ma, po prostu ją POMIJA i kończy zerem.
 * Brakujący plik nie robi więc czerwieni — robi cichy ubytek pokrycia.
 * Tak było z `scripts/panel-komunikat.test.mjs`: obraz budował się zielono,
 * uruchamiając 21 zamiast 33 testów.
 *
 * Test czyta OBA prawdziwe pliki (`package.json`, `Dockerfile`), więc pilnuje
 * także każdego przyszłego pliku dopisanego do `build` bez kopiowania go
 * do obrazu.
 */
final class ObrazAssetowMaPlikiTestowTest extends TestCase
{
    public function test_kazdy_plik_z_node_test_jest_kopiowany_do_etapu_assets(): void
    {
        $korzen = dirname(__DIR__, 2);

        $pliki = self::plikiPodaneDoNodeTest($korzen.'/package.json');
        $this->assertNotEmpty($pliki, 'Polecenie `build` przestało wołać `node --test` — test stracił przedmiot.');

        $kopiowane = self::sciezkiKopiowaneWEtapieAssets($korzen.'/Dockerfile');

        foreach ($pliki as $plik) {
            $this->assertFileExists($korzen.'/'.$plik, "`package.json` woła nieistniejący plik testowy: $plik");
            $this->assertTrue(
                self::czyKopiowany($plik, $kopiowane),
                "Etap `assets` w Dockerfile nie kopiuje `$plik`, a `npm run build` podaje go do `node --test`. ".
                'Node pomija nieistniejący plik bez błędu, więc obraz zbudowałby się zielono z mniejszą liczbą testów.',
            );
        }
    }

    /**
     * Lista plików z pierwszego `node --test …` w poleceniu `build`.
     *
     * @return list<string>
     */
    private static function plikiPodaneDoNodeTest(string $packageJson): array
    {
        $tresc = (string) file_get_contents($packageJson);
        /** @var array{scripts?: array<string, string>} $dane */
        $dane = (array) json_decode($tresc, true, 512, JSON_THROW_ON_ERROR);
        $build = $dane['scripts']['build'] ?? '';

        $pliki = [];

        foreach (explode('&&', $build) as $czlon) {
            $czlon = trim($czlon);

            if (! str_starts_with($czlon, 'node --test ')) {
                continue;
            }

            foreach (preg_split('/\s+/', substr($czlon, strlen('node --test '))) ?: [] as $argument) {
                if ($argument !== '' && ! str_starts_with($argument, '-')) {
                    $pliki[] = $argument;
                }
            }
        }

        return $pliki;
    }

    /**
     * Źródła wszystkich `COPY` w etapie `assets` (bez `COPY --from=…`, który
     * bierze z innego obrazu, a nie z repozytorium).
     *
     * @return list<string>
     */
    private static function sciezkiKopiowaneWEtapieAssets(string $dockerfile): array
    {
        $linie = file($dockerfile, FILE_IGNORE_NEW_LINES) ?: [];
        $wEtapie = false;
        $zrodla = [];

        foreach ($linie as $linia) {
            if (preg_match('/^FROM\s+\S+\s+AS\s+(\S+)/i', trim($linia), $dopasowanie) === 1) {
                $wEtapie = strtolower($dopasowanie[1]) === 'assets';

                continue;
            }

            if (! $wEtapie || preg_match('/^COPY\s+(.*)$/i', trim($linia), $dopasowanie) !== 1) {
                continue;
            }

            $argumenty = preg_split('/\s+/', trim($dopasowanie[1])) ?: [];

            if ($argumenty !== [] && str_starts_with($argumenty[0], '--')) {
                continue;
            }

            // Ostatni argument to cel wewnątrz obrazu, reszta to źródła.
            array_pop($argumenty);

            foreach ($argumenty as $zrodlo) {
                $zrodla[] = ltrim($zrodlo, './');
            }
        }

        return $zrodla;
    }

    /** @param list<string> $kopiowane */
    private static function czyKopiowany(string $plik, array $kopiowane): bool
    {
        foreach ($kopiowane as $zrodlo) {
            if ($zrodlo === $plik || str_starts_with($plik, rtrim($zrodlo, '/').'/')) {
                return true;
            }
        }

        return false;
    }
}

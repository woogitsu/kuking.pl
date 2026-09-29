<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2009 — Dependabot ma PROPONOWAĆ duże (major) aktualizacje Composera i npm
 * osobnym, nieugrupowanym PR-em, a nie je przemilczać.
 *
 * CO BYŁO NIE TAK
 * Komentarz w `.github/dependabot.yml` zapowiadał, że o major Dependabot
 * „ma PYTAĆ osobnym, nieugrupowanym PR-em”, a tuż pod nim stał wpis
 * `ignore: dependency-name: "*"` z `version-update:semver-major`. Taki wpis
 * nie wydziela majorów do osobnego PR-a — wyłącza je z aktualizacji wersji
 * całkowicie. Nikt nie dostawał sygnału, że np. `intervention/image` 4 albo
 * `phpunit/phpunit` 13 już są.
 *
 * JAK MA BYĆ (dokumentacja GitHuba, opcja `groups`)
 * „Any outdated dependencies that do not match a rule are updated in
 * individual pull requests.” Grupa z `update-types: [minor, patch]` nie
 * pasuje do aktualizacji major, więc major idzie własnym PR-em. Wystarczy:
 *   1. żadnego wildcardowego `ignore` dla semver-major,
 *   2. każda grupa ma jawne `update-types` wyłącznie z minor/patch
 *      (grupa BEZ `update-types` obejmuje wszystkie poziomy, w tym major).
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że Dependabot na GitHubie faktycznie otworzy PR — to wie tylko GitHub.
 * Pilnuje strony, którą trzymamy w repozytorium. Ekosystemu `docker` nie
 * dotyczy: tam majory ignorujemy celowo (postgres musi zgadzać się
 * z serwerem na Railwayu — komentarz przy wpisie).
 *
 * Parser YAML jest tu własny i celowo mały (mapy, listy blokowe, listy
 * `["a", "b"]`, cudzysłowy, komentarze), bo w vendorze nie ma symfony/yaml,
 * a dopisywanie zależności do composer.json dla jednego strażnika to
 * nieproporcjonalny koszt. Plik, którego nie umie przeczytać, oblewa test.
 *
 * @bez-kontroli-dodatniej Kontrola ujemna jest w samym teście: te same asercje biegną na zmutowanej w pamięci konfiguracji (przywrócony wildcard ignore, grupa bez update-types) i muszą oblać, a plik, którego parser nie przeczyta, daje czerwień, nie cichą zieleń.
 */
final class DependabotProponujeMajorOsobnoTest extends TestCase
{
    private const EKOSYSTEMY = ['composer', 'npm'];

    private const DOZWOLONE_W_GRUPIE = ['minor', 'patch'];

    #[Test]
    public function composer_i_npm_nie_ignoruja_major_a_grupy_obejmuja_tylko_minor_i_patch(): void
    {
        $bledy = self::naruszenia((string) file_get_contents(self::sciezka()));

        $this->assertSame([], $bledy, implode("\n", [
            '.github/dependabot.yml przestał proponować major osobnym PR-em (#2009):',
            ...$bledy,
        ]));
    }

    #[Test]
    public function przywrocony_wildcard_ignore_semver_major_oblewa(): void
    {
        // Kontrola ujemna na kopii w pamięci: dokładnie ten wpis, który był
        // przed #2009, dopisany z powrotem do bloku Composera.
        $tresc = (string) file_get_contents(self::sciezka());
        $zepsuta = preg_replace(
            '/(- package-ecosystem: "composer"\n)/',
            "$1    ignore:\n      - dependency-name: \"*\"\n        update-types: [\"version-update:semver-major\"]\n",
            $tresc,
            1,
            $ile,
        );
        $this->assertSame(1, $ile, 'Nie znalazłem bloku Composera — kontrola ujemna straciła przedmiot.');

        $bledy = self::naruszenia((string) $zepsuta);

        $this->assertNotSame([], $bledy);
        $this->assertStringContainsString('composer', implode("\n", $bledy));
        $this->assertStringContainsString('ignore', implode("\n", $bledy));
    }

    #[Test]
    public function wildcard_ignore_bez_update_types_oblewa_bo_wylacza_tez_major(): void
    {
        $tresc = (string) file_get_contents(self::sciezka());
        $zepsuta = preg_replace(
            '/(- package-ecosystem: "npm"\n)/',
            "$1    ignore:\n      - dependency-name: \"*\"\n",
            $tresc,
            1,
            $ile,
        );
        $this->assertSame(1, $ile, 'Nie znalazłem bloku npm — kontrola ujemna straciła przedmiot.');

        $bledy = implode("\n", self::naruszenia((string) $zepsuta));

        $this->assertStringContainsString('npm: ignore', $bledy);
    }

    #[Test]
    public function grupa_bez_update_types_oblewa_bo_wciagnelaby_major(): void
    {
        $tresc = (string) file_get_contents(self::sciezka());
        $zepsuta = preg_replace(
            '/(\n      npm-minor-i-patch:\n)\s*update-types:\n\s*- "minor"\n\s*- "patch"\n/',
            "$1        patterns:\n          - \"*\"\n",
            $tresc,
            1,
            $ile,
        );
        $this->assertSame(1, $ile, 'Nie znalazłem grupy npm-minor-i-patch — kontrola ujemna straciła przedmiot.');

        $bledy = implode("\n", self::naruszenia((string) $zepsuta));

        $this->assertStringContainsString('npm-minor-i-patch', $bledy);
    }

    #[Test]
    public function parser_czyta_konfiguracje_zgodnie_z_oczekiwaniami(): void
    {
        // Parser, który nic nie widzi, przepuszcza wszystko.
        $konfiguracja = self::yaml((string) file_get_contents(self::sciezka()));

        $this->assertSame(2, $konfiguracja['version'] ?? null);
        $this->assertIsArray($konfiguracja['updates'] ?? null);

        $composer = self::wpis($konfiguracja, 'composer');
        $this->assertSame(['minor', 'patch'], $composer['groups']['composer-minor-i-patch']['update-types'] ?? null);
        $this->assertSame(['laravel/*'], $composer['groups']['composer-minor-i-patch']['exclude-patterns'] ?? null);

        $docker = self::wpis($konfiguracja, 'docker');
        $this->assertSame(
            [['dependency-name' => '*', 'update-types' => ['version-update:semver-major']]],
            $docker['ignore'] ?? null,
            'Wpis docker ma ignorować major celowo — patrz komentarz w dependabot.yml.',
        );
    }

    /** @return list<string> */
    private static function naruszenia(string $tresc): array
    {
        $konfiguracja = self::yaml($tresc);
        $bledy = [];

        foreach (self::EKOSYSTEMY as $ekosystem) {
            $wpis = self::wpis($konfiguracja, $ekosystem);

            if (($wpis['schedule']['interval'] ?? null) !== 'weekly') {
                $bledy[] = "$ekosystem: harmonogram ma zostać cotygodniowy (schedule.interval: weekly).";
            }

            if (! is_int($wpis['open-pull-requests-limit'] ?? null)) {
                $bledy[] = "$ekosystem: brak open-pull-requests-limit — majory bez limitu mogą zalać kolejkę.";
            }

            foreach ((array) ($wpis['ignore'] ?? []) as $regula) {
                $nazwa = (string) ($regula['dependency-name'] ?? '');
                $typy = (array) ($regula['update-types'] ?? []);

                // Wildcard bez `update-types` wyłącza wszystkie aktualizacje, więc też major.
                if (str_contains($nazwa, '*') && ($typy === [] || in_array('version-update:semver-major', $typy, true))) {
                    $bledy[] = "$ekosystem: ignore `$nazwa` z version-update:semver-major wyłącza WSZYSTKIE PR-y major, "
                        .'zamiast wysłać je osobno. Usuń ten wpis; major i tak nie trafi do grupy minor/patch.';
                }
            }

            $grupy = $wpis['groups'] ?? null;

            if (! is_array($grupy) || $grupy === []) {
                $bledy[] = "$ekosystem: brak grup — minor i patch miały iść jednym PR-em tygodniowo.";

                continue;
            }

            foreach ($grupy as $nazwa => $grupa) {
                $typy = $grupa['update-types'] ?? null;

                if (! is_array($typy) || $typy === []) {
                    $bledy[] = "$ekosystem: grupa `$nazwa` nie ma update-types, więc obejmuje też major. "
                        .'Dopisz update-types: [minor, patch].';

                    continue;
                }

                $obce = array_values(array_diff($typy, self::DOZWOLONE_W_GRUPIE));

                if ($obce !== []) {
                    $bledy[] = "$ekosystem: grupa `$nazwa` obejmuje ".implode(', ', $obce)
                        .' — major ma przychodzić osobnym PR-em, nie w grupie.';
                }
            }
        }

        return $bledy;
    }

    /**
     * @param  array<string, mixed>  $konfiguracja
     * @return array<string, mixed>
     */
    private static function wpis(array $konfiguracja, string $ekosystem): array
    {
        $pasujace = array_values(array_filter(
            (array) ($konfiguracja['updates'] ?? []),
            fn ($wpis): bool => is_array($wpis) && ($wpis['package-ecosystem'] ?? null) === $ekosystem,
        ));

        if (count($pasujace) !== 1) {
            self::fail("Oczekiwałem dokładnie jednego wpisu `$ekosystem` w .github/dependabot.yml, jest ".count($pasujace).'.');
        }

        return $pasujace[0];
    }

    // --- Mały parser YAML (podzbiór używany w dependabot.yml) -------------

    /** @return array<string, mixed> */
    private static function yaml(string $tresc): array
    {
        $linie = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $tresc)) as $numer => $linia) {
            $bezKomentarza = self::bezKomentarza($linia);

            if (trim($bezKomentarza) === '') {
                continue;
            }

            if (str_contains($bezKomentarza, "\t")) {
                self::fail('Tabulator w .github/dependabot.yml, linia '.($numer + 1).'.');
            }

            $linie[] = [strlen($bezKomentarza) - strlen(ltrim($bezKomentarza)), ltrim(rtrim($bezKomentarza)), $numer + 1];
        }

        $i = 0;
        $wynik = self::blok($linie, $i, 0);

        if ($i !== count($linie)) {
            self::fail('Parser nie przeczytał .github/dependabot.yml do końca (linia '.($linie[$i][2] ?? '?').').');
        }

        return $wynik;
    }

    private static function bezKomentarza(string $linia): string
    {
        $cudzyslow = null;

        for ($k = 0, $n = strlen($linia); $k < $n; $k++) {
            $znak = $linia[$k];

            if ($cudzyslow !== null) {
                $cudzyslow = $znak === $cudzyslow ? null : $cudzyslow;
            } elseif ($znak === '"' || $znak === "'") {
                $cudzyslow = $znak;
            } elseif ($znak === '#' && ($k === 0 || $linia[$k - 1] === ' ')) {
                return substr($linia, 0, $k);
            }
        }

        return $linia;
    }

    /**
     * @param  list<array{int, string, int}>  $linie
     * @return array<array-key, mixed>
     */
    private static function blok(array &$linie, int &$i, int $wciecie): array
    {
        if (str_starts_with($linie[$i][1], '- ') || $linie[$i][1] === '-') {
            return self::lista($linie, $i, $wciecie);
        }

        return self::mapa($linie, $i, $wciecie);
    }

    /**
     * @param  list<array{int, string, int}>  $linie
     * @return list<mixed>
     */
    private static function lista(array &$linie, int &$i, int $wciecie): array
    {
        $wynik = [];

        while ($i < count($linie) && $linie[$i][0] === $wciecie
            && (str_starts_with($linie[$i][1], '- ') || $linie[$i][1] === '-')) {
            $tresc = ltrim(substr($linie[$i][1], 1));

            if ($tresc === '') {
                $i++;
                $wynik[] = self::blok($linie, $i, $linie[$i][0]);
            } elseif (preg_match('/^("[^"]*"|[^\s"\[][^:]*):(\s|$)/', $tresc) === 1) {
                // `- klucz: wartość` otwiera mapę wciętą o długość „- ”.
                $wewnatrz = $wciecie + strlen($linie[$i][1]) - strlen($tresc);
                $linie[$i] = [$wewnatrz, $tresc, $linie[$i][2]];
                $wynik[] = self::mapa($linie, $i, $wewnatrz);
            } else {
                $wynik[] = self::skalar($tresc);
                $i++;
            }
        }

        return $wynik;
    }

    /**
     * @param  list<array{int, string, int}>  $linie
     * @return array<string, mixed>
     */
    private static function mapa(array &$linie, int &$i, int $wciecie): array
    {
        $wynik = [];

        while ($i < count($linie) && $linie[$i][0] === $wciecie && ! str_starts_with($linie[$i][1], '- ')) {
            if (preg_match('/^("[^"]*"|[^\s"][^:]*):(?:\s+(.*))?$/', $linie[$i][1], $m) !== 1) {
                self::fail('Nie umiem przeczytać linii '.$linie[$i][2].' w .github/dependabot.yml: '.$linie[$i][1]);
            }

            $klucz = trim($m[1], '"');
            $wartosc = $m[2] ?? '';
            $i++;

            if ($wartosc !== '') {
                $wynik[$klucz] = self::skalar($wartosc);
            } elseif ($i < count($linie) && ($linie[$i][0] > $wciecie
                || ($linie[$i][0] === $wciecie && str_starts_with($linie[$i][1], '- ')))) {
                $wynik[$klucz] = self::blok($linie, $i, $linie[$i][0]);
            } else {
                $wynik[$klucz] = null;
            }
        }

        if ($i < count($linie) && $linie[$i][0] > $wciecie) {
            self::fail('Nieoczekiwane wcięcie w linii '.$linie[$i][2].' .github/dependabot.yml.');
        }

        return $wynik;
    }

    private static function skalar(string $tekst): mixed
    {
        $tekst = trim($tekst);

        if (str_starts_with($tekst, '[') && str_ends_with($tekst, ']')) {
            $srodek = trim(substr($tekst, 1, -1));

            return $srodek === '' ? [] : array_map(self::skalar(...), array_map('trim', explode(',', $srodek)));
        }

        if (preg_match('/^"(.*)"$|^\'(.*)\'$/', $tekst, $m) === 1) {
            return $m[2] ?? $m[1];
        }

        return preg_match('/^-?\d+$/', $tekst) === 1 ? (int) $tekst : $tekst;
    }

    private static function sciezka(): string
    {
        return dirname(__DIR__, 2).'/.github/dependabot.yml';
    }
}

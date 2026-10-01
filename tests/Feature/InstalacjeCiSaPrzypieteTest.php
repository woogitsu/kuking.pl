<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Łańcuch dostaw CI: to, co workflow pobiera z sieci i uruchamia, jest
 * przypięte do sumy albo odcisku zapisanego w repozytorium.
 *
 *  - #2309 — klucz repozytorium PGDG. Oba kroki „Klient PostgreSQL 18”
 *    w ci.yml zapisywały pobrany klucz wprost do katalogu kluczy APT. Każdy
 *    klucz spod tego adresu podpisywałby potem `postgresql-client-18`.
 *    Teraz oba kroki wołają scripts/ci/klient-postgresql-18.sh, który
 *    porównuje odcisk przed `apt-get update`;
 *  - #2310 — `pip install openpyxl==3.1.5` bez hasha. Teraz pip instaluje
 *    tylko z pliku wymagań z `--hash=sha256:` i z `--require-hashes`;
 *  - #2263 — usługi PostgreSQL w siedmiu jobach ci.yml z ruchomego tagu
 *    `postgres:18-alpine`. Teraz po digeście, tym samym co w
 *    docker/ci-postgres/Dockerfile, który widzi Dependabot.
 *
 * Test czyta workflowy przez yaml.safe_load (Python), bo GitHub Actions nie
 * da się uruchomić z testu. Każda reguła ma funkcję zwracającą naruszenia.
 * Ta sama funkcja idzie też na syntetycznym, zepsutym wejściu (kontrola
 * dodatnia), żeby reguła, która nic nie widzi, nie świeciła na zielono.
 * Zachowanie skryptu klienta (obcy klucz zatrzymuje krok przed apt-get)
 * sprawdza tests/skrypty/klient-postgresql-18.sh, uruchamiany tutaj.
 */
final class InstalacjeCiSaPrzypieteTest extends TestCase
{
    private const SKRYPT_KLIENTA = 'scripts/ci/klient-postgresql-18.sh';

    private const DOCKERFILE_USLUGI = 'docker/ci-postgres/Dockerfile';

    private const DIGEST = '/@sha256:[0-9a-f]{64}$/';

    /** @return array<string, mixed> */
    private function yaml(string $sciezka): array
    {
        $proces = new Process([
            'python3', '-c',
            'import sys, json, yaml; print(json.dumps(yaml.safe_load(open(sys.argv[1], encoding="utf-8"))))',
            base_path($sciezka),
        ]);
        $proces->run();
        $this->assertSame(0, $proces->getExitCode(), "yaml.safe_load nie przeczytał {$sciezka}: ".$proces->getErrorOutput());

        $dane = json_decode($proces->getOutput(), true);
        $this->assertIsArray($dane, "{$sciezka} nie jest mapą YAML.");

        return $dane;
    }

    /** @return array<string, array<string, mixed>> ścieżka => sparsowany workflow */
    private function workflowy(): array
    {
        $pliki = glob(base_path('.github/workflows/*.yml')) ?: [];
        $this->assertGreaterThanOrEqual(6, count($pliki), 'Test przestał widzieć workflowy w .github/workflows.');

        $wynik = [];
        foreach ($pliki as $plik) {
            $sciezka = '.github/workflows/'.basename($plik);
            $wynik[$sciezka] = $this->yaml($sciezka);
        }

        return $wynik;
    }

    /** @return list<array{job: string, krok: array<string, mixed>}> */
    private function kroki(array $workflow): array
    {
        $kroki = [];
        foreach ((array) ($workflow['jobs'] ?? []) as $nazwa => $job) {
            foreach ((array) ($job['steps'] ?? []) as $krok) {
                if (is_array($krok)) {
                    $kroki[] = ['job' => (string) $nazwa, 'krok' => $krok];
                }
            }
        }

        return $kroki;
    }

    private function plik(string $sciezka): string
    {
        $this->assertFileExists(base_path($sciezka), "Nie ma {$sciezka}. Jeśli go przeniesiono, popraw ten test razem z nim.");

        return (string) file_get_contents(base_path($sciezka));
    }

    // ── #2309 ─────────────────────────────────────────────────────────────

    /**
     * Krok, który sam dodaje repozytorium PGDG (klucz albo źródło APT), omija
     * sprawdzenie odcisku w skrypcie klienta.
     *
     * @return list<string>
     */
    private function naruszeniaKluczaPgdg(string $plik, array $workflow): array
    {
        $naruszenia = [];
        foreach ($this->kroki($workflow) as ['job' => $job, 'krok' => $krok]) {
            $run = (string) ($krok['run'] ?? '');
            if (preg_match('/ACCC4CF8|apt\.postgresql\.org|postgresql-client-\d+/', $run) === 1) {
                $naruszenia[] = "{$plik}, job `{$job}`, krok „".($krok['name'] ?? '?').'” sam dodaje repozytorium PGDG albo instaluje klienta PostgreSQL z APT. '
                    .'Klucz PGDG nie przechodzi wtedy przez sprawdzenie odcisku (#2309). Wołaj `bash '.self::SKRYPT_KLIENTA.'`.';
            }
        }

        return $naruszenia;
    }

    #[Test]
    public function klient_postgresql_18_w_ci_idzie_tylko_przez_skrypt_ze_sprawdzeniem_odcisku(): void
    {
        $workflowy = $this->workflowy();

        $naruszenia = [];
        foreach ($workflowy as $plik => $workflow) {
            array_push($naruszenia, ...$this->naruszeniaKluczaPgdg($plik, $workflow));
        }
        $this->assertSame([], $naruszenia, implode("\n", $naruszenia));

        $kroki = array_values(array_filter(
            $this->kroki($workflowy['.github/workflows/ci.yml']),
            static fn (array $k): bool => str_starts_with((string) ($k['krok']['name'] ?? ''), 'Klient PostgreSQL 18'),
        ));
        $this->assertCount(2, $kroki, 'ci.yml ma mieć dwa kroki „Klient PostgreSQL 18” (joby z kopią bazy). Jeśli ich liczba zmieniła się celowo, popraw ten test.');
        foreach ($kroki as ['job' => $job, 'krok' => $krok]) {
            $this->assertSame(
                'bash '.self::SKRYPT_KLIENTA,
                trim((string) ($krok['run'] ?? '')),
                "ci.yml, job `{$job}`: krok „Klient PostgreSQL 18” nie woła samego skryptu klienta ze sprawdzeniem odcisku klucza PGDG (#2309).",
            );
        }

        // Kontrola dodatnia reguły: dawny krok z ci.yml (klucz prosto do APT).
        $dawny = ['jobs' => ['kopia' => ['steps' => [[
            'name' => 'Klient PostgreSQL 18',
            'run' => "sudo curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc\nsudo apt-get install -y -qq postgresql-client-18\n",
        ]]]]];
        $this->assertNotSame([], $this->naruszeniaKluczaPgdg('syntetyczny.yml', $dawny), 'Reguła klucza PGDG przepuściła krok sprzed #2309.');
    }

    #[Test]
    public function skrypt_klienta_sprawdza_odcisk_zanim_klucz_trafi_do_apt(): void
    {
        $skrypt = $this->plik(self::SKRYPT_KLIENTA);

        $this->assertMatchesRegularExpression('/^PGDG_ODCISK="[0-9A-F]{40}"$/m', $skrypt, 'Skrypt klienta nie ma pełnego odcisku klucza PGDG (40 znaków hex) (#2309).');

        // Polecenia powłoki mogą być złamane ukośnikiem odwrotnym i mieć inne opcje limitu czasu.
        $polecenia = preg_replace('/\\\\\r?\n[ \t]*/', ' ', $skrypt);
        $this->assertIsString($polecenia);
        $wzorPobrania = '/\bcurl[ \t]+-fsSL\b[^\r\n]*[ \t]+-o[ \t]+"\$tymczasowy"[ \t]+"\$PGDG_URL"/';
        $znalezionoPobranie = preg_match($wzorPobrania, $polecenia, $dopasowanie, PREG_OFFSET_CAPTURE);
        $pobranie = $znalezionoPobranie === 1 ? $dopasowanie[0][1] : false;
        $sprawdzenie = strpos($polecenia, 'sprawdz_klucz_pgdg "$tymczasowy" || exit 1');
        $instalacja = strpos($polecenia, 'sudo install -m 0644 "$tymczasowy" "$PGDG_KLUCZ"');
        $aktualizacja = strpos($polecenia, 'sudo apt-get update');

        $this->assertNotFalse($pobranie, 'Skrypt klienta nie pobiera klucza PGDG do pliku tymczasowego — klucz szedłby prosto do katalogu APT (#2309).');
        $this->assertNotFalse($sprawdzenie, 'Skrypt klienta nie sprawdza odcisku pobranego klucza PGDG przed użyciem (#2309).');
        $this->assertNotFalse($instalacja, 'Skrypt klienta nie instaluje sprawdzonego klucza z pliku tymczasowego.');
        $this->assertNotFalse($aktualizacja, 'Skrypt klienta nie woła apt-get update — test stracił przedmiot.');
        $this->assertTrue(
            $pobranie < $sprawdzenie && $sprawdzenie < $instalacja && $instalacja < $aktualizacja,
            'Kolejność w skrypcie klienta ma być: pobranie do pliku tymczasowego, sprawdzenie odcisku, instalacja klucza, apt-get update (#2309).',
        );

        // Kontrola ujemna rozpoznania: zapis klucza od razu do APT nie jest pobraniem do pliku tymczasowego.
        $bezPlikuTymczasowego = str_replace('-o "$tymczasowy" "$PGDG_URL"', '-o "$PGDG_KLUCZ" "$PGDG_URL"', $polecenia, $liczbaPodmian);
        $this->assertSame(1, $liczbaPodmian);
        $this->assertSame(0, preg_match($wzorPobrania, $bezPlikuTymczasowego));
    }

    #[Test]
    public function obcy_klucz_pgdg_zatrzymuje_instalacje_przed_apt_get(): void
    {
        $proces = new Process(['bash', 'tests/skrypty/klient-postgresql-18.sh'], base_path());
        $proces->setTimeout(120);
        $proces->run();

        $this->assertSame(0, $proces->getExitCode(), 'Test skryptu klienta PostgreSQL 18 oblał: '.$proces->getOutput().$proces->getErrorOutput());
        $this->assertStringContainsString('Klient PostgreSQL 18: OK', $proces->getOutput());
        // Kontrola dodatnia przyrządu: kontrola ujemna naprawdę poszła po regułach klucza.
        $this->assertSame(4, substr_count($proces->getOutput(), ': mutacja zapaliła przypadki'));
    }

    // ── #2310 ─────────────────────────────────────────────────────────────

    /**
     * Pusta lista, gdy KAŻDE wymaganie w pliku ma `==` i hash SHA-256.
     *
     * @return list<string>
     */
    private function naruszeniaPlikuWymagan(string $plik, string $tresc): array
    {
        // Kontynuacje wierszy (`\` na końcu) sklejamy, komentarze wycinamy.
        $logiczne = preg_split('/\r\n|\n|\r/', (string) preg_replace('/\\\\(?:\r\n|\n|\r)\s*/', ' ', $tresc)) ?: [];
        $naruszenia = [];
        $wymagan = 0;
        foreach ($logiczne as $wiersz) {
            $wiersz = trim((string) preg_replace('/(^|\s)#.*$/', '', $wiersz));
            if ($wiersz === '') {
                continue;
            }
            $wymagan++;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*==[A-Za-z0-9.]+(\s+--hash=sha256:[0-9a-f]{64})+$/', $wiersz) !== 1) {
                $naruszenia[] = "{$plik}: wymaganie „{$wiersz}” nie ma dokładnej wersji (==) i hasha --hash=sha256:<64 hex> (#2310).";
            }
        }
        if ($wymagan === 0) {
            $naruszenia[] = "{$plik}: plik wymagań jest pusty — test stracił przedmiot.";
        }

        return $naruszenia;
    }

    /**
     * @return list<string>
     */
    private function naruszeniaPip(string $plik, array $workflow): array
    {
        $naruszenia = [];
        foreach ($this->kroki($workflow) as ['job' => $job, 'krok' => $krok]) {
            $run = (string) ($krok['run'] ?? '');
            if (preg_match_all('/\bpip3?\s+install\b[^\n]*/', $run, $wywolania) < 1) {
                continue;
            }
            foreach ($wywolania[0] as $wywolanie) {
                $gdzie = "{$plik}, job `{$job}`: `{$wywolanie}`";
                if (! str_contains($wywolanie, '--require-hashes')) {
                    $naruszenia[] = "{$gdzie} instaluje pakiety Pythona bez --require-hashes, więc pip nie sprawdza sumy pobranego pliku (#2310).";

                    continue;
                }
                if (preg_match('/\s-r\s+(\S+)/', $wywolanie, $m) !== 1) {
                    $naruszenia[] = "{$gdzie} nie czyta wymagań z pliku (-r) — wersje i hashe mają stać w repozytorium (#2310).";

                    continue;
                }
                if (! is_file(base_path($m[1]))) {
                    $naruszenia[] = "{$gdzie} wskazuje nieistniejący plik wymagań {$m[1]}.";

                    continue;
                }
                array_push($naruszenia, ...$this->naruszeniaPlikuWymagan($m[1], (string) file_get_contents(base_path($m[1]))));
            }
        }

        return $naruszenia;
    }

    #[Test]
    public function pip_w_workflowach_instaluje_tylko_z_pliku_z_hashami(): void
    {
        $naruszenia = [];
        $instalacji = 0;
        foreach ($this->workflowy() as $plik => $workflow) {
            foreach ($this->kroki($workflow) as ['krok' => $krok]) {
                $instalacji += preg_match_all('/\bpip3?\s+install\b/', (string) ($krok['run'] ?? ''));
            }
            array_push($naruszenia, ...$this->naruszeniaPip($plik, $workflow));
        }

        $this->assertGreaterThanOrEqual(1, $instalacji, 'Test nie widzi żadnego `pip install` w workflowach (ceny-warzyw-auto.yml) — stracił przedmiot.');
        $this->assertSame([], $naruszenia, implode("\n", $naruszenia));

        // Kontrola dodatnia obu reguł: stan sprzed #2310 i wymaganie bez hasha.
        $dawny = ['jobs' => ['pobierz' => ['steps' => [['run' => 'pip install --disable-pip-version-check openpyxl==3.1.5']]]]];
        $this->assertNotSame([], $this->naruszeniaPip('syntetyczny.yml', $dawny), 'Reguła pip przepuściła instalację bez hasha sprzed #2310.');
        $this->assertNotSame(
            [],
            $this->naruszeniaPlikuWymagan('syntetyczny.txt', "openpyxl==3.1.5 \\\n    --hash=sha256:".str_repeat('a', 64)."\net-xmlfile==2.0.0\n"),
            'Reguła pliku wymagań przepuściła zależność bez hasha.',
        );
        $this->assertSame(
            [],
            $this->naruszeniaPlikuWymagan('syntetyczny.txt', "# komentarz\nopenpyxl==3.1.5 \\\n    --hash=sha256:".str_repeat('a', 64)."\n"),
            'Reguła pliku wymagań odrzuciła poprawny wpis z hashem.',
        );
    }

    // ── #2263 ─────────────────────────────────────────────────────────────

    /**
     * Obrazy kontenerów jobów i usług: `job.container(.image)` i `services.*.image`.
     *
     * @return list<array{gdzie: string, obraz: string}>
     */
    private function obrazy(string $plik, array $workflow): array
    {
        $obrazy = [];
        foreach ((array) ($workflow['jobs'] ?? []) as $nazwa => $job) {
            $kontener = $job['container'] ?? null;
            $kontener = is_array($kontener) ? ($kontener['image'] ?? null) : $kontener;
            if (is_string($kontener)) {
                $obrazy[] = ['gdzie' => "{$plik}, job `{$nazwa}`, container", 'obraz' => $kontener];
            }
            foreach ((array) ($job['services'] ?? []) as $usluga => $definicja) {
                $obraz = is_array($definicja) ? ($definicja['image'] ?? null) : null;
                if (is_string($obraz)) {
                    $obrazy[] = ['gdzie' => "{$plik}, job `{$nazwa}`, usługa `{$usluga}`", 'obraz' => $obraz];
                }
            }
        }

        return $obrazy;
    }

    /**
     * @param  list<array{gdzie: string, obraz: string}>  $obrazy
     * @return list<string>
     */
    private function naruszeniaObrazow(array $obrazy, string $obrazZDockerfile): array
    {
        $naruszenia = [];
        foreach ($obrazy as ['gdzie' => $gdzie, 'obraz' => $obraz]) {
            if (preg_match(self::DIGEST, $obraz) !== 1) {
                $naruszenia[] = "{$gdzie}: obraz `{$obraz}` bez digestu — tag jest ruchomy, więc dwa przebiegi tego samego commita mogą dostać różne obrazy (#2263).";

                continue;
            }
            if (str_starts_with($obraz, 'postgres:18-alpine@') && $obraz !== $obrazZDockerfile) {
                $naruszenia[] = "{$gdzie}: `{$obraz}` ma inny digest niż FROM w ".self::DOCKERFILE_USLUGI." (`{$obrazZDockerfile}`). "
                    .'Po PR-ze Dependabota przepisz nowy digest do wszystkich usług w ci.yml (#2263).';
            }
        }

        return $naruszenia;
    }

    #[Test]
    public function obrazy_uslug_w_workflowach_sa_przypiete_do_digestu_z_dockerfile_dependabota(): void
    {
        $this->assertSame(
            1,
            preg_match('/^FROM\s+(postgres:18-alpine@sha256:[0-9a-f]{64})\s*$/m', $this->plik(self::DOCKERFILE_USLUGI), $m),
            self::DOCKERFILE_USLUGI.' nie ma linii FROM postgres:18-alpine@sha256:<digest> — Dependabot nie ma skąd proponować nowego digestu usługi (#2263).',
        );
        $zDockerfile = $m[1];

        $obrazy = [];
        foreach ($this->workflowy() as $plik => $workflow) {
            array_push($obrazy, ...$this->obrazy($plik, $workflow));
        }
        $postgres = array_filter($obrazy, static fn (array $o): bool => str_starts_with($o['obraz'], 'postgres:'));
        $this->assertGreaterThanOrEqual(7, count($postgres), 'Test widzi mniej niż siedem usług PostgreSQL w workflowach — stracił przedmiot.');

        $naruszenia = $this->naruszeniaObrazow($obrazy, $zDockerfile);
        $this->assertSame([], $naruszenia, implode("\n", $naruszenia));

        $this->assertStringContainsString(
            '"/docker/ci-postgres"',
            $this->plik('.github/dependabot.yml'),
            'Dependabot (`docker`) nie obejmuje docker/ci-postgres — digest usługi PostgreSQL w CI nigdy by się nie odświeżył (#2263).',
        );

        // Kontrola dodatnia: stan sprzed #2263 i rozjazd digestu z Dockerfile.
        $this->assertNotSame([], $this->naruszeniaObrazow([['gdzie' => 'syntetyczny', 'obraz' => 'postgres:18-alpine']], $zDockerfile), 'Reguła przepuściła obraz bez digestu.');
        $this->assertNotSame([], $this->naruszeniaObrazow([['gdzie' => 'syntetyczny', 'obraz' => 'postgres:18-alpine@sha256:'.str_repeat('0', 64)]], $zDockerfile), 'Reguła przepuściła digest inny niż w Dockerfile.');
    }
}

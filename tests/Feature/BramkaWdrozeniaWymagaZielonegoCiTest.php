<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #2025 — produkcja nie może dostać SHA, którego CI nie przeszło.
 *
 * CO SIĘ STAŁO 26 WRZEŚNIA 2026
 * Commit 64dbdefd trafił na produkcję, choć `CI` tego samego SHA skończyło
 * jako `cancelled`, a wymagany check „Testy (PostgreSQL 18)" był czerwony.
 * Railway „Wait for CI" ocenia konkluzje całych workflow: anulowany workflow
 * blokuje wdrożenie tylko wtedy, gdy ŻADEN inny workflow tego commita nie
 * skończył się sukcesem, a `Push on main` skończył się sukcesem.
 *
 * CO ROBI REPOZYTORIUM (dwie warstwy, bo tylko druga jest bramką)
 *   1. `deploy.yml` → job `audit_ci` — ALARM po fakcie: po udanym deployu
 *      produkcji porównuje SHA z zakończonym CI. Nie zatrzymuje ruchu.
 *   2. `railway-ci-gated-deploy.yml` — BRAMKA przed zmianą ruchu, wyłączona,
 *      dopóki właściciel nie wyłączy autodeploy w Railway i nie ustawi
 *      `KUKING_CI_GATED_RAILWAY_DEPLOY=true` (docs/infra/RAILWAY_CI_GATE.md).
 *
 * DLACZEGO TEST CZYTA PLIKI WORKFLOW
 * GitHub Actions nie da się uruchomić z testu, a wszystkie trzy usterki,
 * które ten test zamyka, są ciche: bramka, która się nie odpala, wygląda jak
 * bramka, która nic nie ma do zrobienia; alarm bez uprawnień do odczytu
 * przebiegów pada dopiero na produkcji; a zmiana nazwy joba zbiorczego CI
 * sprawia, że bramka do końca świata odmawia wdrożenia „bo CI nie jest zielone".
 *
 * Czego ten test NIE dowodzi: że autodeploy jest wyłączony w Railway ani że
 * sekret i zmienne bramki są ustawione. To stan panelu i repozytorium na
 * GitHubie — zna go tylko właściciel (docs/infra/RAILWAY_CI_GATE.md).
 *
 * @bez-kontroli-dodatniej Czyta pliki workflow i asertuje na ich treści, ale każda asercja wymaga dokładnej, jednej obecności warunku (dopasowanie regułą, a nie „zawiera"), więc zgubiony albo przeredagowany warunek daje czerwień, nie cichą zieleń; kontrole ujemne wykonano ręcznie na każdej asercji.
 */
class BramkaWdrozeniaWymagaZielonegoCiTest extends TestCase
{
    private const NAZWA_JOBA_ZBIORCZEGO = 'Testy (PostgreSQL 18)';

    private function plik(string $sciezka): string
    {
        $pelna = base_path($sciezka);

        $this->assertFileExists($pelna, "Nie ma pliku {$sciezka}. Jeśli go przeniesiono, popraw ten test razem z nim.");

        return (string) file_get_contents($pelna);
    }

    /**
     * Treść bez wierszy komentarza — komentarze mają prawo tłumaczyć,
     * dlaczego warunku „nie ma", i nie mogą zaliczać asercji.
     */
    private function bezKomentarzy(string $yaml): string
    {
        return implode("\n", array_filter(
            preg_split('/\r\n|\n|\r/', $yaml) ?: [],
            static fn (string $wiersz): bool => preg_match('/^\s*#/', $wiersz) !== 1,
        ));
    }

    /**
     * Blok jednego joba (od `  nazwa:` do następnego joba na tym samym wcięciu).
     */
    private function job(string $yaml, string $nazwa): string
    {
        $this->assertSame(
            1,
            preg_match('/^  '.preg_quote($nazwa, '/').':(?:\r\n|\n|\r)(.*?)(?=^  [A-Za-z0-9_-]+:(?:\r\n|\n|\r)|^\S|\z)/ms', $this->bezKomentarzy($yaml), $m),
            "W workflow nie ma joba `{$nazwa}` (albo jest ich kilka). Jeśli zmienił nazwę, popraw ten test razem z nim.",
        );

        return $m[1];
    }

    #[Test]
    public function bramka_reaguje_tylko_na_zakonczone_ci_i_tylko_gdy_wlasciciel_ja_wlaczyl(): void
    {
        $workflow = $this->bezKomentarzy($this->plik('.github/workflows/railway-ci-gated-deploy.yml'));

        $this->assertMatchesRegularExpression(
            '/^on:(?:\r\n|\n|\r)  workflow_run:(?:\r\n|\n|\r)    workflows: \[CI\](?:\r\n|\n|\r)    types: \[completed\](?:\r\n|\n|\r)/m',
            $workflow,
            'Bramka ma się odpalać wyłącznie po zakończeniu workflow `CI` (workflow_run/completed). '
            .'Inny wyzwalacz (push, workflow_dispatch) pozwoliłby ją ominąć albo uruchomić bez wyniku CI.',
        );

        $this->assertStringNotContainsString(
            'workflow_dispatch',
            $workflow,
            'Bramka nie może mieć ręcznego uruchomienia — omijałoby zdarzenie z wynikiem CI.',
        );
        $this->assertStringNotContainsString('pull_request', $workflow, 'Bramka wdraża produkcję, więc nie może chodzić na zdarzeniach PR.');

        $job = $this->job($workflow, 'deploy');

        foreach ([
            "vars.KUKING_CI_GATED_RAILWAY_DEPLOY == 'true'" => 'Bez jawnego włączenia przez właściciela bramka odpalałaby się obok autodeployu Railway i dawała podwójne wdrożenia.',
            "github.event.workflow_run.event == 'push'" => 'Wdrażamy tylko z push, nie z PR ani z ręcznego przebiegu.',
            "github.event.workflow_run.head_branch == 'main'" => 'Produkcja dostaje wyłącznie `main`.',
            "github.event.workflow_run.conclusion == 'success'" => 'Bez tego warunku anulowane albo czerwone CI też wdrażałoby produkcję — dokładnie #2025.',
            'github.event.workflow_run.head_repository.full_name == github.repository' => 'Przebieg z forka nie może uruchomić wdrożenia produkcji.',
        ] as $warunek => $powod) {
            $this->assertSame(1, substr_count($job, $warunek), "Job `deploy` bramki musi mieć warunek `{$warunek}`. {$powod}");
        }

        $this->assertSame(1, preg_match_all('/^    if:/m', $job), 'Warunki mają stać w jednym kluczu `if:` (powtórzony klucz kasuje cały plik).');
        $this->assertMatchesRegularExpression('/^    environment: production$/m', $job, 'Wdrożenie produkcji idzie przez środowisko `production` (sekret i ewentualni recenzenci, #1925).');
        $this->assertStringContainsString('run: python3 scripts/railway-ci-gated-deploy.py', $job);
    }

    /**
     * #2233 — checkout dla `workflow_run` bez `ref` bierze bieżący main, który
     * mógł się przesunąć po zakończeniu CI. Skrypt bramki z commita B
     * wdrażałby wtedy SHA A. Checkout ma wskazać commit zielonego CI, a skrypt
     * ma to jeszcze porównać z `git rev-parse HEAD`.
     */
    #[Test]
    public function bramka_checkoutuje_dokladnie_sha_zielonego_ci(): void
    {
        $job = $this->job($this->plik('.github/workflows/railway-ci-gated-deploy.yml'), 'deploy');

        $this->assertSame(
            1,
            preg_match_all('/^      - uses: actions\/checkout@/m', $job),
            'Job `deploy` bramki ma mieć dokładnie jeden checkout — test stracił przedmiot.',
        );
        $this->assertMatchesRegularExpression(
            '/^      - uses: actions\/checkout@[0-9a-f]{40}[^\n]*(?:\r\n|\n|\r)        with:(?:\r\n|\n|\r)(?:          [a-z-]+: [^\n]*(?:\r\n|\n|\r))*?          ref: \$\{\{ github\.event\.workflow_run\.head_sha \}\}$/m',
            $job,
            'Checkout bramki nie ma `ref: ${{ github.event.workflow_run.head_sha }}` — bez niego skrypt wdrożenia pochodzi z bieżącego main, a nie z commita, którego CI jest zielone (#2233).',
        );

        $skrypt = $this->plik('scripts/railway-ci-gated-deploy.py');
        $this->assertMatchesRegularExpression(
            '/^    sha = verify_ci\(event, repo, github_get\)\n    verify_checkout\(sha, local_head\(\)\)$/m',
            $skrypt,
            'Skrypt bramki nie porównuje `git rev-parse HEAD` z SHA z CI zaraz po weryfikacji CI (#2233).',
        );
    }

    #[Test]
    public function bramka_ma_tylko_odczyt_i_nie_przerywa_wdrozenia_w_toku(): void
    {
        $workflow = $this->bezKomentarzy($this->plik('.github/workflows/railway-ci-gated-deploy.yml'));

        $this->assertMatchesRegularExpression(
            '/^permissions:(?:\r\n|\n|\r)  contents: read(?:\r\n|\n|\r)  actions: read(?:\r\n|\n|\r)(?!  )/m',
            $workflow,
            'Token bramki ma mieć tylko `contents: read` i `actions: read` (odczyt przebiegów CI). Zapis do repozytorium nie jest jej potrzebny.',
        );
        $this->assertMatchesRegularExpression(
            '/^concurrency:(?:\r\n|\n|\r)  group: railway-ci-gated-production(?:\r\n|\n|\r)  cancel-in-progress: false$/m',
            $workflow,
            'Dwa wdrożenia produkcji nie mogą biec równolegle, a przerwanie sekwencji web → worker → scheduler w połowie jest gorsze niż poczekanie.',
        );
    }

    #[Test]
    public function skrypt_bramki_wymaga_dokladnie_tego_joba_zbiorczego_ktory_ma_ci(): void
    {
        $ci = $this->plik('.github/workflows/ci.yml');
        $skrypt = $this->plik('scripts/railway-ci-gated-deploy.py');

        $this->assertStringContainsString(
            '"'.self::NAZWA_JOBA_ZBIORCZEGO.'"',
            $skrypt,
            'Skrypt bramki nie szuka już joba zbiorczego. Bramka MUSI sprawdzać wymagany check, nie tylko konkluzję całego workflow (#2025).',
        );

        $this->assertMatchesRegularExpression(
            '/^  testy:(?:\r\n|\n|\r)    name: '.preg_quote(self::NAZWA_JOBA_ZBIORCZEGO, '/').'$/m',
            $ci,
            'Job zbiorczy w ci.yml nie nazywa się już „'.self::NAZWA_JOBA_ZBIORCZEGO.'". Skrypt bramki szuka go po nazwie, '
            .'więc po zmianie nazwy bramka odmawiałaby wdrożenia każdego commita. Zmień nazwę w obu miejscach naraz '
            .'(oraz wymagany check w ustawieniach GitHuba).',
        );

        $this->assertMatchesRegularExpression(
            '/^      - name: Testy bramki Railway po CI(?:\r\n|\n|\r)        run: python3 -m unittest discover -s scripts -p test_railway_ci_gated_deploy\.py -v$/m',
            $ci,
            'CI musi uruchamiać kontrole skryptu bramki, zanim skrypt dostanie prawo decydowania o produkcji.',
        );
    }

    #[Test]
    public function alarm_po_wdrozeniu_odpala_sie_dla_produkcji_i_ma_odczyt_przebiegow(): void
    {
        $workflow = $this->plik('.github/workflows/deploy.yml');
        $job = $this->job($workflow, 'audit_ci');

        $this->assertMatchesRegularExpression(
            "/^    if: >-(?:\\r\\n|\\n|\\r)      github\.event_name == 'deployment_status' &&(?:\\r\\n|\\n|\\r)      github\.event\.deployment_status\.state == 'success'$/m",
            $job,
            'Alarm ma się odpalać po każdym udanym deployu (deployment_status/success) — to Railway, nie ten workflow, zaczyna wdrożenie.',
        );
        $this->assertMatchesRegularExpression(
            '/^    permissions:(?:\r\n|\n|\r)      contents: read(?:\r\n|\n|\r)      actions: read/m',
            $job,
            'Bez `actions: read` alarm nie odczyta przebiegów CI i padnie na produkcji zamiast alarmować o niej.',
        );

        foreach ([
            'DEPLOY_SHA: ${{ github.event.deployment.sha }}',
            'DEPLOYED_AT: ${{ github.event.deployment_status.created_at }}',
            'DEPLOY_ENV: ${{ github.event.deployment.environment }}',
            'run: node scripts/ci-po-wdrozeniu.mjs',
        ] as $wiersz) {
            $this->assertStringContainsString($wiersz, $job, "Job `audit_ci` musi zawierać `{$wiersz}`.");
        }

        $this->assertDoesNotMatchRegularExpression('/^    needs:/m', $job, 'Alarm nie może czekać na inne joby — sonda dymna, która padła, nie ma prawa go wyciszyć.');
        $this->assertDoesNotMatchRegularExpression('/continue-on-error:\s*true/', $job, 'Alarm, który nie może zaczerwienić przebiegu, niczego nie alarmuje.');
    }

    #[Test]
    public function testy_alarmu_biegna_w_budowaniu_assetow(): void
    {
        $paczka = json_decode($this->plik('package.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString(
            'scripts/ci-po-wdrozeniu.test.mjs',
            (string) ($paczka['scripts']['build'] ?? ''),
            'Test alarmu #2025 wypadł z `npm run build` — nic w CI nie pilnuje już reguły „anulowane CI obok zielonego workflow to alarm".',
        );
    }
}

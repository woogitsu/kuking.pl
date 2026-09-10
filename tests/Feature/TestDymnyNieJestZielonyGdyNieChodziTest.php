<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DLACZEGO TEST DYMNY PO WDROŻENIU NIGDY SIĘ NIE URUCHOMIŁ — I DLACZEGO
 * PIERWSZA POPRAWKA POGORSZYŁA SPRAWĘ.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  POMIAR
 * ══════════════════════════════════════════════════════════════════════
 *
 * `deploy.yml` sam dokumentował: **249 przebiegów workflow „Deploy",
 * wszystkie `skipped`.** Test dymny — jedyna rzecz, która miała wyłapać
 * „deploy się udał, ale strona nie działa" — nie uruchomił się ani razu.
 *
 * Pierwsza poprawka zdjęła warunek z poziomu JOBA. Job zaczął więc kończyć
 * się jako `success`. Sprawdzone 10 września 2026 w logu przebiegu
 * 34484294591: **job `success`, krok „Test dymny" `skipped`.**
 *
 * To jest gorsze niż stan przed poprawką. Pominięty job widać na liście
 * przebiegów. Zielony job z pominiętym krokiem wygląda dokładnie tak samo
 * jak test, który przeszedł — a audyt infrastruktury wymagał dowodu „job =
 * SUCCESS, nie skipped". Ten job spełniał kryterium formalnie, nie
 * sprawdzając niczego.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  PRAWDZIWA PRZYCZYNA
 * ══════════════════════════════════════════════════════════════════════
 *
 * W logu tego samego przebiegu stoi wartość, której nie gwarantuje żadna
 * dokumentacja:
 *
 *     deployment.environment = 'ideal-exploration / production'
 *
 * Railway przysyła „<nazwa projektu> / <środowisko>", nie gołe
 * `production`. `case` dopasowywał dokładne `production` i `staging`, więc
 * każde zdarzenie wpadało w gałąź `*` i ustawiało `pomin=1`.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CZEGO PILNUJE TEN PLIK
 * ══════════════════════════════════════════════════════════════════════
 *
 * Dwóch rzeczy, i obie są potrzebne:
 *
 * 1. że parsowanie nazwy środowiska nadal obcina przedrostek projektu —
 *    bo bez tego wracamy do 249 pominięć;
 * 2. że **pominięty test dymny kończy job na czerwono** — bo bez tego
 *    wracamy do stanu jeszcze gorszego: zielono i bez sprawdzenia.
 *
 * Punkt 1 sprawdzamy URUCHAMIAJĄC tę samą logikę powłoki na prawdziwej
 * zmierzonej wartości, a nie szukając wzorca w pliku. Test, który tylko
 * sprawdza w YAML-u obecność podmiany obcinającej wszystko do ostatniego
 * ukośnika, przechodzi także wtedy, gdy ktoś napisze ją źle — a tutaj cała
 * wartość siedzi w tym, czy wynik
 * naprawdę wychodzi `production`.
 */
class TestDymnyNieJestZielonyGdyNieChodziTest extends TestCase
{
    private function workflow(): string
    {
        $sciezka = base_path('.github/workflows/deploy.yml');

        $this->assertFileExists($sciezka, 'Nie ma .github/workflows/deploy.yml.');

        return (string) file_get_contents($sciezka);
    }

    /**
     * Uruchamia tę samą podmianę powłoki, którą robi workflow, i sprawdza
     * WYNIK — na wartości zmierzonej w realnym zdarzeniu Railwaya.
     */
    #[Test]
    public function test_nazwa_srodowiska_z_railwaya_sprowadza_sie_do_production(): void
    {
        $przypadki = [
            // Zmierzone w logu przebiegu 34484294591 (10.09.2026).
            'ideal-exploration / production' => 'production',
            'ideal-exploration / staging' => 'staging',
            // Gdyby Railway kiedyś przysłał gołą nazwę — musi działać dalej.
            'production' => 'production',
            'staging' => 'staging',
            // Inna nazwa projektu nie ma prawa nic zmienić.
            'jakis-inny-projekt / production' => 'production',
            // Nazwa, której nie obsługujemy, ma zostać nieobsłużona.
            'ideal-exploration / preview-123' => 'preview-123',
        ];

        foreach ($przypadki as $wejscie => $oczekiwane) {
            $skrypt = 'env_name='.escapeshellarg((string) $wejscie).'; '
                .'srodowisko="${env_name##*/}"; '
                .'srodowisko="$(echo "$srodowisko" | tr -d \'[:space:]\')"; '
                .'printf %s "$srodowisko"';

            $wynik = shell_exec('bash -c '.escapeshellarg($skrypt));

            $this->assertSame(
                $oczekiwane,
                $wynik,
                "Nazwa środowiska '{$wejscie}' sprowadza się do '{$wynik}', a ma do '{$oczekiwane}'. ".
                'To jest dokładnie ta pomyłka, przez którą 249 przebiegów testu dymnego '.
                'zostało pominiętych.',
            );
        }
    }

    #[Test]
    public function test_workflow_obcina_przedrostek_projektu_przed_dopasowaniem(): void
    {
        $yaml = $this->workflow();

        $this->assertStringContainsString(
            '${env_name##*/}',
            $yaml,
            'Workflow dopasowuje nazwę środowiska bez obcięcia przedrostka projektu. '.
            'Railway przysyła „<projekt> / <środowisko>", więc `case "$env_name"` '.
            'na gołym `production` nie trafi nigdy.',
        );

        // Świadomie NIE dopisujemy pełnej nazwy z projektem do `case` —
        // zmiana nazwy projektu w Railwayu nie ma prawa po cichu wyłączyć
        // testu dymnego.
        $this->assertStringNotContainsString(
            'ideal-exploration / production)',
            $yaml,
            'W `case` stoi pełna nazwa razem z projektem. Zmiana nazwy projektu '.
            'w panelu Railwaya wyłączyłaby wtedy test dymny po cichu.',
        );
    }

    #[Test]
    public function test_pominiety_test_dymny_konczy_job_niepowodzeniem(): void
    {
        $yaml = $this->workflow();

        $this->assertMatchesRegularExpression(
            "/steps\.target\.outputs\.pomin\s*==\s*'1'\s*&&\s*\n?\s*github\.event\.deployment_status\.state\s*==\s*'success'/",
            $yaml,
            'Brak kroku, który kończy job niepowodzeniem, gdy UDANE wdrożenie nie '.
            'zostało dymnie sprawdzone. Bez tego job świeci zielono, nie sprawdzając '.
            'niczego — a to wygląda identycznie jak test, który przeszedł.',
        );

        $this->assertMatchesRegularExpression(
            '/::error title=Test dymny nie został uruchomiony/',
            $yaml,
            'Krok ma zgłaszać błąd widoczny w interfejsie GitHuba, nie tylko kończyć '.
            'się kodem wyjścia.',
        );
    }

    #[Test]
    public function test_podsumowanie_pokazuje_wynik_kroku_a_nie_status_calego_joba(): void
    {
        $yaml = $this->workflow();

        $this->assertStringContainsString(
            'steps.dymny.outcome',
            $yaml,
            'Podsumowanie raportuje `job.status` zamiast wyniku samego kroku. '.
            'Dlatego pisało „success" nad pominiętym testem dymnym: job jako całość '.
            'przeszedł, bo pominięty krok nie jest porażką.',
        );

        $this->assertMatchesRegularExpression(
            '/id:\s*dymny/',
            $yaml,
            'Krok testu dymnego nie ma `id: dymny`, więc `steps.dymny.outcome` '.
            'jest puste i podsumowanie znowu nic nie mówi.',
        );
    }
}

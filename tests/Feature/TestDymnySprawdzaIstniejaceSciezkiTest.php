<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test dymny po wdrożeniu nie może sprawdzać ścieżek, których nie ma.
 *
 * CO SIĘ STAŁO 9 WRZEŚNIA 2026
 * `.github/workflows/deploy.yml` w kroku „Test dymny" wymagał, żeby
 * `/logowanie` oddawało HTTP 200. Ta trasa nie istnieje — formularz logowania
 * stoi pod `/login`, a `/logowanie` jest tylko przedrostkiem dla
 * `/logowanie/kod` (drugi krok weryfikacji dwuetapowej). Sprawdzone na żywej
 * produkcji: `/logowanie` oddaje **404**.
 *
 * Nikt tego nie zauważył, bo ten test dymny **nie uruchomił się ani razu**:
 * workflow „Deploy" miał wtedy 249 przebiegów i wszystkie z conclusion
 * `skipped`. Dwie usterki nałożone jedna na drugą — bramka, która nigdy nie
 * przepuszcza, i za nią kontrola, która by oblała.
 *
 * DLACZEGO TEN TEST ISTNIEJE
 * Bramkę da się naprawić raz. Rozjazd między ścieżkami w workflow a trasami
 * w aplikacji wróci przy pierwszej zmianie nazwy trasy — i wróci cicho, bo
 * test dymny chodzi dopiero PO wdrożeniu, na produkcji, gdy jest już za późno
 * na tanią poprawkę. Ten test przenosi to sprawdzenie do CI, przed scaleniem.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że test dymny się uruchamia (tego z PHP sprawdzić nie da się wcale — mówi
 * o tym historia przebiegów w GitHub Actions) ani że produkcja odpowiada
 * tak, jak workflow oczekuje. Dowodzi jednej rzeczy: że ścieżki, po których
 * pyta, są zarejestrowanymi trasami tej aplikacji.
 */
class TestDymnySprawdzaIstniejaceSciezkiTest extends TestCase
{
    private function workflowWdrozenia(): string
    {
        $sciezka = base_path('.github/workflows/deploy.yml');

        $this->assertFileExists(
            $sciezka,
            'Nie ma .github/workflows/deploy.yml. Jeśli plik przeniesiono, popraw ścieżkę tutaj.',
        );

        return (string) file_get_contents($sciezka);
    }

    /**
     * Ścieżki, których test dymny oczekuje z kodem 200 — czyli te, które
     * MUSZĄ istnieć. Wywołania oczekujące 404 (jest tam celowe sprawdzenie
     * strony błędu) świadomie pomijamy: ich sensem jest właśnie nieistnienie.
     *
     * @return list<string>
     */
    private function sciezkiOczekujace200(string $workflow): array
    {
        $sciezki = [];

        foreach (preg_split('/\r\n|\n|\r/', $workflow) ?: [] as $wiersz) {
            $bezKomentarza = (string) preg_replace('/#.*$/', '', $wiersz);

            if (preg_match('/^\s*check\s+(\S+)\s+(\d{3})\s/', $bezKomentarza, $dopasowanie) !== 1) {
                continue;
            }

            [, $sciezka, $kod] = $dopasowanie;

            if ($kod !== '200') {
                continue;
            }

            // Ścieżki budowane w locie (`/nie-ma-takiej-strony-$(date +%s)`)
            // nie są trasami i nie mają być sprawdzane.
            if (str_contains($sciezka, '$')) {
                continue;
            }

            $sciezki[] = $sciezka;
        }

        return $sciezki;
    }

    #[Test]
    public function kazda_sciezka_z_testu_dymnego_jest_zarejestrowana_trasa(): void
    {
        $sciezki = $this->sciezkiOczekujace200($this->workflowWdrozenia());

        // Kontrola metody pomiaru: gdyby wyrażenie przestało cokolwiek łapać,
        // ten test przechodziłby zawsze, nie sprawdzając nic. Pięć sprawdzeń
        // w kroku „Test dymny" istniało, gdy to pisałem; trzy to margines
        // na przyszłe zmiany, ale nie na zero.
        $this->assertGreaterThanOrEqual(
            3,
            count($sciezki),
            'W kroku „Test dymny" nie widzę już ścieżek oczekujących 200. Albo zmienił się '
            .'kształt wywołań `check`, albo czytam zły plik — jedno i drugie unieważnia ten test.',
        );

        $trasy = [];

        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            if (in_array('GET', $trasa->methods(), true)) {
                $trasy[] = '/'.ltrim($trasa->uri(), '/');
            }
        }

        foreach ($sciezki as $sciezka) {
            $this->assertContains(
                $sciezka,
                $trasy,
                "Test dymny po wdrożeniu wymaga HTTP 200 od `{$sciezka}`, a takiej trasy GET nie ma "
                .'w aplikacji. Tak było z `/logowanie` — formularz logowania stoi pod `/login`. '
                .'Kontrola, która pyta o nieistniejącą ścieżkę, wywraca wdrożenie albo (gorzej) '
                .'nie wywraca niczego, bo nikt jej nie uruchamia.',
            );
        }
    }
}

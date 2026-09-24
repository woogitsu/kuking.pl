<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * #994 — polityka prywatności nie może wiązać retencji dziennika serwera
 * z życiem instancji.
 *
 * Polityka mówiła: „Dzienniki serwera żyją tyle, ile działająca instancja
 * serwisu”. Produkcja loguje jednak na `stderr` (`.railway/railway.ts`),
 * a Railway przechwytuje `stdout`/`stderr` do własnego narzędzia dzienników.
 * Wpisy przeżywają więc restart i wymianę instancji — zdanie opisywało
 * człowiekowi krótszy i inny cykl życia danych niż prawdziwy.
 *
 * Test czyta odbiornik z konfiguracji wdrożenia i wymaga, żeby polityka
 * opisywała go zgodnie z nim. Zmiana `LOG_CHANNEL` zapali ten test — to jest
 * moment, w którym ktoś musi poprawić tekst polityki (`docs/DEPLOYMENT.md`).
 *
 * Liczby dni test nie sprawdza: nikt w repozytorium jej nie potwierdził.
 */
final class PolitykaOpisujeRetencjeDziennikaSerweraTest extends TestCase
{
    private function polityka(): string
    {
        return (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
    }

    /** Wiersz tabeli z §2 o błędach technicznych. */
    private function wierszBledow(): string
    {
        preg_match('/^\| Wykrywanie i naprawa błędów technicznych \|.*$/mu', $this->polityka(), $trafienie);

        // KONTROLA METODY: bez wiersza asercje niżej badałyby pusty tekst
        // (`docs/PULAPKI_TESTOW.md` §2).
        $this->assertNotEmpty($trafienie, 'Polityka nie ma już wiersza o błędach technicznych — test przestał mierzyć.');

        return $trafienie[0];
    }

    public function test_produkcja_loguje_na_stderr_przechwytywany_przez_railway(): void
    {
        $railway = (string) file_get_contents(base_path('.railway/railway.ts'));

        $this->assertMatchesRegularExpression(
            '/^\s*LOG_CHANNEL:\s*"stderr",/m',
            $railway,
            'Produkcja nie loguje już na stderr. Polityka prywatności (§2 i tabela dostawców) '
            .'opisuje odbiornik dziennika — popraw ją razem z tym testem (docs/DEPLOYMENT.md).',
        );
    }

    public function test_polityka_nie_wiaze_retencji_dziennika_z_instancja(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/instancj/iu',
            $this->wierszBledow(),
            'Polityka znowu wiąże dziennik serwera z życiem instancji. Railway przechowuje '
            .'logi ze stderr niezależnie od instancji, zgodnie z planem konta (#994).',
        );
    }

    public function test_polityka_nazywa_railway_jako_odbiorce_dziennika(): void
    {
        $this->assertStringContainsString(
            'Railway',
            $this->wierszBledow(),
            'Wiersz o błędach technicznych nie mówi, kto przechowuje dziennik serwera (#994).',
        );

        $this->assertMatchesRegularExpression(
            '/^\| Railway \|[^|\n]*dziennik[^|\n]*\|/mu',
            $this->polityka(),
            'Tabela dostawców nie mówi, że Railway przechowuje dziennik serwera (#994).',
        );
    }
}

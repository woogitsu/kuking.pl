<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Import przepisu z PDF (D-300) woła `pdfinfo` i `pdftotext` z pakietu
 * systemowego `poppler-utils`. To nie jest zależność Composera, więc
 * `composer install` jej nie dociągnie, a jej brak nie wywróci budowy obrazu
 * — wyjdzie dopiero na produkcji, komunikatem „odczyt PDF chwilowo nie
 * działa" przy każdym pliku.
 *
 * Dlatego test czyta PRAWDZIWY Dockerfile i sprawdza, że pakiet jest
 * instalowany w etapie `runtime` (tym, który trafia na Railway), a nie tylko
 * gdzieś w pliku, oraz że job testów w CI go doinstalowuje — testy importu
 * PDF uruchamiają prawdziwe narzędzia.
 */
final class ObrazMaNarzedziaPdfTest extends TestCase
{
    public function test_etap_runtime_instaluje_poppler_utils(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile');

        $start = strpos($dockerfile, ' AS runtime');
        $this->assertNotFalse($start, 'Dockerfile nie ma etapu `runtime`.');

        $etap = substr($dockerfile, $start);
        $nastepny = strpos($etap, "\nFROM ");
        $etap = $nastepny === false ? $etap : substr($etap, 0, $nastepny);

        $this->assertMatchesRegularExpression(
            '/apt-get install[^&]*\bpoppler-utils\b/s',
            $etap,
            'Etap `runtime` nie instaluje `poppler-utils` — import PDF na produkcji nie zadziała.',
        );
    }

    public function test_job_testow_w_ci_doinstalowuje_poppler_utils(): void
    {
        $ci = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

        $this->assertMatchesRegularExpression(
            '/apt-get install[^\n]*\bpoppler-utils\b/',
            $ci,
            'CI nie instaluje `poppler-utils`, a testy importu PDF wołają prawdziwe `pdftotext`.',
        );
    }
}

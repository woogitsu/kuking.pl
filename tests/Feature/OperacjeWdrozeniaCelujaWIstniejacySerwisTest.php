<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ścieżka operacyjna wdrożenia musi celować w serwis, który ISTNIEJE.
 *
 * DLACZEGO TO JEST TEST, A NIE UWAGA W RUNBOOKU
 * Bo job `operate` w `.github/workflows/deploy.yml` — ten, po który sięga się
 * w trakcie awarii — odwoływał się do serwisów `web`, `worker` i `scheduler`,
 * których w Railway nigdy nie było. Zmierzone connectorem 9 września 2026:
 * projekt `ideal-exploration` ma dwa serwisy, `Postgres` i `kuking.pl`.
 * Rozbicie na trzy stoi w `.railway/railway.ts`, ale `railway config apply`
 * nie zostało uruchomione ani razu, więc nie obowiązuje.
 *
 * Skutki były dwa, i drugi jest groźniejszy od pierwszego:
 *   1. `migrate` i `rollback` wołały `railway ssh --service web`, czyli
 *      padały na nieistniejącym serwisie — awaria głośna, więc znośna;
 *   2. `redeploy` przebiegał pętlą po trzech nazwach, a każde niepowodzenie
 *      zbywał ostrzeżeniem `|| echo "pomijam"`. Gdy nie istniała ŻADNA
 *      z nich, krok kończył się ZIELONO, nie restartując niczego. To jest
 *      dokładnie ten kształt usterki, który kosztował projekt cały dzień
 *      przy poczcie: narzędzie mówi „zrobione", a nie zrobiło nic.
 *
 * Test czyta plik workflow, bo tylko tam ta wiedza żyje — GitHub Actions nie
 * da się uruchomić z testu. Czego ten test więc NIE dowodzi: że serwis o tej
 * nazwie naprawdę stoi w Railway. Dowodzi tylko, że workflow nie zawiera
 * nazw, o których wiemy, że są zmyślone, i że ma je w jednym miejscu.
 *
 * Gdy kiedyś dojdzie do rozbicia na trzy serwisy przez `railway config apply`,
 * ten test trzeba poprawić RAZEM z workflow — i wtedy będzie mówił prawdę
 * o nowej topologii, a nie o starej.
 */
class OperacjeWdrozeniaCelujaWIstniejacySerwisTest extends TestCase
{
    /**
     * Nazwy serwisów, które deklaruje `.railway/railway.ts`, a których
     * w Railway nie ma. Odwołanie do nich z workflow jest usterką.
     */
    private const ZMYSLONE_NAZWY = ['web', 'worker', 'scheduler'];

    private function workflowWdrozenia(): string
    {
        $sciezka = base_path('.github/workflows/deploy.yml');

        $this->assertFileExists(
            $sciezka,
            'Nie ma .github/workflows/deploy.yml. Jeśli plik przeniesiono, popraw ścieżkę tutaj.'
        );

        return (string) file_get_contents($sciezka);
    }

    #[Test]
    public function operacje_biora_nazwe_serwisu_z_jednego_miejsca(): void
    {
        $workflow = $this->workflowWdrozenia();

        $this->assertStringContainsString(
            'APP_SERVICE: kuking.pl',
            $workflow,
            'Job operate nie ma już zmiennej APP_SERVICE z nazwą prawdziwego serwisu Railway. '
            .'Nazwa serwisu ma stać w JEDNYM miejscu — rozsypana po krokach rozjeżdża się z rzeczywistością.'
        );
    }

    #[Test]
    public function zadna_operacja_nie_wola_nieistniejacego_serwisu(): void
    {
        $workflow = $this->workflowWdrozenia();

        // Sprawdzamy tylko WYWOŁANIA CLI, nie komentarze — komentarze mają
        // prawo (i obowiązek) tłumaczyć, dlaczego tych nazw tu nie ma.
        $wiersze = preg_split('/\R/', $workflow) ?: [];

        foreach ($wiersze as $numer => $wiersz) {
            $bezKomentarza = preg_replace('/#.*$/', '', $wiersz) ?? '';

            if (! str_contains($bezKomentarza, 'railway ')) {
                continue;
            }

            foreach (self::ZMYSLONE_NAZWY as $nazwa) {
                $this->assertDoesNotMatchRegularExpression(
                    '/--service\s+"?'.preg_quote($nazwa, '/').'"?(\s|$)/',
                    $bezKomentarza,
                    "Wiersz ".($numer + 1)." woła Railway z serwisem `{$nazwa}`, którego w projekcie nie ma. "
                    .'Użyj "$APP_SERVICE". Jeśli topologia naprawdę się zmieniła (uruchomiono '
                    .'`railway config apply`), popraw ten test razem z workflow.'
                );
            }
        }
    }

    #[Test]
    public function redeploy_nie_zbywa_porazki_ostrzezeniem(): void
    {
        $workflow = $this->workflowWdrozenia();

        $od = mb_strpos($workflow, '- name: Redeploy');
        $this->assertNotFalse(
            $od,
            'W deploy.yml nie ma już kroku „Redeploy". Jeśli zmienił nazwę, popraw ten test razem z nim.'
        );

        $do = mb_strpos($workflow, '- name:', $od + 10);
        $krok = $do === false
            ? mb_substr($workflow, $od)
            : mb_substr($workflow, $od, $do - $od);

        // Komentarze wycinamy, i to nie z ostrożności: komentarz w tym kroku
        // CYTUJE `|| echo "pomijam"`, żeby wyjaśnić, czego tam nie ma. Bez
        // wycięcia test wywracałby się o własne uzasadnienie.
        $komendy = implode("\n", array_map(
            static fn (string $wiersz): string => (string) preg_replace('/#.*$/', '', $wiersz),
            preg_split('/\R/', $krok) ?: []
        ));

        // Kontrola metody pomiaru: jeśli nie widzimy tu wywołania redeploya,
        // czytamy nie ten fragment, a sprawdzenie niżej byłoby puste.
        $this->assertStringContainsString(
            'railway redeploy',
            $komendy,
            'W kroku „Redeploy" nie ma wywołania `railway redeploy`. Czytam zły fragment pliku.'
        );

        $this->assertStringNotContainsString(
            '|| echo',
            $komendy,
            'Krok „Redeploy" znowu zbywa niepowodzenie echem. Redeploy, który nic nie zrobił '
            .'i zameldował sukces, jest gorszy od redeploya, który padł — bo po tym pierwszym '
            .'nikt nie szuka przyczyny.'
        );
    }
}

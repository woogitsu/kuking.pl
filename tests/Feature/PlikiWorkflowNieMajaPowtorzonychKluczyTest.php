<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Powtórzony klucz w pliku workflow kasuje CAŁY plik — po cichu.
 *
 * CO SIĘ STAŁO 9 WRZEŚNIA 2026
 * W `deploy.yml` jeden krok dostał dwa klucze `if:` pod sobą:
 *
 *     - name: Rejestracja wydania w Sentry
 *       if: steps.target.outputs.pomin != '1'
 *       if: vars.SENTRY_ORG != '' && vars.SENTRY_PROJECT != ''
 *
 * Żaden zwykły parser YAML na to nie krzyknie — drugi klucz cicho nadpisuje
 * pierwszy. GitHub Actions ODRZUCA WTEDY CAŁY PLIK. Skutki, po kolei:
 *
 *   1. każdy push tworzył przebieg `deploy.yml` kończący się porażką
 *      BEZ ANI JEDNEGO JOBA (nie ma czego uruchomić, plik jest nieważny);
 *   2. ta porażka zatruwała check suite commita;
 *   3. Railway ma włączone „Wait for CI" (`checkSuites: true`), więc
 *      POMIJAŁ każdy deploy — status `SKIPPED`, jeden po drugim.
 *
 * Produkcja stała ponad cztery godziny na starej wersji, podczas gdy CI
 * świeciło na zielono, PR-y się scalały, a wdrożenia „przechodziły". Nikt
 * nie dostał ani jednego czerwonego sygnału tam, gdzie się patrzy.
 *
 * DLACZEGO TEST W PHP, SKORO JEST ACTIONLINT
 * Bo `actionlint` w CI mówi to samo dopiero PO pushu, a wtedy zepsuty plik
 * już zdążył zatruć check suite i zablokować wdrożenie. Ten test chodzi
 * w `php artisan test`, czyli także w haku `pre-push` — czyli ZANIM zepsucie
 * dotrze na GitHuba. Jedno i drugie ma sens, w tej kolejności.
 */
class PlikiWorkflowNieMajaPowtorzonychKluczyTest extends TestCase
{
    /**
     * Klucze powtórzone w obrębie jednego odwzorowania YAML.
     *
     * Świadomie NIE jest to pełny parser: pilnujemy jednej, konkretnej
     * pomyłki, którą już popełniliśmy. Pomijamy komentarze, wiersze
     * w blokach tekstowych (`run: |`, `description: >-`) i wszystko,
     * co nie wygląda na „klucz:".
     *
     * @return list<string>
     */
    private function powtorzoneKlucze(string $yaml): array
    {
        $znalezione = [];

        /** @var array<int, array<string, int>> $poziomy klucze widziane na danym wcięciu */
        $poziomy = [];

        $wciecieBloku = null;

        foreach (preg_split('/\r\n|\n|\r/', $yaml) ?: [] as $numer => $wiersz) {
            if (trim($wiersz) === '' || preg_match('/^\s*#/', $wiersz) === 1) {
                continue;
            }

            $wciecie = strlen($wiersz) - strlen(ltrim($wiersz, ' '));

            // Wewnątrz bloku tekstowego (`|`, `>-`) nie ma kluczy — jest treść,
            // która ma pełne prawo zawierać dwukropki i wyglądać jak YAML.
            if ($wciecieBloku !== null) {
                if ($wciecie > $wciecieBloku) {
                    continue;
                }

                $wciecieBloku = null;
            }

            foreach (array_keys($poziomy) as $glebiej) {
                if ($glebiej > $wciecie) {
                    unset($poziomy[$glebiej]);
                }
            }

            $tresc = ltrim($wiersz, ' ');

            // Nowy element listy zaczyna NOWE odwzorowanie na tym wcięciu —
            // bez tego dwa kroki z kluczem `name:` wyglądałyby jak duplikat.
            if (str_starts_with($tresc, '- ')) {
                unset($poziomy[$wciecie]);
                $tresc = substr($tresc, 2);
                $wciecie += 2;

                foreach (array_keys($poziomy) as $glebiej) {
                    if ($glebiej >= $wciecie) {
                        unset($poziomy[$glebiej]);
                    }
                }
            }

            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*):(\s|$)/', $tresc, $dopasowanie) !== 1) {
                continue;
            }

            $klucz = $dopasowanie[1];
            $reszta = trim(substr($tresc, strlen($klucz) + 1));

            if ($reszta === '|' || $reszta === '|-' || $reszta === '>' || $reszta === '>-') {
                $wciecieBloku = $wciecie;
            }

            if (isset($poziomy[$wciecie][$klucz])) {
                $znalezione[] = sprintf(
                    'klucz „%s" powtórzony w wierszu %d (pierwszy raz w %d)',
                    $klucz,
                    $numer + 1,
                    $poziomy[$wciecie][$klucz],
                );
            }

            $poziomy[$wciecie][$klucz] = $numer + 1;
        }

        return $znalezione;
    }

    /**
     * @return list<array{string}>
     */
    public static function plikiWorkflow(): array
    {
        $pliki = glob(base_path('.github/workflows/*.yml')) ?: [];

        return array_map(static fn (string $s): array => [$s], array_values($pliki));
    }

    #[Test]
    public function zaden_plik_workflow_nie_ma_powtorzonego_klucza(): void
    {
        $pliki = self::plikiWorkflow();

        $this->assertGreaterThan(
            3,
            count($pliki),
            'Znalazłem mniej niż cztery pliki workflow — czytam złe miejsce, więc ten test niczego nie mierzy.',
        );

        foreach ($pliki as [$sciezka]) {
            $powtorzone = $this->powtorzoneKlucze((string) file_get_contents($sciezka));

            $this->assertSame(
                [],
                $powtorzone,
                basename($sciezka).': '.implode('; ', $powtorzone)."\n"
                .'GitHub Actions odrzuca CAŁY plik z powtórzonym kluczem — push tworzy wtedy przebieg '
                .'kończący się porażką bez ani jednego joba, a taka porażka zatruwa check suite commita '
                ."i Railway (Wait for CI) POMIJA wdrożenie.\n"
                .'Tak właśnie produkcja stała cztery godziny 9 września. Scal warunki w jeden klucz.',
            );
        }
    }

    /**
     * Kontrola metody pomiaru: skaner ma naprawdę widzieć duplikat, a nie
     * zwracać pustą listę na wszystko.
     */
    #[Test]
    public function skaner_wykrywa_duplikat_dokladnie_tego_ksztaltu_co_awaria(): void
    {
        $zepsuty = <<<'YAML'
        jobs:
          verify:
            steps:
              - name: Pierwszy
                run: echo tak
              - name: Rejestracja wydania w Sentry
                if: steps.target.outputs.pomin != '1'
                if: vars.SENTRY_ORG != ''
                uses: getsentry/action-release@v3
        YAML;

        $this->assertNotSame([], $this->powtorzoneKlucze($zepsuty), 'Skaner nie widzi duplikatu, który położył wdrożenia.');
    }

    /**
     * Druga kontrola: dwa kroki z kluczem `name:` to NIE jest duplikat.
     * Bez tego test oblewałby każdy poprawny plik i zostałby wyłączony.
     */
    #[Test]
    public function powtorzony_klucz_w_dwoch_roznych_krokach_nie_jest_bledem(): void
    {
        $poprawny = <<<'YAML'
        jobs:
          verify:
            steps:
              - name: Pierwszy
                run: |
                  echo "if: to jest tekst, nie klucz"
                  echo "if: i drugi raz też"
              - name: Drugi
                run: echo dwa
        YAML;

        $this->assertSame([], $this->powtorzoneKlucze($poprawny));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #1957 — `ceny-warzyw-auto.yml` przekazywał osobisty token zapisu
 * (`CENY_WARZYW_PAT`, `Contents: read/write` + `Pull requests: read/write`)
 * do `actions/checkout`, a zaraz potem TEN SAM przebieg uruchamiał kod
 * z repozytorium (`python3 scripts/ceny-warzyw-zsrir-pobierz.py` i test
 * parsera). Checkout zapisuje token w konfiguracji gita na cały przebieg —
 * skompromitowany skrypt (albo jego zależność, np. `openpyxl`) mógł go
 * odczytać (`git config --local --get-regexp 'credential|url'`) i wynieść
 * poza kontrolę tego joba.
 *
 * NAPRAWA: dwa joby. `pobierz` uruchamia kod repozytorium z checkoutem BEZ
 * tokenu (`persist-credentials: false`, brak `token:`). `publikuj` ma
 * token, ale poniżej checkoutu wykonuje wyłącznie polecenia `git`/`gh`
 * zapisane wprost w pliku workflow — żaden skrypt z `scripts/`.
 *
 * CO TEN TEST SPRAWDZA
 *  1. job, który odwołuje się do `secrets.CENY_WARZYW_PAT`, nie zawiera
 *     w żadnym swoim kroku wywołania czegokolwiek z katalogu `scripts/`
 *     (kontrola WĄSKA: to jest jedyny katalog z kodem, który ten workflow
 *     w ogóle uruchamia — `docs/PULAPKI_TESTOW.md` §2 ostrzega przed
 *     kontrolą, która „łapie wszystko");
 *  2. job, który NIE ma dostępu do sekretu, ma jednak `persist-credentials:
 *     false` na swoim checkoucie — token nie ma się gdzie zapisać, nawet
 *     przez pomyłkę przy następnej zmianie tego pliku;
 *  3. KONTROLA DODATNIA (`docs/PULAPKI_TESTOW.md` §2): syntetyczny,
 *     jednojobowy YAML odtwarzający dokładnie usterkę sprzed #1957
 *     (`token:` w checkoucie, a niżej `run: python3 scripts/...`
 *     W TYM SAMYM jobie) MUSI zostać wykryty — inaczej reguła 1 mierzyłaby
 *     pustkę;
 *  4. KONTROLA UJEMNA: syntetyczny YAML z dwoma jobami w kształcie naprawy
 *     (token i kod repozytorium w RÓŻNYCH jobach) NIE ma zostać wykryty —
 *     inaczej reguła oblewałaby także poprawny kod.
 */
final class WorkflowCenNieUruchamiaKoduZTokenemZapisuTest extends TestCase
{
    private const PLIK = '.github/workflows/ceny-warzyw-auto.yml';

    #[Test]
    public function job_z_tokenem_zapisu_nie_uruchamia_kodu_repozytorium(): void
    {
        $yaml = $this->wczytaj(self::PLIK);
        $joby = $this->joby($yaml);

        $this->assertNotSame([], $joby, 'Nie znalazłem ani jednego joba w ceny-warzyw-auto.yml — czytam zły fragment pliku.');

        $zTokenem = array_filter($joby, fn (string $blok): bool => $this->uzywaTokenuDoZapisu($blok));
        $this->assertNotSame(
            [],
            $zTokenem,
            'Żaden job w ceny-warzyw-auto.yml nie odwołuje się już do secrets.CENY_WARZYW_PAT — '
            .'jeśli token zniknął stąd celowo, ten test stał się nieaktualny i trzeba go poprawić.',
        );

        foreach ($zTokenem as $nazwa => $blok) {
            $this->assertFalse(
                $this->uruchamiaKodRepozytorium($blok),
                "Job „{$nazwa}” ma dostęp do secrets.CENY_WARZYW_PAT i mimo to uruchamia kod z katalogu "
                .'scripts/ — dokładnie usterka #1957: skompromitowany skrypt mógłby odczytać token zapisu '
                .'z konfiguracji gita, którą zostawia tam ten sam checkout.',
            );
        }
    }

    #[Test]
    public function job_bez_tokenu_ktory_uruchamia_kod_repozytorium_ma_persist_credentials_false(): void
    {
        $yaml = $this->wczytaj(self::PLIK);
        $joby = $this->joby($yaml);

        $bezTokenu = array_filter(
            $joby,
            fn (string $blok): bool => ! $this->uzywaTokenuDoZapisu($blok) && $this->uruchamiaKodRepozytorium($blok),
        );

        $this->assertNotSame(
            [],
            $bezTokenu,
            'Nie znalazłem joba bez tokenu, który uruchamia kod repozytorium — kontrola metody: '
            .'sprawdzenie persist-credentials niżej byłoby puste, nie zielone.',
        );

        foreach ($bezTokenu as $nazwa => $blok) {
            // Bez komentarzy YAML: zdanie „z `persist-credentials: false`”
            // w komentarzu nad krokiem nie może zastąpić samego ustawienia.
            $this->assertMatchesRegularExpression(
                '/persist-credentials:\s*false/',
                (string) preg_replace('/^\s*#.*$/m', '', $blok),
                "Job „{$nazwa}” uruchamia kod repozytorium i nie ma dostępu do secrets.CENY_WARZYW_PAT, "
                .'ale jego checkout nie ma `persist-credentials: false` — przy następnej zmianie tego pliku '
                .'token mógłby tu wrócić bez tego zabezpieczenia.',
            );
        }
    }

    /** Kontrola dodatnia: dokładnie usterka #1957, w jednym jobie. */
    #[Test]
    public function kontrola_dodatnia_wykrywa_token_i_kod_repozytorium_w_jednym_jobie(): void
    {
        $zly = <<<'YAML'
            jobs:
              odswiez:
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@0000000000000000000000000000000000000000 # v7.0.1
                    with:
                      token: ${{ secrets.CENY_WARZYW_PAT }}
                  - name: Pobierz ceny
                    run: python3 scripts/ceny-warzyw-zsrir-pobierz.py --zapisz
                  - name: Push
                    run: git push origin HEAD:branch
            YAML;

        $joby = $this->joby($zly);
        $this->assertCount(1, $joby, 'Kontrola dodatnia nie znalazła joba w syntetycznym YAML-u — parser się zepsuł.');

        $blok = $joby['odswiez'];
        $this->assertTrue(
            $this->uzywaTokenuDoZapisu($blok) && $this->uruchamiaKodRepozytorium($blok),
            'Kontrola dodatnia: syntetyczny YAML z tokenem i `python3 scripts/...` w TYM SAMYM jobie '
            .'powinien zostać wykryty jako łączący token zapisu z kodem repozytorium.',
        );
    }

    /** Kontrola ujemna: naprawiony kształt (dwa joby) nie ma zostać złapany. */
    #[Test]
    public function kontrola_ujemna_nie_lapie_naprawionego_ksztaltu_dwoch_jobow(): void
    {
        $dobry = <<<'YAML'
            jobs:
              pobierz:
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@0000000000000000000000000000000000000000 # v7.0.1
                    with:
                      persist-credentials: false
                  - name: Pobierz ceny
                    run: python3 scripts/ceny-warzyw-zsrir-pobierz.py --zapisz
              publikuj:
                needs: pobierz
                runs-on: ubuntu-latest
                steps:
                  - uses: actions/checkout@0000000000000000000000000000000000000000 # v7.0.1
                    with:
                      token: ${{ secrets.CENY_WARZYW_PAT }}
                  - name: Push
                    run: git push origin HEAD:branch
            YAML;

        $joby = $this->joby($dobry);
        $this->assertCount(2, $joby, 'Kontrola ujemna nie znalazła dwóch jobów w syntetycznym YAML-u — parser się zepsuł.');

        foreach ($joby as $nazwa => $blok) {
            if ($this->uzywaTokenuDoZapisu($blok)) {
                $this->assertFalse(
                    $this->uruchamiaKodRepozytorium($blok),
                    "Kontrola ujemna: job „{$nazwa}” z tokenem w naprawionym kształcie nie powinien "
                    .'być rozpoznany jako uruchamiający kod repozytorium — inaczej reguła oblewałaby także poprawny kod.',
                );
            }
        }
    }

    private function wczytaj(string $sciezka): string
    {
        $pelna = base_path($sciezka);
        $this->assertFileExists($pelna, "Nie ma pliku {$sciezka}.");

        return (string) file_get_contents($pelna);
    }

    /**
     * Rozbija YAML na bloki jobów najwyższego poziomu (`jobs:` → nazwa
     * z wcięciem dwóch spacji). Celowo prosty, liniowy parser — ten sam
     * styl co reszta strażników workflowów w tym repozytorium
     * (np. `CiDajeKazdemuJobowiWlasneNarzedziaTest`), bez zależności od
     * pełnego parsera YAML.
     *
     * @return array<string, string> nazwa joba → cały jego blok (surowy tekst)
     */
    private function joby(string $yaml): array
    {
        $wiersze = preg_split('/\r\n|\n|\r/', $yaml) ?: [];
        $wJobs = false;
        $joby = [];
        $biezacy = null;

        foreach ($wiersze as $wiersz) {
            if (preg_match('/^jobs:\s*$/', $wiersz) === 1) {
                $wJobs = true;

                continue;
            }

            if (! $wJobs) {
                continue;
            }

            // Koniec sekcji `jobs:`: wiersz niepusty z wcięciem mniejszym
            // niż dwie spacje (nowa sekcja najwyższego poziomu).
            if (preg_match('/^\S/', $wiersz) === 1) {
                break;
            }

            if (preg_match('/^  (\S[\w-]*):\s*$/', $wiersz, $m) === 1) {
                $biezacy = $m[1];
                $joby[$biezacy] = '';

                continue;
            }

            if ($biezacy !== null) {
                $joby[$biezacy] .= $wiersz."\n";
            }
        }

        return $joby;
    }

    /**
     * Czy blok joba zawiera krok, który uruchamia kod z katalogu
     * `scripts/` (jedyny katalog z kodem, który ten workflow w ogóle
     * wykonuje — stąd kontrola wąska, nie „każdy `run:`").
     */
    private function uruchamiaKodRepozytorium(string $blokJoba): bool
    {
        // Wymagamy wywołania interpretera przed ścieżką, nie samego
        // wystąpienia `scripts/...py` — ten sam napis pojawia się też
        // jako czysty TEKST w opisie automatycznego PR-a (`gh pr create
        // --body "... scripts/ceny-warzyw-zsrir-pobierz.py ..."`), a to
        // nie jest uruchomienie kodu.
        return preg_match('/\bpython3?\s+scripts\/\S+\.py\b/', $blokJoba) === 1;
    }

    /**
     * Czy blok joba UŻYWA `secrets.CENY_WARZYW_PAT` do zapisu — jako
     * `token:` checkoutu (persystuje poświadczenie w konfiguracji gita)
     * albo jako `GH_TOKEN`/`GITHUB_TOKEN` (używa go `gh`). Celowo węższe
     * niż samo `str_contains($blok, 'secrets.CENY_WARZYW_PAT')`: job
     * `pobierz` tylko SPRAWDZA, czy sekret jest ustawiony
     * (`${{ secrets.CENY_WARZYW_PAT != '' }}`), nie używa go do niczego —
     * szeroka kontrola łapałaby też ten krok i fałszywie oskarżała job,
     * który nigdy nie dostaje poświadczenia (`docs/PULAPKI_TESTOW.md` §2).
     */
    private function uzywaTokenuDoZapisu(string $blokJoba): bool
    {
        return preg_match(
            '/\b(?:token|GH_TOKEN|GITHUB_TOKEN):\s*\$\{\{\s*secrets\.CENY_WARZYW_PAT\s*\}\}/',
            $blokJoba,
        ) === 1;
    }
}

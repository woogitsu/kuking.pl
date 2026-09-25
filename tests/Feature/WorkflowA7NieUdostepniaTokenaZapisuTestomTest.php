<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `audyt-a7-final-check.yml` nie daje tokena zapisu jobowi, który uruchamia
 * kod z gałęzi (#1742).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Cały workflow miał `permissions: contents: write` i JEDEN job, który po
 * kolei: robił checkout z `persist-credentials: true`, uruchamiał
 * `composer install`, Pint i `php artisan test` — a dopiero POTEM (ale
 * nadal w tym samym jobie, z tym samym tokenem i tymi samymi zachowanymi
 * poświadczeniami gita) commitował i pushował na gałąź audytową. Każdy
 * z tych kroków miał więc dostęp do tokena zapisu do repozytorium: paczka
 * Composera, skrypt Pinta albo zależność testowa mogły w tym oknie użyć
 * zachowanych poświadczeń do zmiany repozytorium.
 *
 * CO PILNUJE TEN TEST
 *  1. domyślne uprawnienia workflow (przed `jobs:`) to `contents: read`;
 *  2. w całym pliku jest dokładnie JEDEN `contents: write` i dokładnie
 *     JEDNO `persist-credentials: true` — oba w tym samym, odseparowanym
 *     jobie zapisu;
 *  3. job, który robi checkout, Composer, Pint i testy, nie ma
 *     `contents: write` ani `persist-credentials: true` — jego checkout
 *     ma `persist-credentials: false`;
 *  4. job z tokenem zapisu NIE uruchamia `composer install`, Pinta ani
 *     testów — dostaje gotową łatkę formatowania, a nie token plus
 *     dowolny kod z gałęzi.
 *
 * CZEGO NIE PILNUJE
 * Czy `git push` w ogóle jest tu potrzebny — to świadoma decyzja tego
 * audytu (zapis sformatowanych dowodów z powrotem na gałąź). Ten test
 * pilnuje TYLKO tego, że token zapisu nie jest dostępny szerzej, niż ten
 * jeden cel wymaga.
 */
class WorkflowA7NieUdostepniaTokenaZapisuTestomTest extends TestCase
{
    private const PLIK = '.github/workflows/audyt-a7-final-check.yml';

    private function tresc(): string
    {
        return (string) file_get_contents(base_path(self::PLIK));
    }

    /** Blok tekstu joba o nazwie `$nazwa` — od nagłówka `  nazwa:` do następnego joba na tym wcięciu albo końca pliku. */
    private function blokJoba(string $tresc, string $nazwa): string
    {
        $wzorzec = '/^  '.preg_quote($nazwa, '/').':\n(.*?)(?=^  [a-zA-Z0-9_-]+:\n|\z)/ms';

        $trafil = preg_match($wzorzec, $tresc, $dopasowanie);

        $this->assertSame(
            1,
            $trafil,
            "Nie znaleziono joba `{$nazwa}` w ".self::PLIK.' — dopasuj ten test do nowego kształtu pliku.',
        );

        return $dopasowanie[1];
    }

    public function test_domyslne_uprawnienia_workflow_to_tylko_odczyt(): void
    {
        $tresc = $this->tresc();

        $przedJobami = substr($tresc, 0, (int) strpos($tresc, "\njobs:"));

        $this->assertMatchesRegularExpression(
            '/^permissions:\s*\n\s*contents:\s*read\s*$/m',
            $przedJobami,
            'Uprawnienia domyślne workflow (przed `jobs:`) mają być `contents: read`. '.
                'Job, który je potrzebuje szersze, ustawia je SAM, na swoim poziomie.',
        );
    }

    public function test_tylko_jeden_zapis_i_jedno_zachowane_poswiadczenie_w_calym_pliku(): void
    {
        $tresc = $this->tresc();

        $this->assertSame(
            1,
            preg_match_all('/^\s*contents:\s*write\s*$/m', $tresc),
            self::PLIK.' ma inną niż 1 liczbę wystąpień `contents: write`. Token zapisu ma dostawać '.
                'wyłącznie job, który naprawdę pushuje — nic więcej.',
        );

        $this->assertSame(
            1,
            preg_match_all('/^\s*persist-credentials:\s*true\s*$/m', $tresc),
            self::PLIK.' ma inną niż 1 liczbę wystąpień `persist-credentials: true`. Zachowane poświadczenia '.
                'checkoutu mają istnieć tylko w kroku, który naprawdę pushuje.',
        );
    }

    public function test_job_sprawdz_nie_ma_dostepu_do_tokena_zapisu(): void
    {
        $blok = $this->blokJoba($this->tresc(), 'sprawdz');

        $this->assertDoesNotMatchRegularExpression(
            '/contents:\s*write/',
            $blok,
            'Job `sprawdz` (checkout + Composer + Pint + testy) ma `contents: write`. '.
                'Ten job uruchamia kod z gałęzi — nie może mieć tokena zapisu.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/persist-credentials:\s*true/',
            $blok,
            'Job `sprawdz` zachowuje poświadczenia checkoutu (`persist-credentials: true`). '.
                'Composer, Pint i testy w tym jobie nie mogą mieć dostępu do zapisanego tokena.',
        );

        $this->assertMatchesRegularExpression(
            '/persist-credentials:\s*false/',
            $blok,
            'Job `sprawdz` nie ustawia jawnie `persist-credentials: false` przy checkoucie.',
        );
    }

    public function test_job_z_zapisem_nie_uruchamia_composera_pinta_ani_testow(): void
    {
        $blok = $this->blokJoba($this->tresc(), 'zapisz-formatowanie');

        $this->assertMatchesRegularExpression(
            '/contents:\s*write/',
            $blok,
            'Job `zapisz-formatowanie` powinien mieć `contents: write` — to jedyny job, który pushuje.',
        );

        foreach (['composer install', 'vendor/bin/pint', 'php artisan test'] as $polecenie) {
            $this->assertStringNotContainsString(
                $polecenie,
                $blok,
                "Job z tokenem zapisu uruchamia `{$polecenie}`. Token zapisu ma dostawać wyłącznie ".
                    'krok, który stosuje gotową łatkę i pushuje — nie kod z gałęzi.',
            );
        }
    }
}

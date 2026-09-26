<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Zrzut bazy w katalogu roboczym nie wjedzie do repozytorium przez
 * `git add .` (audyt A5-17, D-09).
 *
 * Pytamy PRAWDZIWEGO gita (`git check-ignore --no-index`), a nie własnego
 * matchera: chodzi o to, co zrobi `git add`, nie o to, co myślimy, że zrobi.
 * Kontrola dodatnia jest tu równie ważna: śledzone, celowe pliki SQL
 * (`database/reference/`, `docs/obciazenie/`, `scripts/`) nie mogą zostać
 * zakryte — wtedy nowego pliku w tych katalogach nie dałoby się dodać.
 */
class ZrzutyBazyPozaRepozytoriumTest extends TestCase
{
    private function ignorowany(string $sciezka): bool
    {
        if (! is_dir(base_path('.git')) && ! is_file(base_path('.git'))) {
            $this->markTestSkipped('Drzewo bez `.git` — nie ma kogo zapytać o `.gitignore`.');
        }

        exec('git -C '.escapeshellarg(base_path()).' check-ignore -q --no-index -- '.escapeshellarg($sciezka), $wyjscie, $kod);

        $this->assertContains($kod, [0, 1], "git check-ignore zakończył się kodem {$kod}.");

        return $kod === 0;
    }

    /** @return array<string, array{0: string}> */
    public static function zrzuty(): array
    {
        return [
            'pg_dump w korzeniu' => ['kuking.dump'],
            'pg_dump custom w storage' => ['storage/app/kopia.pgdump'],
            'zrzut CMS' => ['baza.dump.cms'],
            'SQL w korzeniu' => ['zrzut-produkcji.sql'],
            'SQL spakowany w korzeniu' => ['zrzut-produkcji.sql.gz'],
            'SQL w storage' => ['storage/app/kopie/baza.sql'],
        ];
    }

    #[DataProvider('zrzuty')]
    public function test_zrzut_jest_ignorowany(string $sciezka): void
    {
        $this->assertTrue($this->ignorowany($sciezka), "{$sciezka} wjechałby do repozytorium przez `git add .`.");
    }

    /** @return array<string, array{0: string}> */
    public static function celowePlikiSql(): array
    {
        return [
            'referencja schematu' => ['database/reference/nowy.sql'],
            'pomiar obciążenia' => ['docs/obciazenie/nowy.sql'],
            'skrypt' => ['scripts/nowy.sql'],
        ];
    }

    #[DataProvider('celowePlikiSql')]
    public function test_celowe_pliki_sql_dalej_mozna_dodac(string $sciezka): void
    {
        $this->assertFalse($this->ignorowany($sciezka), "{$sciezka} jest zakryty — nowego pliku SQL tam nie da się dodać.");
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Nowa migracja ma własny znacznik czasu (#2302, IN-15).
 *
 * Osiem migracji ma znacznik `2026_09_26_100000`, siedem `2026_09_24_100000`.
 * Laravel ustala wtedy kolejność sortowaniem PEŁNEJ nazwy pliku, czyli
 * alfabetem opisu — dziś przypadkiem zgodnym z zależnościami
 * (`create_importy_przepisow` < `create_przepisy_z_importu`), ale jedna zmiana
 * nazwy albo nowa migracja o „wcześniejszym” opisie odwraca kolejność i pada
 * `migrate` na świeżej bazie (albo gorzej: tylko na produkcji, gdzie część
 * grupy już się wykonała).
 *
 * Istniejących nazw NIE zmieniamy: tabela `migrations` na produkcji pamięta je
 * po nazwie, więc zmiana nazwy to ponowne uruchomienie migracji. Zamrażamy
 * zatem dzisiejsze grupy (`HISTORYCZNE_GRUPY`, stan 30.09.2026) z liczebnością,
 * a każdy inny znacznik ma być jedyny. Nowy plik dołączony do starej grupy też
 * oblewa, bo zmienia jej liczebność.
 */
final class MigracjeMajaUnikalnyZnacznikCzasuTest extends TestCase
{
    /** Znacznik => liczba plików, zmierzone 30.09.2026 na `origin/main` (224f9eea5). */
    private const HISTORYCZNE_GRUPY = [
        '2026_09_06_100000' => 2,
        '2026_09_06_120000' => 3,
        '2026_09_06_210000' => 2,
        '2026_09_09_100000' => 2,
        '2026_09_09_400000' => 2,
        '2026_09_10_100000' => 2,
        '2026_09_10_400000' => 5,
        '2026_09_10_500000' => 2,
        '2026_09_24_100000' => 7,
        '2026_09_24_120000' => 3,
        '2026_09_25_100000' => 3,
        '2026_09_26_100000' => 8,
        '2026_09_26_110000' => 2,
        '2026_09_26_200000' => 3,
    ];

    /** @return array<string, list<string>> znacznik => pliki */
    public static function grupy(string $katalog): array
    {
        $grupy = [];

        foreach (scandir($katalog) ?: [] as $nazwa) {
            if (! str_ends_with($nazwa, '.php')) {
                continue;
            }

            $znacznik = preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_[a-z0-9_]+\.php$/', $nazwa, $m) === 1 ? $m[1] : 'ZŁA NAZWA';
            $grupy[$znacznik][] = $nazwa;
        }

        ksort($grupy);

        return $grupy;
    }

    /** @param  array<string, list<string>>  $grupy
     * @return list<string> */
    public static function naruszenia(array $grupy): array
    {
        $naruszenia = [];

        foreach ($grupy as $znacznik => $pliki) {
            $dozwolone = self::HISTORYCZNE_GRUPY[$znacznik] ?? 1;

            if ($znacznik === 'ZŁA NAZWA' || count($pliki) > $dozwolone) {
                $naruszenia[] = $znacznik.' ('.count($pliki).' plików, dozwolone '.$dozwolone.'): '.implode(', ', $pliki);
            }
        }

        return $naruszenia;
    }

    public function test_kazda_nowa_migracja_ma_wlasny_znacznik_czasu(): void
    {
        $katalog = dirname(__DIR__, 2).'/database/migrations';
        $grupy = self::grupy($katalog);
        $plikow = array_sum(array_map('count', $grupy));

        $spis = count(glob($katalog.'/*.php') ?: []);
        $this->assertGreaterThan(100, $plikow, 'Skan widzi podejrzanie mało migracji — zła ścieżka?');
        $this->assertSame($spis, $plikow, 'scandir i glob widzą inną liczbę migracji (docs/PULAPKI_TESTOW.md §2b).');

        $this->assertSame(
            [],
            self::naruszenia($grupy),
            'Migracja ze znacznikiem czasu, który ma już inna migracja (#2302, IN-15). Kolejność ustaliłby wtedy alfabet opisu, '
            .'nie data. Nadaj nowej migracji WŁASNY znacznik, unikalny także względem gałęzi na origin '
            .'(AGENTS.md §6). Istniejących nazw nie zmieniaj — produkcja pamięta je w tabeli migrations.',
        );
    }

    public function test_straznik_widzi_nowy_plik_w_starej_grupie_i_nowy_duplikat(): void
    {
        // Kontrola dodatnia na sztucznym katalogu: bez niej strażnik mógłby
        // nie wykrywać niczego, a test wyżej świeciłby na zielono.
        $grupy = [
            '2026_09_26_100000' => array_fill(0, 9, 'x.php'),
            '2026_10_01_100000' => ['2026_10_01_100000_a.php', '2026_10_01_100000_b.php'],
            '2026_10_01_110000' => ['2026_10_01_110000_c.php'],
            '2026_09_24_100000' => array_fill(0, 7, 'y.php'),
        ];

        $naruszenia = self::naruszenia($grupy);

        $this->assertCount(2, $naruszenia, implode("\n", $naruszenia));
        $this->assertStringStartsWith('2026_09_26_100000', $naruszenia[0]);
        $this->assertStringStartsWith('2026_10_01_100000', $naruszenia[1]);
    }
}

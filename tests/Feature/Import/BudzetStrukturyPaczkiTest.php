<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Prawdziwy parser w osobnym PHP 256M: dawny fatal nie może zabić całego zestawu testów. */
class BudzetStrukturyPaczkiTest extends TestCase
{
    private const PROBA = 'tests/Support/paczka-budzet-2611.php';

    /** @return array<string, array{string}> */
    public static function duzeNiezgodnePaczki(): array
    {
        return [
            'obce pole z drobnymi obiektami' => ['obce_obiekty'],
            'dozwolona sekcja z drobnymi obiektami' => ['dozwolone_obiekty'],
            'wiele pustych tablic' => ['puste_tablice'],
            'jeden slot ponad budzetem' => ['granica_odrzucona'],
            'zbyt wiele pol w jednym kontenerze' => ['za_duzo_separatorow'],
        ];
    }

    #[DataProvider('duzeNiezgodnePaczki')]
    public function test_nadmierna_struktura_jest_odrzucona_przed_fatalem_256_mb(string $tryb): void
    {
        $proces = $this->proces($tryb);

        self::assertSame(3, $proces->getExitCode(), 'BUDZET_2611_ODMOWA_BEZ_FATALA: '.$proces->getOutput().' '.$proces->getErrorOutput());
        self::assertStringContainsString('ODRZUCENIE kod=za_duzo_pozycji', $proces->getOutput());
        self::assertSame('', $proces->getErrorOutput());
    }

    /** @return array<string, array{string}> */
    public static function bezpiecznePaczki(): array
    {
        return [
            'mala' => ['maly'],
            '11,6 MB poprawnych wpisow' => ['duzy_eksport'],
            '5000 gestych receptur z polami eksportera' => ['gesty_eksport'],
            '1000 rozbudowanych receptur z 40 skladnikami i 20 krokami' => ['gesty_eksport_rozbudowany'],
            '100000 obiektow z osmioma kluczami' => ['wiele_kluczy'],
            'nawiasy i escapowane cudzyslowy w napisie' => ['napisy'],
            'zagniezdzenie w dozwolonej glebokosci' => ['zagniezdzenie'],
            'ostatni dozwolony slot' => ['granica_dozwolona'],
        ];
    }

    #[DataProvider('bezpiecznePaczki')]
    public function test_poprawna_struktura_przechodzi_w_256_mb(string $tryb): void
    {
        $proces = $this->proces($tryb);

        self::assertSame(0, $proces->getExitCode(), 'BUDZET_2611_POPRAWNA_PACZKA: '.$proces->getOutput().' '.$proces->getErrorOutput());
        self::assertStringContainsString('OK bajty=', $proces->getOutput());
        self::assertSame('', $proces->getErrorOutput());
    }

    private function proces(string $tryb): Process
    {
        $root = dirname(__DIR__, 3);
        $proces = new Process([PHP_BINARY, '-d', 'memory_limit=256M', $root.'/'.self::PROBA, $tryb], $root);
        $proces->setTimeout(30);
        $proces->run();

        return $proces;
    }
}

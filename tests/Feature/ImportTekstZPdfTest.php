<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Pdf\TekstZPdf;
use Illuminate\Support\Facades\Process;
use Tests\Support\MalyPdf;
use Tests\TestCase;

/**
 * Warstwa tekstu z PDF-a (D-300) — PRAWDZIWYMI narzędziami `poppler-utils`
 * tam, gdzie liczy się zachowanie narzędzia (tekst, brak tekstu, liczba
 * stron, uszkodzony plik), i `Process::fake` tam, gdzie trzeba odegrać stan,
 * którego nie zbudujemy w pamięci (plik z hasłem, brak narzędzia).
 *
 * `pdftotext` i `pdfinfo` muszą być w środowisku testów — w CI instaluje je
 * krok „poppler-utils" joba testów, w obrazie Dockera etap `runtime`.
 * Brak narzędzia NIE pomija testu: test pada z nazwą pakietu.
 */
final class ImportTekstZPdfTest extends TestCase
{
    /** @var list<string> */
    private array $pliki = [];

    protected function tearDown(): void
    {
        foreach ($this->pliki as $plik) {
            @unlink($plik);
        }

        parent::tearDown();
    }

    private function plik(string $tresc): string
    {
        $sciezka = (string) tempnam(sys_get_temp_dir(), 'kuking-pdf-');
        file_put_contents($sciezka, $tresc);
        $this->pliki[] = $sciezka;

        return $sciezka;
    }

    private function wymagajPopplera(): void
    {
        $this->assertTrue(
            Process::run(['pdftotext', '-v'])->exitCode() !== 127,
            'Brak pdftotext w środowisku testów — zainstaluj pakiet systemowy poppler-utils.',
        );
    }

    private function odrzucenie(string $sciezka): ImportOdrzucony
    {
        try {
            (new TekstZPdf)->odczytaj($sciezka);
        } catch (ImportOdrzucony $e) {
            return $e;
        }

        $this->fail('Odczyt PDF przeszedł, a nie powinien.');
    }

    public function test_pdf_z_warstwa_tekstu_jest_czytany_lokalnie(): void
    {
        $this->wymagajPopplera();

        $tekst = (new TekstZPdf)->odczytaj($this->plik(MalyPdf::zTekstem([
            ['Sernik babci', 'Skladniki', '1 kg twarogu', 'Przygotowanie', '1. Utrzyj twarog.'],
        ])));

        $this->assertStringContainsString('Sernik babci', $tekst);
        $this->assertStringContainsString('1 kg twarogu', $tekst);
    }

    public function test_pdf_bez_warstwy_tekstu_jest_rozpoznany(): void
    {
        $this->wymagajPopplera();

        $this->assertSame(ImportOdrzucony::PDF_BEZ_TEKSTU, $this->odrzucenie($this->plik(MalyPdf::bezTekstu(2)))->kod);
    }

    public function test_pdf_z_za_duza_liczba_stron_jest_odrzucony_z_liczba_w_komunikacie(): void
    {
        $this->wymagajPopplera();
        config(['kuking.import.pdf.max_stron' => 5]);

        $e = $this->odrzucenie($this->plik(MalyPdf::zTekstem(array_fill(0, 6, ['Strona z tekstem przepisu i opisem']))));

        $this->assertSame(ImportOdrzucony::PDF_ZA_DUZO_STRON, $e->kod);
        $this->assertStringContainsString('najwyżej 5', $e->getMessage());
    }

    public function test_za_duzy_plik_jest_odrzucony_zanim_ruszy_jakiekolwiek_narzedzie(): void
    {
        Process::fake();
        config(['kuking.import.pdf.max_mb' => 1]);

        $e = $this->odrzucenie($this->plik('%PDF-1.4'.str_repeat(' ', 1024 * 1024 + 10)));

        $this->assertSame(ImportOdrzucony::PDF_ZA_DUZY, $e->kod);
        $this->assertStringContainsString('1 MB', $e->getMessage());
        Process::assertNothingRan();
    }

    public function test_plik_ktory_nie_jest_pdf_jest_odrzucony_bez_narzedzi(): void
    {
        Process::fake();

        $this->assertSame(ImportOdrzucony::PDF_USZKODZONY, $this->odrzucenie($this->plik('<html>udaję PDF</html>'))->kod);
        Process::assertNothingRan();
    }

    public function test_uszkodzony_pdf_jest_odrzucony(): void
    {
        $this->wymagajPopplera();

        $this->assertSame(
            ImportOdrzucony::PDF_USZKODZONY,
            $this->odrzucenie($this->plik("%PDF-1.4\nto nie jest dalej pdf\n"))->kod,
        );
    }

    public function test_pdf_z_haslem_mowi_jak_zdjac_haslo(): void
    {
        Process::fake([
            '*pdfinfo*' => Process::result(output: '', errorOutput: 'Command Line Error: Incorrect password', exitCode: 1),
        ]);

        $e = $this->odrzucenie($this->plik("%PDF-1.7\n"));

        $this->assertSame(ImportOdrzucony::PDF_ZASZYFROWANY, $e->kod);
        $this->assertStringContainsString('bez hasła', $e->getMessage());
    }

    public function test_brak_narzedzia_to_komunikat_a_nie_blad_serwera(): void
    {
        Process::fake([
            '*pdfinfo*' => Process::result(errorOutput: 'sh: 1: exec: pdfinfo: not found', exitCode: 127),
        ]);

        $this->assertSame(ImportOdrzucony::NARZEDZIE_PDF_NIEDOSTEPNE, $this->odrzucenie($this->plik("%PDF-1.7\n"))->kod);
    }

    public function test_pdftotext_czyta_tylko_dozwolone_strony_bez_powloki(): void
    {
        Process::fake([
            '*pdfinfo*' => Process::result(output: "Pages:          3\nEncrypted:      no\n"),
            '*pdftotext*' => Process::result(output: 'Bigos myśliwski z kapustą i grzybami'),
        ]);
        config(['kuking.import.pdf.max_stron' => 5]);

        $sciezka = $this->plik("%PDF-1.7\n");
        (new TekstZPdf)->odczytaj($sciezka);

        Process::assertRan(fn ($proces): bool => is_array($proces->command)
            && $proces->command[0] === 'pdftotext'
            && in_array('-l', $proces->command, true)
            && in_array('5', $proces->command, true)
            && end($proces->command) === '-');
    }
}

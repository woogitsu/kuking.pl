<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Wyjście KONSOLI komend operacyjnych bez komunikatu cudzego wyjątku
 * (issue #1860, luka po #973).
 *
 * #973 zamknął drogę `getMessage()` → logger i pilnuje jej skaner
 * w `LogOperacyjnyBezKomunikatuWyjatkuTest`. Ten skaner patrzy wyłącznie na
 * wywołania loggera, więc trzy komendy od zdjęć dalej wypisywały na terminal
 * surowy komunikat klienta storage: `kuking:sprawdz-zdjecia-po-przenosinach`,
 * `kuking:sprawdz-kopie-zdjec` i `kuking:odbior-zdjec`. Terminal
 * `railway ssh` to ta sama klasa ryzyka — komunikat R2 niesie adres żądania
 * z kluczem obiektu, a znacznik `<…>` z komunikatu przefarbowuje resztę linii.
 *
 * Teraz idzie tam `BezpiecznyBlad::jednaLinia()`: klasa, bezpieczny kod,
 * miejsce, odcisk — i identyfikator wiersza media, który komenda dokłada sama.
 */
class KonsolaBezKomunikatuWyjatkuTest extends TestCase
{
    use RefreshDatabase;

    private const ZLY_KOMUNIKAT = "Error executing HeadObject on https://konto.r2.example/media/basia.jpg?X-Amz-Signature=deadbeef123\r\n"
        .'<error>basia@example.com</error> [2026-09-26] produkcja.CRITICAL: wstrzykniety';

    /** @return list<string> */
    private function zakazane(): array
    {
        return ['X-Amz-Signature', 'deadbeef123', 'basia@example.com', 'wstrzykniety', 'konto.r2.example', "\r"];
    }

    private function dyskKtoryPada(): FilesystemAdapter
    {
        $dysk = Mockery::mock(FilesystemAdapter::class);
        $dysk->shouldReceive('exists')->andThrow(new RuntimeException(self::ZLY_KOMUNIKAT));

        return $dysk;
    }

    private function zdjecie(): Media
    {
        return Media::factory()->create([
            'disk' => 'nowe_oryginaly',
            'variants_disk' => 'nowe_publiczne',
            'status' => Media::STATUS_READY,
            'object_key' => 'incoming/basia/2026/09/sernik.jpg',
            'metadata' => ['variants' => ['feed' => ['key' => 'media/basia/2026/09/sernik_feed.webp']]],
        ]);
    }

    /**
     * Całe wyjście komendy jako tekst. NIE `doesntExpectOutputToContain()`:
     * gdy ta sama linia pasuje też do `expectsOutputToContain()`, Mockery
     * zalicza zapis tamtemu oczekiwaniu i zakaz przechodzi mimo zakazanej
     * frazy — sprawdzone kontrolą ujemną przy pisaniu tego testu.
     *
     * @param  array<string, mixed>  $parametry
     */
    private function assertWyjscieBezKomunikatu(string $komenda, array $parametry, string $oczekiwane): void
    {
        $kod = Artisan::call($komenda, $parametry);
        $wyjscie = Artisan::output();

        $this->assertNotSame(0, $kod, 'Błąd odczytu musi dać kod wyjścia różny od zera.');
        $this->assertStringContainsString($oczekiwane, $wyjscie);

        foreach ($this->zakazane() as $fraza) {
            $this->assertStringNotContainsString($fraza, $wyjscie);
        }
    }

    public function test_jedna_linia_ma_klase_kod_i_odcisk_a_nie_komunikat(): void
    {
        $e = new RuntimeException(self::ZLY_KOMUNIKAT, 0, new RuntimeException('przyczyna basia@example.com'));

        $linia = BezpiecznyBlad::jednaLinia($e);

        foreach ($this->zakazane() as $fraza) {
            $this->assertStringNotContainsString($fraza, $linia);
        }

        $this->assertStringNotContainsString("\n", $linia);
        $this->assertStringStartsWith(RuntimeException::class.', tests/Feature/KonsolaBezKomunikatuWyjatkuTest.php:', $linia);
        $this->assertStringContainsString('przyczyna: '.RuntimeException::class, $linia);
        $this->assertStringEndsWith('odcisk '.BezpiecznyBlad::kontekst($e)['odcisk'], $linia);
    }

    public function test_sprawdzanie_po_przenosinach_nie_wypisuje_komunikatu_storage(): void
    {
        Storage::set('nowe_oryginaly', $this->dyskKtoryPada());
        Storage::fake('nowe_publiczne');
        Storage::fake('r2_legacy');
        $media = $this->zdjecie();

        $this->assertWyjscieBezKomunikatu(
            'kuking:sprawdz-zdjecia-po-przenosinach',
            [],
            'BŁĄD ODCZYTU: '.$media->getKey().' — '.RuntimeException::class.', ',
        );
    }

    public function test_sprawdzanie_kopii_zdjec_nie_wypisuje_komunikatu_storage(): void
    {
        config(['filesystems.disks.kopia_testowa' => ['driver' => 'local', 'root' => sys_get_temp_dir()]]);
        Storage::set('kopia_testowa', $this->dyskKtoryPada());
        $media = $this->zdjecie();

        $this->assertWyjscieBezKomunikatu(
            'kuking:sprawdz-kopie-zdjec',
            ['--dysk' => 'kopia_testowa', '--prefiks' => 'migawka/'],
            'BŁĄD ODCZYTU: media '.$media->getKey().' — '.RuntimeException::class.', ',
        );
    }

    /**
     * Skaner wyjścia konsoli: każde `getMessage()` w `app/Console` jest albo
     * zamienione na `BezpiecznyBlad`, albo stoi na liście niżej z powodem.
     * Nowe wywołanie spoza listy oblewa — to jest ta część, której zabrakło
     * przy #973, bo tamten skaner zna tylko loggera.
     */
    public function test_kazde_get_message_w_komendach_ma_uzasadnienie(): void
    {
        // plik => [fragment linii => dlaczego wolno]
        $dozwolone = [
            'Commands/BramkaR2.php' => [
                '$this->skrot($e->getMessage())' => 'bramka R2 pracuje na własnym obiekcie kontrolnym, nie na kluczach użytkowników; komunikat R2 to jedyna wskazówka dla właściciela',
            ],
            'Commands/SprawdzModel.php' => [
                '$this->skrot($blad->getMessage())' => 'odpowiedź API modelu na zapytanie kontrolne bez danych użytkownika',
            ],
            'Commands/NadajRole.php' => [
                '$this->error($exception->getMessage())' => 'DomainException z ChangeUserRole — własne zdanie po polsku',
            ],
            'Commands/PrzeniesZdjeciaDoNowychBucketow.php' => [
                '\'metadata.variants niepełne: \'.$e->getMessage()' => 'WariantyMetadanychNiepelne — własny wyjątek bez identyfikatora medium i treści właściciela (#1905)',
            ],
            'Commands/SprawdzZdjeciaPoPrzenosinach.php' => [
                '\'klucz\' => $e->getMessage()' => 'WariantyMetadanychNiepelne — własny wyjątek bez identyfikatora medium i treści właściciela (#1905)',
            ],
            'Commands/RaportPrzejrzystosci.php' => [
                '$this->error($e->getMessage())' => 'InvalidArgumentException z własnej dzien() — zdanie po polsku z datą, którą operator sam wpisał w --od/--do',
            ],
            'Commands/SprawdzPoczte.php' => [
                '$this->bezZnacznikow($e->getMessage())' => 'list kontrolny na adres, który operator sam wpisał; znaczniki ucieczkowane',
                'mb_strtolower($e->getMessage())' => 'tylko klasyfikacja przyczyny, tekst nie idzie na wyjście',
            ],
        ];

        $naruszenia = [];
        $znalezione = 0;
        $katalog = app_path('Console');
        $pliki = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($katalog));

        foreach ($pliki as $plik) {
            if (! $plik->isFile() || $plik->getExtension() !== 'php') {
                continue;
            }

            $wzgledna = substr($plik->getPathname(), strlen($katalog) + 1);

            foreach (file($plik->getPathname()) ?: [] as $nr => $linia) {
                if (! str_contains($linia, 'getMessage()')) {
                    continue;
                }

                $znalezione++;
                $pasuje = false;

                foreach (array_keys($dozwolone[$wzgledna] ?? []) as $fragment) {
                    $pasuje = $pasuje || str_contains($linia, $fragment);
                }

                if (! $pasuje) {
                    $naruszenia[] = $wzgledna.':'.($nr + 1).' '.trim($linia);
                }
            }
        }

        // Kontrola dodatnia: skaner naprawdę widzi wywołania z listy.
        $this->assertGreaterThanOrEqual(10, $znalezione, 'Skaner nie znalazł znanych wywołań getMessage() — sprawdź ścieżkę.');
        $this->assertSame([], $naruszenia, "Surowy komunikat wyjątku w komendzie — użyj BezpiecznyBlad::jednaLinia():\n".implode("\n", $naruszenia));
    }
}

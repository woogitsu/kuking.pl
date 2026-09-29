<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Odzywcze\ImportujWartosciOdzywcze;
use App\Models\AliasSkladnika;
use App\Models\SkladnikOdzywczy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #2130, decyzja właściciela 29.09.2026 (D-330): słownik wartości odżywczych
 * ma rosnącą wersję danych, więc starszy import (np. z wycofanego wdrożenia)
 * nie nadpisuje nowszego.
 *
 * Test dwóch procesów (wyścig pod blokadą) jest w
 * `Tests\Dwa\ImportOdzywczyWersjaPodBlokadaTest`.
 *
 * @bez-kontroli-dodatniej Testy wykonują import na PostgreSQL i porównują zawartość tabel; strażnik wersji czyta tylko pliki danych, a jego negatyw (zmieniony CSV ma inny hash niż zapisany) jest w tym samym pliku.
 */
final class WersjaSlownikaOdzywczegoTest extends TestCase
{
    use RefreshDatabase;

    private const KLUCZ = 'test_2130_wersja';

    /** @var list<string> */
    private array $katalogi = [];

    protected function tearDown(): void
    {
        foreach ($this->katalogi as $katalog) {
            foreach (glob($katalog.'/*') ?: [] as $plik) {
                @unlink($plik);
            }
            @rmdir($katalog);
        }
        parent::tearDown();
    }

    #[Test]
    public function starszy_import_po_nowszym_nie_nadpisuje_danych_i_ostrzega_w_logu(): void
    {
        $nowszy = $this->katalog(2, true);
        $starszy = $this->katalog(1, false);
        app(ImportujWartosciOdzywcze::class)->handle($nowszy);
        $this->assertSame(1, SkladnikOdzywczy::query()->where('klucz', self::KLUCZ)->count());

        Log::spy();
        $wynik = app(ImportujWartosciOdzywcze::class)->handle($starszy);

        $this->assertTrue($wynik['pominieto']);
        $this->assertSame(1, SkladnikOdzywczy::query()->where('klucz', self::KLUCZ)->count(), 'Starszy import cofnął nowsze dane.');
        Log::shouldHaveReceived('warning')->withArgs(static fn (string $m, array $ctx = []): bool => ($ctx['wersja_plikow'] ?? null) === 1 && ($ctx['wersja_bazy'] ?? null) === 2 && count($ctx) === 2)->once();
    }

    #[Test]
    public function nowsza_wersja_nadpisuje_starsza(): void
    {
        app(ImportujWartosciOdzywcze::class)->handle($this->katalog(1, false));
        $this->assertSame(0, SkladnikOdzywczy::query()->where('klucz', self::KLUCZ)->count());

        $wynik = app(ImportujWartosciOdzywcze::class)->handle($this->katalog(2, true));

        $this->assertFalse($wynik['pominieto']);
        $this->assertSame(1, SkladnikOdzywczy::query()->where('klucz', self::KLUCZ)->count());
    }

    #[Test]
    public function niekompletna_baza_jest_odbudowana_nawet_przez_starszy_import(): void
    {
        $nowszy = $this->katalog(2, true);
        $starszy = $this->katalog(1, false);
        app(ImportujWartosciOdzywcze::class)->handle($nowszy);
        AliasSkladnika::query()->delete();

        $wynik = app(ImportujWartosciOdzywcze::class)->handle($starszy);

        $this->assertFalse($wynik['pominieto'], 'Niekompletna baza ma być odbudowana, nawet gdy pliki są starsze.');
        $this->assertGreaterThan(0, AliasSkladnika::query()->count());
    }

    #[Test]
    public function ta_sama_wersja_i_zgodny_odcisk_daje_szybka_sciezke(): void
    {
        $katalog = $this->katalog(2, true);
        app(ImportujWartosciOdzywcze::class)->handle($katalog);

        $this->assertTrue(app(ImportujWartosciOdzywcze::class)->handle($katalog)['pominieto']);
    }

    #[Test]
    public function wymus_swiadomie_pomija_ochrone_wersji(): void
    {
        app(ImportujWartosciOdzywcze::class)->handle($this->katalog(2, true));

        $wynik = app(ImportujWartosciOdzywcze::class)->handle($this->katalog(1, false), wymus: true);

        $this->assertFalse($wynik['pominieto']);
        $this->assertSame(0, SkladnikOdzywczy::query()->where('klucz', self::KLUCZ)->count());
    }

    #[Test]
    public function brak_pliku_wersji_to_wersja_zero_i_nie_nadpisuje_zapisanej(): void
    {
        app(ImportujWartosciOdzywcze::class)->handle($this->katalog(1, true));
        $bezWersji = $this->katalog(null, false);

        $this->assertTrue(app(ImportujWartosciOdzywcze::class)->handle($bezWersji)['pominieto']);
        $this->assertSame(1, SkladnikOdzywczy::query()->where('klucz', self::KLUCZ)->count());
    }

    // --- Strażnik: zmiana CSV wymaga nowego wiersza w WERSJA -----------------

    #[Test]
    public function zmiana_csv_bez_podbicia_wersji_oblewa(): void
    {
        $katalog = base_path(ImportujWartosciOdzywcze::KATALOG);
        $wersja = ImportujWartosciOdzywcze::odczytajWersje($katalog);

        $this->assertGreaterThanOrEqual(1, $wersja['wersja'], 'Plik WERSJA jest pusty albo nieczytelny.');
        $this->assertSame(
            $wersja['historia'][$wersja['wersja']],
            ImportujWartosciOdzywcze::hashDanych($katalog),
            'Zmieniono skladniki.csv albo miary.csv bez nowej wersji danych. Dopisz w database/data/odzywcze/WERSJA '
            .'wiersz „'.($wersja['wersja'] + 1).' '.ImportujWartosciOdzywcze::hashDanych($katalog).'” (numer o 1 większy). '
            .'Bez tego starszy import mógłby nadpisać nowsze dane albo odwrotnie (#2130, D-330).',
        );
    }

    #[Test]
    public function numery_wersji_w_pliku_rosna_i_sie_nie_powtarzaja(): void
    {
        $tresc = (string) file_get_contents(base_path(ImportujWartosciOdzywcze::KATALOG.'/'.ImportujWartosciOdzywcze::PLIK_WERSJI));
        preg_match_all('/^(\d+)\s+[0-9a-f]{64}$/m', $tresc, $m);
        $numery = array_map('intval', $m[1]);

        $this->assertNotSame([], $numery);
        $posortowane = array_values(array_unique($numery));
        sort($posortowane);
        $this->assertSame($posortowane, $numery, 'Numery w WERSJA mają rosnąć w kolejności wierszy i nie mogą się powtarzać.');
    }

    #[Test]
    public function kontrola_ujemna_straznika_zmieniony_csv_ma_inny_hash_niz_zapisany(): void
    {
        $katalog = $this->katalog(1, false);
        $wersja = ImportujWartosciOdzywcze::odczytajWersje($katalog);
        $this->assertSame($wersja['historia'][1], ImportujWartosciOdzywcze::hashDanych($katalog));

        file_put_contents($katalog.'/miary.csv', "\n", FILE_APPEND);

        $this->assertNotSame($wersja['historia'][1], ImportujWartosciOdzywcze::hashDanych($katalog));
    }

    /**
     * Kopia danych z repozytorium z podaną wersją (null = bez pliku WERSJA);
     * wariant „z dodatkiem” ma jeden składnik więcej. Wpis w WERSJA ma hash
     * faktycznych plików tego katalogu.
     */
    private function katalog(?int $wersja, bool $zDodatkiem): string
    {
        $katalog = sys_get_temp_dir().'/kuking-odzywcze-wersja-'.bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($katalog, 0700));
        $this->katalogi[] = $katalog;

        $zrodlo = base_path(ImportujWartosciOdzywcze::KATALOG);
        $skladniki = (string) file_get_contents($zrodlo.'/skladniki.csv');
        if ($zDodatkiem) {
            $skladniki = rtrim($skladniki, "\n")."\n".self::KLUCZ.',Składnik testowy 2130 wersja,alias2130wersja,ciqual,0,,0,Test,10,1,1,1'."\n";
        }
        file_put_contents($katalog.'/skladniki.csv', $skladniki);
        copy($zrodlo.'/miary.csv', $katalog.'/miary.csv');

        if ($wersja !== null) {
            file_put_contents($katalog.'/WERSJA', $wersja.' '.ImportujWartosciOdzywcze::hashDanych($katalog)."\n");
        }

        return $katalog;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wydania\Actions\ZarejestrujWdrozenie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `App\Domain\Wydania\Actions\ZarejestrujWdrozenie` (issue #1932, D-318):
 * numeruje wdrożenia i zapamiętuje, pod jakim numerem pojawiła się każda
 * funkcja z sekcji „## Najnowsze zmiany".
 *
 * Test na dwóch prawdziwych połączeniach (bezpieczeństwo przy równoległym
 * starcie) mieszka osobno: `tests/Dwa/RejestracjaWdrozeniaNaDwochPolaczeniachTest.php`.
 */
class ZarejestrujWdrozenieTest extends TestCase
{
    use RefreshDatabase;

    private function commit(int $bajt = 0xA): string
    {
        return str_pad(dechex($bajt), 40, '0');
    }

    /** Podmienia `kuking.nowosci.tresc` na plik tymczasowy — nigdy na prawdziwy plik redakcyjny. */
    private function trescNowosci(string $markdown): void
    {
        $plik = tempnam(sys_get_temp_dir(), 'nowosci');
        file_put_contents($plik, $markdown);
        config(['kuking.nowosci.tresc' => $plik]);
    }

    public function test_pierwsze_wdrozenie_dostaje_numer_jeden(): void
    {
        $numer = app(ZarejestrujWdrozenie::class)->handle($this->commit(1), 'Alfa 0.68');

        $this->assertSame(1, $numer);
        $this->assertDatabaseHas('wdrozenia', [
            'commit' => $this->commit(1),
            'etykieta' => 'Alfa 0.68',
            'numer' => 1,
        ]);
    }

    public function test_kolejne_wdrozenie_tej_samej_etykiety_dostaje_kolejny_numer(): void
    {
        app(ZarejestrujWdrozenie::class)->handle($this->commit(1), 'Alfa 0.68');
        $drugi = app(ZarejestrujWdrozenie::class)->handle($this->commit(2), 'Alfa 0.68');

        $this->assertSame(2, $drugi);
    }

    public function test_nowa_etykieta_zaczyna_numeracje_od_jeden(): void
    {
        app(ZarejestrujWdrozenie::class)->handle($this->commit(1), 'Alfa 0.68');
        app(ZarejestrujWdrozenie::class)->handle($this->commit(2), 'Alfa 0.68');

        // Podbicie dużego numeru (ręczne, razem z ROADMAP.md) — końcówka
        // wraca do 001, bo to jest NOWA sekwencja.
        $numer = app(ZarejestrujWdrozenie::class)->handle($this->commit(3), 'Alfa 0.69');

        $this->assertSame(1, $numer);
    }

    public function test_ten_sam_commit_drugi_raz_nie_zuzywa_kolejnego_numeru(): void
    {
        $pierwszy = app(ZarejestrujWdrozenie::class)->handle($this->commit(1), 'Alfa 0.68');
        $drugi = app(ZarejestrujWdrozenie::class)->handle($this->commit(1), 'Alfa 0.68');

        $this->assertSame($pierwszy, $drugi);
        $this->assertSame(1, DB::table('wdrozenia')->where('etykieta', 'Alfa 0.68')->count());

        // Kolejny, NOWY commit dostaje 2 — a nie 3, bo powtórzenie wyżej nie
        // miało prawa zużyć numeru.
        $trzeci = app(ZarejestrujWdrozenie::class)->handle($this->commit(2), 'Alfa 0.68');
        $this->assertSame(2, $trzeci);
    }

    public function test_odmawia_niepoprawnego_commita(): void
    {
        $this->expectException(RuntimeException::class);

        app(ZarejestrujWdrozenie::class)->handle('nie-sha', 'Alfa 0.68');
    }

    public function test_zapisuje_pierwsze_pojawienie_sie_funkcji_z_najnowszych_zmian(): void
    {
        $this->trescNowosci(<<<'MD'
            # Co nowego

            <a id="najnowsze-zmiany"></a>
            ## Najnowsze zmiany

            ### Zróbcie swoją wersję

            Opis pierwszej funkcji.

            ### Prywatna notatka

            Opis drugiej funkcji.

            <a id="alfa-067"></a>
            ## Alfa 0.67 — stare wydanie

            ### Ta funkcja jest już wydana

            Nie powinna trafić do dziennika — jest POZA „Najnowsze zmiany".
            MD);

        $numer = app(ZarejestrujWdrozenie::class)->handle($this->commit(1), 'Alfa 0.68');

        $this->assertDatabaseHas('wdrozenia_funkcje', [
            'etykieta' => 'Alfa 0.68',
            'naglowek_slug' => 'zróbcie-swoją-wersję',
            'numer' => $numer,
        ]);
        $this->assertDatabaseHas('wdrozenia_funkcje', [
            'etykieta' => 'Alfa 0.68',
            'naglowek_slug' => 'prywatna-notatka',
            'numer' => $numer,
        ]);
        $this->assertDatabaseMissing('wdrozenia_funkcje', ['naglowek_slug' => 'ta-funkcja-jest-już-wydana']);
        $this->assertSame(2, DB::table('wdrozenia_funkcje')->count());
    }

    public function test_funkcja_widziana_w_dwoch_wdrozeniach_zostaje_przy_pierwszym_numerze(): void
    {
        $this->trescNowosci(<<<'MD'
            <a id="najnowsze-zmiany"></a>
            ## Najnowsze zmiany

            ### Nowa funkcja

            Pierwszy opis.
            MD);

        $pierwszy = app(ZarejestrujWdrozenie::class)->handle($this->commit(1), 'Alfa 0.68');

        // Ktoś dopisał kolejne zdanie do TEGO SAMEGO akapitu i wdrożył ponownie —
        // nagłówek jest ten sam, więc numer pierwszego pojawienia ma zostać.
        $this->trescNowosci(<<<'MD'
            <a id="najnowsze-zmiany"></a>
            ## Najnowsze zmiany

            ### Nowa funkcja

            Pierwszy opis, i jeszcze jedno zdanie.
            MD);

        app(ZarejestrujWdrozenie::class)->handle($this->commit(2), 'Alfa 0.68');

        $this->assertDatabaseHas('wdrozenia_funkcje', [
            'etykieta' => 'Alfa 0.68',
            'naglowek_slug' => 'nowa-funkcja',
            'numer' => $pierwszy,
        ]);
        $this->assertSame(1, DB::table('wdrozenia_funkcje')->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneSesje;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retencja tabeli `sessions` (RZ-01, 21.09.2026).
 *
 * CO TU JEST NAJWAŻNIEJSZE
 * Nie to, że stare wiersze znikają — to robiła już loteria frameworka, tylko
 * w 2% żądań. Najważniejsza jest DRUGA strona: próg NIGDY nie schodzi poniżej
 * `SESSION_LIFETIME`, bo wiersz młodszy niż `lifetime` należy do sesji ŻYWEJ,
 * a jego skasowanie to wylogowanie człowieka w środku pracy. Komenda, która
 * kasuje za dużo, jest gorsza od braku komendy: brak komendy zostawia wiersze,
 * a nadgorliwość wyrzuca ludzi z serwisu bez słowa.
 */
class RetencjaSesjiTest extends TestCase
{
    use RefreshDatabase;

    /** Wiersz sesji o zadanym wieku ostatniej aktywności. Zwraca jego `id`. */
    private function sesja(int $dniTemu): string
    {
        $id = (string) Str::uuid();

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => null,
            'ip_address' => '203.0.113.0',
            'user_agent' => 'test',
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->subDays($dniTemu)->getTimestamp(),
        ]);

        return $id;
    }

    public function test_sesja_starsza_niz_prog_znika_a_mlodsza_zostaje(): void
    {
        config(['session.lifetime' => 120, 'kuking.sessions.retention_days' => 7]);

        // Wyraźnie POZA i WEWNĄTRZ granicy — nie tylko „bardzo stara", żeby
        // test naprawdę pilnował progu, a nie dowolnej dużej różnicy.
        $stara = $this->sesja(8);
        $mloda = $this->sesja(6);

        $wynik = (new PrzedawnioneSesje)->posprzataj(7);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame(7, $wynik['dni']);
        $this->assertFalse($wynik['podniesiony']);
        $this->assertDatabaseMissing('sessions', ['id' => $stara]);
        // Asercja kontrolna: młodsza sesja naprawdę została. Bez niej test
        // przeszedłby też wtedy, gdyby komenda skasowała WSZYSTKO.
        $this->assertDatabaseHas('sessions', ['id' => $mloda]);
    }

    /**
     * BARIERA: krótsza retencja niż `SESSION_LIFETIME` NIE wylogowuje ludzi.
     *
     * Scenariusz jest realny, nie wymyślony: `.railway/railway.ts` planuje
     * `SESSION_LIFETIME=43200` (30 dni), a domyślna retencja w
     * `config/kuking.php` to 7 dni. Bez bariery pierwsze nocne uruchomienie
     * wyrzuciłoby z serwisu każdego, kto nie zaglądał od tygodnia — i to
     * przy sesji, która według ustawień miała żyć jeszcze trzy tygodnie.
     */
    public function test_prog_nie_schodzi_ponizej_czasu_zycia_sesji(): void
    {
        config(['session.lifetime' => 43200]); // 30 dni

        $zywa = $this->sesja(10);   // wygasłaby dopiero po 30 dniach
        $martwa = $this->sesja(31); // po czasie życia sesji

        $wynik = (new PrzedawnioneSesje)->posprzataj(1);

        $this->assertSame(30, $wynik['dni'], 'Próg nie został podniesiony do SESSION_LIFETIME.');
        $this->assertTrue($wynik['podniesiony']);
        $this->assertSame(1, $wynik['skasowano']);
        // Kontrola dodatnia po obu stronach granicy naraz: żywa sesja została,
        // a wygasła zniknęła. Sama „żywa została" byłaby zielona także wtedy,
        // gdyby komenda nie robiła nic.
        $this->assertDatabaseHas('sessions', ['id' => $zywa]);
        $this->assertDatabaseMissing('sessions', ['id' => $martwa]);
    }

    public function test_komenda_dziala_i_respektuje_opcje_na_sucho(): void
    {
        config(['session.lifetime' => 120, 'kuking.sessions.retention_days' => 7]);

        $this->sesja(30);
        $this->sesja(1);

        $this->artisan('kuking:sprzataj-sesje', ['--na-sucho' => true])->assertSuccessful();
        // Kontrola: OBA wiersze wciąż tu są po na-sucho — nie tylko „coś
        // zostało", tylko dokładnie tyle, ile było.
        $this->assertDatabaseCount('sessions', 2);

        $this->artisan('kuking:sprzataj-sesje')->assertSuccessful();
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_komenda_przyjmuje_wlasny_prog_w_dniach(): void
    {
        config(['session.lifetime' => 120, 'kuking.sessions.retention_days' => 365]);

        $this->sesja(3);

        // Gdyby opcja `--dni` była ignorowana, próg z konfiguracji (365 dni)
        // zostawiłby ten wiersz i test by oblał.
        $this->artisan('kuking:sprzataj-sesje', ['--dni' => 2])->assertSuccessful();

        $this->assertDatabaseCount('sessions', 0);
    }

    /**
     * Komenda bez wpisu w harmonogramie jest komendą, której nikt nie uruchamia
     * — a cała wartość tej zmiany polega na tym, że retencja przestaje zależeć
     * od loterii. Dlatego wpięcie jest sprawdzane, a nie zakładane.
     */
    public function test_zadanie_jest_wpiete_w_harmonogram(): void
    {
        $nazwy = array_map(
            static fn ($zadanie): string => (string) $zadanie->description,
            app(Schedule::class)->events(),
        );

        $this->assertContains('kuking:sprzataj-sesje', $nazwy);
        // Kontrola dodatnia dla samego odczytu harmonogramu: gdyby lista
        // nazw była pusta albo zbudowana z czegoś innego niż `->name()`,
        // asercja wyżej byłaby zielona tylko przez przypadek.
        $this->assertContains('kuking:sprzataj-audyt', $nazwy);
    }
}

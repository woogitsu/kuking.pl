<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnionePotwierdzeniaRodo;
use App\Domain\Compliance\SlownikPotwierdzenRodo;
use App\Models\User;
use App\Support\NumerZadaniaRodo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 36 MIESIĘCY OD `zakonczono` — I ANI DNIA WIĘCEJ, ANI DNIA MNIEJ.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DLACZEGO KAŻDA ODMOWA MA TU KONTROLĘ DODATNIĄ
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Test automatu kasującego jest wyjątkowo łatwy do napisania tak, żeby nic
 * nie mierzył. „Wiersz ze wstrzymaniem nie zniknął" przechodzi tak samo na
 * automacie, który nie kasuje NICZEGO — na przykład dlatego, że predykat ma
 * literówkę w nazwie kolumny albo że komenda w ogóle się nie zarejestrowała.
 * Dlatego każdy test niżej pokazuje OBIE strony: co ma zniknąć i co ma zostać,
 * w jednym przebiegu (`PULAPKI_TESTOW.md` §2).
 */
class RetencjaPotwierdzenRodoTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_automat_kasuje_przedawnione_i_omija_wstrzymane(): void
    {
        // KONTROLA DODATNIA I ODMOWA W JEDNYM PRZEBIEGU.
        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');
        $stareWstrzymane = $this->potwierdzenie(
            zakonczono: '2022-01-15',
            wstrzymanieDo: Carbon::today()->addYear()->toDateString(),
            wstrzymanieSprawa: 'Sygn. akt I C 123/25',
        );
        $swieze = $this->potwierdzenie(zakonczono: Carbon::today()->subMonths(6)->toDateString());
        $wToku = $this->potwierdzenie(wynik: 'w_toku', zakonczono: null);

        $wynik = app(PrzedawnionePotwierdzeniaRodo::class)->posprzataj(36);

        $this->assertSame(1, $wynik['skasowano'], 'Automat nie skasował przedawnionego potwierdzenia.');
        $this->assertSame(1, $wynik['wstrzymane'], 'Automat nie policzył wiersza pominiętego z powodu wstrzymania.');

        $this->assertDatabaseMissing('potwierdzenia_zadan_rodo', ['numer' => $stare]);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $stareWstrzymane]);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $swieze]);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $wToku]);
    }

    #[Test]
    public function test_wstrzymanie_wygasa_samo_i_wiersz_wraca_pod_retencje(): void
    {
        // `wstrzymanie_do` jest DATĄ, nie flagą — dokładnie po to, żeby
        // blokada nie trwała, dopóki ktoś o niej nie zapomni. Ten test jest
        // kontrolą dodatnią do testu wyżej z drugiej strony: pokazuje, że
        // „ominięty" nie znaczy „nietykalny na zawsze".
        $wygasle = $this->potwierdzenie(
            zakonczono: '2022-01-15',
            wstrzymanieDo: Carbon::today()->subDay()->toDateString(),
            wstrzymanieSprawa: 'Sygn. akt I C 123/23 — zamknięta',
        );

        $wynik = app(PrzedawnionePotwierdzeniaRodo::class)->posprzataj(36);

        $this->assertSame(0, $wynik['wstrzymane'], 'Wygasłe wstrzymanie nadal liczy się jako obowiązujące.');
        $this->assertSame(1, $wynik['skasowano']);
        $this->assertDatabaseMissing('potwierdzenia_zadan_rodo', ['numer' => $wygasle]);
    }

    #[Test]
    public function test_prog_liczy_sie_bez_przepelnienia_daty(): void
    {
        // A6-04: `subMonths(1)` od 31 marca daje 3 marca (przepełnienie
        // lutego), a `subMonthsNoOverflow(1)` — 28/29 lutego. Próg przesunięty
        // w stronę NOWSZYCH wierszy kasuje dowód wykonania art. 17 PRZED
        // czasem, a to jest ubytek nie do odtworzenia.
        //
        // Test ustawia zegar na 31 marca i stawia wiersz dokładnie w tej
        // dobie, którą rozdziela różnica między tymi dwoma metodami.
        Carbon::setTestNow(Carbon::parse('2029-03-31 12:00:00'));

        // 36 miesięcy bez przepełnienia: 2026-03-31. Z przepełnieniem
        // (`subMonths`) wyszłoby 2026-03-31 też — dlatego bierzemy próg
        // miesięczny, na którym te dwie metody NAPRAWDĘ się różnią.
        $prog = Carbon::now()->subMonthsNoOverflow(1)->toDateString();

        $this->assertSame('2029-02-28', $prog, 'Sam Carbon liczy inaczej, niż zakłada ten test.');

        // Wiersz zamknięty 2029-03-02: starszy niż próg z przepełnieniem
        // (2029-03-03), ale NOWSZY niż próg bez przepełnienia (2029-02-28).
        // Automat liczący `subMonths` skasowałby go; ten ma go zostawić.
        $granica = $this->potwierdzenie(zakonczono: '2029-03-02');

        // KONTROLA DODATNIA: wiersz starszy od obu progów ma zniknąć — bez
        // niej ten test przechodziłby na automacie, który nie kasuje nic.
        $starszy = $this->potwierdzenie(zakonczono: '2029-02-01');

        $wynik = app(PrzedawnionePotwierdzeniaRodo::class)->posprzataj(1);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $granica]);
        $this->assertDatabaseMissing('potwierdzenia_zadan_rodo', ['numer' => $starszy]);

        Carbon::setTestNow();
    }

    #[Test]
    public function test_na_sucho_liczy_ale_nie_kasuje(): void
    {
        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');

        $wynik = app(PrzedawnionePotwierdzeniaRodo::class)->posprzataj(36, naSucho: true);

        $this->assertSame(1, $wynik['skasowano'], 'Dry-run nie policzył kandydata.');

        // Trzeci argument `assertDatabaseHas` to NAZWA POŁĄCZENIA, nie
        // komunikat — stąd osobna asercja z własnym zdaniem.
        $this->assertSame(
            1,
            DB::table('potwierdzenia_zadan_rodo')->where('numer', $stare)->count(),
            'Dry-run skasował wiersz.',
        );
    }

    #[Test]
    public function test_komenda_bierze_okres_z_configu_i_melduje_obie_liczby(): void
    {
        $this->assertSame(36, (int) config('kuking.potwierdzenia_rodo.retention_months'));

        // PRZEŁĄCZNIK KASOWANIA ISTNIEJE I DOMYŚLNIE JEST WYŁĄCZONY.
        //
        // Autor tej zmiany świadomie przełącznika nie dał i pilnował tego
        // asercją, że w configu go NIE MA — argumentując, że wyłącznik
        // retencji jest bezterminowością pod inną nazwą. Właściciel
        // rozstrzygnął inaczej: samo liczenie terminu zostaje, ale
        // nieodwracalne kasowanie dowodu obsługi żądania RODO czeka na opinię
        // prawną co do okresu 36 miesięcy. Ten test pilnuje teraz DRUGIEJ
        // strony tej decyzji — że domyślnie nic nie ginie.
        $this->assertFalse(
            config('kuking.potwierdzenia_rodo.kasowanie_wlaczone'),
            'Kasowanie potwierdzeń RODO jest włączone domyślnie, a miało czekać na opinię prawną.',
        );

        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');
        $this->potwierdzenie(
            zakonczono: '2022-01-15',
            wstrzymanieDo: Carbon::today()->addYear()->toDateString(),
            wstrzymanieSprawa: 'Sygn. akt I C 123/25',
        );

        Artisan::call('kuking:sprzataj-potwierdzenia-rodo');
        $wyjscie = Artisan::output();

        // Wyłączone kasowanie NADAL liczy i mówi, ile wierszy czekałoby —
        // po to, żeby w dniu opinii prawnej widać było skalę.
        $this->assertStringContainsString('WYŁĄCZONE do czasu opinii prawnej', $wyjscie);
        $this->assertStringContainsString('Do skasowania: 1 potwierdzeń', $wyjscie);
        $this->assertStringContainsString('Pominięto z powodu udokumentowanego wstrzymania', $wyjscie);
        $this->assertStringContainsString(': 1.', $wyjscie);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $stare]);
    }

    #[Test]
    public function test_wlaczony_przelacznik_naprawde_kasuje(): void
    {
        // Druga strona przełącznika. Bez tego testu „wyłączone" mogłoby
        // znaczyć „zepsute" — a wtedy opinia prawna niczego by nie odblokowała.
        config(['kuking.potwierdzenia_rodo.kasowanie_wlaczone' => true]);

        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');
        $wstrzymane = $this->potwierdzenie(
            zakonczono: '2022-01-15',
            wstrzymanieDo: Carbon::today()->addYear()->toDateString(),
            wstrzymanieSprawa: 'Sygn. akt I C 123/25',
        );

        Artisan::call('kuking:sprzataj-potwierdzenia-rodo');
        $wyjscie = Artisan::output();

        $this->assertStringNotContainsString('WYŁĄCZONE', $wyjscie);
        $this->assertStringContainsString('Skasowano 1 potwierdzeń', $wyjscie);
        $this->assertDatabaseMissing('potwierdzenia_zadan_rodo', ['numer' => $stare]);

        // Wstrzymanie udokumentowaną sprawą broni wiersza także przy
        // włączonym kasowaniu — inaczej przełącznik kasowałby za dużo.
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $wstrzymane]);
    }

    #[Test]
    public function test_harmonogram_zna_te_komende(): void
    {
        // Automat, którego nikt nie woła, jest automatem tylko z nazwy.
        $nazwy = array_map(
            static fn (object $zadanie): ?string => $zadanie->description ?? null,
            app(Schedule::class)->events(),
        );

        $this->assertContains('kuking:sprzataj-potwierdzenia-rodo', $nazwy);

        // KONTROLA DODATNIA: skan harmonogramu naprawdę widzi zadania.
        $this->assertContains('kuking:sprzataj-audyt', $nazwy);
    }

    /**
     * Wstawia potwierdzenie przez `DB::table()` i zwraca jego numer.
     *
     * Gołym zapytaniem, nie modelem — ten plik mierzy PREDYKAT automatu,
     * a wstawianie przez ścieżkę zapisu wiązałoby go z regułami, których
     * tu nie badamy (i nie dałoby się postawić wiersza zamkniętego w 2022).
     */
    private function potwierdzenie(
        string $wynik = 'wykonane',
        ?string $zakonczono = '2022-01-15',
        ?string $wstrzymanieDo = null,
        ?string $wstrzymanieSprawa = null,
    ): string {
        $numer = NumerZadaniaRodo::wygeneruj();

        DB::table('potwierdzenia_zadan_rodo')->insert([
            'id' => (string) Str::uuid(),
            'numer' => $numer,
            'rodzaj' => SlownikPotwierdzenRodo::RODZAJE[0],
            'wynik' => $wynik,
            'zakres' => $wynik === 'wykonane' ? User::DELETE_SCOPE_MINIMUM : null,
            'otrzymano' => '2021-12-15',
            'zakonczono' => $zakonczono,
            'wersja_procedury' => SlownikPotwierdzenRodo::WERSJA_PROCEDURY_DZIS,
            'wyjatki' => null,
            'konto_id' => null,
            'wstrzymanie_do' => $wstrzymanieDo,
            'wstrzymanie_sprawa' => $wstrzymanieSprawa,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $numer;
    }
}

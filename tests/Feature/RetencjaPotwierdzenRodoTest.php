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
 * RETENCJA POTWIERDZEŃ RODO — WŁĄCZONA 2.10.2026 (decyzja właściciela, #2708, odsyła do D-233).
 * Do tej daty kasowanie było wyłączone (D-233); niżej testy dowodzą obu stanów
 * przełącznika awaryjnego, domyślnego włączenia i wpisu w harmonogramie.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO TEN PLIK PILNUJE, A CO PILNOWAŁ WCZEŚNIEJ
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Pierwotnie dowodził, że automat KASUJE po 36 miesiącach domyślnie.
 * Właściciel rozstrzygnął inaczej: sam predykat zostaje i nadal jest tu
 * zmierzony co do dnia, ale DOMYŚLNIE NIC SIĘ NIE KASUJE, dopóki prawnik
 * nie potwierdzi okresu. Dlatego plik ma teraz dwie warstwy:
 *
 *  * testy PREDYKATU — wołają `PrzedawnionePotwierdzeniaRodo` wprost,
 *    z jawnym progiem; klasa nie pyta o przełącznik i pytać nie ma;
 *  * testy WYŁĄCZENIA — wołają komendę i dowodzą, że bez jawnego włączenia
 *    nie wykonuje żadnego `DELETE`, oraz że `--na-sucho` mimo to liczy.
 *
 * Druga warstwa jest po to, żeby ktoś za tydzień nie „naprawił" decyzji
 * właściciela z powrotem, nie zauważywszy, że to była decyzja.
 *
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
    public function test_retencja_jest_domyslnie_wlaczona_na_36_miesiecy(): void
    {
        // TO JEST TEST DECYZJI WŁAŚCICIELA Z 2.10.2026 (#2708): 36 miesięcy,
        // włączone, z wyłącznikiem awaryjnym w zmiennej środowiskowej.
        $this->assertTrue((bool) config('kuking.potwierdzenia_rodo.retencja_wlaczona'), 'Retencja potwierdzeń RODO nie jest domyślnie włączona.');
        $this->assertSame(36, config('kuking.potwierdzenia_rodo.retention_months'));
    }

    #[Test]
    public function test_komenda_na_domyslnej_konfiguracji_kasuje_po_36_miesiacach_i_zostawia_mlodsze(): void
    {
        $stare = $this->potwierdzenie(zakonczono: Carbon::today()->subMonthsNoOverflow(36)->subDay()->toDateString());
        $granica = $this->potwierdzenie(zakonczono: Carbon::today()->subMonthsNoOverflow(36)->addDay()->toDateString());

        $this->artisan('kuking:sprzataj-potwierdzenia-rodo')->assertSuccessful();

        $this->assertDatabaseMissing('potwierdzenia_zadan_rodo', ['numer' => $stare]);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $granica]);
    }

    #[Test]
    public function test_potwierdzenie_konta_z_zabezpieczonym_dowodem_zostaje_a_inne_znika(): void
    {
        $zatrzymane = User::factory()->create();
        $inne = User::factory()->create();
        $a = $this->potwierdzenie(zakonczono: '2022-01-15');
        $b = $this->potwierdzenie(zakonczono: '2022-01-15');
        DB::table('potwierdzenia_zadan_rodo')->where('numer', $a)->update(['konto_id' => $zatrzymane->getKey()]);
        DB::table('potwierdzenia_zadan_rodo')->where('numer', $b)->update(['konto_id' => $inne->getKey()]);
        $this->zabezpieczDowod($zatrzymane);

        $this->artisan('kuking:sprzataj-potwierdzenia-rodo')->assertSuccessful();

        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $a]);
        $this->assertDatabaseMissing('potwierdzenia_zadan_rodo', ['numer' => $b]);
    }

    #[Test]
    public function test_komenda_przy_wylaczonej_retencji_nie_kasuje_niczego(): void
    {
        config(['kuking.potwierdzenia_rodo.retencja_wlaczona' => false]);
        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');

        Artisan::call('kuking:sprzataj-potwierdzenia-rodo', ['--miesiace' => 36]);
        $wyjscie = Artisan::output();

        $this->assertStringContainsString('WYŁĄCZONA', $wyjscie);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $stare]);

        // KONTROLA DODATNIA: wiersz NAPRAWDĘ był kandydatem do skasowania,
        // więc jego przetrwanie mierzy wyłączenie, a nie pusty predykat.
        // Bez tej asercji test przechodziłby tak samo na literówce w kolumnie.
        $this->assertSame(
            1,
            app(PrzedawnionePotwierdzeniaRodo::class)->posprzataj(36, naSucho: true)['skasowano'],
            'Wiersz nie był kandydatem — ten test nie mierzy wyłączenia.',
        );
    }

    #[Test]
    public function test_na_sucho_liczy_takze_przy_wylaczonej_retencji(): void
    {
        config(['kuking.potwierdzenia_rodo.retencja_wlaczona' => false]);
        // Tym właściciel ma przygotować dane historyczne, zanim prawnik
        // potwierdzi okres — dlatego dry-run NIE jest blokowany wyłączeniem.
        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');

        Artisan::call('kuking:sprzataj-potwierdzenia-rodo', ['--na-sucho' => true, '--miesiace' => 36]);
        $wyjscie = Artisan::output();

        $this->assertStringContainsString('Do skasowania: 1', $wyjscie);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $stare]);
    }

    #[Test]
    public function test_bez_okresu_komenda_odmawia_zamiast_zgadywac(): void
    {
        config(['kuking.potwierdzenia_rodo.retention_months' => null]);
        $this->potwierdzenie(zakonczono: '2022-01-15');

        // Bez `--miesiace` i bez potwierdzonego okresu w configu nie ma z czego
        // policzyć progu. Milczące przyjęcie 36 byłoby dokładnie tym, przed
        // czym broni `retention_months => null`.
        $kod = Artisan::call('kuking:sprzataj-potwierdzenia-rodo');

        $this->assertSame(1, $kod, 'Komenda bez ustalonego okresu powinna wyjść błędem.');
        $this->assertStringContainsString('nie jest ustalony', Artisan::output());
    }

    #[Test]
    public function test_po_wlaczeniu_komenda_kasuje_i_melduje_obie_liczby(): void
    {
        // DRUGA STRONA WYŁĄCZENIA. Bez tego testu „nic się nie skasowało"
        // przechodziłoby także na komendzie zepsutej na amen, a droga
        // włączenia opisana w configu byłaby obietnicą bez pokrycia.
        config([
            'kuking.potwierdzenia_rodo.retencja_wlaczona' => true,
            'kuking.potwierdzenia_rodo.retention_months' => 36,
        ]);

        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');
        $this->potwierdzenie(
            zakonczono: '2022-01-15',
            wstrzymanieDo: Carbon::today()->addYear()->toDateString(),
            wstrzymanieSprawa: 'Sygn. akt I C 123/25',
        );

        Artisan::call('kuking:sprzataj-potwierdzenia-rodo');
        $wyjscie = Artisan::output();

        $this->assertStringContainsString('Skasowano 1 potwierdzeń', $wyjscie);
        $this->assertStringContainsString('Pominięto z powodu udokumentowanego wstrzymania', $wyjscie);
        $this->assertStringContainsString(': 1.', $wyjscie);
        $this->assertDatabaseMissing('potwierdzenia_zadan_rodo', ['numer' => $stare]);
    }

    #[Test]
    public function test_harmonogram_zna_te_komende_na_wolnym_slocie(): void
    {
        $zadania = [];
        foreach (app(Schedule::class)->events() as $zdarzenie) {
            $zadania[(string) ($zdarzenie->description ?? '')] = $zdarzenie->expression;
        }

        $this->assertSame('0 2 * * *', $zadania['kuking:sprzataj-potwierdzenia-rodo'] ?? null, 'Retencja potwierdzeń RODO nie stoi w harmonogramie o 02:00.');

        // KONTROLA DODATNIA: skan harmonogramu widzi też inne zadania.
        $this->assertArrayHasKey('kuking:sprzataj-audyt', $zadania);
    }

    private function zabezpieczDowod(User $konto): void
    {
        DB::table('zabezpieczenia_dowodow')->insert([
            'id' => (string) Str::uuid(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'subject_user_id' => $konto->getKey(),
        ]);
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

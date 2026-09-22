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
 * RETENCJA POTWIERDZEŃ RODO — DZIŚ WYŁĄCZONA (decyzja właściciela, D-233).
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
    public function test_retencja_jest_domyslnie_wylaczona_a_okres_nieustalony(): void
    {
        // TO JEST TEST DECYZJI, NIE IMPLEMENTACJI (D-233). Gdy padnie, nie
        // „popraw konfigurację" — sprawdź najpierw, czy prawnik potwierdził
        // okres i czy właściciel świadomie włączył kasowanie.
        $this->assertFalse(
            (bool) config('kuking.potwierdzenia_rodo.retencja_wlaczona'),
            'Kasowanie potwierdzeń RODO zostało włączone domyślnie. To była decyzja właściciela (D-233), nie usterka do naprawienia.',
        );

        $this->assertNull(
            config('kuking.potwierdzenia_rodo.retention_months'),
            'Okres retencji dostał wartość domyślną. Dopóki prawnik go nie potwierdzi, ma być `null` — inaczej samo przestawienie flagi kasuje według zgadniętego progu.',
        );
    }

    #[Test]
    public function test_komenda_przy_wylaczonej_retencji_nie_kasuje_niczego(): void
    {
        $stare = $this->potwierdzenie(zakonczono: '2022-01-15');

        Artisan::call('kuking:sprzataj-potwierdzenia-rodo', ['--miesiace' => 36]);
        $wyjscie = Artisan::output();

        $this->assertStringContainsString('WYŁĄCZONA', $wyjscie);
        $this->assertStringContainsString('D-233', $wyjscie);
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
    public function test_harmonogram_celowo_nie_zna_tej_komendy(): void
    {
        // DRUGA BARIERA WYŁĄCZENIA (D-233): zadania nie ma w harmonogramie,
        // więc nie wystartuje nawet przy przypadkowo ustawionej zmiennej.
        // Dopisanie go tutaj jest jednym z trzech kroków włączenia opisanych
        // przy kluczu `potwierdzenia_rodo` w `config/kuking.php`.
        $nazwy = array_map(
            static fn (object $zadanie): ?string => $zadanie->description ?? null,
            app(Schedule::class)->events(),
        );

        $this->assertNotContains(
            'kuking:sprzataj-potwierdzenia-rodo',
            $nazwy,
            'Zadanie retencji wróciło do harmonogramu. To była decyzja właściciela (D-233) — sprawdź ją, zanim to „naprawisz".',
        );

        // KONTROLA DODATNIA: skan harmonogramu naprawdę widzi zadania,
        // więc powyższa nieobecność coś znaczy.
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

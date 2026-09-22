<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #87 — wszystkie daty na ekranie były przesunięte o dwie godziny.
 *
 * Serwis pokazywał czas UTC, bo `config('app.timezone')` to UTC. Przy wpisie
 * sprzed pół godziny nikt by tego nie zauważył. Przy TERMINIE, po którym coś
 * się kończy — data zawieszenia konta, termin na odwołanie od decyzji
 * moderacyjnej, wygaśnięcie paczki z danymi — dwie godziny to różnica między
 * „zdążyłem" a „nie zdążyłem".
 *
 * PIERWSZA WERSJA NAPRAWY BYŁA GORSZA OD PROBLEMU
 * Issue proponowało przestawić `app.timezone` na `Europe/Warsaw` (zmienna
 * `APP_TIMEZONE` istnieje w `.env`, więc wyglądała, jakby coś robiła).
 * Test niżej pokazał, że to PSUJE DANE: Laravel wysyła do PostgreSQL czas bez
 * informacji o strefie, a baza czyta go w strefie sesji, więc 23:30 czasu
 * polskiego lądowało w kolumnie jako 23:30 UTC. Dwie godziny za późno,
 * po cichu, z rozjazdem między wierszami sprzed i po zmianie.
 *
 * Dlatego baza trzyma UTC, a ekran pokazuje czas polski przez `Czas`.
 */
class StrefaCzasowaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * WIDOKI, KTÓRYM WOLNO WOŁAĆ `->format()` POZA `Czas::`.
     *
     * Rejestr istnieje, bo `->format()` ma DWA różne zastosowania, a skan
     * ich nie odróżnia: format dla człowieka (wtedy musi iść przez `Czas::`,
     * inaczej pokaże UTC) i format dla maszyny — `datetime` w `<time>`,
     * ISO 8601 w danych strukturalnych, nazwa pliku w eksporcie. Ten drugi
     * MA być niezależny od strefy czytelnika i przepuszczanie go przez
     * `Czas::` byłoby usterką, nie poprawką.
     *
     * Rejestr jest PUSTY i to jest stan zmierzony, nie założony: 20.09.2026
     * w `resources/views` nie było ani jednego wywołania `->format()`.
     * Wpis wolno dopisać tylko z powodem mówiącym, DLA KOGO jest ten format.
     * „Tak było" nie jest powodem.
     *
     * Pilnuje tego `test_rejestr_formatowania_nie_ma_martwych_wpisow` —
     * wpis wskazujący plik, który nie woła `->format()`, jest usuwany,
     * zanim zdąży kogoś przekonać, że coś sprawdzono.
     *
     * @var array<string, string>
     */
    private const WYJATKI_FORMATOWANIA = [];

    /**
     * REJESTR NIE MA MARTWYCH WPISÓW.
     *
     * Wpis, który przestał być potrzebny, jest gorszy niż jego brak: wygląda
     * na świadomą decyzję i cicho wyłącza spod skanu plik, który od dawna
     * niczego nie łamie. Następny czytelnik zobaczy wyjątek i uzna, że ktoś
     * to przemyślał.
     */
    public function test_rejestr_formatowania_nie_ma_martwych_wpisow(): void
    {
        /*
         * ASERCJA, KTÓRA DZIAŁA TAKŻE PRZY PUSTYM REJESTRZE.
         *
         * Sama pętla niżej nie wykonuje się, dopóki rejestr jest pusty —
         * a test bez ani jednej asercji jest zielony i nic nie znaczy
         * (PHPUnit nazywa go „risky" i ma rację). Dlatego najpierw
         * potwierdzamy stan, który ten pusty rejestr opisuje: w widokach
         * nie ma dziś ani jednego gołego `->format()`. Gdy ktoś takie
         * wywołanie doda i wpisze je do rejestru, ta liczba przestanie być
         * zerem i pętla zacznie mieć co sprawdzać.
         */
        $zFormatem = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $plik) {
            if (! str_ends_with((string) $plik, '.blade.php')) {
                continue;
            }

            if (str_contains((string) file_get_contents((string) $plik), '->format(')) {
                $zFormatem[] = str_replace(resource_path('views').'/', '', (string) $plik);
            }
        }

        sort($zFormatem);

        $this->assertSame(
            array_keys(self::WYJATKI_FORMATOWANIA),
            $zFormatem,
            'Widoki wołające `->format()` muszą zgadzać się co do jednego z rejestrem wyjątków. '
            .'Nowe wywołanie bez wpisu to przeoczenie; wpis bez wywołania to martwy wyjątek.',
        );

        foreach (self::WYJATKI_FORMATOWANIA as $wzgledna => $powod) {
            $sciezka = resource_path('views').'/'.$wzgledna;

            $this->assertFileExists($sciezka, "Rejestr wskazuje nieistniejący widok: {$wzgledna}");
            $this->assertNotSame('', trim($powod), "Wpis rejestru bez powodu: {$wzgledna}");
            $this->assertStringContainsString(
                '->format(',
                (string) file_get_contents($sciezka),
                "Martwy wpis rejestru: {$wzgledna} nie woła już ->format(), więc wyjątek jest niepotrzebny.",
            );
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_baza_liczy_i_zapisuje_w_utc(): void
    {
        // To NIE jest przeoczenie, tylko warunek poprawności zapisu.
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_ekran_pokazuje_czas_polski(): void
    {
        $this->assertSame('Europe/Warsaw', Czas::strefa());
    }

    public function test_data_na_ekranie_jest_polska_a_nie_utc(): void
    {
        // 21:30 UTC to 23:30 czasu polskiego. Wcześniej ekran pisał „21:30",
        // czyli godzinę, o której nic się nie działo.
        $moment = CarbonImmutable::parse('2026-07-15 21:30:00', 'UTC');

        $this->assertSame('23:30', Czas::data($moment, 'H:i'));
        $this->assertSame('15 lipca 2026', Czas::data($moment));
    }

    public function test_termin_na_granicy_doby_pokazuje_wlasciwy_dzien(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU.
        //
        // 22:30 UTC to już 16 lipca czasu polskiego. Serwis pokazywał
        // „15 lipca, 22:30" — a człowiek, który przychodzi w ostatniej chwili
        // odwołać się od blokady, patrzy właśnie na tę datę i ma rację,
        // że jej ufa.
        $moment = CarbonImmutable::parse('2026-07-15 22:30:00', 'UTC');

        $this->assertSame('16 lipca 2026, 00:30', Czas::data($moment, 'j F Y, H:i'));
    }

    public function test_zmiana_czasu_jest_uwzgledniona(): void
    {
        // Zimą Polska jest UTC+1, latem UTC+2. Sztywne przesunięcie o dwie
        // godziny byłoby poprawne przez pół roku — czyli niepoprawne.
        $zima = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        $lato = CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC');

        $this->assertSame('13:00', Czas::data($zima, 'H:i'));
        $this->assertSame('14:00', Czas::data($lato, 'H:i'));
    }

    public function test_brak_daty_nie_wywala_widoku(): void
    {
        // Widoki pytały o to przez `?->translatedFormat(...)`, więc pomocnik
        // musi umieć to samo — inaczej trzeba by dopisać `@if` w każdym
        // miejscu, a to jest ten rodzaj zmiany, przy której ktoś zapomni.
        $this->assertSame('', Czas::dataLubNic(null));
    }

    public function test_baza_dalej_przechowuje_czas_w_utc(): void
    {
        // Druga strona reguły, i to ta ważniejsza dla danych. Gdyby zmiana
        // zaczęła zapisywać do bazy czas lokalny, porównania dat między
        // starymi a nowymi wierszami rozjechałyby się o dwie godziny.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 21:30:00', 'UTC'));

        $user = User::factory()->create();
        $surowa = DB::table('users')->where('id', $user->getKey())->value('created_at');

        $this->assertSame(
            '2026-07-15 21:30:00',
            CarbonImmutable::parse($surowa)->utc()->format('Y-m-d H:i:s'),
            'Baza zapisała czas lokalny zamiast UTC.',
        );
    }

    public function test_zaden_widok_nie_formatuje_daty_z_pominieciem_pomocnika(): void
    {
        // TO JEST TEST, KTÓRY PILNUJE, ŻEBY TO SIĘ NIE ROZJECHAŁO.
        //
        // Dwadzieścia dziewięć miejsc formatowało daty samodzielnie. Naprawa
        // jednego z nich nic nie daje, jeśli następny widok znowu pokaże UTC —
        // a nikt tego nie zauważy, bo różnica to dwie godziny, nie błąd.
        $winne = [];
        $przejrzanych = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        foreach ($iterator as $plik) {
            if (! str_ends_with((string) $plik, '.blade.php')) {
                continue;
            }

            $przejrzanych++;

            $tresc = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents((string) $plik)) ?? '';

            // Sprawdzamy LINIA PO LINII, a nie cały plik: `Czas::lokalnie($x)`
            // z doklejonym `->diffForHumans()` jest poprawne, więc szukanie
            // samego „->diffForHumans(" w pliku dawałoby fałszywy alarm,
            // a szukanie „czy plik gdziekolwiek używa Czas::" — fałszywą zieleń.
            $wzgledna = str_replace(resource_path('views').'/', '', (string) $plik);

            if (array_key_exists($wzgledna, self::WYJATKI_FORMATOWANIA)) {
                continue;
            }

            foreach (explode("\n", $tresc) as $linia) {
                foreach (['translatedFormat', 'diffForHumans', 'format'] as $metoda) {
                    if (str_contains($linia, '->'.$metoda.'(') && ! str_contains($linia, 'Czas::')) {
                        $winne[] = $wzgledna;
                    }
                }
            }
        }

        // KONTROLNA — MUSI STAĆ PRZED ASERCJĄ NIŻEJ.
        //
        // Oczekiwaną wartością niżej jest PUSTA tablica, więc pętla, która nie
        // wykonała się ani razu, daje ten sam wynik co pętla, która przejrzała
        // wszystkie widoki i nic nie znalazła. Zły katalog, zmieniona nazwa
        // `resources/views`, przeniesienie widoków do pakietu — i test dalej
        // świeci zielono, nie sprawdzając niczego. Zmierzone: po podstawieniu
        // pustego katalogu asercja niżej przechodziła.
        $this->assertGreaterThan(
            50,
            $przejrzanych,
            'Nie przejrzano widoków (albo prawie żadnego) — reszta tego testu '
            .'nie sprawdzałaby wtedy niczego.',
        );

        $this->assertSame(
            [],
            array_values(array_unique($winne)),
            'Widok formatuje datę z pominięciem `App\Support\Czas` — pokaże czas UTC.',
        );
    }
}

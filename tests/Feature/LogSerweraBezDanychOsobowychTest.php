<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\BezDanychOsobowychWLogu;
use App\Logging\FiltrDanychOsobowych;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PDOException;
use ReflectionClassConstant;
use RuntimeException;
use Tests\TestCase;

/**
 * Log serwera (stderr → Railway) nie niesie e-maila ani hasha hasła
 * z `QueryException` — audyt prywatności z 23 września 2026.
 *
 * Test przechodzi PRAWDZIWĄ drogą produkcji: `report()` → kanał `stderr`
 * z `JsonFormatter` (jak w `.railway/railway.ts`) → strumień. Strumień jest
 * tu plikiem tymczasowym zamiast `php://stderr`, reszta konfiguracji kanału
 * zostaje nietknięta — łącznie z tapem, którego ten test pilnuje.
 */
class LogSerweraBezDanychOsobowychTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'basia.kowalska@example.com';

    private const HASH = '$2y$12$abcdefghijklmnopqrstuuJbT5z0s9m7Q1wq3e5r7t9y1u3i5o7pa';

    private string $plik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plik = (string) tempnam(sys_get_temp_dir(), 'log-stderr-');

        config([
            'logging.default' => 'stderr',
            'logging.channels.stderr.handler_with.stream' => $this->plik,
            'logging.channels.stderr.formatter' => JsonFormatter::class,
            'logging.channels.blad_webhook.url' => null,
        ]);
        Log::forgetChannel('stderr');
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('stderr');
        @unlink($this->plik);

        parent::tearDown();
    }

    private function naruszenieUnikalnosci(): QueryException
    {
        User::factory()->create(['email' => self::EMAIL]);
        $wiersz = User::factory()->raw(['email' => self::EMAIL]);
        $wiersz['password'] = self::HASH;

        try {
            DB::table('users')->insert($wiersz);
        } catch (QueryException $e) {
            return $e;
        }

        $this->fail('Oczekiwano naruszenia unikalności adresu e-mail.');
    }

    public function test_zgloszony_query_exception_nie_zostawia_w_logu_emaila_ani_hasha(): void
    {
        $wyjatek = $this->naruszenieUnikalnosci();

        // Warunek wstępny: bez filtra dane naprawdę by wyszły.
        $this->assertStringContainsString(self::EMAIL, $wyjatek->getMessage());
        $this->assertStringContainsString(self::HASH, $wyjatek->getMessage());

        report($wyjatek);

        $log = (string) file_get_contents($this->plik);
        $this->assertNotSame('', $log, 'report() nie zapisał niczego na kanale stderr.');

        $this->assertStringNotContainsString(self::EMAIL, $log);
        $this->assertStringNotContainsString(self::HASH, $log);
        $this->assertStringNotContainsString('basia.kowalska', $log);

        // Przydatność diagnostyczna zostaje.
        $this->assertStringContainsString('SQLSTATE[23505]', $log);
        $this->assertStringContainsString('users_email_unique', $log);
        $this->assertStringContainsString('insert into users', $log);
        $this->assertStringContainsString(json_encode($wyjatek::class), $log);
        $this->assertStringContainsString(json_encode(PDOException::class), $log);
        $this->assertStringContainsString('LogSerweraBezDanychOsobowychTest.php:', $log);
    }

    public function test_surowy_komunikat_bazy_wklejony_jako_tekst_tez_jest_uciety(): void
    {
        $wyjatek = $this->naruszenieUnikalnosci();

        Log::error('Nie udało się: '.$wyjatek->getMessage(), ['uzytkownik' => self::EMAIL]);

        $log = (string) file_get_contents($this->plik);
        $this->assertStringNotContainsString(self::EMAIL, $log);
        $this->assertStringNotContainsString(self::HASH, $log);
        $this->assertStringContainsString('Nie udało się: SQLSTATE[23505]', $log);
        $this->assertStringContainsString(BezDanychOsobowychWLogu::EMAIL, $log);
    }

    public function test_zwykly_wyjatek_zachowuje_komunikat_bez_danych(): void
    {
        report(new RuntimeException('Brak wariantu feed dla zdjęcia 42, kontakt: '.self::EMAIL.' hash '.self::HASH));

        $log = (string) file_get_contents($this->plik);
        $this->assertStringContainsString('Brak wariantu feed dla zdjęcia 42, kontakt: [e-mail usunięty] hash [hash hasła usunięty]', $log);
        $this->assertStringNotContainsString(self::EMAIL, $log);
        $this->assertStringNotContainsString(self::HASH, $log);
    }

    /** Kontrola dodatnia: log bez danych osobowych przechodzi bez zmian. */
    public function test_zwykly_log_bez_danych_osobowych_zostaje_bez_zmian(): void
    {
        Log::info('kuking:sprawdz-kolejke — zaległe {liczba}, koszt $5', ['liczba' => 3, 'trasa' => 'recipes.show']);

        $wpis = json_decode(trim((string) file_get_contents($this->plik)), true);

        $this->assertSame('kuking:sprawdz-kolejke — zaległe 3, koszt $5', $wpis['message']);
        $this->assertSame(['liczba' => 3, 'trasa' => 'recipes.show'], $wpis['context']);
    }

    /** Każdy kanał logu serwera ma filtr — nowy kanał stderr bez niego to regres. */
    public function test_wszystkie_kanaly_serwera_maja_filtr(): void
    {
        foreach (['stderr', 'pomiary', 'single', 'daily'] as $kanal) {
            $this->assertContains(
                FiltrDanychOsobowych::class,
                config("logging.channels.$kanal.tap", []),
                "Kanał $kanal nie ma filtra danych osobowych.",
            );
        }
    }

    /**
     * Regresja: `preg_replace()` przy błędzie PCRE („JIT stack limit
     * exhausted", „Backtrack limit exhausted") oddaje `null`, a `(string) null`
     * wycinał CAŁĄ treść wpisu — po cichu. Teraz zostaje znacznik, nigdy
     * oryginał. Wzorzec e-maila już nie pada na złośliwym komentarzu (test
     * niżej), więc błąd PCRE wymuszamy tu niskim limitem nawrotów.
     */
    public function test_blad_wyrazenia_regularnego_zostawia_znacznik_a_nie_pustke_ani_oryginal(): void
    {
        $tekst = 'x@'.str_repeat('a.', 1000).' '.self::EMAIL;
        $limit = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '50');

        try {
            // Warunek wstępny: przy tym limicie wzorzec e-maila naprawdę pada.
            $this->assertNull(@preg_replace($this->wzorzecEmail(), 'E', $tekst));

            Log::warning('Wpis z komentarzem', ['tresc' => $tekst, 'post_id' => 7]);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }

        $wpis = json_decode(trim((string) file_get_contents($this->plik)), true);

        $this->assertIsArray($wpis);
        $this->assertSame('Wpis z komentarzem', $wpis['message']);
        $this->assertSame(7, $wpis['context']['post_id']);
        $this->assertStringStartsWith(BezDanychOsobowychWLogu::BLAD_FILTRA, $wpis['context']['tresc']);
        $this->assertStringNotContainsString(self::EMAIL, (string) file_get_contents($this->plik));
    }

    /**
     * Regresja: dawny wzorzec e-maila nawracał katastrofalnie — ~20 KB tekstu
     * z „@" i kropkami (`x@a.a.a.…`) wyczerpywało stos JIT i cała wartość
     * zamieniała się w `BLAD_FILTRA`. Jeden złośliwy komentarz wycinał więc
     * z logu wpis, który miał pomóc w dochodzeniu. Teraz tekst zostaje,
     * a znika tylko adres.
     */
    public function test_zlosliwy_tekst_z_malpa_i_kropkami_nie_zamienia_wartosci_w_znacznik(): void
    {
        $zlosliwe = [
            'x@'.str_repeat('a.', 10000),
            'x@'.str_repeat('1.', 10000),
            str_repeat('a.', 5000).'@'.str_repeat('a1.', 3000).'1',
            str_repeat('a@a.', 5000),
        ];

        // Warunek wstępny: dawny wzorzec naprawdę się na tym wywraca.
        $this->assertNull(@preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', 'E', $zlosliwe[0]));

        foreach ($zlosliwe as $i => $szum) {
            $this->assertGreaterThan(19000, strlen($szum));
            Log::warning('Wpis z komentarzem', ['tresc' => $szum.' '.self::EMAIL.' koniec', 'nr' => $i]);
        }

        $log = (string) file_get_contents($this->plik);
        $wpisy = array_map(static fn (string $linia): array => json_decode($linia, true), explode("\n", trim($log)));

        $this->assertCount(count($zlosliwe), $wpisy);
        $this->assertStringNotContainsString(self::EMAIL, $log);
        $this->assertStringNotContainsString(BezDanychOsobowychWLogu::BLAD_FILTRA, $log);

        foreach ($wpisy as $wpis) {
            $this->assertStringEndsWith(' '.BezDanychOsobowychWLogu::EMAIL.' koniec', $wpis['context']['tresc']);
        }

        // Pierwszy szum nie ma domeny z literami na końcu — zostaje bajt w bajt.
        $this->assertStringStartsWith($zlosliwe[0], $wpisy[0]['context']['tresc']);
    }

    /** Kontrola dodatnia wzorca: adresy z prawdziwego życia dalej znikają. */
    public function test_wzorzec_emaila_lapie_zwykle_adresy_i_zostawia_to_co_nie_jest_adresem(): void
    {
        $przypadki = [
            'napisz: basia@wp.pl.' => 'napisz: [E].',
            'x a.b+c%d@sub.dom-ena.co.uk!' => 'x [E]!',
            'jan-kowalski@poczta.onet.pl, ewa@o2.pl' => '[E], [E]',
            '<basia@wp.pl>: Recipient address rejected' => '<[E]>: Recipient address rejected',
            'foo@bar' => 'foo@bar',
            'user@host.123' => 'user@host.123',
            'wersja 1.2@3.4' => 'wersja 1.2@3.4',
        ];

        foreach ($przypadki as $wejscie => $oczekiwane) {
            $this->assertSame($oczekiwane, preg_replace($this->wzorzecEmail(), '[E]', $wejscie), $wejscie);
        }
    }

    private function wzorzecEmail(): string
    {
        return (string) (new ReflectionClassConstant(BezDanychOsobowychWLogu::class, 'WZORZEC_EMAIL'))->getValue();
    }

    /** Model w kontekście, klucz-adres i tablica głębsza niż limit filtra. */
    public function test_obiekty_klucze_i_glebokie_tablice_tez_sa_czyszczone(): void
    {
        $basia = User::factory()->make(['email' => self::EMAIL]);

        $gleboko = ['e' => self::EMAIL];
        for ($i = 0; $i < 12; $i++) {
            $gleboko = ['poziom' => $gleboko];
        }

        Log::info('Kontekst z obiektami', [
            'user' => $basia,
            'licznik' => [self::EMAIL => 3],
            'gleboko' => $gleboko,
        ]);

        $log = (string) file_get_contents($this->plik);
        $wpis = json_decode(trim($log), true);

        $this->assertStringNotContainsString(self::EMAIL, $log);
        $this->assertStringNotContainsString('basia.kowalska', $log);
        $this->assertSame([BezDanychOsobowychWLogu::EMAIL => 3], $wpis['context']['licznik']);
        $this->assertArrayHasKey(User::class, $wpis['context']['user']);
        $this->assertStringContainsString(BezDanychOsobowychWLogu::ZA_GLEBOKO, $log);
    }

    /**
     * Głębokość sprawdzana NA PROCESORZE, nie na pliku: `JsonFormatter` sam
     * ucina normalizację na 9. poziomie, więc „w logu nie ma e-maila" byłoby
     * prawdą także wtedy, gdyby procesor oddał za głęboką tablicę surową.
     * Tu widać, że w miejscu za głębokiej tablicy stoi znacznik.
     */
    public function test_tablica_glebsza_niz_limit_zamienia_sie_w_znacznik_na_procesorze(): void
    {
        $gleboko = ['e' => self::EMAIL];
        for ($i = 0; $i < 12; $i++) {
            $gleboko = ['poziom' => $gleboko];
        }

        $wynik = (new BezDanychOsobowychWLogu)(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'test',
            level: Level::Info,
            message: 'Głęboko',
            context: ['gleboko' => $gleboko],
        ));

        $wezel = $wynik->context['gleboko'];
        $poziomy = 1;

        while (is_array($wezel)) {
            $this->assertSame(['poziom'], array_keys($wezel));
            $wezel = $wezel['poziom'];
            $poziomy++;
        }

        $this->assertSame(BezDanychOsobowychWLogu::ZA_GLEBOKO, $wezel);
        // Znacznik stoi dokładnie na granicy filtra, nie wcześniej.
        $this->assertSame((new ReflectionClassConstant(BezDanychOsobowychWLogu::class, 'GLEBOKOSC'))->getValue(), $poziomy);
    }

    /**
     * `LOG_STACK=stderr,blad_webhook`: stos zbiera procesory kanałów
     * składowych. Filtr jest na HANDLERZE stderr, więc handler webhooka dalej
     * dostaje PRAWDZIWY obiekt wyjątku (klasa, plik:linia, odcisk), a nie
     * tablicę z procesora — a log stderr i tak jest czysty.
     */
    public function test_stos_z_webhookiem_nie_odbiera_webhookowi_obiektu_wyjatku(): void
    {
        Http::fake();
        config([
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['stderr', 'blad_webhook'],
            'logging.channels.blad_webhook.url' => 'https://example.test/webhook',
        ]);
        Log::forgetChannel('stack');
        Log::forgetChannel('blad_webhook');

        $wyjatek = $this->naruszenieUnikalnosci();
        Log::error($wyjatek->getMessage(), ['exception' => $wyjatek]);
        Log::forgetChannel('stack');
        Log::forgetChannel('blad_webhook');

        Http::assertSent(function ($zadanie) use ($wyjatek): bool {
            $tresc = json_encode($zadanie->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // Gałąź „jest obiekt wyjątku": klasa i plik:linia, bez komunikatu.
            $this->assertStringContainsString(trim((string) json_encode($wyjatek::class), '"'), (string) $tresc);
            $miejsce = ltrim(substr($wyjatek->getFile(), strlen(base_path())), '/').':'.$wyjatek->getLine();
            $this->assertStringContainsString($miejsce, (string) $tresc);
            $this->assertStringContainsString('odcisk: ', (string) $tresc);
            $this->assertStringNotContainsString(self::EMAIL, (string) $tresc);
            $this->assertStringNotContainsString(BezDanychOsobowychWLogu::EMAIL, (string) $tresc);

            return true;
        });

        $log = (string) file_get_contents($this->plik);
        $this->assertStringContainsString('SQLSTATE[23505]', $log);
        $this->assertStringNotContainsString(self::EMAIL, $log);
    }
}

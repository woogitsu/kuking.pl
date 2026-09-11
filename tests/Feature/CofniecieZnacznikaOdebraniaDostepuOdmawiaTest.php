<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji dokładającej `dostep_odebrany_at` nie kasuje po cichu
 * informacji „ta osoba odebrała nam dostęp u dostawcy" (issue #259, D-088).
 *
 * DLACZEGO TA MIGRACJA WYMAGA STRAŻNIKA
 * `down()` kasuje kolumnę, a po `down()` prawie nigdy nie idzie nic — idzie
 * kolejny `migrate` (`migrate:refresh` w CI albo awaryjny rollback
 * wdrożenia). Kolumna WRACA, żaden wiersz nie ginie, więc NIE MA BŁĘDU DO
 * ZAUWAŻENIA. Wraca tylko PUSTA: od tej chwili serwis twierdzi, że
 * powiązanie jest żywe, choć u dostawcy zostało cofnięte, a ekran
 * „Ustawienia → Bezpieczeństwo" pokazuje tym osobom połączone konto, którego
 * już nie ma. Znika przy tym jedyny ślad kliknięcia, które człowiek zrobił
 * u dostawcy, a którego skutków nikt mu nie zapowiedział.
 *
 * Test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która nie
 * cofa się NIGDY, blokowałaby świeże wdrożenie, staging i lokalne bazy bez
 * powodu — i jest błędem tej samej wagi w drugą stronę. Odmowa ma być WĄSKA
 * (D-088), więc jest tu też kontrola, że strażnik nie rusza niczego poza
 * swoim zakresem, oraz kontrola, że furtka opisana w komunikacie NAPRAWDĘ
 * działa — furtka opisana, a niedziałająca, jest gorsza niż jej brak.
 */
class CofniecieZnacznikaOdebraniaDostepuOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_KASUJ_ZNACZNIKI_ODEBRANIA';

    private const PLIK = 'migrations/2026_09_11_700000_dodaj_znacznik_odebrania_dostepu.php';

    private const TABELA = 'tozsamosci_zewnetrzne';

    private const KOLUMNA = 'dostep_odebrany_at';

    /**
     * Sprzątanie zmiennej środowiskowej MUSI objąć wszystkie trzy miejsca.
     * Furtka w migracji czyta `getenv()`, ale `putenv()` w PHP-ie z SAPI CLI
     * nie jest jedyną drogą, którą ta nazwa trafia do procesu — zostawiona
     * w `$_SERVER` albo `$_ENV` przeciekłaby do kolejnych testów i ciszej
     * niż cokolwiek wyłączyła strażnika.
     */
    protected function tearDown(): void
    {
        unset($_SERVER[self::ZGODA], $_ENV[self::ZGODA]);
        putenv(self::ZGODA);

        parent::tearDown();
    }

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    /**
     * Powiązanie z Facebookiem dla nowej osoby.
     *
     * Wiersze zakładamy tak, jak robi to serwis — `connectFacebook()`
     * i `oznaczOdebranieDostepu()`, czyli jawne, nazwane metody modelu.
     * Własny `insert()` omijałby `$fillable` (celowo puste, patrz
     * `TozsamoscZewnetrzna`) i przy okazji łatwo rozjechałby się
     * z CHECK-ami tej tabeli: dostawca z zamkniętej listy, identyfikator bez
     * spacji, `UNIQUE (dostawca, identyfikator)` i `UNIQUE (dostawca,
     * user_id)`. Konta zakładamy fabryką (`$this->user()`), bo nazwy
     * użytkowników mają w bazie własny CHECK.
     */
    private function powiazanie(string $identyfikator, bool $odebrany): User
    {
        $osoba = $this->user();
        $osoba->connectFacebook($identyfikator);

        if ($odebrany) {
            $osoba->oznaczOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);
        }

        return $osoba;
    }

    /** Wiersz powiązania tej osoby — w całości, do porównania „przed i po". */
    private function wiersz(User $osoba): ?object
    {
        return DB::table(self::TABELA)->where('user_id', $osoba->getKey())->first();
    }

    private function znacznikow(): int
    {
        return (int) DB::table(self::TABELA)->whereNotNull(self::KOLUMNA)->count();
    }

    public function test_cofniecie_odmawia_gdy_ktos_odebral_nam_dostep(): void
    {
        // JEDNA OSOBA, NIE PIĘĆ. Komunikat sklejał dotąd liczbę ze słowem
        // „osób" („1 osób odebrało"), więc test zakładał pięć, żeby nie
        // zamrażać w asercji błędnej polszczyzny — a przypadek najbardziej
        // prawdopodobny na produkcji, czyli jedna osoba, był jedynym
        // nieprzetestowanym. Komunikat stawia teraz rzeczownik przed liczbą
        // („Liczba osób, których to dotyczy: 1."), więc jedynka jest zdaniem
        // poprawnym i to ona jest tu mierzona.
        $this->powiazanie('10221234567890001', odebrany: true);

        // DWA POWIĄZANIA BEZ ZNACZNIKA leżą w tej samej tabeli i nie mają
        // się liczyć. Zostają tu po to, żeby różnica 1 kontra 3 dowodziła,
        // że strażnik pyta o `whereNotNull`, a nie o „czy cokolwiek tu jest".
        $this->powiazanie('10221234567890801', odebrany: false);
        $this->powiazanie('10221234567890802', odebrany: false);

        $wartoscPrzed = DB::table(self::TABELA)
            ->whereNotNull(self::KOLUMNA)
            ->orderBy('id')
            ->value(self::KOLUMNA);

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM — i to nie
        // jest stylistyka. `$this->fail()` rzuca `AssertionFailedError`, a ta
        // dziedziczy przez `PHPUnit\Framework\Exception` po `RuntimeException`,
        // więc postawiona wewnątrz `try` wpadłaby do własnego `catch`. Tutaj
        // wyłapują ją dziś asercje na treść komunikatu, ale przy `catch` bez
        // asercji ten sam kształt daje test-atrapę: zielony także wtedy, gdyby
        // strażnika nie było wcale. Poza blokiem odmowa jest sprawdzana wprost.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało informację o odebraniu dostępu.');

        // Trzy rzeczy, bez których komunikat zostawia człowieka z samym
        // „nie da się": ILU osób to dotyczy, CZYM TO GROZI i jak
        // powiedzieć wprost „wiem, co robię". Fragmenty dobrane tak, żeby
        // żaden nie mógł trafić się przypadkiem w innym komunikacie tego
        // repozytorium — nie „Cofnięcie tej migracji", które ma pięć
        // innych strażników, tylko zdania osobliwe dla TEGO.
        $this->assertStringContainsString(
            'Liczba osób, których to dotyczy: 1.',
            $odmowa->getMessage(),
            'Komunikat nie mówi, ilu osób dotyczy strata.',
        );

        $this->assertStringContainsString(
            'kolumna wróci pusta, więc serwis uzna te powiązania za żywe',
            $odmowa->getMessage(),
            'Komunikat nie mówi, czym grozi cofnięcie — a to jest cały powód odmowy.',
        );

        $this->assertStringContainsString(
            self::ZGODA.'=true',
            $odmowa->getMessage(),
            'Komunikat nie podaje sposobu, żeby powiedzieć wprost „wiem, co robię".',
        );

        // NAJWAŻNIEJSZE: dane NADAL SĄ. Odmowa, która zdążyła skasować
        // kolumnę, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertTrue(
            Schema::hasColumn(self::TABELA, self::KOLUMNA),
            'Odmowa zdążyła skasować kolumnę — czyli nie była odmową.',
        );

        $this->assertSame(1, $this->znacznikow(), 'Po odmowie znacznik ma zostać na miejscu.');

        // I dwa powiązania BEZ znacznika też leżą nietknięte — odmowa ma być
        // wąska. Razem trzy wiersze, z czego strażnik policzył jeden.
        $this->assertSame(3, (int) DB::table(self::TABELA)->count(),
            'Odmowa ruszyła powiązania, których ta migracja w ogóle nie dotyczy.');

        $this->assertSame(
            $wartoscPrzed,
            DB::table(self::TABELA)->whereNotNull(self::KOLUMNA)->orderBy('id')->value(self::KOLUMNA),
            'Znacznik ma mieć po odmowie dokładnie tę samą wartość, co przed nią.',
        );
    }

    public function test_na_swiezym_wdrozeniu_cofniecie_dziala_bez_pytania(): void
    {
        // Pusta tabela to świeże wdrożenie: nie ma czego stracić i nie ma
        // o co pytać. To jest kontrola dodatnia dla strażnika wyżej — bez
        // niej test przechodziłby także dla migracji, która nie cofa się
        // NIGDY, a to jest błąd tej samej wagi w drugą stronę.
        $this->assertSame(0, (int) DB::table(self::TABELA)->count());

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasColumn(self::TABELA, self::KOLUMNA),
            'Na pustej tabeli `down()` ma po prostu zadziałać i usunąć kolumnę.',
        );
    }

    public function test_powiazanie_bez_znacznika_nie_blokuje_cofniecia(): void
    {
        // Druga połowa kontroli dodatniej i ta ważniejsza: tabela NIE jest
        // pusta, tylko kolumna jest. Strażnik ma liczyć wypełnione znaczniki,
        // a nie wiersze — inaczej każde konto połączone z Facebookiem
        // blokowałoby rollback do końca świata.
        $this->powiazanie('10221234567890999', odebrany: false);

        $this->assertSame(1, (int) DB::table(self::TABELA)->count());
        $this->assertSame(0, $this->znacznikow());

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasColumn(self::TABELA, self::KOLUMNA),
            'Powiązanie BEZ znacznika nie ma czego stracić — `down()` ma przejść.',
        );
    }

    public function test_odmowa_nie_rusza_niczego_poza_swoim_zakresem(): void
    {
        // Kontrola wąskości (D-088): strażnik broni znaczników i tylko ich.
        // Powiązanie, którego ta strata w ogóle nie dotyczy, i konto, do
        // którego należy, mają po odmowie wyglądać dokładnie tak samo.
        $this->powiazanie('10221234567890001', odebrany: true);

        $nietkniety = $this->powiazanie('10221234567890002', odebrany: false);

        $wierszPrzed = $this->wiersz($nietkniety);
        $kontoPrzed = DB::table('users')->where('id', $nietkniety->getKey())->first();

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM — i to nie
        // jest stylistyka. `$this->fail()` rzuca `AssertionFailedError`, a ta
        // dziedziczy przez `PHPUnit\Framework\Exception` po `RuntimeException`
        // (`vendor/phpunit/phpunit/src/Framework/Exception/Exception.php`).
        // Postawiona wewnątrz `try` wpadłaby więc do własnego `catch`, a ten
        // przy teście wąskości nie ma asercji, bo sprawdzamy SKUTKI UBOCZNE —
        // czyli test byłby zielony także wtedy, gdyby strażnika nie było wcale.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło, choć jeden znacznik był wypełniony.');

        $this->assertEquals(
            $wierszPrzed,
            $this->wiersz($nietkniety),
            'Odmowa ruszyła powiązanie, którego ta migracja w ogóle nie dotyczy.',
        );

        $this->assertEquals(
            $kontoPrzed,
            DB::table('users')->where('id', $nietkniety->getKey())->first(),
            'Odmowa ruszyła konto, choć miała tylko odmówić.',
        );
    }

    public function test_zgoda_wypowiedziana_wprost_pozwala_skasowac(): void
    {
        // Furtka opisana w komunikacie, a niedziałająca, jest gorsza niż jej
        // brak: człowiek wpisuje to, co mu kazano, nic się nie dzieje i nie
        // wie, czy pomylił się on, czy serwis. Dlatego testujemy DOKŁADNIE tę
        // wartość, którą komunikat odmowy podaje człowiekowi — `=true`.
        // Migracja czyta ją przez `filter_var(..., FILTER_VALIDATE_BOOLEAN)`,
        // więc przyjmuje też `1`, `on` czy `yes`, ale to jest szczegół
        // implementacji; wiążące jest to, co stoi w komunikacie.
        $this->powiazanie('10221234567890003', odebrany: true);

        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasColumn(self::TABELA, self::KOLUMNA),
            'Zgoda wypowiedziana wprost tak, jak każe komunikat, ma przepuścić cofnięcie.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji pól zgłoszenia prawnego nie kasuje po cichu zgłoszeń
 * z DSA art. 16 (D-088).
 *
 * DLACZEGO AKURAT TA MIGRACJA JEST GROŹNA
 * `down()` zdejmuje osiem kolumn, w tym `notifier_name`, `notifier_email`
 * i `illegality_explanation`. W wierszu zostaje samo `reason` — czterdzieści
 * znaków kodu powodu — czyli zgłoszenie bez zgłaszającego i bez treści.
 * A `down()` prawie nigdy nie występuje sam: po nim idzie kolejny `migrate`,
 * kolumny WRACAJĄ, CHECK-i WRACAJĄ, liczba wierszy się zgadza i NIE MA BŁĘDU
 * DO ZAUWAŻENIA. Znika tylko to, czego nikt już nie odtworzy: imię i adres
 * osoby, która zgłosiła przestępstwo, oraz uzasadnienie, na podstawie którego
 * moderator podjął decyzję i na które ktoś mógł się powołać w odwołaniu.
 *
 * Zabezpieczeniem jest `throw` w `down()`, nie zdanie w komentarzu migracji
 * (AGENTS.md §6). Ten test sprawdza, że ten `throw` naprawdę tam stoi i że
 * stoi PRZED kasowaniem, a nie po nim.
 *
 * Test sprawdza CZTERY rzeczy, bo sama odmowa nie jest dowodem:
 *  1. odmowa przy danych do stracenia — razem z tym, że dane PO ODMOWIE NADAL SĄ;
 *  2. kontrola dodatnia: na tabeli bez zgłoszeń prawnych `down()` cofa naprawdę
 *     (bez tego test przechodziłby także dla migracji, która nie cofa się nigdy);
 *  3. wąskość: zgłoszenie społecznościowe zostaje po odmowie nietknięte;
 *  4. furtka opisana w komunikacie naprawdę działa — i działa dokładnie przy
 *     tej wartości, którą komunikat podaje.
 */
class CofniecieZgloszenPrawnychOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_KASUJE_ZGLOSZENIA_PRAWNE';

    private const PLIK = 'migrations/2026_09_06_200000_add_legal_notice_fields_to_reports.php';

    /**
     * Kolumny, które `down()` zdejmuje — czyli dokładnie to, co jest do
     * stracenia. Lista jest wypisana wprost, a nie czytana z migracji:
     * test ma OBLAĆ, gdy ktoś dopisze tam dziewiątą kolumnę bez namysłu.
     *
     * @var list<string>
     */
    private const KOLUMNY = [
        'source', 'notifier_name', 'notifier_email', 'target_url',
        'illegality_explanation', 'good_faith_at', 'receipt_sent_at', 'decision_sent_at',
    ];

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
     * Zgłoszenie nielegalnej treści w kształcie, który baza w ogóle przyjmuje.
     *
     * `reports_legal_notice_complete_check` wymaga uzasadnienia i oświadczenia
     * o dobrej wierze, a `target_id` przy nierozpoznanym adresie jest puste —
     * ten sam kształt, co w `CofniecieMigracjiNumerSprawyTest`, żeby nie było
     * w repozytorium dwóch różnych pomysłów na to samo zgłoszenie.
     */
    private function zgloszeniePrawne(string $imie, string $email): Report
    {
        return Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'notifier_name' => $imie,
            'notifier_email' => $email,
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /**
     * Zgłoszenie SPOŁECZNOŚCIOWE: „to jest spam".
     *
     * Musi mieć zgłaszającego i konkretny cel — pilnuje tego
     * `reports_community_target_check`. Konto bierzemy z fabryki, bo nazwy
     * użytkowników mają w bazie własny CHECK i własny string tu by się o niego
     * odbił.
     */
    private function zgloszenieSpolecznosciowe(): Report
    {
        $zglaszajacy = User::factory()->create();

        return Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'reason' => 'spam',
            'details' => 'Ten wpis to reklama garnków.',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_cofniecie_odmawia_gdy_sa_zgloszenia_prawne(): void
    {
        // JEDEN WIERSZ, NIE PIĘĆ — i to jest cała treść tej zmiany.
        // Komunikat wklejał dotąd liczbę w sztywną frazę („jest 1 zgłoszeń"),
        // więc ten test zakładał pięć wierszy, żeby nie zamrażać w asercji
        // błędnej polszczyzny. Skutek był taki, że przypadek NAJBARDZIEJ
        // prawdopodobny na produkcji — jeden wiersz — był jedynym, którego
        // nikt nie sprawdzał. Komunikat stawia teraz rzeczownik przed liczbą,
        // więc jedynka jest zdaniem poprawnym i to ona jest tu mierzona.
        $pierwsze = $this->zgloszeniePrawne('Barbara Nowak', 'barbara@example.com');

        // DWA ZGŁOSZENIA SPOŁECZNOŚCIOWE leżą w tej samej tabeli i NIE MA
        // ich w liczbie z komunikatu. Zostają tu właśnie po to: gdyby
        // strażnik liczył wszystkie wiersze, napisałby „3" i asercja niżej
        // by oblała. Przy jednym wierszu w zakresie ta kontrola wąskości
        // jest ważniejsza niż przy pięciu, bo różnica 1 kontra 3 jest
        // jedynym, co odróżnia dobry licznik od złego.
        $this->zgloszenieSpolecznosciowe();
        $this->zgloszenieSpolecznosciowe();

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

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało dane zgłaszających z DSA art. 16.');

        // Komunikat ma powiedzieć ILU rzeczy to dotyczy, CO ZROBIĆ
        // ZAMIAST TEGO i jak powiedzieć wprost „wiem, co robię".
        // Bez tych trzech rzeczy człowiek o drugiej w nocy zostaje
        // z samym „nie da się" i szuka sposobu, żeby to obejść.
        $this->assertStringContainsString(
            'Liczba zgłoszeń nielegalnej treści (DSA art. 16) w tabeli `reports`: 1.',
            $odmowa->getMessage(),
            'Komunikat ma podać liczbę zgłoszeń PRAWNYCH (1), a nie wszystkich wierszy (3).',
        );

        // Stara, niegramatyczna fraza nie ma prawa wrócić.
        $this->assertStringNotContainsString('jest 1 zgłoszeń', $odmowa->getMessage());
        $this->assertStringContainsString('Zrób kopię tabeli, a potem uruchom ponownie', $odmowa->getMessage());
        $this->assertStringContainsString(self::ZGODA, $odmowa->getMessage());

        // NAJWAŻNIEJSZE: dane NADAL SĄ. Odmowa, która zdążyła skasować, to
        // tylko ładniejszy komunikat o stracie.
        foreach (self::KOLUMNY as $kolumna) {
            $this->assertTrue(
                Schema::hasColumn('reports', $kolumna),
                "Kolumna `{$kolumna}` zniknęła mimo odmowy cofnięcia.",
            );
        }

        $wiersz = DB::table('reports')->where('id', $pierwsze->getKey())->first();

        $this->assertNotNull($wiersz, 'Zgłoszenie prawne zniknęło z tabeli mimo odmowy cofnięcia.');
        $this->assertSame('Barbara Nowak', $wiersz->notifier_name, 'Imię zgłaszającego przepadło mimo odmowy.');
        $this->assertSame('barbara@example.com', $wiersz->notifier_email, 'Adres zgłaszającego przepadł mimo odmowy.');
        $this->assertSame(
            'To jest mój tekst, przepisany bez zgody z mojej książki.',
            $wiersz->illegality_explanation,
            'Uzasadnienie nielegalności przepadło mimo odmowy — a to na jego podstawie zapada decyzja.',
        );

        $this->assertSame(
            1,
            DB::table('reports')->where('source', Report::SOURCE_LEGAL_NOTICE)->count(),
            'Po odmowie zniknęło zgłoszenie prawne, którego strażnik miał bronić.',
        );

        // I dwa społecznościowe też leżą nietknięte — odmowa ma być WĄSKA.
        $this->assertSame(
            2,
            DB::table('reports')->where('source', Report::SOURCE_COMMUNITY)->count(),
            'Odmowa ruszyła zgłoszenia społecznościowe, których ta migracja nie dotyczy.',
        );
    }

    public function test_na_tabeli_bez_zgloszen_prawnych_cofniecie_dziala_bez_pytania(): void
    {
        // Tabela NIE jest pusta — jest w niej zwykłe zgłoszenie
        // społecznościowe. Odmowa ma być WĄSKA (D-088): blokuje ją wyłącznie
        // obecność danych, których `up()` nie odtworzy, a nie sam fakt, że
        // ktoś już czegoś użył.
        $this->zgloszenieSpolecznosciowe();

        $this->assertSame(
            0,
            DB::table('reports')->where('source', Report::SOURCE_LEGAL_NOTICE)->count(),
            'Test startuje ze zgłoszeniem prawnym w tabeli — mierzyłby co innego, niż zakłada.',
        );

        $this->assertTrue(Schema::hasColumn('reports', 'source'), 'Kolumny nie było już przed cofnięciem.');

        $this->migracja()->down();

        // KONTROLA DODATNIA: bez niej ten test przechodziłby także dla
        // migracji, która nie cofa się NIGDY — a zablokowany na zawsze
        // rollback jest błędem tej samej wagi w drugą stronę.
        $this->assertFalse(
            Schema::hasColumn('reports', 'source'),
            'Cofnięcie nie zdjęło kolumny `source` — `down()` nic nie cofnął.',
        );
        $this->assertFalse(
            Schema::hasColumn('reports', 'illegality_explanation'),
            'Cofnięcie nie zdjęło kolumny `illegality_explanation`.',
        );
    }

    public function test_odmowa_nie_rusza_zgloszenia_spolecznosciowego(): void
    {
        // Wąskość odmowy: strażnik broni zgłoszeń prawnych, a nie przerabia
        // przy okazji sprawy, które z DSA art. 16 nie mają nic wspólnego.
        $spoleczne = $this->zgloszenieSpolecznosciowe();
        $this->zgloszeniePrawne('Barbara Nowak', 'barbara@example.com');

        $przed = DB::table('reports')->where('id', $spoleczne->getKey())->first();
        $this->assertNotNull($przed, 'Zgłoszenia społecznościowego nie było już przed cofnięciem.');

        // Odmowę odkładamy do zmiennej i oceniamy POZA blokiem. Pusty `catch`
        // przy teście wąskości znaczyłby, że test przechodzi także wtedy, gdy
        // `down()` w ogóle nie odmówił — a wtedy nie mierzyłby wąskości
        // odmowy, tylko wąskość cofnięcia, czyli czegoś innego.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło, choć w tabeli leży zgłoszenie prawne.');

        $this->assertEquals(
            $przed,
            DB::table('reports')->where('id', $spoleczne->getKey())->first(),
            'Odmowa ruszyła zgłoszenie społecznościowe, którego ta migracja w ogóle nie dotyczy.',
        );
    }

    public function test_furtka_wypowiedziana_wprost_pozwala_cofnac(): void
    {
        $this->zgloszeniePrawne('Barbara Nowak', 'barbara@example.com');

        // Furtkę podaje się w środowisku procesu:
        // `KUKING_ROLLBACK_KASUJE_ZGLOSZENIA_PRAWNE=1 php artisan migrate:rollback`.
        // Furtka opisana w komunikacie, a niedziałająca, jest gorsza niż jej
        // brak: człowiek robi kopię, wpisuje podaną zmienną i dostaje ten sam
        // komunikat, czyli uczy się, że komunikaty tej migracji kłamią.
        putenv(self::ZGODA.'=1');

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasColumn('reports', 'source'),
            'Furtka nie zadziałała: kolumny zostały mimo jawnej zgody.',
        );

        // Cena, o której komunikat odmowy uprzedza: `down()` kasuje po drodze
        // wiersze bez celu, a zgłoszenie prawne z nierozpoznanym adresem
        // właśnie takie jest. To nie jest efekt uboczny do naprawienia — to
        // dokładnie ta strata, przed którą strażnik wyżej ostrzega.
        $this->assertSame(0, DB::table('reports')->count());
    }

    public function test_furtka_reaguje_dokladnie_na_jedynke_a_nie_na_dowolna_prawde(): void
    {
        // Ta migracja porównuje `getenv(...) !== '1'`, a NIE przepuszcza
        // wartości przez `filter_var` jak migracje dziennika zgód czy skali
        // tekstu. Różnica jest widoczna dopiero tutaj i jest warta testu:
        // komunikat podaje jedną konkretną wartość, więc każda inna ma
        // zostawić dane na miejscu, zamiast po cichu otworzyć drzwi.
        $zgloszenie = $this->zgloszeniePrawne('Barbara Nowak', 'barbara@example.com');

        putenv(self::ZGODA.'=true');

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło przy wartości `true`, choć komunikat mówi o wartości 1.');

        $this->assertStringContainsString(self::ZGODA, $odmowa->getMessage());

        $this->assertTrue(Schema::hasColumn('reports', 'notifier_name'));
        $this->assertSame(
            'Barbara Nowak',
            DB::table('reports')->where('id', $zgloszenie->getKey())->value('notifier_name'),
        );
    }
}

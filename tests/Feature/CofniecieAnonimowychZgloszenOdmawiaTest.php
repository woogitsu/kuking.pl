<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji dopuszczającej ANONIMOWE zgłoszenia prawne nie kasuje
 * dowodu i nie dopisuje nikomu wymyślonego nazwiska (D-088).
 *
 * CO ROBI PILNOWANA MIGRACJA
 * `2026_09_07_600000_allow_anonymous_legal_notices` ROZLUŹNIA CHECK
 * `reports_legal_notice_complete_check`: zgłoszenie nielegalnej treści nie
 * musi już mieć `notifier_name`. Anonimowość jest tu wprost przewidziana
 * przepisem — art. 16 ust. 2 lit. c DSA zwalnia z podania danych
 * zgłaszającego przy przestępstwach z art. 3-7 dyrektywy 2011/93/UE.
 *
 * DLACZEGO `down()` MUSI ODMAWIAĆ, A NIE „NAPRAWIAĆ"
 * Cofnięcie przywraca ostrzejszy warunek (`notifier_name IS NOT NULL`),
 * a PostgreSQL sprawdza przy zakładaniu CHECK-a istniejące wiersze. Żeby
 * `ALTER TABLE` przeszedł, trzeba by anonimowym zgłoszeniom albo DOPISAĆ
 * wymyślone nazwisko, albo je SKASOWAĆ. Pierwsze jest kłamstwem w kolumnie,
 * na którą ktoś powoła się w sprawie karnej; drugie niszczy dowód w sprawie
 * o najcięższej możliwej wadze. A `down()` prawie nigdy nie występuje sam:
 * po nim idzie kolejny `migrate`, CHECK wraca do luźnej postaci i NIE MA
 * BŁĘDU DO ZAUWAŻENIA — zostaje tylko wiersz z nazwiskiem, którego nikt nie
 * podał, albo brak wiersza, którego nikt nie policzy.
 *
 * Ta migracja NIE MA furtki przez zmienną środowiskową i to jest świadome:
 * zgody na skasowanie zgłoszenia o przestępstwie wobec dziecka nie wypowiada
 * się jednym `=1` w wierszu poleceń.
 *
 * Test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która nie
 * cofa się NIGDY, blokowałaby świeże wdrożenie bez powodu i byłaby błędem
 * tej samej wagi w drugą stronę (D-088: „odmowa musi być WĄSKA").
 */
class CofniecieAnonimowychZgloszenOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const PLIK = 'migrations/2026_09_07_600000_allow_anonymous_legal_notices.php';

    /**
     * Ile anonimowych zgłoszeń zakłada test odmowy.
     *
     * JEDEN, nie pięć — i ta zmiana jest treścią poprawki, nie kosmetyką.
     * Komunikat migracji wklejał liczbę w szablon „W bazie jest N anonimowych
     * zgłoszeń prawnych", po polsku poprawny dopiero od pięciu w górę. Test
     * zakładał więc pięć wierszy, żeby nie zamrażać w asercji błędnej
     * odmiany — a skutkiem było to, że przypadek najbardziej prawdopodobny
     * na produkcji, czyli JEDEN wiersz, nie był sprawdzany nigdy.
     *
     * Komunikat stawia teraz rzeczownik przed liczbą („Liczba anonimowych
     * zgłoszeń prawnych w bazie: 1."), więc jedynka jest zdaniem poprawnym
     * i to ona jest tu mierzona.
     */
    private const ANONIMOWYCH = 1;

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    /**
     * @param  array<string, mixed>  $atrybuty
     */
    private function zgloszeniePrawne(array $atrybuty = []): Report
    {
        return Report::create(array_merge([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => null,
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść przedstawia dziecko w sposób, który wypełnia znamiona przestępstwa.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'status' => Report::STATUS_OPEN,
        ], $atrybuty));
    }

    /** Zgłoszenie bez żadnych danych zgłaszającego — art. 16 ust. 2 lit. c DSA. */
    private function zgloszenieAnonimowe(): Report
    {
        return $this->zgloszeniePrawne([
            'notifier_name' => null,
            'notifier_email' => null,
        ]);
    }

    /**
     * Zgłoszenie SPOŁECZNOŚCIOWE — „to jest spam". Zupełnie inna droga,
     * której ta migracja w ogóle nie dotyczy.
     */
    private function zgloszenieSpolecznosciowe(): Report
    {
        // Nazwy użytkowników mają własny CHECK (`profiles_username_check`),
        // więc konta biorą się z fabryki, a nie z wymyślonych stringów.
        $zglaszajacy = User::factory()->create();
        $zgloszony = User::factory()->create();

        return Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'user',
            'target_id' => $zgloszony->getKey(),
            'reason' => 'spam',
            'details' => 'Ten profil wkleja reklamy pod każdym przepisem.',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /**
     * Czy baza przyjmuje DZIŚ zgłoszenie prawne bez `notifier_name`.
     *
     * To jest jedyny uczciwy sposób sprawdzenia CHECK-a: próba zapisu, która
     * ma się o niego odbić. Samo odpytanie `pg_constraint` o tekst warunku
     * mówiłoby, co w bazie NAPISANO, a nie co baza ROBI.
     *
     * ZAGNIEŻDŻONA TRANSAKCJA NIE JEST OZDOBNIKIEM. W PostgreSQL nieudane
     * zapytanie przerywa CAŁĄ transakcję — tę samą, w której `RefreshDatabase`
     * trzyma test. Bez SAVEPOINT-a każde następne zapytanie, także zwykły
     * SELECT w asercji, kończyłoby się `SQLSTATE[25P02] current transaction
     * is aborted`. `catch` uspokaja PHP, nie bazę.
     *
     * Wiersz próbny znika razem z `rollBack()`, więc metoda niczego po sobie
     * nie zostawia — także wtedy, gdy zapis się udał.
     */
    private function bazaPrzyjmujeAnonimoweZgloszenie(): bool
    {
        DB::beginTransaction();

        try {
            $this->zgloszenieAnonimowe();
        } catch (\Throwable) {
            DB::rollBack();

            return false;
        }

        DB::rollBack();

        return true;
    }

    public function test_cofniecie_odmawia_gdy_w_bazie_sa_anonimowe_zgloszenia(): void
    {
        $anonimowe = [];

        for ($i = 0; $i < self::ANONIMOWYCH; $i++) {
            $anonimowe[] = $this->zgloszenieAnonimowe()->getKey();
        }

        // `fail()` NIE STOI W `try` i to jest jedyny powód, dla którego ten
        // blok wygląda tak, a nie krócej. `AssertionFailedError` dziedziczy
        // `PHPUnit\Framework\Exception` → `RuntimeException` → `Exception`,
        // więc `fail()` postawione wewnątrz `try` wpadłoby do `catch` poniżej —
        // do tego samego, który ma złapać odmowę migracji. Przy takim kształcie
        // `catch` bez asercji na treść komunikatu robi z testu atrapę: zielony
        // także wtedy, gdy `down()` w ogóle nie odmawia. Wyjątek idzie więc do
        // zmiennej, a ocena stoi poza blokiem, gdzie nic jej nie łapie.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło, choć w bazie są anonimowe zgłoszenia prawne.');

        $komunikat = $odmowa->getMessage();

        // ILU wierszy to dotyczy. Bez liczby człowiek nie wie, czy ma
        // przed sobą jedną sprawę do rozstrzygnięcia, czy tysiąc.
        $this->assertStringContainsString(
            'Liczba anonimowych zgłoszeń prawnych w bazie: '.self::ANONIMOWYCH.'.',
            $komunikat,
            'Komunikat odmowy musi podać, ILU zgłoszeń dotyczy.',
        );

        // Stara, niegramatyczna fraza nie ma prawa wrócić.
        $this->assertStringNotContainsString('jest 1 anonimowych zgłoszeń', $komunikat);

        // CO ZROBIĆ ZAMIAST TEGO — i to wprost, jako polecenie dla
        // człowieka, a nie jako samo „nie da się".
        $this->assertStringContainsString(
            'Zdecyduj świadomie i zrób to ręcznie, zanim cofniesz tę migrację.',
            $komunikat,
            'Komunikat odmowy musi powiedzieć, co zrobić zamiast cofania.',
        );

        // DLACZEGO — komunikat nazywa OBA złe wyjścia, nie jedno.
        // Człowiek, który zna tylko jedno, wybierze drugie.
        $this->assertStringContainsString(
            'wpisania im wymyślonego nazwiska, albo ich skasowania',
            $komunikat,
            'Komunikat ma nazwać oba wyjścia, które kusiłyby zamiast odmowy.',
        );
        $this->assertStringContainsString(
            'Pierwsze jest kłamstwem w kolumnie, drugie niszczy dowód w sprawie',
            $komunikat,
            'Komunikat ma powiedzieć, co złego jest w KAŻDYM z tych dwóch wyjść.',
        );

        // Podstawa prawna anonimowości — żeby nie wyglądało to na brak
        // w naszych danych, tylko na sytuację przewidzianą przepisem.
        $this->assertStringContainsString(
            'art. 16 ust. 2 lit. c DSA',
            $komunikat,
            'Komunikat ma wskazać przepis, z którego ta anonimowość wynika.',
        );

        // NAJWAŻNIEJSZE: dane NADAL SĄ i nadal są anonimowe. Odmowa, która
        // zdążyła skasować wiersz albo dopisać mu wymyślone nazwisko, to
        // tylko ładniejszy komunikat o stracie.
        $this->assertSame(
            self::ANONIMOWYCH,
            DB::table('reports')->whereIn('id', $anonimowe)->count(),
            'Odmowa skasowała zgłoszenia, których miała bronić.',
        );
        $this->assertSame(
            self::ANONIMOWYCH,
            DB::table('reports')->whereIn('id', $anonimowe)->whereNull('notifier_name')->count(),
            'Odmowa dopisała zgłoszeniom nazwisko, którego nikt nie podał.',
        );

        // I warunek został taki, jaki był: luźny. Odmowa, która zdjęłaby
        // CHECK i nie zdążyła założyć nowego, byłaby cichym rozluźnieniem.
        $this->assertTrue(
            $this->bazaPrzyjmujeAnonimoweZgloszenie(),
            'Po odmowie baza ma nadal przyjmować zgłoszenia anonimowe — `down()` nie miał prawa niczego zmienić.',
        );
    }

    public function test_na_swiezym_wdrozeniu_cofniecie_dziala_i_naprawde_zaostrza_warunek(): void
    {
        // Nikt nie złożył anonimowego zgłoszenia, więc nie ma czego stracić
        // i nie ma o co pytać. To jest kontrola dodatnia dla strażnika wyżej:
        // bez niej test przechodziłby także dla migracji, która nie cofa się
        // NIGDY.
        $this->assertSame(
            0,
            DB::table('reports')->where('source', Report::SOURCE_LEGAL_NOTICE)->whereNull('notifier_name')->count(),
        );

        $this->assertTrue(
            $this->bazaPrzyjmujeAnonimoweZgloszenie(),
            'Przed cofnięciem baza MUSI przyjmować zgłoszenie bez nazwiska — inaczej `up()` nie zrobił tego, co obiecuje.',
        );

        $this->migracja()->down();

        // Dowód, że `down()` naprawdę coś zmienił, a nie tylko nie rzucił
        // wyjątku: ostrzejszy warunek wrócił i baza odbija to samo
        // zgłoszenie, które chwilę wcześniej przyjmowała.
        $this->assertFalse(
            $this->bazaPrzyjmujeAnonimoweZgloszenie(),
            'Po cofnięciu baza ma znów odbijać zgłoszenie bez nazwiska — inaczej `down()` nic nie cofnął.',
        );

        $this->migracja()->up();

        $this->assertTrue(
            $this->bazaPrzyjmujeAnonimoweZgloszenie(),
            'Ponowny `up()` ma wrócić do stanu, w którym anonimowe zgłoszenie przechodzi.',
        );
    }

    public function test_odmowa_nie_rusza_zgloszen_spoza_swojego_zakresu(): void
    {
        // Kontrola WĄSKOŚCI (D-088): strażnik broni zgłoszeń anonimowych,
        // a nie przestawia przy okazji wszystkiego, co stoi w tabeli.
        $anonimowe = [];

        for ($i = 0; $i < self::ANONIMOWYCH; $i++) {
            $anonimowe[] = $this->zgloszenieAnonimowe()->getKey();
        }

        $zNazwiskiem = $this->zgloszeniePrawne(['notifier_name' => 'Maria Podpisana'])->getKey();
        $spolecznosciowe = $this->zgloszenieSpolecznosciowe()->getKey();

        $przedZNazwiskiem = DB::table('reports')->where('id', $zNazwiskiem)->first();
        $przedSpolecznosciowe = DB::table('reports')->where('id', $spolecznosciowe)->first();

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło, choć w bazie są anonimowe zgłoszenia prawne.');

        // Odmowa jest tu oczekiwana; sprawdzamy jej SKUTKI UBOCZNE.
        // Przy okazji: licznik w komunikacie liczy TYLKO anonimowe.
        // Zgłoszenie z nazwiskiem i społecznościowe nie mają prawa się
        // do niego wliczyć, bo ostrzejszy warunek przepuszcza je oba.
        $this->assertStringContainsString(
            'Liczba anonimowych zgłoszeń prawnych w bazie: '.self::ANONIMOWYCH.'.',
            $odmowa->getMessage(),
            'Licznik w komunikacie policzył wiersze spoza zakresu odmowy.',
        );

        $this->assertEquals(
            $przedZNazwiskiem,
            DB::table('reports')->where('id', $zNazwiskiem)->first(),
            'Odmowa ruszyła podpisane zgłoszenie prawne, którego ta migracja w ogóle nie dotyczy.',
        );
        $this->assertEquals(
            $przedSpolecznosciowe,
            DB::table('reports')->where('id', $spolecznosciowe)->first(),
            'Odmowa ruszyła zgłoszenie społecznościowe, czyli zupełnie inną drogę.',
        );
    }
}

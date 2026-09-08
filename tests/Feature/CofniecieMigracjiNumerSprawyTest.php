<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `migrate:rollback` nie kasuje po cichu numerów spraw.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Migracja `2026_09_07_910000_add_numer_sprawy_to_reports` dokłada kolumnę
 * `numer_sprawy` i ma w `down()` strażnika: odmawia cofnięcia, gdy w tabeli
 * leżą zgłoszenia prawne. Strażnik był OPISANY w komentarzu migracji i w
 * `docs/DATABASE.md`, ale nie był NICZYM sprawdzony — a plan wycofania ma być
 * wykonany, nie opisany. Ten test jest tym wykonaniem.
 *
 * DLACZEGO AKURAT TU BOLI
 * Numer sprawy jest losowy, więc po skasowaniu kolumny nie da się go
 * odtworzyć. Dla zgłaszającego BEZ KONTA jest jedynym sposobem rozpoznania
 * własnej sprawy: konta nie ma, listy zgłoszeń nie ma, poczty serwis dziś nie
 * wysyła. Cofnięcie migracji odbiera mu więc jedyny ślad po sprawie, która ma
 * własny termin odpowiedzi z DSA art. 16.
 *
 * Test sprawdza OBIE strony i furtkę. Sama odmowa nie wystarczy: migracja,
 * która nigdy się nie cofa, blokowałaby staging i lokalne bazy, gdzie nie ma
 * czego stracić.
 */
class CofniecieMigracjiNumerSprawyTest extends TestCase
{
    use RefreshDatabase;

    private const FURTKA = 'KUKING_ROLLBACK_KASUJE_NUMERY_SPRAW';

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_07_910000_add_numer_sprawy_to_reports.php',
        );
    }

    private function zgloszeniePrawne(): Report
    {
        return Report::create([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);
    }

    private function kolumnaIstnieje(): bool
    {
        return DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['reports', 'numer_sprawy'],
        ) !== [];
    }

    public function test_cofniecie_odmawia_gdy_sa_zgloszenia_prawne(): void
    {
        $zgloszenie = $this->zgloszeniePrawne();
        $numer = $zgloszenie->numer_sprawy;

        $this->assertNotNull($numer, 'Zgłoszenie nie dostało numeru — test sprawdzałby pustkę.');

        try {
            $this->migracja()->down();

            $this->fail('Cofnięcie przeszło i skasowało numery spraw.');
        } catch (RuntimeException $e) {
            // Komunikat ma mówić ILE się straci i CO ZROBIĆ. „Ktoś coś straci"
            // nie zatrzymuje nikogo o drugiej w nocy.
            $this->assertStringContainsString('1 zgłoszeń prawnych', $e->getMessage());
            $this->assertStringContainsString('kopię tabeli', $e->getMessage());
            $this->assertStringContainsString(self::FURTKA, $e->getMessage());
        }

        // ASERCJA KONTROLNA: odmowa, która i tak zdążyła skasować kolumnę,
        // byłaby tylko ładniejszym komunikatem o stracie. Numer ma być
        // dokładnie ten sam, nie „jakiś".
        $this->assertTrue($this->kolumnaIstnieje(), 'Kolumna `numer_sprawy` zniknęła mimo odmowy.');
        $this->assertSame(
            $numer,
            DB::table('reports')->where('id', $zgloszenie->getKey())->value('numer_sprawy'),
            'Numer sprawy zmienił się mimo odmowy cofnięcia.',
        );
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma zgłoszeń prawnych, więc nie ma o co pytać.
        $this->assertSame(
            0,
            DB::table('reports')->where('source', Report::SOURCE_LEGAL_NOTICE)->count(),
            'Test startuje z niepustą tabelą — mierzyłby co innego, niż zakłada.',
        );

        $this->assertTrue($this->kolumnaIstnieje(), 'Kolumny nie było już przed cofnięciem.');

        $this->migracja()->down();

        $this->assertFalse($this->kolumnaIstnieje(), 'Cofnięcie nie zdjęło kolumny `numer_sprawy`.');
    }

    public function test_furtka_ze_srodowiska_procesu_przepuszcza_cofniecie(): void
    {
        $this->zgloszeniePrawne();

        // Furtkę podaje się w środowisku procesu:
        // `KUKING_ROLLBACK_KASUJE_NUMERY_SPRAW=1 php artisan migrate:rollback`.
        // Strażnik czyta ją przez `getenv()`, tak samo jak trzy pozostałe
        // migracje z furtką — i to jest sedno tego testu: gdyby ktoś wrócił do
        // odczytu przez warstwę konfiguracji, ta droga przestałaby działać
        // dokładnie tam, gdzie jest potrzebna, czyli na produkcji.
        $poprzednia = getenv(self::FURTKA);
        putenv(self::FURTKA.'=1');

        try {
            $this->migracja()->down();

            $this->assertFalse(
                $this->kolumnaIstnieje(),
                'Furtka nie zadziałała: kolumna została mimo jawnej zgody.',
            );
        } finally {
            if ($poprzednia === false) {
                putenv(self::FURTKA);
            } else {
                putenv(self::FURTKA.'='.$poprzednia);
            }
        }

        // ASERCJA KONTROLNA: furtka ma przepuszczać TYLKO wtedy, gdy jest
        // ustawiona. Bez sprzątnięcia po sobie kolejny test w tym samym
        // procesie dostałby cofnięcie bez pytania i nie zauważyłby tego.
        $this->assertNotSame(
            '1',
            getenv(self::FURTKA),
            'Zmienna furtki wyciekła poza ten test.',
        );
    }
}

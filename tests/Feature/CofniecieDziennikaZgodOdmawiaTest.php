<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji dziennika zgód nie kasuje po cichu dowodu z art. 7 RODO
 * (D-088, issue #341).
 *
 * DLACZEGO AKURAT TA MIGRACJA JEST GROŹNA
 * `down()` nie psuje niczego, co widać. Wysyłka digestu nie czyta tej tabeli
 * ani razu, więc po skasowaniu serwis chodzi dalej, a po `down()` prawie
 * zawsze idzie kolejny `migrate` — tabela wraca PUSTA i NIE MA BŁĘDU DO
 * ZAUWAŻENIA. Znika za to jedyna odpowiedź na pytanie, które przy sporze
 * zada UODO: kiedy ta osoba kliknęła zgodę i czy jej wcześniej nie cofnęła.
 * Boolean `users.wants_weekly_digest` jest ostatnią klatką filmu, którego
 * nikt nie nagrywał.
 *
 * `docs/DATABASE.md` ostrzegał przed tym od 10 września, a kod robił
 * `dropIfExists` bez słowa. Ten test pilnuje, żeby ostrzeżenie i kod mówiły
 * to samo — bo ostrzeżenie w dokumencie nie jest zabezpieczeniem.
 *
 * Test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która nie
 * cofa się nigdy, blokowałaby świeże wdrożenie bez powodu i byłaby błędem
 * tej samej wagi w drugą stronę.
 */
class CofniecieDziennikaZgodOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_KASUJ_DZIENNIK_ZGOD';

    private const PLIK = 'migrations/2026_09_10_400000_create_dziennik_zgod_table.php';

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

    private function zapiszZgode(): User
    {
        $basia = User::factory()->create();

        WpisZgody::create([
            'user_id' => $basia->getKey(),
            'cel' => WpisZgody::CEL_TYGODNIOWY_DIGEST,
            'czynnosc' => WpisZgody::UDZIELONA,
            'zrodlo' => WpisZgody::ZRODLO_USTAWIENIA,
            'wersja_polityki' => '2026-09-10',
        ]);

        return $basia;
    }

    public function test_cofniecie_odmawia_gdy_dziennik_ma_zapisy(): void
    {
        $this->zapiszZgode();

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

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało dowód zgody.');

        // Komunikat ma powiedzieć ILU zapisów to dotyczy, CO ZROBIĆ
        // ZAMIAST TEGO i jak powiedzieć wprost „wiem, co robię".
        // Komunikat bez tych trzech rzeczy zostawia człowieka z samym
        // „nie da się".
        $this->assertStringContainsString('które znikną: 1.', $odmowa->getMessage());
        $this->assertStringContainsString('copy dziennik_zgod', $odmowa->getMessage());
        $this->assertStringContainsString(self::ZGODA, $odmowa->getMessage());

        // NAJWAŻNIEJSZE: dowód nadal jest. Odmowa, która zdążyła skasować
        // tabelę, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertTrue(Schema::hasTable('dziennik_zgod'));
        $this->assertSame(1, (int) DB::table('dziennik_zgod')->count());
    }

    public function test_na_swiezym_wdrozeniu_cofniecie_dziala_bez_pytania(): void
    {
        // Pusty dziennik to świeże wdrożenie: nie ma czego stracić i nie ma
        // o co pytać. To jest kontrola dodatnia dla strażnika wyżej — bez
        // niej test przechodziłby także dla migracji, która nie cofa się
        // NIGDY.
        $this->assertSame(0, (int) DB::table('dziennik_zgod')->count());

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasTable('dziennik_zgod'),
            'Na pustym dzienniku `down()` ma po prostu zadziałać.',
        );
    }

    public function test_zgoda_wypowiedziana_wprost_pozwala_skasowac(): void
    {
        $this->zapiszZgode();

        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('dziennik_zgod'));
    }

    public function test_odmowa_nie_rusza_niczego_poza_dziennikiem(): void
    {
        // Kontrola wąskości: strażnik ma bronić dziennika, a nie przy okazji
        // przestawiać konto, którego dotyczy zgoda.
        $basia = $this->zapiszZgode();
        $przed = DB::table('users')->where('id', $basia->getKey())->first();

        try {
            $this->migracja()->down();
        } catch (RuntimeException) {
            // Odmowa jest tu oczekiwana; sprawdzamy jej SKUTKI UBOCZNE.
        }

        $this->assertEquals($przed, DB::table('users')->where('id', $basia->getKey())->first());
    }
}

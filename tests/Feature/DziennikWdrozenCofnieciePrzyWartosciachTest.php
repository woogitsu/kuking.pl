<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * `down()` dziennika wdrożeń ODMAWIA, gdy tabele `wdrozenia`/`wdrozenia_funkcje`
 * mają wiersze (issue #1932, D-318, D-088).
 *
 * DLACZEGO TO JEST WARTOŚĆ SEMANTYCZNA, NIE ZWYKŁE DANE
 * Numer wdrożenia jest już POKAZANY ludziom — w stopce i pod „od Alfa
 * 0.68.NNN" na stronie „Co nowego". Cofnięcie migracji na wypełnionej bazie
 * i kolejny `migrate` (dokładnie to, co robi `migrate:refresh` w CI i
 * awaryjny rollback wdrożenia) zacząłby liczyć numery od 1 dla KAŻDEJ
 * etykiety — nowe wdrożenie dostałoby numer, który już wcześniej znaczył
 * coś innego. Ten sam wzorzec kontroli co
 * `CofniecieMigracjiNieKasujeZeszytowTest`: sprawdzamy OBIE strony, bo
 * migracja, która nigdy się nie cofa, jest błędem tej samej wagi w drugą
 * stronę.
 */
class DziennikWdrozenCofnieciePrzyWartosciachTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_26_130000_utworz_dziennik_wdrozen.php');
    }

    public function test_cofniecie_odmawia_gdy_dziennik_ma_wiersze(): void
    {
        DB::table('wdrozenia')->insert([
            'commit' => str_repeat('a', 40),
            'etykieta' => 'Alfa 0.68',
            'numer' => 1,
            'created_at' => now(),
        ]);

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło, mimo że w dzienniku jest wiersz.');
        $this->assertStringContainsString('wdrozenia` ma 1 wiersz', $odmowa->getMessage());
        $this->assertStringContainsString('DROP TABLE', $odmowa->getMessage());

        // NAJWAŻNIEJSZE: tabela i wiersz nadal istnieją. Odmowa, która i tak
        // zdążyła skasować dane, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertSame(1, DB::table('wdrozenia')->count());
    }

    public function test_cofniecie_odmawia_gdy_tylko_tabela_funkcji_ma_wiersze(): void
    {
        // Stan, w którym `wdrozenia` jest (chwilowo) pusta, a `wdrozenia_funkcje`
        // nie — nie powinien się zdarzyć w normalnej pracy komendy, ale
        // strażnik sprawdza OBIE tabele niezależnie, nie tylko pierwszą.
        DB::table('wdrozenia_funkcje')->insert([
            'etykieta' => 'Alfa 0.68',
            'naglowek_slug' => 'przyklad',
            'naglowek_tekst' => 'Przykład',
            'numer' => 1,
            'created_at' => now(),
        ]);

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło, mimo że wdrozenia_funkcje ma wiersz.');
        $this->assertStringContainsString('wdrozenia_funkcje` ma 1 wiersz', $odmowa->getMessage());
    }

    public function test_na_swiezej_bazie_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma czego stracić, więc nie ma o co pytać. Migracja, która ZAWSZE
        // odmawia, blokowałaby staging i lokalne bazy bez powodu.
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('wdrozenia'));
        $this->assertFalse(Schema::hasTable('wdrozenia_funkcje'));

        // Migrujemy z powrotem, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie.
        $this->migracja()->up();
    }

    public function test_migracja_nie_kasuje_tabel_zanim_sprawdzi_czy_wolno(): void
    {
        $kod = (string) file_get_contents(database_path('migrations/2026_09_26_130000_utworz_dziennik_wdrozen.php'));

        $sprawdzenie = strpos($kod, '$wierszyWdrozen > 0 || $wierszyFunkcji > 0');
        $kasowanie = strpos($kod, "Schema::dropIfExists('wdrozenia_funkcje')");

        $this->assertNotFalse($sprawdzenie);
        $this->assertNotFalse($kasowanie);
        $this->assertLessThan($kasowanie, $sprawdzenie, 'Sprawdzenie stoi PO kasowaniu tabel.');
    }
}

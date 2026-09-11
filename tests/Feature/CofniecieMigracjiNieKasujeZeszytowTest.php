<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * `migrate:rollback` nie kasuje po cichu zapisanych wpisów (audyt F-02).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Migracja `2026_09_06_150000_collection_items_accept_posts` zmienia klucz
 * główny `collection_items`. Stary klucz `(collection_id, recipe_id)` nie
 * dopuszcza NULL, więc przy cofaniu wiersze z `post_id` nie mają jak przetrwać.
 * Pierwsza wersja po prostu je usuwała — `DELETE FROM ... WHERE post_id IS NOT
 * NULL` — z komentarzem, że to „utrata danych, świadoma i jedyna możliwa".
 *
 * Świadoma nie znaczy dopuszczalna. `php artisan migrate:rollback` wpisuje się
 * odruchowo, zwykle w pośpiechu i zwykle wtedy, gdy coś już poszło nie tak.
 * Jedyne ostrzeżenie stało w komentarzu w pliku, którego w takiej chwili nikt
 * nie otwiera. Zeszyt człowiek buduje miesiącami — skasowanie go przy cofaniu
 * SCHEMATU jest utratą danych bez związku z tym, co się właśnie psuło.
 *
 * Ten test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która
 * nigdy się nie cofa, jest równie zła, tylko w drugą stronę — blokowałaby
 * cofnięcie na świeżym środowisku, gdzie nie ma czego stracić.
 */
class CofniecieMigracjiNieKasujeZeszytowTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_06_150000_collection_items_accept_posts.php',
        );
    }

    private function zapiszWpisDoZeszytu(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');

        $zeszyt = Collection::create([
            'owner_id' => $basia->getKey(),
            'name' => 'Na kiedyś',
            'visibility' => 'private',
        ]);

        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'post_id' => $wpis->getKey(),
            'created_at' => now(),
        ]);
    }

    public function test_cofniecie_odmawia_gdy_w_zeszytach_leza_zapisane_wpisy(): void
    {
        $this->zapiszWpisDoZeszytu();

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM (D-133):
        // `$this->fail()` rzuca `AssertionFailedError`, a ta dziedziczy przez
        // `PHPUnit\Framework\Exception` po `RuntimeException`, więc
        // postawiona wewnątrz `try` wpadłaby do własnego `catch`.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało zapisane wpisy.');

        // Komunikat ma mówić, ILE się straci i CO ZROBIĆ. „Ktoś coś straci"
        // nie skłania nikogo do zatrzymania się o drugiej w nocy.
        //
        // JEDEN wpis, nie pięć: liczba stoi na końcu zdania, za rzeczownikiem
        // w mianowniku, więc jedynka jest tu poprawna po polsku (D-132).
        $this->assertStringContainsString('Liczba wpisów, które znikną: 1.', $odmowa->getMessage());

        // Stara, niegramatyczna forma nie ma prawa wrócić.
        $this->assertStringNotContainsString('skasuje 1 zapisanych wpisów', $odmowa->getMessage());

        $this->assertStringContainsString('collection_items_kopia', $odmowa->getMessage());
        $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_ZAPISANE_WPISY', $odmowa->getMessage());

        // NAJWAŻNIEJSZE: wiersz nadal jest. Odmowa, która i tak zdążyła
        // skasować dane, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertSame(1, DB::table('collection_items')->whereNotNull('post_id')->count());
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma czego stracić, więc nie ma o co pytać. Migracja, która odmawia
        // ZAWSZE, blokowałaby staging i lokalne bazy bez żadnego powodu.
        $this->migracja()->down();

        $kolumny = DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['collection_items', 'post_id'],
        );

        $this->assertSame([], $kolumny, 'Cofnięcie nie usunęło kolumny post_id.');

        // Migrujemy z powrotem, żeby nie zostawić bazy w połowie drogi
        // dla kolejnych testów w tym samym procesie.
        $this->migracja()->up();
    }

    public function test_migracja_nie_kasuje_wierszy_zanim_sprawdzi_czy_wolno(): void
    {
        // Kolejność w `down()` ma znaczenie: gdyby `DELETE` stał przed
        // sprawdzeniem, wyjątek leciałby już po utracie danych. Czytamy kod,
        // bo w działaniu tej różnicy nie widać — w obu wersjach leci wyjątek.
        $kod = (string) file_get_contents(database_path(
            'migrations/2026_09_06_150000_collection_items_accept_posts.php',
        ));

        $sprawdzenie = strpos($kod, '$this->upewnijSieZeWolnoKasowacZapisaneWpisy();');
        $kasowanie = strpos($kod, "DB::statement('DELETE FROM collection_items");

        $this->assertNotFalse($sprawdzenie);
        $this->assertNotFalse($kasowanie);

        $this->assertLessThan(
            $kasowanie,
            $sprawdzenie,
            'Sprawdzenie stoi PO kasowaniu — wyjątek poleciałby już po utracie danych.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Druga zgoda w `dziennik_zgod` (D-296): CHECK przyjmuje `odczyt_ai`
 * i `ekran_importu`, a `down()` odmawia, gdy są takie dowody (D-088).
 *
 * @bez-kontroli-dodatniej Plik nie asertuje na treści źródła: wczytuje migrację przez require i mierzy jej zachowanie, a kontrola dodatnia stoi w tym samym pliku.
 */
final class CofniecieZgodyOdczytuAiOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path('migrations/2026_09_26_100200_dziennik_zgod_cel_odczyt_ai.php');
    }

    public function test_cofniecie_odmawia_gdy_sa_zgody_na_odczyt(): void
    {
        app(PrzestawZgodeNaOdczytAi::class)->handle(User::factory()->create(), true, WpisZgody::ZRODLO_EKRAN_IMPORTU);

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie zwęziło CHECK mimo zapisanych dowodów zgody.');
        $this->assertStringContainsString('1 zapisów', $odmowa->getMessage());
        $this->assertStringContainsString('OPENAI_IMPORT_KEY', $odmowa->getMessage());
    }

    public function test_kontrola_dodatnia_bez_takich_wierszy_cofa_sie_i_wraca(): void
    {
        WpisZgody::create([
            'user_id' => User::factory()->create()->getKey(),
            'cel' => WpisZgody::CEL_TYGODNIOWY_DIGEST,
            'czynnosc' => WpisZgody::UDZIELONA,
            'zrodlo' => WpisZgody::ZRODLO_USTAWIENIA,
            'wersja_polityki' => '2026-09-10',
        ]);

        $this->migracja()->down();

        // Po cofnięciu stary, wąski CHECK znowu odrzuca nowy cel.
        $odrzucone = false;
        try {
            DB::transaction(fn () => DB::table('dziennik_zgod')->insert([
                'user_id' => User::factory()->create()->getKey(), 'cel' => 'odczyt_ai', 'czynnosc' => 'udzielona',
                'zrodlo' => 'ustawienia', 'wersja_polityki' => '2026-09-10',
            ]));
        } catch (QueryException $e) {
            $odrzucone = $e->getCode() === '23514';
        }
        $this->assertTrue($odrzucone);

        $this->migracja()->up();
    }

    public function test_baza_nadal_odrzuca_cel_spoza_listy(): void
    {
        $this->expectException(QueryException::class);

        DB::table('dziennik_zgod')->insert([
            'user_id' => User::factory()->create()->getKey(), 'cel' => 'marketing', 'czynnosc' => 'udzielona',
            'zrodlo' => 'ekran_importu', 'wersja_polityki' => '2026-09-10',
        ]);
    }
}

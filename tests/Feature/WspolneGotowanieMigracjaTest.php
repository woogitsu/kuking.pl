<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookingSession;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Schemat wspólnego gotowania (#2385): CHECK-i i indeksy w bazie oraz wąska
 * odmowa rollbacku (AGENTS.md §6, D-088). Odmowa przy trwających sesjach,
 * przejście na pustej bazie i przy samych wygasłych sesjach.
 *
 * @bez-kontroli-dodatniej Test wykonuje down() migracji i zapisy łamiące CHECK-i na prawdziwej bazie; obie strony pomiaru (odmowa i przejście) są w nim samym.
 */
class WspolneGotowanieMigracjaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_10_01_170420_create_cooking_sessions_tables.php';

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE');
        parent::tearDown();
    }

    public function test_tabele_i_klucze_obce_istnieja(): void
    {
        foreach (['cooking_sessions', 'cooking_session_participants', 'cooking_session_steps', 'cooking_session_invitations'] as $tabela) {
            $this->assertTrue(Schema::hasTable($tabela), $tabela);
        }

        $this->assertSame(['cooking_session_participants_role_check'], $this->checki('cooking_session_participants'));
    }

    public function test_baza_odrzuca_nieznany_status_sesji(): void
    {
        $sesja = $this->sesja();

        $this->expectException(QueryException::class);
        DB::table('cooking_sessions')->where('id', $sesja->getKey())->update(['status' => 'ended']);
    }

    public function test_baza_odrzuca_oczekujace_zaproszenie_bez_skrotu_i_odwolane_ze_skrotem(): void
    {
        $sesja = $this->sesja();

        try {
            DB::transaction(fn () => $this->zaproszenie($sesja, ['status' => 'pending', 'token_hash' => null]));
            $this->fail('Oczekujące zaproszenie bez skrótu tokenu przeszło.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::transaction(fn () => $this->zaproszenie($sesja, ['status' => 'revoked', 'token_hash' => str_repeat('a', 64)]));
            $this->fail('Odwołane zaproszenie ze skrótem tokenu przeszło.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_dwa_oczekujace_linki_w_jednej_sesji_sa_niemozliwe(): void
    {
        $sesja = $this->sesja();
        $this->zaproszenie($sesja, ['token_hash' => str_repeat('a', 64)]);

        $this->expectException(QueryException::class);
        $this->zaproszenie($sesja, ['token_hash' => str_repeat('b', 64)]);
    }

    public function test_ta_sama_para_gospodarz_przepis_ma_jedna_sesje(): void
    {
        $sesja = $this->sesja();

        $this->expectException(QueryException::class);
        DB::table('cooking_sessions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'recipe_id' => $sesja->recipe_id,
            'host_id' => $sesja->host_id,
            'status' => 'active',
            'revision' => 1,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cofniecie_odmawia_gdy_jest_trwajaca_sesja(): void
    {
        $this->sesja();

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby trwającą sesję.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba niewygasłych sesji, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE=1', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('cooking_sessions'));
        $this->assertSame(1, CookingSession::query()->count());
    }

    public function test_cofniecie_przechodzi_na_pustej_bazie(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_sessions'));
        $this->assertFalse(Schema::hasTable('cooking_session_invitations'));
    }

    public function test_cofniecie_przechodzi_gdy_wszystkie_sesje_wygasly(): void
    {
        $sesja = $this->sesja();
        DB::table('cooking_sessions')->where('id', $sesja->getKey())->update(['expires_at' => now()->subMinute(), 'created_at' => now()->subDay()]);

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_sessions'));
    }

    public function test_cofniecie_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->sesja();
        putenv('KUKING_ROLLBACK_KASUJE_WSPOLNE_GOTOWANIE=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('cooking_sessions'));
    }

    private function sesja(): CookingSession
    {
        $host = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $host->getKey()]);
        $id = (string) \Illuminate\Support\Str::uuid();

        DB::table('cooking_sessions')->insert([
            'id' => $id,
            'recipe_id' => $recipe->getKey(),
            'host_id' => $host->getKey(),
            'status' => 'active',
            'revision' => 1,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return CookingSession::query()->findOrFail($id);
    }

    /** @param  array<string, mixed>  $pola */
    private function zaproszenie(CookingSession $sesja, array $pola): void
    {
        DB::table('cooking_session_invitations')->insert($pola + [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'session_id' => $sesja->getKey(),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function checki(string $tabela): array
    {
        return DB::table('pg_constraint')
            ->where('conrelid', DB::raw("'{$tabela}'::regclass"))
            ->where('contype', 'c')
            ->orderBy('conname')
            ->pluck('conname')
            ->all();
    }

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA);
    }
}

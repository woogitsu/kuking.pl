<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tabela `personal_access_tokens` (migracja
 * `2026_09_25_100000_create_personal_access_tokens_table`, D-270).
 *
 * Każde ograniczenie ma tu kontrolę dodatnią (poprawny wiersz przechodzi)
 * i ujemną (zły wiersz odbija się od BAZY, nie od PHP).
 *
 * @bez-kontroli-dodatniej Plik migracji jest tu WYKONYWANY (`down()`/`up()` na bazie), nie czytany — każda asercja mierzy stan bazy, nie tekst źródła.
 */
class TabelaTokenowDostepuTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRACJA = 'migrations/2026_09_25_100000_create_personal_access_tokens_table.php';

    public function test_w_bazie_lezy_skrot_a_jawny_token_nie_trafia_nigdzie(): void
    {
        $osoba = $this->user();
        $nowy = $osoba->createToken('Telefon Ani');

        [$id, $sekret] = explode('|', $nowy->plainTextToken, 2);

        $this->assertTrue(Str::isUuid($id), 'Identyfikator tokenu ma być UUID-em, nie kolejnym numerem.');
        $this->assertStringStartsWith('kuking_', $sekret, 'Brak przedrostka — skanery sekretów nie rozpoznają tokenu.');

        $wiersz = DB::table('personal_access_tokens')->where('id', $id)->first();

        $this->assertNotNull($wiersz);
        $this->assertSame(hash('sha256', $sekret), $wiersz->token);
        $this->assertSame(User::class, $wiersz->tokenable_type);
        $this->assertSame($osoba->getKey(), $wiersz->tokenable_id);
        $this->assertSame('Telefon Ani', $wiersz->name);

        foreach ((array) $wiersz as $kolumna => $wartosc) {
            $this->assertStringNotContainsString($sekret, (string) $wartosc, "Jawny sekret tokenu leży w kolumnie {$kolumna}.");
        }
    }

    public function test_skrotu_nie_da_sie_przypisac_masowo(): void
    {
        $this->assertNotContains('token', (new PersonalAccessToken)->getFillable());
        $this->assertNotContains('abilities', (new PersonalAccessToken)->getFillable());
    }

    public function test_baza_odrzuca_jawny_token_zamiast_skrotu(): void
    {
        $osoba = $this->user();

        $this->wstaw($osoba, ['token' => str_repeat('a', 64)]); // kontrola dodatnia

        $this->oczekujOdmowy(fn () => $this->wstaw($osoba, ['token' => 'kuking_'.Str::random(57)]), 'personal_access_tokens_token_format_check');
    }

    public function test_baza_odrzuca_pusta_nazwe_urzadzenia(): void
    {
        $osoba = $this->user();

        $this->oczekujOdmowy(fn () => $this->wstaw($osoba, ['name' => '   ']), 'personal_access_tokens_name_not_blank_check');
    }

    public function test_baza_odrzuca_wlasciciela_innego_niz_konto(): void
    {
        $osoba = $this->user();

        $this->oczekujOdmowy(fn () => $this->wstaw($osoba, ['tokenable_type' => 'App\\Models\\Post']), 'personal_access_tokens_tokenable_type_check');
    }

    public function test_baza_odrzuca_token_nieistniejacego_konta(): void
    {
        $this->oczekujOdmowy(fn () => DB::table('personal_access_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'tokenable_type' => User::class,
            'tokenable_id' => (string) Str::uuid7(),
            'name' => 'Telefon',
            'token' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'personal_access_tokens_tokenable_id_foreign');
    }

    public function test_skasowanie_konta_zabiera_jego_tokeny_kaskada(): void
    {
        $osoba = $this->user();
        $obca = $this->user();
        $osoba->createToken('Telefon');
        $obca->createToken('Tablet');

        DB::table('profiles')->where('user_id', $osoba->getKey())->delete();
        DB::table('users')->where('id', $osoba->getKey())->delete();

        $this->assertSame(0, PersonalAccessToken::query()->where('tokenable_id', $osoba->getKey())->count());
        $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $obca->getKey())->count(),
            'Kaskada zabrała token cudzego konta.');
    }

    public function test_invalidate_sessions_odwoluje_wszystkie_tokeny_tego_konta_i_tylko_jego(): void
    {
        $osoba = $this->user();
        $obca = $this->user();
        $osoba->createToken('Telefon');
        $osoba->createToken('Tablet');
        $obca->createToken('Telefon');

        $osoba->invalidateSessions();

        $this->assertSame(0, $osoba->tokens()->count());
        $this->assertSame(1, $obca->tokens()->count());
    }

    /**
     * Rollback bez odmowy (D-088 dotyczy wartości semantycznych, a token jest
     * poświadczeniem): po `down()` + `up()` tabela wraca PUSTA — każde
     * urządzenie loguje się jeszcze raz. Kierunek bezpieczny: odwołanie
     * dostępu, nie jego przywrócenie.
     */
    public function test_wycofanie_i_ponowna_migracja_zostawiaja_pusta_tabele_i_nie_ruszaja_kont(): void
    {
        $osoba = $this->user();
        $osoba->createToken('Telefon');
        $haslo = DB::table('users')->where('id', $osoba->getKey())->value('password');

        $migracja = require database_path(self::MIGRACJA);

        $migracja->down();
        $this->assertFalse(Schema::hasTable('personal_access_tokens'));
        $this->assertSame($haslo, DB::table('users')->where('id', $osoba->getKey())->value('password'));

        $migracja->up();
        $this->assertTrue(Schema::hasTable('personal_access_tokens'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // I działa dalej — ponowna migracja zakłada ograniczenia od nowa.
        $osoba->createToken('Telefon po powrocie');
        $this->assertSame(1, $osoba->tokens()->count());
        $this->oczekujOdmowy(fn () => $this->wstaw($osoba, ['token' => 'jawny']), 'personal_access_tokens_token_format_check');
    }

    /**
     * @param  array<string, mixed>  $zmiany
     */
    private function wstaw(User $osoba, array $zmiany): void
    {
        DB::table('personal_access_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'tokenable_type' => User::class,
            'tokenable_id' => $osoba->getKey(),
            'name' => 'Telefon',
            'token' => hash('sha256', Str::random(40)),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
            ...$zmiany,
        ]);
    }

    private function oczekujOdmowy(callable $zapis, string $ograniczenie): void
    {
        try {
            DB::transaction(fn () => $zapis());
        } catch (QueryException $e) {
            $this->assertStringContainsString($ograniczenie, $e->getMessage());

            return;
        }

        $this->fail("Baza przyjęła wiersz, który miało odrzucić ograniczenie {$ograniczenie}.");
    }
}

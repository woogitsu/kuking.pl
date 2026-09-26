<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Api\ZakresyTokenu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Zamknięty zakres (abilities) tokenu API `/api/v1` (D-271, #1928).
 *
 * Przed tą zmianą `User::createToken()` wydawał domyślnie `['*']` —
 * wildcard Sanctum obejmujący KAŻDĄ trasę, także tę, która dopiero
 * powstanie. Testy niżej mierzą, że:
 *
 *  - domyślnie wydany token NIGDY nie niesie `*`, tylko jawną listę
 *    z `ZakresyTokenu`;
 *  - trasa wymagająca konkretnego zakresu (`ability:...`) odbija token,
 *    który go nie ma, i wpuszcza ten, który go ma (kontrola dodatnia
 *    i ujemna na TEJ SAMEJ trasie);
 *  - słownik jest naprawdę ZAMKNIĘTY: nieznany zakres i wildcard `*`
 *    nie dają się wydać nawet podane jawnie.
 *
 * Trasy sprawdzające rejestruje ten test sam, tak jak `FundamentApiTest`
 * (etap 1 nie ma jeszcze tras produkcyjnych chronionych zakresem).
 */
class ZakresyTokenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.api.wlaczone' => true]);
        config(['kuking.api.limity.na_adres' => '1000,1', 'kuking.api.limity.na_token' => '1000,1']);

        Route::middleware('api')->prefix('api/v1/_proba_zakresy')->group(function (): void {
            Route::middleware(['auth:sanctum', 'ability:'.ZakresyTokenu::TRESC_PISZ])
                ->post('/wpis', fn (Request $request) => ['id' => $request->user()?->getKey()]);
        });
    }

    public function test_domyslny_token_nie_niesie_wildcarda_tylko_jawna_zamknieta_liste(): void
    {
        $osoba = $this->user();

        $token = $osoba->createToken('Telefon Ani');

        $this->assertNotContains('*', $token->accessToken->abilities,
            'Domyślny token nie może nieść wildcarda — obejmowałby też przyszłe trasy.');
        $this->assertSame(User::DOMYSLNE_UPRAWNIENIA_API, $token->accessToken->abilities);
        $this->assertContains(ZakresyTokenu::TRESC_PISZ, $token->accessToken->abilities);
    }

    public function test_token_bez_wymaganego_zakresu_dostaje_403_na_trasie_ktora_go_wymaga(): void
    {
        $osoba = $this->user();
        $token = $osoba->createToken('Telefon bez zapisu', [ZakresyTokenu::TRESC_CZYTAJ])->plainTextToken;

        $this->postJson('/api/v1/_proba_zakresy/wpis', [], ['Authorization' => 'Bearer '.$token])
            ->assertForbidden();
    }

    /**
     * Kontrola dodatnia do testu wyżej — na TEJ SAMEJ trasie: bez niej
     * 403 mógłby równie dobrze znaczyć „trasa jest zepsuta dla każdego".
     */
    public function test_token_z_wymaganym_zakresem_przechodzi_na_tej_samej_trasie(): void
    {
        $osoba = $this->user();
        $token = $osoba->createToken('Telefon z zapisem', [ZakresyTokenu::TRESC_PISZ])->plainTextToken;

        $this->postJson('/api/v1/_proba_zakresy/wpis', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('id', $osoba->getKey());
    }

    public function test_nie_da_sie_wydac_tokenu_z_wildcardem_nawet_podanym_jawnie(): void
    {
        $osoba = $this->user();

        $this->expectException(InvalidArgumentException::class);

        $osoba->createToken('Telefon z wildcardem', ['*']);
    }

    public function test_nie_da_sie_wydac_tokenu_z_zakresem_spoza_slownika(): void
    {
        $osoba = $this->user();

        $this->expectException(InvalidArgumentException::class);

        $osoba->createToken('Telefon z literowka', ['tresc:usun-wszystko']);
    }

    public function test_nie_da_sie_wydac_tokenu_bez_zadnego_zakresu(): void
    {
        $osoba = $this->user();

        $this->expectException(InvalidArgumentException::class);

        $osoba->createToken('Telefon bez zakresu', []);
    }
}

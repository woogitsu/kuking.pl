<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zgoda na cotygodniowy przegląd nie może być domyślnie włączona.
 *
 * DLACZEGO TO JEST BŁĄD, A NIE DROBIAZG
 * Kolumna `users.wants_weekly_digest` miała `default(true)`, a formularz
 * rejestracji NIE PYTA o tę zgodę wcale — nie ma tam takiego pola, jest
 * tylko `age_confirmed` i `terms_accepted`. Efekt: każde nowe konto
 * powstawało z włączoną zgodą, o którą nikt go nie zapytał.
 *
 * Zgoda, o którą nie zapytano, nie jest zgodą — a szkic polityki
 * prywatności opiera wysyłkę przeglądu właśnie na art. 6 ust. 1 lit. a
 * RODO, czyli na zgodzie. Dopóki kolumna wstaje na `true`, to zdanie
 * w polityce jest nieprawdziwe od pierwszego dnia.
 *
 * DLACZEGO NIE ZASZKODZIŁO TO NIKOMU DOTĄD, I DLACZEGO TO NIE JEST
 * ARGUMENT ZA ZOSTAWIENIEM TEGO
 * Cotygodniowego przeglądu nie ma dziś w kodzie w ogóle: zero mailable'i,
 * zero notyfikacji, zero jobów, a żadne z odczytań tej kolumny nie jest
 * klauzulą `where` wybierającą odbiorców. Nic więc nie zostało wysłane.
 * Ale to znaczy tylko tyle, że pułapka jeszcze nie wypaliła: w dniu, w
 * którym ktoś dopisze wysyłkę, wszystkie istniejące konta są już
 * zapisane — bez ani jednego kliknięcia.
 *
 * CZEGO TEN TEST NIE SPRAWDZA
 * Nie sprawdza, czy przegląd jest wysyłany — bo nie jest. Sprawdza
 * wyłącznie stan początkowy zgody i to, że ekran ustawień nadal pozwala
 * ją WŁĄCZYĆ. Ta druga część jest kontrolą: bez niej test przechodziłby
 * równie dobrze, gdyby ktoś przez pomyłkę zabetonował pole na `false`
 * i odebrał ludziom możliwość zapisania się.
 */
class ZgodaNaPrzegladNieJestDomyslnaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function daneRejestracji(array $nadpisz = []): array
    {
        return array_merge([
            'display_name' => 'Basia',
            'username' => 'basia',
            'email' => 'basia@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ], $nadpisz);
    }

    public function test_nowe_konto_z_rejestracji_nie_ma_zgody_na_przeglad(): void
    {
        $this->post(route('register'), $this->daneRejestracji())->assertRedirect();

        // `username` leży w `profiles`, nie w `users` — szukamy po adresie.
        $konto = User::query()->where('email', 'basia@example.com')->sole();

        $this->assertFalse(
            (bool) $konto->wants_weekly_digest,
            'Nowe konto wstało ze zgodą na cotygodniowy przegląd, choć formularz rejestracji o nią nie pytał.',
        );
    }

    /**
     * Drugie wejście od strony bazy, nie kontrolera: `INSERT` pomijający
     * tę kolumnę musi dać `false`. Sam test przez rejestrację by nie
     * wystarczył — gdyby ktoś w przyszłości ustawił `false` jawnie w
     * `RegisterController`, a `DEFAULT` w bazie został na `true`, każda
     * inna droga tworzenia konta (seeder, komenda, import) nadal
     * zapisywałaby ludzi bez pytania.
     */
    public function test_domyslna_wartosc_w_bazie_jest_wylaczona(): void
    {
        $domyslna = DB::selectOne(
            "select column_default from information_schema.columns
             where table_name = 'users' and column_name = 'wants_weekly_digest'",
        );

        $this->assertNotNull($domyslna, 'Kolumna `users.wants_weekly_digest` nie istnieje.');

        $this->assertStringStartsWith(
            'false',
            (string) $domyslna->column_default,
            'DEFAULT kolumny `wants_weekly_digest` w bazie nadal włącza zgodę.',
        );
    }

    /**
     * KONTROLA. Bez tego testu poprzednie dwa przechodziłyby także wtedy,
     * gdyby zgody nie dało się już w ogóle wyrazić.
     */
    public function test_ekran_ustawien_nadal_pozwala_zapisac_sie_na_przeglad(): void
    {
        $konto = User::factory()->create(['wants_weekly_digest' => false]);

        $this->actingAs($konto)
            ->put(route('settings.privacy'), [
                'original_digest' => (int) $konto->fresh()->wants_weekly_digest,
                'original_memories' => (int) $konto->fresh()->memories_enabled,

                'wants_weekly_digest' => '1',
            ])
            ->assertRedirect();

        $this->assertTrue(
            (bool) $konto->fresh()->wants_weekly_digest,
            'Konto nie mogło zapisać się na przegląd z ekranu ustawień.',
        );
    }
}

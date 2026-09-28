<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `email` i `email_verified_at` nie dają się ustawić masowym przypisaniem
 * (issue #195, AGENTS.md §7).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO JEST TAKA SAMA REGUŁA JAK PRZY `status` I `role`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Adres e-mail jest jedyną drogą odzyskania konta: kto go przestawi,
 * przejmuje konto resetem hasła. Jego zmiana jest więc zmianą STANU KONTA,
 * a nie edycją profilu — i musi być jawną, nazwaną operacją, nie efektem
 * ubocznym `update()`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TEST MIERZY WARTOŚĆ W BAZIE, A NIE ZAWARTOŚĆ `$fillable`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo regułą jest SKUTEK, nie zapis w liście. `assertNotContains('email',
 * $user->getFillable())` przechodziłby także wtedy, gdyby ktoś wyłączył
 * ochronę inaczej (`Model::unguard()`, `$guarded = []`, własny `fill()`).
 * Ten sam wybór opisuje `SecurityTest` przy `status` i `role` — tam
 * dopisanie pól do `$fillable` przechodziło niezauważone, dopóki test
 * pytał o kontroler zamiast o wartość.
 */
class AdresEmailPozaMasowymPrzypisaniemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cała klasa mierzy ciche odrzucenie pola z produkcji, nie wyjątek
        // trybu ścisłego (#976) — patrz `TestCase::mierzMasowePrzypisanieJakWProdukcji()`.
        $this->mierzMasowePrzypisanieJakWProdukcji();
    }

    public function test_update_nie_ustawia_adresu_email(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $basia->update(['email' => 'napastnik@example.test']);

        $this->assertSame(
            'basia@example.test',
            $basia->fresh()->email,
            'Adres e-mail da się ustawić masowym przypisaniem — `email` wróciło do $fillable. '
            .'To jest przejęcie konta przez dowolny `update($request->all())`, także taki, '
            .'który o adresie w ogóle nie myśli (issue #195).',
        );
    }

    public function test_update_nie_potwierdza_adresu(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $basia->forceFill(['email_verified_at' => null])->save();
        $basia->update(['email_verified_at' => now()]);

        $this->assertNull(
            $basia->fresh()->email_verified_at,
            'Potwierdzenie adresu da się ustawić masowym przypisaniem — a ma pochodzić '
            .'z kliknięcia w link, nie z pola w formularzu.',
        );
    }

    public function test_tworzenie_konta_masowym_przypisaniem_nie_wnosi_adresu(): void
    {
        $konto = new User(['email' => 'podszywam@example.test', 'locale' => 'pl']);

        $this->assertNull($konto->email);
        $this->assertSame('pl', $konto->locale, 'Kontrola: pola dozwolone nadal przechodzą.');
    }

    /**
     * KONTROLA DRUGIEJ STRONY. Bez niej „adres się nie zapisuje" mogłoby
     * znaczyć, że nie zapisuje się NIGDY — czyli że rejestracja i zmiana
     * adresu są zepsute, a testy wyżej i tak zielone.
     */
    public function test_jawna_akcja_domenowa_adres_ustawia(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $basia->assignEmail('  Nowa.Basia@Example.TEST  ', potwierdzony: true)->save();

        $swiezy = $basia->fresh();

        // Przy okazji: mutator normalizuje adres (małe litery, bez spacji) —
        // ta droga nie omija reguły z `users_email_lower_unique`.
        $this->assertSame('nowa.basia@example.test', $swiezy->email);
        $this->assertNotNull($swiezy->email_verified_at);
    }

    public function test_rejestracja_nadal_zapisuje_adres(): void
    {
        $this->post(route('register'), [
            'display_name' => 'Basia',
            'username' => 'basia_z_podkarpacia',
            'email' => 'Basia@Example.TEST',
            'password' => 'zielonapietruszkarano',
            'password_confirmation' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'basia@example.test']);
    }
}

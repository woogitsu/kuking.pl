<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Odrzucony formularz prywatności ma pokazać błąd i zachować to, co człowiek
 * PRZED CHWILĄ wybrał — nie to, co wciąż stoi w bazie (issue #792).
 *
 * CO BYŁO ZEPSUTE
 * `resources/views/pages/settings/privacy.blade.php` czytało oba checkboxy
 * wyłącznie z `auth()->user()`, z pominięciem `old()`. Widok też nie miał
 * żadnego miejsca renderującego błąd walidacji — ani podsumowania, ani
 * komunikatu przy polu. Efekt: po odrzuconym żądaniu formularz milczał
 * o powodzie i pokazywał stan sprzed wysyłki, jakby zmiana nigdy nie
 * dotarła — a `PrzestawZgodeNaDigest` faktycznie nic nie zapisał, bo
 * `$request->validate()` rzuca wyjątek PRZED `$request->user()->update()`.
 */
class OdrzuconyFormularzPrywatnosciTest extends TestCase
{
    use RefreshDatabase;

    public function test_odrzucony_digest_pokazuje_blad_i_nie_zmienia_danych(): void
    {
        $basia = $this->user('basia', [
            'memories_enabled' => true,
            'wants_weekly_digest' => false,
        ]);

        // `followingRedirects()`, bo błędy walidacji i stare wejście żyją
        // w sesji dokładnie JEDEN request — osobne, ręczne `->get()` po
        // `->put()` to o request za dużo i gubi flash (patrz inne testy
        // w repo z tym samym komentarzem, np. DrzwiWejsciowePrawdaTest).
        $ekran = $this->actingAs($basia)->followingRedirects()->from(route('settings.privacy'))
            ->put(route('settings.privacy'), [
                // Odznaczenie wspomnień: brak pola w żądaniu, tak jak robi to
                // przeglądarka przy odznaczonym checkboxie.
                'wants_weekly_digest' => 'nie-liczba-ani-prawda-falsz',
            ]);

        $ekran->assertOk();

        // Nic się nie zapisało — walidacja padła PRZED update().
        $this->assertTrue($basia->fresh()->memories_enabled);
        $this->assertFalse($basia->fresh()->wants_weekly_digest);

        // Komunikat widoczny i w podsumowaniu, i przy polu (UX_50_PLUS.md).
        $ekran->assertSee('Sprawdź formularz');
        $ekran->assertSee('id="f-wants_weekly_digest-error"', false);
        $ekran->assertSee('href="#f-wants_weekly_digest"', false);
    }

    public function test_odrzucone_zadanie_pokazuje_z_powrotem_poprawny_wybor_a_nie_wartosc_z_bazy(): void
    {
        // Sedno #792: człowiek ODZNACZA wspomnienia (pole w ogóle nie
        // przychodzi w żądaniu) i JEDNOCZEŚNIE psuje digest. Poprawny
        // fragment żądania (odznaczenie) ma wrócić na ekranie, a nie wartość
        // wciąż leżąca w bazie (`true`).
        $basia = $this->user('basia', [
            'memories_enabled' => true,
            'wants_weekly_digest' => false,
        ]);

        // `->from()` odtwarza Referer, który przeglądarka i tak wysyła przy
        // wysłaniu formularza z tej samej strony — bez niego domyślny
        // fallback `url()->previous()` ląduje na stronie głównej, nie na
        // formularzu (dokładnie ten mechanizm bada issue #795).
        $html = (string) $this->actingAs($basia)->followingRedirects()->from(route('settings.privacy'))
            ->put(route('settings.privacy'), [
                'wants_weekly_digest' => 'zepsute',
                // memories_enabled celowo pominięte — odznaczony checkbox.
            ])->assertOk()->getContent();

        // `old('memories_enabled', ...)` musi wrócić `null` (odznaczone),
        // nie `true` z bazy — inaczej odznaczenie widoczne na ekranie po
        // odrzuceniu jest kłamstwem: baza go nie zapisała, a mimo to widok
        // pokazywałby zaznaczone pole, gdyby czytał tylko z modelu.
        $this->assertMatchesRegularExpression(
            '/<input id="f-memories_enabled".*?>/s',
            $html,
            'Pole wspomnień ma się znaleźć na stronie.',
        );
        preg_match('/<input id="f-memories_enabled".*?>/s', $html, $dopasowanie);
        $this->assertStringNotContainsString(
            'checked',
            $dopasowanie[0] ?? '',
            'Po odrzuconym żądaniu formularz ma pokazać odznaczony checkbox '
            .'wspomnień (to, co człowiek wysłał), nie zaznaczony stan z bazy.',
        );
    }

    public function test_poprawny_pierwszy_get_pokazuje_stan_z_bazy(): void
    {
        $basia = $this->user('basia', [
            'memories_enabled' => false,
            'wants_weekly_digest' => true,
        ]);

        $html = (string) $this->actingAs($basia)->get(route('settings.privacy'))->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="memories_enabled"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="wants_weekly_digest"[^>]*checked/', $html);
    }

    /**
     * KONTRAKT ZMIENIONY ŚWIADOMIE (#879/#882, pozycja 5 przeglądu właściciela).
     *
     * Wcześniej ten test pilnował, że formularz BEZ pól `original_*` zapisuje
     * się „jak dawniej". Tak już nie jest i nie jest to regresja: formularz,
     * który nie niesie stanu początkowego, nie potrafi dowieść, że nie
     * nadpisuje decyzji podjętej po jego otwarciu — na przykład wypisania się
     * z tygodniowego e-maila klikniętego w międzyczasie w stopce listu.
     * Właściciel rozstrzygnął, że zapis NIE MOŻE cofać nowszej decyzji, więc
     * stary formularz dostaje czytelną odmowę zamiast cichego nadpisania.
     *
     * Pełne pokrycie tej ścieżki stoi w `StaryFormularzPrywatnosciTest`.
     */
    public function test_formularz_bez_stanu_poczatkowego_dostaje_odmowe_zamiast_nadpisac(): void
    {
        $basia = $this->user('basia', [
            'memories_enabled' => true,
            'wants_weekly_digest' => false,
        ]);

        $this->actingAs($basia)->put(route('settings.privacy'), [
            'wants_weekly_digest' => '1',
            // memories_enabled pominięte = odznaczenie.
        ])->assertSessionHasErrors(['original_digest', 'original_memories']);

        // Nic się nie zapisało — ani połowa formularza.
        $basia->refresh();
        $this->assertTrue($basia->memories_enabled);
        $this->assertFalse($basia->wants_weekly_digest);
    }
}

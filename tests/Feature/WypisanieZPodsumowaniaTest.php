<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Digest\OdnosnikWypisania;
use App\Models\CookedEvent;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Wypisanie z tygodniowego podsumowania (issue #11 pkt 6, D-057).
 *
 * TRZY RZECZY, KTÓRE MUSZĄ BYĆ PRAWDZIWE NARAZ:
 *
 *  1. **BEZ LOGOWANIA.** Człowiek, który chce przestać, nie może być
 *     zmuszony do przypomnienia sobie hasła — bo zamiast tego kliknie
 *     w skrzynce „to jest spam", a to psuje dostarczalność CAŁEJ poczty
 *     Kuking, łącznie z resetami haseł (`docs/decyzje/POCZTA.md` §3).
 *  2. **PODPIS ZAMIAST HASŁA.** `AGENTS.md` §7: „UUID w adresie NIE JEST
 *     autoryzacją". Bez podpisu trasa musi oddać 403, inaczej każdy wypisałby
 *     każdego, znając sam identyfikator konta.
 *  3. **NAPRAWDĘ ZATRZYMUJE WYSYŁKĘ.** Wypisanie, po którym przychodzi
 *     kolejny list, jest gorsze niż brak wypisania: człowiek już raz
 *     powiedział „nie" i drugi raz powie to filtrowi antyspamowemu.
 */
class WypisanieZPodsumowaniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.digest.wlaczony', true);
    }

    private function autorZWykonaniem(string $username): User
    {
        $autor = $this->user($username);
        $kucharz = $this->user($username.'_kucharz');
        $przepis = Recipe::factory()->for($autor, 'author')->create();

        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);

        return $autor;
    }

    public function test_wypisanie_dziala_bez_logowania(): void
    {
        $osoba = $this->user('wypisuje_sie');

        // Bez `actingAs` — dokładnie tak, jak z odnośnika w skrzynce
        // pocztowej otwartego na telefonie, na którym nikt nie jest
        // zalogowany do Kuking.
        $this->get(OdnosnikWypisania::dla($osoba))
            ->assertOk()
            ->assertSee('Nie będziemy już pisać');

        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);
    }

    public function test_bez_podpisu_nie_da_sie_wypisac_nikogo(): void
    {
        $osoba = $this->user('cudze_konto');

        // Ten sam adres, ale bez podpisu — czyli dokładnie to, co potrafi
        // złożyć ktoś, kto zna sam identyfikator konta.
        $this->get('/podsumowanie/wypisz/'.$osoba->getKey())->assertForbidden();

        $this->assertTrue((bool) $osoba->fresh()->wants_weekly_digest);
    }

    public function test_podrobiony_podpis_nie_dziala(): void
    {
        $osoba = $this->user('podrobiony');

        $adres = OdnosnikWypisania::dla($osoba);
        $zepsuty = preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('a', 64), $adres);

        $this->get((string) $zepsuty)->assertForbidden();

        $this->assertTrue((bool) $osoba->fresh()->wants_weekly_digest);
    }

    public function test_wypisanie_naprawde_zatrzymuje_wysylke(): void
    {
        Mail::fake();

        $autor = $this->autorZWykonaniem('nie_chce_wiecej');

        $this->get(OdnosnikWypisania::dla($autor))->assertOk();

        Artisan::call('kuking:wyslij-podsumowania');

        Mail::assertNothingQueued();
    }

    /**
     * Nagłówek `List-Unsubscribe-Post` (RFC 8058) każe Gmailowi i Outlookowi
     * wysłać PUSTY `POST` bez sesji i bez tokenu CSRF. Jeśli ta trasa go nie
     * przyjmie, przycisk „wypisz się" przy nadawcy jest ozdobą — a to
     * najkrótsza droga wyjścia, jaka w ogóle istnieje.
     */
    public function test_klient_pocztowy_wypisuje_metoda_post_bez_tokenu_csrf(): void
    {
        $osoba = $this->user('gmail_jednym_klikiem');

        $this->post(OdnosnikWypisania::dla($osoba))->assertOk();

        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);
    }

    public function test_droga_powrotna_wlacza_z_powrotem(): void
    {
        $osoba = $this->user('rozmyslil_sie');

        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();
        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);

        $this->post(OdnosnikWypisania::powrotDla($osoba))
            ->assertOk()
            ->assertSee('Będziemy pisać dalej');

        $this->assertTrue((bool) $osoba->fresh()->wants_weekly_digest);
    }

    /**
     * #1403: `GET` na odnośnik powrotny NIE włącza zgody. Adres z podpisem
     * ląduje w historii przeglądarki i w podglądach linków, a przeglądarka
     * potrafi pobrać stronę „na zapas" — udzielenie zgody musi być
     * kliknięciem człowieka (`POST` z tokenem CSRF).
     */
    public function test_wejscie_get_na_odnosnik_powrotny_tylko_pyta(): void
    {
        $osoba = $this->user('prefetch1403');

        $this->post(OdnosnikWypisania::dla($osoba))->assertOk();
        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);
        $dowody = WpisZgody::query()->where('user_id', $osoba->getKey())->count();

        $this->get(OdnosnikWypisania::powrotDla($osoba))
            ->assertOk()
            ->assertSee('Chcesz znowu dostawać podsumowanie?')
            ->assertSee('Tak, chcę je dostawać')
            ->assertSee('method="POST"', false)
            ->assertSee('action="'.e(OdnosnikWypisania::powrotDla($osoba)).'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('Będziemy pisać dalej');

        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest, 'GET włączył tygodniowy list bez kliknięcia.');
        $this->assertSame($dowody, WpisZgody::query()->where('user_id', $osoba->getKey())->count());

        // Kontrola dodatnia: przycisk z tej strony (POST) naprawdę włącza.
        $this->post(OdnosnikWypisania::powrotDla($osoba))->assertOk()->assertSee('Będziemy pisać dalej');
        $this->assertTrue((bool) $osoba->fresh()->wants_weekly_digest);
    }

    /** Droga powrotna bez tokenu CSRF nie zapisuje (nie jest na liście wyjątków). */
    public function test_post_na_odnosnik_powrotny_bez_tokenu_csrf_nie_wlacza(): void
    {
        $osoba = $this->user('bez_tokenu1403', ['wants_weekly_digest' => false]);

        // Framework pomija CSRF w testach; tu świadomie przywracamy walidację
        // (ten sam zabieg co w `CloudflareCachePrivacyTest`).
        $this->app->instance('env', 'local');
        $this->post(OdnosnikWypisania::powrotDla($osoba))->assertStatus(419);

        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);
    }

    public function test_wypisanie_zostawia_sygnal_ale_tylko_raz(): void
    {
        $osoba = $this->user('licznik_wypisow');

        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();
        // Drugie wejście: odświeżenie strony albo skaner odnośników
        // w firmowej poczcie. To nie jest drugie wypisanie i nie ma podbijać
        // progu „wypisy > 1% na wysyłkę" (RETENTION_LOOPS §6 wiersz 5).
        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();

        $this->assertSame(
            1,
            ProductSignal::query()->where('signal_name', ZapiszSygnal::WEEKLY_DIGEST_UNSUBSCRIBED)->count(),
        );
    }

    /**
     * Zgodę wolno wycofać zawsze (RODO art. 7 ust. 3) — także z konta,
     * które akurat jest zawieszone albo zgłoszone do usunięcia. Odmowa
     * z powodu stanu konta byłaby odmową wykonania prawa, a nie
     * zabezpieczeniem.
     */
    public function test_konto_zawieszone_tez_moze_sie_wypisac(): void
    {
        $osoba = $this->user('zawieszony', ['status' => User::STATUS_SUSPENDED]);

        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();

        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);
    }

    public function test_odnosnik_dziala_takze_po_dlugim_czasie(): void
    {
        $osoba = $this->user('stary_list');

        $adres = OdnosnikWypisania::dla($osoba);

        // Pół roku później, bo tyle potrafi przeleżeć list w archiwum
        // skrzynki — i to jest dokładnie moment, w którym ktoś się
        // rozmyśla. Wygasający odnośnik wypisania mówiłby wtedy „nie da
        // się wypisać".
        $this->travel(180)->days();

        $this->get($adres)->assertOk();

        $this->assertFalse((bool) $osoba->fresh()->wants_weekly_digest);
    }

    public function test_ekran_prywatnosci_dalej_pozwala_sie_zapisac(): void
    {
        // Asercja KONTROLNA do całego tego pliku: gdyby ktoś „naprawił"
        // wypisywanie, betonując zgodę na `false`, wszystkie testy wyżej
        // dalej by przechodziły, a nikt nie mógłby się już zapisać.
        $osoba = $this->user('chce_wrocic', ['wants_weekly_digest' => false]);

        $this->actingAs($osoba)->put(route('settings.privacy'), [
            'wants_weekly_digest' => '1',
        ])->assertRedirect();

        $this->assertTrue((bool) $osoba->fresh()->wants_weekly_digest);
    }
}

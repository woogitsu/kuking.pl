<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wielkość liter w adresie e-mail i w nazwie użytkownika (audyt A25).
 *
 * DWA BŁĘDY O JEDNEJ PRZYCZYNIE
 * PostgreSQL porównuje teksty z uwzględnieniem wielkości liter, a kod
 * w niektórych miejscach o tym pamiętał, a w innych nie. Rozjazd był
 * widoczny dopiero z zewnątrz:
 *
 *  1. E-MAIL. `User` zapisuje adres małymi literami (mutator), ale walidacja
 *     pytała bazę o wartość SUROWĄ. Dla „Jan@Example.com" `Rule::unique`
 *     nie znajdowało nic, zapis szedł dalej i dopiero PostgreSQL odbijał
 *     duplikat kluczem unikalnym — HTTP 500 zamiast komunikatu „na ten adres
 *     jest już konto". Odtworzone: użytkowników w bazie 1, odpowiedź 500.
 *
 *  2. NAZWA UŻYTKOWNIKA. `Rule::unique('profiles','username')` porównuje
 *     przez `=`, więc „Basia" rejestrowała się obok „basia". Odtworzone:
 *     w bazie stanęły oba profile.
 *
 * DRUGI Z NICH NIE JEST TYLKO BAŁAGANEM
 * `LoginController::findUser()` szuka nazwy JUŻ bez rozróżniania wielkości
 * liter. Przy dwóch pasujących wierszach `->first()` bez `ORDER BY` zwraca
 * ten, który baza akurat poda pierwszy — więc ktoś, kto zarejestruje „Basia"
 * obok istniejącej „basia", może odciąć prawdziwą Basię od jej konta:
 * poprawne hasło zaczyna dawać „nieprawidłowe hasło", bez żadnej wskazówki,
 * co się stało.
 *
 * Do tego dochodzi zwykłe podszycie się: @Basia i @basia pod komentarzem
 * to dla czytelnika ta sama osoba.
 */
class WielkoscLiterWLoginieTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function dane(array $nadpisz = []): array
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

    /**
     * Rejestracja loguje od razu, a trasa `/register` jest za `guest`.
     * Bez wylogowania drugie żądanie leci na `/home` i test przechodzi
     * niezależnie od naprawy — pierwsza wersja tego testu tak właśnie
     * „przechodziła", nie sprawdzając niczego.
     */
    private function zarejestruj(array $nadpisz = []): TestResponse
    {
        $this->app['auth']->logout();
        $this->flushSession();

        return $this->post(route('register'), $this->dane($nadpisz));
    }

    public function test_adres_z_wielkiej_litery_nie_wywala_rejestracji(): void
    {
        $this->zarejestruj()->assertRedirect();

        $odpowiedz = $this->zarejestruj([
            'username' => 'basia_druga',
            'email' => 'Basia@Example.com',
        ]);

        // Kluczowe jest to, czego NIE ma: żadnego 500.
        $odpowiedz->assertRedirect()->assertSessionHasErrors('email');

        $this->assertSame(1, User::count(), 'Powstało drugie konto na ten sam adres.');
    }

    public function test_komunikat_mowi_ze_konto_juz_istnieje(): void
    {
        $this->zarejestruj();

        $this->zarejestruj([
            'username' => 'basia_druga',
            'email' => 'BASIA@EXAMPLE.COM',
        ])->assertSessionHasErrorsIn('default', [
            'email' => 'Na ten adres jest już założone konto. Możesz się zalogować albo odzyskać hasło.',
        ]);
    }

    public function test_pozostale_pola_nie_znikaja_po_odbiciu_adresu(): void
    {
        $this->zarejestruj();

        $this->zarejestruj([
            'username' => 'basia_druga',
            'display_name' => 'Barbara z Podkarpacia',
            'email' => 'Basia@Example.com',
        ]);

        // Przepisywanie czterech pól od nowa przez jedno złe to najczęstszy
        // moment rezygnacji z rejestracji (AGENTS.md §5).
        $this->assertSame('Barbara z Podkarpacia', old('display_name'));
        $this->assertSame('basia_druga', old('username'));
    }

    public function test_nazwa_uzytkownika_z_wielkiej_litery_jest_zajeta(): void
    {
        $this->zarejestruj();

        $this->zarejestruj([
            'username' => 'Basia',
            'email' => 'inna@example.com',
        ])->assertSessionHasErrors('username');

        $this->assertSame(1, Profile::count(), 'Powstał drugi profil różniący się tylko wielkością liter.');
    }

    public function test_prawdziwa_basia_nadal_loguje_sie_swoja_nazwa(): void
    {
        $this->zarejestruj();

        // Próba zajęcia „Basia" — po naprawie odbija się o walidację.
        $this->zarejestruj(['username' => 'Basia', 'email' => 'inna@example.com']);

        $this->app['auth']->logout();
        $this->flushSession();

        // Ten test jest STRAŻNIKIEM, nie odtworzeniem błędu: bez naprawy
        // w bazie stały dwa profile i `findUser()` oddawał ten, który
        // PostgreSQL akurat podał pierwszy — czasem właściwy. Test, który
        // zależy od kolejności bez `ORDER BY`, nie jest dowodem na nic.
        // Dowodem jest test wyżej: drugi profil w ogóle nie powstaje.
        // Tutaj pilnujemy, żeby naprawa nie odcięła prawdziwej Basi.
        $this->post(route('login'), [
            'login' => 'basia',
            'password' => 'zielonapietruszkarano',
        ])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertSame('basia@example.com', auth()->user()->email);
    }

    public function test_zmiana_nazwy_w_ustawieniach_tez_nie_przepuszcza_wariantu(): void
    {
        $this->zarejestruj();
        $this->zarejestruj(['username' => 'ania', 'email' => 'ania@example.com'])->assertRedirect();

        $ania = User::where('email', 'ania@example.com')->firstOrFail();

        // Droga, o której się zapomina: rejestracja zamknięta, ustawienia nie.
        $this->actingAs($ania)
            ->put('/ustawienia/profil', [
                'display_name' => 'Ania',
                'username' => 'BASIA',
            ])
            ->assertSessionHasErrors('username');

        $this->assertSame('ania', $ania->profile->refresh()->username);
    }

    public function test_wlasna_nazwa_nie_jest_dla_siebie_zajeta(): void
    {
        $this->zarejestruj();

        $basia = User::where('email', 'basia@example.com')->firstOrFail();

        // Bez tego wyjątku nikt nie zapisałby już bio ani regionu: formularz
        // wysyła wszystkie pola naraz, razem z niezmienioną nazwą.
        $this->actingAs($basia)
            ->put('/ustawienia/profil', [
                'display_name' => 'Basia',
                'username' => 'basia',
                'bio' => 'Gotuję od 1978 roku.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Gotuję od 1978 roku.', $basia->profile->refresh()->bio);
    }

    public function test_nazwa_zostaje_zapisana_tak_jak_wpisana(): void
    {
        // Porównujemy bez rozróżniania wielkości liter, ale NIE przepisujemy
        // cudzej nazwy na małe litery. „AniaGotuje" ma zostać „AniaGotuje".
        $this->zarejestruj(['username' => 'AniaGotuje', 'email' => 'ania@example.com'])
            ->assertRedirect();

        $this->assertSame('AniaGotuje', Profile::first()->username);
    }

    public function test_profil_otwiera_sie_takze_pod_inna_wielkoscia_liter(): void
    {
        $this->zarejestruj(['username' => 'AniaGotuje', 'email' => 'ania@example.com']);

        // Link przepisany ręcznie albo poprawiony przez autokorektę telefonu
        // ma trafiać do właściwej osoby, a nie na stronę „nie ma takiej".
        $this->get('/@aniagotuje')->assertOk()->assertSee('AniaGotuje');
        $this->get('/@ANIAGOTUJE')->assertOk()->assertSee('AniaGotuje');
    }

    public function test_link_do_zmiany_hasla_dochodzi_takze_z_wielkiej_litery(): void
    {
        Notification::fake();

        $this->zarejestruj();

        $this->app['auth']->logout();
        $this->flushSession();

        $this->post(route('password.email'), ['email' => 'Basia@Example.com'])
            ->assertRedirect();

        // Odpowiedź jest z założenia ta sama dla adresu istniejącego
        // i nieistniejącego, więc bez tej asercji nikt by się nie dowiedział,
        // że list nie wyszedł. Człowiek czekałby na wiadomość, która nigdy
        // nie miała przyjść.
        Notification::assertSentTo(
            User::where('email', 'basia@example.com')->firstOrFail(),
            ResetPassword::class,
        );
    }

    public function test_baza_sama_pilnuje_unikalnosci_nazwy(): void
    {
        $this->zarejestruj();

        $inna = User::factory()->create();

        // Piszemy PROSTO DO BAZY, z pominięciem walidatora — bo tak właśnie
        // wchodzą dane z seedera, z konsoli i z przyszłego importu. Poza tym
        // między sprawdzeniem walidatora a zapisem jest okno, w które mieszczą
        // się dwa równoczesne żądania. Gwarancja ma stać w bazie
        // (AGENTS.md §6), a walidator jest po to, żeby człowiek dostał
        // komunikat zamiast błędu 500.
        //
        // Uwaga na pułapkę: `Profile::create()` z nowym `user_id` odbiłoby się
        // o klucz główny (fabryka użytkownika sama zakłada profil), więc test
        // przechodziłby z zupełnie innego powodu niż badany.
        $this->expectException(QueryException::class);

        DB::table('profiles')
            ->where('user_id', $inna->getKey())
            ->update(['username' => 'BASIA']);
    }
}

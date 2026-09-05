<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nazwy zastrzeżone dla obsługi serwisu (issue #42).
 *
 * Bez tego `moderacja`, `pomoc` i `platnosci` są wolne dokładnie tak samo jak
 * `basia_z_podkarpacia`. Konto @pomoc, które pisze „potwierdź hasło, bo
 * blokujemy konto", nie potrzebuje żadnej luki technicznej — wystarczy mu
 * nazwa. Nasza grupa jest na to szczególnie podatna, bo uczono ją ufać temu,
 * co wygląda oficjalnie.
 *
 * Testujemy OBIE drogi nadania nazwy. Ta druga (ustawienia profilu) jest tą,
 * o której się zapomina: samo pilnowanie rejestracji byłoby zabezpieczeniem
 * na pokaz — zakładasz konto „basia", wchodzisz w ustawienia i zmieniasz
 * nazwę na „pomoc".
 *
 * Osobno pilnujemy dwóch rzeczy, które łatwo zepsuć przy „dokręcaniu śruby":
 *
 *   * reszta poprawnie wypełnionego formularza NIE ZNIKA po odrzuceniu nazwy
 *     (AGENTS.md §5 — osoba, która przez dziesięć minut wystukiwała adres
 *     e-mail na telefonie, drugi raz tego formularza nie wypełni);
 *   * nazwa niewinna, która tylko ZAWIERA zastrzeżony fragment („adminowicz",
 *     „pomocnik"), przechodzi. Zablokowanie jej też jest błędem — tyle że
 *     takim, którego nikt nie zgłosi, bo człowiek po prostu zamknie stronę.
 */
class ZastrzezoneNazwyTest extends TestCase
{
    use RefreshDatabase;

    private const KOMUNIKAT = 'Ta nazwa jest zarezerwowana. Wybierz inną.';

    /**
     * Warianty zapisu tej samej nazwy — każdy człowiek przeczyta jako oryginał.
     *
     * Granicę normalizacji opisuje App\Rules\ReservedUsername: sprowadzamy
     * do porównywalnej postaci WYGLĄD znaku (wielkość liter, polskie znaki,
     * homoglify cyfr, znaki rozdzielające), nigdy treść nazwy.
     *
     * @var array<int, string>
     */
    private const WARIANTY = [
        'Moderacja',            // wielka litera
        'MODERACJA',            // capsy
        'mODERACJA',            // capsy mieszane
        'm0deracja',            // zero zamiast „o"
        'm_o_d_e_r_a_c_j_a',    // podkreślniki między literami
        'admin_',               // podkreślnik na końcu
        '4dm1n',                // leet
        'zespo1',               // jedynka zamiast „l"
        'obsługa',              // polskie znaki
        'p.o.m.o.c',            // kropki jako rozdzielacz
        'sup-port',             // myślnik
        'b3zpi3cz3nstwo',       // trójka zamiast „e"
        'plat.no5ci',           // piątka zamiast „s"
    ];

    /**
     * Nazwy, które muszą PRZEJŚĆ, choć zawierają zastrzeżony fragment.
     *
     * „Adminowicz" to prawdziwe nazwisko, „pomocnik" i „kontaktowa_ania"
     * to zwykłe nicki. Zbyt agresywna normalizacja (dopasowanie do prefiksu,
     * sklejanie powtórzeń, odległość edycyjna) zablokowałaby je wszystkie.
     *
     * @var array<int, string>
     */
    private const NIEWINNE = [
        'adminowicz',
        'pomocnik',
        'kontaktowa_ania',
        'moderatorka',
        'zespolowa',
        'admin2024',
        'basia_z_podkarpacia',
        'kucharz_z_kukingu',
    ];

    /**
     * @return array<int, string>
     */
    private function zastrzezone(): array
    {
        return config('kuking.account.reserved_usernames');
    }

    /**
     * Rejestracja z podaną nazwą; reszta pól zawsze poprawna.
     */
    private function zarejestruj(string $username, string $email = 'basia@example.test'): TestResponse
    {
        return $this->from('/register')->post('/register', [
            'display_name' => 'Basia',
            'username' => $username,
            'email' => $email,
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ]);
    }

    /**
     * Zmiana nazwy w ustawieniach profilu; reszta pól zawsze poprawna.
     */
    private function zmienNazwe(User $user, string $username): TestResponse
    {
        return $this->actingAs($user)
            ->from(route('settings.profile'))
            ->put('/ustawienia/profil', [
                'display_name' => 'Basia',
                'username' => $username,
            ]);
    }

    /**
     * Sprawdzamy KOMUNIKAT, a nie samą obecność błędu na polu.
     *
     * Część wariantów zapisu (`obsługa`, `sup-port`, `p.o.m.o.c`) odpada już
     * na regexie nazwy użytkownika. Gdyby test pytał tylko „czy jest błąd na
     * polu username", przechodziłby również wtedy, gdyby listy zastrzeżonych
     * nazw w ogóle nie było — czyli nie sprawdzałby niczego.
     */
    private function assertZarezerwowana(TestResponse $odpowiedz, string $nazwa): void
    {
        // assertSessionHasErrors musi pójść pierwsze: to ono sprowadza worek
        // błędów z sesji do ViewErrorBag. Bez tego session('errors') zwraca
        // surową tablicę i każde ->get() na niej wysypuje test.
        $odpowiedz->assertSessionHasErrors('username', null, 'default');

        $bledy = session('errors')->get('username');

        $this->assertContains(
            self::KOMUNIKAT,
            $bledy,
            "Nazwa „{$nazwa}” nie została odrzucona jako zastrzeżona. Błędy: ".implode(' | ', $bledy),
        );
    }

    // -----------------------------------------------------------------
    // Droga 1: rejestracja
    // -----------------------------------------------------------------

    public function test_zadna_zastrzezona_nazwa_nie_przechodzi_przy_rejestracji(): void
    {
        // Bez tego limit „5 rejestracji na 10 minut" ucina pętlę na szóstej
        // nazwie, a test milczy o dziewięciu pozostałych.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ($this->zastrzezone() as $nazwa) {
            $odpowiedz = $this->zarejestruj($nazwa)->assertRedirect('/register');

            $this->assertZarezerwowana($odpowiedz, $nazwa);
            $this->assertGuest();
            $this->assertDatabaseMissing('profiles', ['username' => $nazwa]);
        }
    }

    public function test_wariant_zapisu_zastrzezonej_nazwy_tez_nie_przechodzi_przy_rejestracji(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (self::WARIANTY as $nazwa) {
            $this->assertZarezerwowana($this->zarejestruj($nazwa), $nazwa);
            $this->assertGuest();
        }
    }

    // -----------------------------------------------------------------
    // Droga 2: zmiana nazwy w ustawieniach — o tej się zapomina
    // -----------------------------------------------------------------

    public function test_zadna_zastrzezona_nazwa_nie_przechodzi_przy_zmianie_w_ustawieniach(): void
    {
        $basia = $this->user('basia');

        foreach ($this->zastrzezone() as $nazwa) {
            $odpowiedz = $this->zmienNazwe($basia, $nazwa)->assertRedirect(route('settings.profile'));

            $this->assertZarezerwowana($odpowiedz, $nazwa);
            $this->assertSame('basia', $basia->profile->fresh()->username);
        }
    }

    public function test_wariant_zapisu_zastrzezonej_nazwy_tez_nie_przechodzi_w_ustawieniach(): void
    {
        $basia = $this->user('basia');

        foreach (self::WARIANTY as $nazwa) {
            $this->assertZarezerwowana($this->zmienNazwe($basia, $nazwa), $nazwa);
            $this->assertSame('basia', $basia->profile->fresh()->username);
        }
    }

    // -----------------------------------------------------------------
    // Nazwy niewinne — drugi rodzaj błędu
    // -----------------------------------------------------------------

    public function test_niewinna_nazwa_z_zastrzezonym_fragmentem_przechodzi(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (self::NIEWINNE as $i => $nazwa) {
            $this->zarejestruj($nazwa, "osoba{$i}@example.test")
                ->assertRedirect(route('onboarding.interests'));

            $this->assertDatabaseHas('profiles', ['username' => $nazwa]);

            // Kolejna rejestracja wymaga wylogowania — poprzednia zalogowała.
            $this->post('/logout');
        }
    }

    // -----------------------------------------------------------------
    // Komunikat
    // -----------------------------------------------------------------

    public function test_komunikat_mowi_co_zrobic_i_nie_gra_slowem_kuking(): void
    {
        $this->zarejestruj('moderacja')->assertSessionHasErrors('username');

        $komunikat = session('errors')->first('username');

        $this->assertSame(self::KOMUNIKAT, $komunikat);

        // D-009 (docs/DECISIONS.md): gra słowem `kuKING` jest zakazana
        // w komunikacie błędu. Żart w momencie, w którym coś się nie udało,
        // czyta się jak kpina.
        $this->assertStringNotContainsStringIgnoringCase('king', $komunikat);
    }

    public function test_komunikat_widac_przy_polu_i_w_podsumowaniu_na_gorze(): void
    {
        $this->zarejestruj('moderacja');

        // AGENTS.md §5: błąd przy polu ORAZ w podsumowaniu na górze formularza,
        // nigdy tylko jedno z dwóch. Stąd kolejność: najpierw podsumowanie
        // (x-error-summary), potem to samo zdanie przy samym polu.
        $this->get('/register')->assertSeeInOrder([
            'error-summary',
            self::KOMUNIKAT,
            'field-error',
            self::KOMUNIKAT,
        ], false);
    }

    // -----------------------------------------------------------------
    // Poprawnie wpisane dane nie znikają
    // -----------------------------------------------------------------

    public function test_reszta_formularza_rejestracji_nie_znika_po_odrzuceniu_nazwy(): void
    {
        $this->zarejestruj('moderacja')->assertRedirect('/register');

        // Formularz wraca z old(): imię i adres e-mail zostają na miejscu.
        $this->get('/register')
            ->assertSee('value="Basia"', false)
            ->assertSee('value="basia@example.test"', false);
    }

    public function test_reszta_ustawien_profilu_nie_znika_po_odrzuceniu_nazwy(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('settings.profile'))
            ->put('/ustawienia/profil', [
                'display_name' => 'Basia',
                'username' => 'platnosci',
                'bio' => 'Gotuje od czterdziestu lat.',
                'region' => 'Podkarpacie',
                'speciality' => 'zupy i kiszonki',
            ])
            ->assertRedirect(route('settings.profile'));

        $this->actingAs($basia)->get(route('settings.profile'))
            ->assertSee('Gotuje od czterdziestu lat.')
            ->assertSee('value="Podkarpacie"', false)
            ->assertSee('value="zupy i kiszonki"', false);

        // Nic z tego nie zostało zapisane — odrzucenie dotyczy całego zapisu.
        $this->assertSame('basia', $basia->profile->fresh()->username);
        $this->assertNull($basia->profile->fresh()->bio);
    }

    // -----------------------------------------------------------------
    // Konta obsługi, które taką nazwę mają legalnie
    // -----------------------------------------------------------------

    public function test_konto_obslugi_z_zastrzezona_nazwa_moze_zapisac_reszte_profilu(): void
    {
        // DemoSeeder zakłada konto @moderacja. Gdyby reguła działała także przy
        // NIEZMIENIONEJ nazwie, taka osoba nie zapisałaby już nigdy niczego
        // w swoim profilu: formularz wysyła wszystkie pola naraz, więc
        // zablokowana nazwa zablokowałaby też bio, region i avatar.
        $moderacja = $this->user('moderacja');

        $this->actingAs($moderacja)
            ->put('/ustawienia/profil', [
                'display_name' => 'Moderacja Kuking',
                'username' => 'moderacja',
                'bio' => 'Piszemy w sprawach zgłoszeń.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Piszemy w sprawach zgłoszeń.', $moderacja->profile->fresh()->bio);
    }

    public function test_konto_obslugi_nadal_nie_moze_wziac_innej_zastrzezonej_nazwy(): void
    {
        // Wyjątek dotyczy wyłącznie nazwy, którą konto już ma. Przesiadka
        // z @moderacja na @platnosci to nadal nadanie sobie nowej nazwy.
        $moderacja = $this->user('moderacja');

        $this->assertZarezerwowana($this->zmienNazwe($moderacja, 'platnosci'), 'platnosci');
        $this->assertSame('moderacja', $moderacja->profile->fresh()->username);
    }

    // -----------------------------------------------------------------
    // Lista mieszka w configu, nie w walidatorach
    // -----------------------------------------------------------------

    public function test_lista_pochodzi_wylacznie_z_configu(): void
    {
        // Sens trzymania listy w jednym miejscu (AGENTS.md §7): dopisanie
        // nazwy do configu ma domknąć OBIE drogi naraz. Gdyby którakolwiek
        // ścieżka miała własną kopię listy, ten test złapałby rozjazd.
        config(['kuking.account.reserved_usernames' => ['ksiegowosc']]);

        $this->assertZarezerwowana($this->zarejestruj('ksiegowosc'), 'ksiegowosc');

        $basia = $this->user('basia');
        $this->assertZarezerwowana($this->zmienNazwe($basia, 'ksiegowosc'), 'ksiegowosc');

        // …a nazwa, której na liście już nie ma, przestaje być zastrzeżona.
        $this->zmienNazwe($basia, 'moderacja')->assertSessionHasNoErrors();
        $this->assertSame('moderacja', $basia->profile->fresh()->username);
    }
}

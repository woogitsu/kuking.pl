<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\EditPost;
use App\Http\Middleware\ParametryAdresuBezTablic;
use App\Models\Collection;
use App\Models\Post;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as TrasaLaravela;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TABLICA W PARAMETRZE ALBO W POLU FORMULARZA NIE KOŃCZY SIĘ HTTP 500
 * (#2239, #2251, #2252, #2253, #2254, #2256, #2257, #2258, #2260, #2262,
 * #2264, #2265, #2266, audyt BP-04).
 *
 * `?tydzien[]=x` albo `email[]=x` daje w PHP tablicę. Kontrolery rzutowały
 * ją na tekst (`(string)`, `trim()`, parametr `?string`), Laravel zamieniał
 * ostrzeżenie „Array to string conversion” w wyjątek — HTTP 500 i alarm
 * na Discordzie.
 *
 * Rozwiązanie jest jedno dla całej rodziny, w dwóch połowach:
 *
 *  - ADRES: `ParametryAdresuBezTablic` w grupie `web` usuwa parametr adresu,
 *    który jest tablicą. Strona wygląda jak bez tego parametru — ten sam kod
 *    odpowiedzi, co adres bez niego (tego pilnują przypadki `get_*`);
 *  - FORMULARZ: `App\Support\Wejscie` w kontrolerach, które czytają pole
 *    przed walidacją albo bez niej. Pole normalizowane przed walidacją
 *    (e-mail, nazwa konta) zostaje tablicą i dostaje błąd reguły `string`
 *    przy polu; ukryte pole (token, wersja) staje się pustym napisem i idzie
 *    zwykłą drogą „nieaktualne”.
 *
 * Przypadki formularzy idą Z PRZEKIEROWANIAMI: błąd walidacji wraca ze
 * starym wejściem (`old()`), a widok, który wypisze tablicę przez `{{ }}`,
 * dałby 500 dopiero na ekranie po przekierowaniu.
 *
 * Dwa strażniki niżej obchodzą to, czego lista przypadków nie wymienia:
 * każdą trasę GET grupy `web` (z tablicą w każdym parametrze, jaki serwis
 * czyta z adresu) i każdy formularz POST widoczny dla gościa (z tablicą
 * w każdym jego polu po kolei).
 */
class TabliceWParametrachNieDajaBledu500Test extends TestCase
{
    use RefreshDatabase;

    /**
     * Parametry, które serwis czyta z ADRESU (`$request->query(...)`, klasy
     * żądań, kursory). Strażnik tras wysyła je wszystkie naraz jako tablice.
     * Lista jest przepisana, nie wyliczana ze źródeł: pilnuje jej to, że
     * middleware usuwa KAŻDĄ tablicę bez względu na nazwę — a lista musi
     * zawierać co najmniej parametry ze zgłoszeń (asercja w strażniku).
     */
    private const PARAMETRY_ADRESU = [
        'tydzien', 'dzien', 'q', 'szukaj', 'email', 'status', 'sortuj', 'kierunek',
        'od', 'do', 'bez_wpisow', 'rok', 'error', 'code', 'state', 'cursor', 'zrodlo',
        'page', 'porcje', 'krok', 'ile', 'zakladka', 'typ', 'tag', 'szkic', 'stan',
        'sekcja', 'potwierdzenie', 'pokaz', 'nawigacja', 'ile_przepisow', 'ile_osob',
        'follow_user', 'follow_recipe', 'czas', 'cel', 'widok', 'filtr', 'selection',
        'wroc', 'token', 'oczekiwany_id', 'wersja', 'redirect', 'next',
    ];

    /** Parametry z treści zgłoszeń — każdy MUSI być w liście wyżej. */
    private const PARAMETRY_ZE_ZGLOSZEN = [
        'tydzien', 'q', 'szukaj', 'email', 'status', 'sortuj', 'kierunek', 'od', 'rok',
        'error', 'code', 'state', 'cursor',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();
        Http::fake();
        Http::preventStrayRequests();
    }

    /** @return array<string, array{0: string}> */
    public static function przypadki(): array
    {
        $nazwy = [
            // #2239 planer, tydzień
            'get_planer_tydzien', 'post_planer_kopiuj_tydzien',
            // #2251 planer, fraza
            'get_planer_q',
            // #2252 zeszyty, szukaj
            'get_zeszyty_szukaj', 'get_zeszyt_szukaj',
            // #2253 i BP-04: formularz nowego hasła
            'get_nowe_haslo_email', 'post_nowe_haslo_email', 'post_nie_pamietam_hasla_email',
            // #2254 admin, lista kont
            'get_admin_konta_szukaj', 'get_admin_konta_status', 'get_admin_konta_sortuj',
            'get_admin_konta_kierunek', 'get_admin_konta_od', 'get_admin_konta_do',
            // #2256 profil, rok
            'get_profil_rok',
            // #2257 spiżarnia, od
            'get_spizarnia_od',
            // #2258 powroty OAuth
            'get_google_error', 'get_google_code', 'get_google_state',
            'get_facebook_error', 'get_facebook_code', 'get_facebook_state',
            // #2260 kolejki moderacji
            'get_odwolania_status', 'get_zgloszenia_status',
            // #2262 feedy, kursor
            'get_odkryj_cursor', 'get_home_cursor',
            // #2264 edycja wpisu, wersja
            'post_edycja_wpisu_wersja',
            // #2265 ukrywanie osoby
            'post_ukrycie_oczekiwany_id', 'post_ukrycie_wroc', 'delete_ukrycie_oczekiwany_id',
            // #2266 logowanie linkiem, token
            'post_logowanie_link_token', 'post_logowanie_link_token_w_adresie',
            // BP-04: rejestracja
            'post_rejestracja_email', 'post_rejestracja_username',
            // Ta sama rodzina, znalezione przy przeglądzie wywołań `(string)`
            'put_ustawienia_profilu_username', 'post_zaproszenie_token', 'post_wpis_usun_tag',
        ];

        return array_combine($nazwy, array_map(static fn (string $n): array => [$n], $nazwy));
    }

    #[DataProvider('przypadki')]
    public function test_tablica_nie_daje_bledu_500(string $przypadek): void
    {
        $this->{$przypadek}();
    }

    // ───────────────────────────── adres (GET) ─────────────────────────────

    /**
     * Adres z tablicą ma odpowiedzieć tak samo jak adres BEZ tego parametru
     * („tablica = brak”), a nie tylko „jakkolwiek poniżej 500”.
     *
     * @param  callable(): void|null  $przed  stan potrzebny przed KAŻDYM z dwóch żądań (np. `state` OAuth)
     */
    private function jakBezParametru(?User $kto, string $adresBez, string $parametr, ?callable $przed = null): void
    {
        $zTablica = $adresBez.(str_contains($adresBez, '?') ? '&' : '?').$parametr.'[]=x';

        if ($przed !== null) {
            $przed();
        }
        $bez = $kto !== null ? $this->actingAs($kto)->get($adresBez) : $this->get($adresBez);

        if ($przed !== null) {
            $przed();
        }
        $z = $kto !== null ? $this->actingAs($kto)->get($zTablica) : $this->get($zTablica);

        $this->assertLessThan(500, $z->getStatusCode(), "GET {$zTablica} dał HTTP {$z->getStatusCode()}.");
        $this->assertSame(
            $bez->getStatusCode(),
            $z->getStatusCode(),
            "GET {$zTablica} ma odpowiedzieć tak jak {$adresBez} — tablica w adresie to brak parametru.",
        );
    }

    private function get_planer_tydzien(): void
    {
        $this->jakBezParametru($this->user('basia'), '/planer', 'tydzien');
    }

    private function get_planer_q(): void
    {
        $this->jakBezParametru($this->user('basia'), '/planer?dzien='.now('Europe/Warsaw')->toDateString(), 'q');
    }

    private function get_zeszyty_szukaj(): void
    {
        $this->jakBezParametru($this->user('basia'), '/zeszyt', 'szukaj');
    }

    private function get_zeszyt_szukaj(): void
    {
        $basia = $this->user('basia');
        $zeszyt = $basia->collections()->create(['name' => 'Obiady', 'visibility' => 'private']);
        $this->assertInstanceOf(Collection::class, $zeszyt);

        $this->jakBezParametru($basia, '/zeszyt/'.$zeszyt->getKey(), 'szukaj');
    }

    private function get_nowe_haslo_email(): void
    {
        $this->jakBezParametru(null, '/nowe-haslo/abc', 'email');
    }

    private function get_admin_konta_szukaj(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/uzytkownicy', 'szukaj');
    }

    private function get_admin_konta_status(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/uzytkownicy', 'status');
    }

    private function get_admin_konta_sortuj(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/uzytkownicy', 'sortuj');
    }

    private function get_admin_konta_kierunek(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/uzytkownicy', 'kierunek');
    }

    private function get_admin_konta_od(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/uzytkownicy', 'od');
    }

    private function get_admin_konta_do(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/uzytkownicy', 'do');
    }

    private function get_profil_rok(): void
    {
        $basia = $this->user('basia');
        $this->jakBezParametru($basia, '/@basia', 'rok');
    }

    private function get_spizarnia_od(): void
    {
        $this->jakBezParametru($this->user('basia'), '/co-ugotuje', 'od');
    }

    private function wlaczGoogle(): void
    {
        config([
            'kuking.google.wlaczone' => true,
            'kuking.google.identyfikator_klienta' => 'klient-testowy.apps.googleusercontent.com',
            'kuking.google.sekret_klienta' => 'sekret-testowy',
        ]);
    }

    private function wlaczFacebooka(): void
    {
        config([
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => '1234567890',
            'kuking.facebook.sekret_klienta' => 'sekret-testowy',
        ]);
    }

    private function get_google_error(): void
    {
        $this->wlaczGoogle();
        $this->jakBezParametru(null, '/wejdz/google/wroc', 'error', fn () => $this->get(route('google.start')));
    }

    private function get_google_code(): void
    {
        $this->wlaczGoogle();
        $this->jakBezParametru(null, '/wejdz/google/wroc?state=x', 'code', fn () => $this->get(route('google.start')));
    }

    private function get_google_state(): void
    {
        $this->wlaczGoogle();
        $this->jakBezParametru(null, '/wejdz/google/wroc?code=x', 'state', fn () => $this->get(route('google.start')));
    }

    private function get_facebook_error(): void
    {
        $this->wlaczFacebooka();
        $this->jakBezParametru(null, '/wejdz/facebook/wroc', 'error', fn () => $this->get(route('facebook.start')));
    }

    private function get_facebook_code(): void
    {
        $this->wlaczFacebooka();
        $this->jakBezParametru(null, '/wejdz/facebook/wroc?state=x', 'code', fn () => $this->get(route('facebook.start')));
    }

    private function get_facebook_state(): void
    {
        $this->wlaczFacebooka();
        $this->jakBezParametru(null, '/wejdz/facebook/wroc?code=x', 'state', fn () => $this->get(route('facebook.start')));
    }

    private function get_odwolania_status(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/odwolania', 'status');
    }

    private function get_zgloszenia_status(): void
    {
        $this->jakBezParametru($this->admin(), '/admin/zgloszenia', 'status');
    }

    private function get_odkryj_cursor(): void
    {
        $this->jakBezParametru(null, '/odkryj', 'cursor');
    }

    private function get_home_cursor(): void
    {
        $this->jakBezParametru($this->user('basia'), '/home?zrodlo=obserwowani', 'cursor');
    }

    // ───────────────────────────── formularze ─────────────────────────────

    /**
     * Wysyła formularz i idzie za przekierowaniami: ekran po błędzie
     * walidacji wypisuje `old()`, więc 500 może przyjść dopiero tam.
     *
     * @param  array<string, mixed>  $dane
     */
    private function wyslij(?User $kto, string $metoda, string $adres, array $dane, ?string $skad = null): TestResponse
    {
        if ($kto !== null) {
            $this->actingAs($kto);
        }
        if ($skad !== null) {
            $this->from($skad);
        }

        $odpowiedz = $this->followingRedirects()->call($metoda, $adres, $dane);
        $this->followRedirects = false;

        $this->assertLessThan(500, $odpowiedz->getStatusCode(), "{$metoda} {$adres} dał HTTP {$odpowiedz->getStatusCode()}.");

        return $odpowiedz;
    }

    private function post_planer_kopiuj_tydzien(): void
    {
        $this->wyslij($this->user('basia'), 'POST', '/planer/kopiuj-tydzien', ['tydzien' => ['2026-09-28']], '/planer')
            ->assertOk();
    }

    private function post_nowe_haslo_email(): void
    {
        $this->wyslij(null, 'POST', '/nowe-haslo', [
            'token' => 'abc',
            'email' => ['a@b.pl'],
            'password' => 'bardzo-dlugie-haslo-123',
            'password_confirmation' => 'bardzo-dlugie-haslo-123',
        ], '/nowe-haslo/abc?email=a@b.pl')->assertOk();
    }

    private function post_nie_pamietam_hasla_email(): void
    {
        $this->wyslij(null, 'POST', '/nie-pamietam-hasla', ['email' => ['a@b.pl']], '/nie-pamietam-hasla')->assertOk();
    }

    private function wpisBasi(User $basia): Post
    {
        return Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Wersja pierwsza.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    private function post_edycja_wpisu_wersja(): void
    {
        $basia = $this->user('basia');
        $post = $this->wpisBasi($basia);

        $this->wyslij($basia, 'PUT', route('posts.update', $post), [
            'body' => 'Poprawka z podrobionym polem.',
            'visibility' => 'public',
            'wersja_edycji' => ['x'],
        ], route('posts.edit', $post))->assertOk()->assertSee('Poprawka z podrobionym polem.');

        // Wersja, której nie było, to konflikt: nic się nie zapisało.
        $this->assertSame('Wersja pierwsza.', $post->fresh()?->body);

        // I ekran po błędzie walidacji (puste `body`) z tablicą w starym
        // wejściu też wstaje — `old('wersja_edycji')` nie trafia do `{{ }}`.
        $this->wyslij($basia, 'PUT', route('posts.update', $post), [
            'body' => '',
            'visibility' => 'public',
            'wersja_edycji' => ['x'],
        ], route('posts.edit', $post))->assertOk();
        $this->assertNotSame('', app(EditPost::class)->wersja($post->fresh()));
    }

    private function osobaDoUkrycia(): User
    {
        $autorka = $this->user('autorka');
        Post::factory()->create(['author_id' => $autorka->getKey(), 'visibility' => Post::VISIBILITY_PUBLIC]);

        return $autorka;
    }

    private function post_ukrycie_oczekiwany_id(): void
    {
        $this->osobaDoUkrycia();
        $this->wyslij($this->user('basia'), 'POST', '/@autorka/ukryj', ['oczekiwany_id' => ['x']], '/@autorka')
            ->assertOk()->assertSee('należy teraz do innej osoby');
    }

    private function post_ukrycie_wroc(): void
    {
        $autorka = $this->osobaDoUkrycia();
        $this->wyslij($this->user('basia'), 'POST', '/@autorka/ukryj', [
            'oczekiwany_id' => (string) $autorka->getKey(),
            'wroc' => ['/odkryj'],
        ], '/@autorka')->assertOk();
    }

    private function delete_ukrycie_oczekiwany_id(): void
    {
        $this->osobaDoUkrycia();
        $this->wyslij($this->user('basia'), 'DELETE', '/@autorka/ukryj', ['oczekiwany_id' => ['x']], '/@autorka')
            ->assertOk();
    }

    private function post_logowanie_link_token(): void
    {
        config()->set('kuking.login_link.wlaczone', true);
        $this->wyslij(null, 'POST', '/logowanie/link/wejdz', ['token' => ['x']], '/login')->assertOk();
    }

    private function post_logowanie_link_token_w_adresie(): void
    {
        // Tablica dopisana do ADRESU akcji wraca przez `$request->input()`,
        // które łączy treść z adresem — middleware usuwa ją i tutaj.
        config()->set('kuking.login_link.wlaczone', true);
        $this->wyslij(null, 'POST', '/logowanie/link/wejdz?token[]=x', [], '/login')->assertOk();
    }

    /** @return array<string, mixed> */
    private function rejestracja(array $zmiany): array
    {
        return $zmiany + [
            'email' => 'nowa@example.com',
            'username' => 'nowa_osoba',
            'display_name' => 'Nowa Osoba',
            'password' => 'bardzo-dlugie-haslo-123',
            'password_confirmation' => 'bardzo-dlugie-haslo-123',
            'terms_accepted' => '1',
        ];
    }

    private function post_rejestracja_email(): void
    {
        $this->from('/register')->post('/register', $this->rejestracja(['email' => ['a@b.pl']]))
            ->assertSessionHasErrors('email');
        $this->assertSame(0, User::query()->count());

        $this->wyslij(null, 'POST', '/register', $this->rejestracja(['email' => ['a@b.pl']]), '/register')->assertOk();
    }

    private function post_rejestracja_username(): void
    {
        $this->from('/register')->post('/register', $this->rejestracja(['username' => ['jan']]))
            ->assertSessionHasErrors('username');
        $this->assertSame(0, User::query()->count());

        $this->wyslij(null, 'POST', '/register', $this->rejestracja(['username' => ['jan']]), '/register')->assertOk();
    }

    private function put_ustawienia_profilu_username(): void
    {
        $basia = $this->user('basia');
        $this->actingAs($basia)->from('/ustawienia/profil')->put('/ustawienia/profil', [
            'display_name' => 'Basia',
            'username' => ['basia'],
        ])->assertSessionHasErrors('username');

        $this->wyslij($basia, 'PUT', '/ustawienia/profil', ['display_name' => 'Basia', 'username' => ['basia']], '/ustawienia/profil')
            ->assertOk();
        $this->assertSame('basia', $basia->profile()->value('username'));
    }

    private function post_zaproszenie_token(): void
    {
        $this->wyslij(null, 'POST', route('zaproszenie.przyjmij'), ['token' => ['x']], '/register');
    }

    private function post_wpis_usun_tag(): void
    {
        $basia = $this->user('basia');
        $post = $this->wpisBasi($basia);

        $this->wyslij($basia, 'PUT', route('posts.update', $post), [
            'body' => 'Wersja pierwsza.',
            'visibility' => 'public',
            'usun_tag' => ['x'],
            'dodaj_tag' => ['y'],
        ], route('posts.edit', $post))->assertOk();
    }

    // ───────────────────────────── strażniki ─────────────────────────────

    /**
     * Każda trasa GET grupy `web`, do której da się zbudować adres, dostaje
     * tablicę w KAŻDYM parametrze, jaki serwis czyta z adresu — jako gość
     * i jako administrator. Żadna nie może odpowiedzieć 500.
     */
    public function test_straznik_zadna_trasa_get_nie_daje_500_z_tablicami_w_adresie(): void
    {
        foreach (self::PARAMETRY_ZE_ZGLOSZEN as $parametr) {
            $this->assertContains($parametr, self::PARAMETRY_ADRESU, "Parametr „{$parametr}” ze zgłoszeń musi być w liście strażnika.");
        }

        $basia = $this->user('basia');
        $post = $this->wpisBasi($basia);
        $zeszyt = $basia->collections()->create(['name' => 'Obiady', 'visibility' => 'private']);
        $podstawienia = [
            'username' => 'basia',
            'post' => (string) $post->getKey(),
            'collection' => (string) $zeszyt->getKey(),
            'user' => (string) $basia->getKey(),
            'token' => 'abc',
        ];

        $zapytanie = implode('&', array_map(static fn (string $p): string => $p.'[]=1', self::PARAMETRY_ADRESU));
        $admin = $this->admin();
        $bledy = [];
        $sprawdzone = 0;

        foreach ($this->trasyGet($podstawienia) as $nazwa => $adres) {
            foreach (['gość' => null, 'administrator' => $admin] as $persona => $kto) {
                $this->app['auth']->forgetGuards();
                $this->flushSession();
                $odpowiedz = $kto !== null
                    ? $this->actingAs($kto)->get($adres.'?'.$zapytanie)
                    : $this->get($adres.'?'.$zapytanie);
                $sprawdzone++;

                if ($odpowiedz->getStatusCode() >= 500) {
                    $bledy[] = "{$nazwa} ({$persona}): GET {$adres}?… → HTTP {$odpowiedz->getStatusCode()}";
                }
            }
        }

        // Pułapka 2: strażnik, który niczego nie odwiedził, też jest „zielony”.
        $this->assertGreaterThan(150, $sprawdzone, "Strażnik wysłał tylko {$sprawdzone} żądań — obchód tras przestał działać.");
        $this->assertSame([], $bledy, "Tablica w adresie dała HTTP 500:\n  • ".implode("\n  • ", $bledy));
    }

    /**
     * Każdy formularz POST, który widzi GOŚĆ na stronach bez parametrów,
     * dostaje po kolei tablicę w każdym swoim polu. Ekran po przekierowaniu
     * (z `old()`) też nie może dać 500.
     */
    public function test_straznik_publiczne_formularze_z_tablica_w_kazdym_polu(): void
    {
        config()->set('kuking.login_link.wlaczone', true);

        $formularze = [];
        foreach ($this->trasyGet([]) as $adres) {
            $this->app['auth']->forgetGuards();
            $odpowiedz = $this->get($adres);
            if ($odpowiedz->getStatusCode() !== 200) {
                continue;
            }
            foreach ($this->formularzePost((string) $odpowiedz->getContent()) as $akcja => $pola) {
                $formularze[$akcja] ??= ['skad' => $adres, 'pola' => $pola];
            }
        }

        $this->assertArrayHasKey('/register', $formularze, 'Strażnik nie znalazł formularza rejestracji.');
        $this->assertGreaterThanOrEqual(4, count($formularze), 'Strażnik znalazł za mało publicznych formularzy: '.implode(', ', array_keys($formularze)));

        $bledy = [];
        $wyslane = 0;
        foreach ($formularze as $akcja => ['skad' => $skad, 'pola' => $pola]) {
            foreach (array_keys($pola) as $pole) {
                $dane = $pola;
                $dane[$pole] = ['x'];
                $this->app['auth']->forgetGuards();
                $this->flushSession();
                $odpowiedz = $this->from($skad)->followingRedirects()->post($akcja, $dane);
                $this->followRedirects = false;
                $wyslane++;

                if ($odpowiedz->getStatusCode() >= 500) {
                    $bledy[] = "POST {$akcja} z {$pole}[] → HTTP {$odpowiedz->getStatusCode()}";
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, $wyslane, "Strażnik wysłał tylko {$wyslane} formularzy.");
        $this->assertSame([], $bledy, "Tablica w polu formularza dała HTTP 500:\n  • ".implode("\n  • ", $bledy));
    }

    /**
     * Wyjątki middleware'u są ŻYWE: formularz GET, który wysyła listę
     * (`name="follow[]"`), ma swoją trasę w `LISTY_DOZWOLONE` — inaczej
     * middleware po cichu zgubiłby wybór człowieka. I odwrotnie: wyjątek
     * działa, lista przechodzi do kontrolera.
     */
    public function test_lista_z_wyjatku_przechodzi_a_inna_tablica_znika(): void
    {
        Route::middleware('web')->get('/_test/tablice', fn () => response()->json(request()->query()))
            ->name('onboarding.people.test');

        $this->get('/_test/tablice?a[]=1&b=2')->assertExactJson(['b' => '2']);

        $this->assertSame(['follow', 'oczekiwani'], ParametryAdresuBezTablic::LISTY_DOZWOLONE['onboarding.people']);
        $basia = $this->user('basia', ['onboarding_zakonczony_at' => null]);
        $this->user('ola');
        $odpowiedz = $this->actingAs($basia)->get(route('onboarding.people').'?follow[]=ola&selection=x');
        $this->assertLessThan(500, $odpowiedz->getStatusCode());

        $html = (string) $odpowiedz->getContent();
        foreach ($this->polaListGet($html) as $pole) {
            $this->assertContains($pole, ParametryAdresuBezTablic::LISTY_DOZWOLONE['onboarding.people'],
                "Formularz GET ekranu „Poznaj ludzi” wysyła listę „{$pole}[]”, a middleware by ją usunął.");
        }
    }

    // ───────────────────────────── narzędzia ─────────────────────────────

    /**
     * Adresy tras GET grupy `web`, do których da się podstawić parametry.
     *
     * @param  array<string, string>  $podstawienia
     * @return array<string, string> nazwa (albo URI) => adres
     */
    private function trasyGet(array $podstawienia): array
    {
        $adresy = [];

        /** @var TrasaLaravela $trasa */
        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            if (! in_array('GET', $trasa->methods(), true) || ! in_array('web', $trasa->gatherMiddleware(), true)) {
                continue;
            }
            $uri = $trasa->uri();
            if (str_starts_with($uri, '_') || str_starts_with($uri, 'livewire')) {
                continue;
            }

            $adres = preg_replace_callback('/\{(\w+)\??\}/', static function (array $m) use ($podstawienia): string {
                return $podstawienia[$m[1]] ?? "\0";
            }, $uri);

            if ($adres === null || str_contains($adres, "\0") || ($trasa->getDomain() !== null)) {
                continue;
            }

            $adresy[$trasa->getName() ?? $uri] = '/'.ltrim($adres, '/');
        }

        return $adresy;
    }

    /**
     * Formularze POST na naszą ścieżkę z polami i ich wartościami.
     *
     * @return array<string, array<string, string>> akcja => [pole => wartość]
     */
    private function formularzePost(string $html): array
    {
        $xpath = $this->xpath($html);
        $wynik = [];

        foreach ($xpath->query('//form[translate(@method, "POST", "post")="post"]') ?: [] as $formularz) {
            if (! $formularz instanceof DOMElement) {
                continue;
            }
            $akcja = (string) parse_url($formularz->getAttribute('action'), PHP_URL_PATH);
            if ($akcja === '' || ! str_starts_with($akcja, '/')) {
                continue;
            }

            $pola = [];
            foreach ($xpath->query('.//input[@name] | .//textarea[@name] | .//select[@name]', $formularz) ?: [] as $pole) {
                if (! $pole instanceof DOMElement) {
                    continue;
                }
                $nazwa = $pole->getAttribute('name');
                if (str_contains($nazwa, '[') || $nazwa === '_token' || $nazwa === '_method'
                    || in_array($pole->getAttribute('type'), ['file', 'submit'], true)) {
                    continue;
                }
                $pola[$nazwa] = $pole->getAttribute('value');
            }

            if ($pola !== []) {
                $wynik[$akcja] = $pola;
            }
        }

        return $wynik;
    }

    /** @return list<string> nazwy pól-list w formularzach GET */
    private function polaListGet(string $html): array
    {
        $xpath = $this->xpath($html);
        $wynik = [];

        foreach ($xpath->query('//form[translate(@method, "GET", "get")="get"]//*[@name]') ?: [] as $pole) {
            if ($pole instanceof DOMElement && str_contains($pole->getAttribute('name'), '[')) {
                $wynik[] = strstr($pole->getAttribute('name'), '[', true);
            }
        }

        return array_values(array_unique($wynik));
    }

    private function xpath(string $html): DOMXPath
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dokument);
    }
}

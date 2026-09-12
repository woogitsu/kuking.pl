<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Notifications\PotwierdzenieAdresu;
use App\Notifications\ProbaWejsciaKontemFacebooka;
use App\Support\Facebook;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * WEJŚCIE KONTEM FACEBOOKA (issue #259, D-069, D-098).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TE TESTY PILNUJĄ, A CZEGO NIE DOWODZĄ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie dowodzą, że rozmawiamy z prawdziwym Facebookiem — odpowiedzi punktu
 * tokenu i węzła `me` są podstawione (`Http::fake`). Dowodzą czegoś innego
 * i ważniejszego: że NASZE decyzje o tym, kogo wpuścić i z kim połączyć
 * konto, są takie, jak rozstrzygnęło D-098 — a te decyzje są przy Facebooku
 * INNE niż przy Google i cała wartość tego pliku leży w tej różnicy.
 *
 * Najważniejsze cztery testy w tym pliku:
 *
 *  1. `test_adres_z_facebooka_nie_laczy_z_istniejacym_kontem` — to jest
 *     miejsce, w którym droga Google łączy konta, a droga Facebooka MUSI
 *     odmówić, bo nie ma dowodu potwierdzenia adresu;
 *  2. `test_nowe_konto_z_facebooka_ma_adres_niepotwierdzony` — adres
 *     z Facebooka nie ma prawa nigdy trafić do bazy jako potwierdzony;
 *  3. `test_brak_adresu_email_daje_zrozumialy_ekran` — funkcja, której przy
 *     Google nie ma wcale (runbook §7.2);
 *  4. `test_powrot_bez_zgodnego_state_nie_wchodzi` — CSRF na drodze OAuth.
 *
 * Do każdego z nich (i do pozostałych istotnych) jest KONTROLA UJEMNA —
 * tabela w opisie Pull Requesta.
 */
class LogowanieKontemFacebookiemTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private const APP_ID = '1234567890123456';

    /** Identyfikator konta Facebooka („App-Scoped User ID"). */
    private const FB_ID = '10221234567890123';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    // ───────────────────────────── narzędzia ─────────────────────────────

    private function wlaczFacebooka(): void
    {
        config([
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => self::APP_ID,
            'kuking.facebook.sekret_klienta' => 'sekret-testowy',
        ]);
    }

    private function wylaczFacebooka(): void
    {
        config([
            'kuking.facebook.identyfikator_klienta' => '',
            'kuking.facebook.sekret_klienta' => '',
        ]);
    }

    /**
     * Kliknięcie „Wejdź kontem Facebooka" — oddaje `state` z sesji.
     *
     * @return array{state: string, adres: string}
     */
    private function klikamyWejdz(): array
    {
        $odpowiedz = $this->get(route('facebook.start'));

        $odpowiedz->assertRedirect();

        return [
            'state' => (string) session('wejscie_facebook.state'),
            'adres' => (string) $odpowiedz->headers->get('Location'),
        ];
    }

    /**
     * Podstawione odpowiedzi Facebooka: punkt tokenu i węzeł `me`.
     *
     * KOLEJNOŚĆ WZORCÓW MA ZNACZENIE — oba wywołania idą na ten sam host,
     * więc wzorzec punktu tokenu musi stać pierwszy.
     *
     * @param  array<string, mixed>  $profil  pola z węzła `me` do podmiany;
     *                                        `null` jako wartość USUWA pole,
     *                                        żeby dało się odtworzyć brak
     *                                        adresu e-mail (runbook §7.2)
     */
    private function udajemyFacebooka(array $profil = []): void
    {
        $dane = array_merge([
            'id' => self::FB_ID,
            'name' => 'Basia Kowalska',
            'email' => 'basia@example.test',
        ], $profil);

        $dane = array_filter($dane, static fn (mixed $wartosc): bool => $wartosc !== null);

        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'token-dostepu-ktorego-nie-zapisujemy',
                'token_type' => 'bearer',
                'expires_in' => 5183944,
            ]),
            'graph.facebook.com/*/me*' => Http::response($dane),
        ]);
    }

    /**
     * Cała droga do powrotu z Facebooka. Oddaje odpowiedź z `callback`.
     *
     * @param  array<string, mixed>  $profil
     * @param  array<string, string>  $parametry
     */
    private function wracamyZFacebooka(array $profil = [], array $parametry = []): TestResponse
    {
        $sesja = $this->klikamyWejdz();
        $this->udajemyFacebooka($profil);

        return $this->get(route('facebook.callback', array_merge([
            'code' => 'kod-autoryzacyjny-od-facebooka',
            'state' => $sesja['state'],
        ], $parametry)));
    }

    /**
     * Wycinek HTML-a między dwoma znacznikami.
     *
     * PUŁAPKA, KTÓRA ZŁAPAŁA W TYM REPOZYTORIUM JUŻ SZEŚĆ OSÓB: asercja na
     * całym HTML-u strony łapie to samo słowo z innego miejsca ekranu.
     * Słowo „Facebook" stoi na stronie logowania także w stopce i w tekstach
     * o kampanii, więc „czy jest przycisk" trzeba sprawdzać WEWNĄTRZ bloku
     * dostawców, a nie w całej odpowiedzi (`docs/PULAPKI_TESTOW.md` §1).
     */
    private function wycinek(string $html, string $od, string $do): string
    {
        $start = strpos($html, $od);

        $this->assertNotFalse($start, "Nie ma na stronie znacznika „{$od}” — sprawdzam zły ekran.");

        $koniec = strpos($html, $do, $start);

        $this->assertNotFalse($koniec, "Nie ma na stronie znacznika „{$do}” — sprawdzam zły ekran.");

        return substr($html, $start, $koniec - $start);
    }

    /** Blok przycisków dostawców ze strony — bez stopki i bez reszty ekranu. */
    private function blokDostawcow(string $adres): string
    {
        $html = (string) $this->get($adres)->assertOk()->getContent();

        return $this->wycinek($html, 'wejscia-dostawcow', '</p>');
    }

    private function identyfikatorFacebooka(User $user): ?string
    {
        $wartosc = TozsamoscZewnetrzna::query()
            ->where('user_id', $user->getKey())
            ->where('dostawca', TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK)
            ->value('identyfikator');

        return $wartosc === null ? null : (string) $wartosc;
    }

    private function element(string $html, string $id): string
    {
        preg_match('/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*>/', $html, $trafienia);

        $this->assertNotEmpty($trafienia, "Nie ma na stronie elementu o id=\"{$id}\" — sprawdzam zły ekran.");

        return $trafienia[0];
    }

    // ─────────────────────── wyłącznik i martwy przycisk ───────────────────────

    #[Test]
    public function test_bez_kluczy_przycisku_nie_ma_i_trasa_odsyla_na_logowanie(): void
    {
        $this->wylaczFacebooka();

        $this->assertFalse(Facebook::dziala());

        foreach ([route('login'), route('register')] as $adres) {
            $html = (string) $this->get($adres)->assertOk()->getContent();

            $this->assertStringNotContainsString(route('facebook.start'), $html,
                'Bez kluczy Facebooka nie wolno pokazać przycisku — to jest dokładnie martwy przycisk z D-053.');
            $this->assertStringNotContainsString('Wejdź kontem Facebooka', $html);
        }

        // Trasa wpisana wprost nie oddaje 404 bez wyjaśnienia i nie wywala
        // się na pustym identyfikatorze aplikacji — odsyła na logowanie.
        $this->get(route('facebook.start'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'wyłączone'));

        // I nikt nie próbuje rozmawiać z Facebookiem bez kluczy.
        Http::fake();
        $this->get(route('facebook.callback', ['code' => 'kod', 'state' => 'cokolwiek']))
            ->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    /**
     * KONTROLA DODATNIA do testu wyżej (`docs/PULAPKI_TESTOW.md` §4): sama
     * asercja „przycisku nie ma" przechodzi też wtedy, gdy przycisku nie ma
     * NIGDY — na przykład gdy komponent przestał się renderować.
     */
    #[Test]
    public function test_z_kluczami_przycisk_stoi_na_logowaniu_i_na_rejestracji(): void
    {
        $this->wlaczFacebooka();

        foreach ([route('login'), route('register')] as $adres) {
            $blok = $this->blokDostawcow($adres);

            $this->assertStringContainsString('Wejdź kontem Facebooka', $blok);
            $this->assertStringContainsString(route('facebook.start'), $blok);
        }
    }

    /**
     * Właściciel chciał Google po lewej, Facebooka po prawej — czyli
     * Facebook jest DRUGI w rzędzie. Sprawdzamy kolejność w bloku
     * dostawców, nie w całym HTML-u.
     */
    #[Test]
    public function test_google_stoi_przed_facebookiem(): void
    {
        $this->wlaczFacebooka();
        config([
            'kuking.google.wlaczone' => true,
            'kuking.google.identyfikator_klienta' => 'klient-testowy.apps.googleusercontent.com',
            'kuking.google.sekret_klienta' => 'sekret-testowy',
        ]);

        $blok = $this->blokDostawcow(route('login'));

        $google = strpos($blok, 'Wejdź kontem Google');
        $facebook = strpos($blok, 'Wejdź kontem Facebooka');

        $this->assertNotFalse($google);
        $this->assertNotFalse($facebook);
        $this->assertLessThan($facebook, $google, 'Google po lewej, Facebook po prawej — prośba właściciela z 11.09.');
    }

    #[Test]
    public function test_wylacznik_zmienna_srodowiskowa_zdejmuje_droge_bez_migracji(): void
    {
        $this->wlaczFacebooka();
        config(['kuking.facebook.wlaczone' => false]);

        $this->assertFalse(Facebook::dziala());
        $this->get(route('login'))->assertOk()->assertDontSee('Wejdź kontem Facebooka');
        $this->get(route('facebook.start'))->assertRedirect(route('login'));
    }

    // ─────────────────────────── state, zakres, wersja ───────────────────────────

    #[Test]
    public function test_adres_zgody_niesie_state_i_tylko_potrzebny_zakres(): void
    {
        $this->wlaczFacebooka();

        $sesja = $this->klikamyWejdz();
        $adres = $sesja['adres'];

        $this->assertStringStartsWith(Facebook::adresAutoryzacji(), $adres);

        parse_str((string) parse_url($adres, PHP_URL_QUERY), $parametry);

        $this->assertSame($sesja['state'], $parametry['state'] ?? null);
        $this->assertSame(self::APP_ID, $parametry['client_id'] ?? null);
        $this->assertSame(route('facebook.callback'), $parametry['redirect_uri'] ?? null);

        // ZAKRES: tyle i ani słowa więcej (issue #259, runbook §8). Te dwa
        // uprawnienia są jedynymi, których Meta nie każe uzasadniać
        // w przeglądzie aplikacji — każde dodatkowe to punkt na ekranie
        // zgody, na którym osoba 60+ ma prawo wyjść.
        $this->assertSame('public_profile,email', $parametry['scope'] ?? null);

        foreach (['user_friends', 'user_photos', 'pages', 'publish', 'user_birthday', 'user_gender'] as $czego) {
            $this->assertStringNotContainsString($czego, (string) ($parametry['scope'] ?? ''));
        }

        // SEKRET NIE WYCHODZI DO PRZEGLĄDARKI CZŁOWIEKA. Nigdy.
        $this->assertStringNotContainsString('sekret-testowy', $adres);

        // NIE PROSIMY O PONOWNE PYTANIE O ODRZUCONE UPRAWNIENIE — jedna
        // prośba, jasne zdanie, droga dalej (Meta ostrzega przed pętlą sama).
        $this->assertArrayNotHasKey('auth_type', $parametry);
    }

    /**
     * WERSJA GRAPH API STOI W JEDNYM MIEJSCU I WCHODZI DO ADRESÓW.
     *
     * To jest obowiązek, którego droga Google nie miała wcale: wersje Meta
     * wygasają, a wywołania spadają wtedy CICHO na starszą. Numer wpisany
     * w trzech miejscach byłby trzema miejscami do przeoczenia (runbook §7.3).
     */
    #[Test]
    public function test_wersja_graph_api_jest_jedna_stala_i_da_sie_ja_podniesc(): void
    {
        $this->wlaczFacebooka();

        $this->assertSame(Facebook::WERSJA_GRAFU_DOMYSLNA, Facebook::wersjaGrafu());

        config(['kuking.facebook.wersja_grafu' => 'v99.0']);

        foreach ([Facebook::adresAutoryzacji(), Facebook::adresTokenu(), Facebook::adresTozsamosci()] as $adres) {
            $this->assertStringContainsString('/v99.0/', $adres,
                'Wersja Graph API ma wchodzić do WSZYSTKICH adresów z jednej stałej.');
        }

        // Pusta wartość nie znaczy „bez wersji": adres bez numeru idzie u Meta
        // na wersję NAJSTARSZĄ Z ŻYWYCH, czyli tę, która wygaśnie najszybciej.
        config(['kuking.facebook.wersja_grafu' => '']);
        $this->assertSame(Facebook::WERSJA_GRAFU_DOMYSLNA, Facebook::wersjaGrafu());
    }

    #[Test]
    public function test_powrot_bez_zgodnego_state_nie_wchodzi(): void
    {
        $this->wlaczFacebooka();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);

        $this->klikamyWejdz();
        $this->udajemyFacebooka();

        $this->get(route('facebook.callback', [
            'code' => 'kod-autoryzacyjny-od-facebooka',
            'state' => 'podrzucony-przez-napastnika',
        ]))->assertRedirect(route('login'));

        // Niezgodny `state` to CSRF na drodze OAuth — nie wolno nikogo wpuścić
        // i nie wolno nawet zapytać Facebooka o token.
        $this->assertGuest();
        Http::assertNothingSent();
    }

    #[Test]
    public function test_state_dziala_tylko_raz(): void
    {
        $this->wlaczFacebooka();
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);

        $sesja = $this->klikamyWejdz();
        $this->udajemyFacebooka();

        $adres = route('facebook.callback', ['code' => 'kod', 'state' => $sesja['state']]);

        $this->get($adres);
        $this->assertAuthenticatedAs($basia);

        // Ten sam adres drugi raz — po wylogowaniu — nie ma prawa wpuścić.
        $this->post(route('logout'));
        $this->get($adres)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ─────────── brak adresu e-mail: funkcja, której przy Google nie ma ───────────

    #[Test]
    public function test_brak_adresu_email_daje_zrozumialy_ekran(): void
    {
        $this->wlaczFacebooka();

        // Konto Facebooka na numer telefonu nie ma adresu e-mail wcale —
        // Graph API po prostu nie oddaje tego pola.
        $odpowiedz = $this->wracamyZFacebooka(['email' => null]);

        $odpowiedz->assertOk();

        $html = (string) $odpowiedz->getContent();

        // Ekran mówi, CO ZROBIĆ, a nie „wystąpił błąd" i nie pokazuje
        // angielskiego komunikatu od dostawcy.
        // NA TREŚCI EKRANU (pułapka 1b): to zdanie jest też `<title>` tej
        // strony, więc asercja na całej odpowiedzi przechodziła także po
        // skasowaniu nagłówka — a wtedy człowiek nie widzi na ekranie nic
        // o adresie e-mail. Zmierzone 12.09.2026.
        $this->assertStringContainsString(
            'Facebook nie podał nam adresu e-mail',
            $this->trescEkranu($html),
        );
        $this->assertStringContainsString(route('register'), $html);
        $this->assertStringNotContainsString('Exception', $html);

        // Konto NIE powstaje — nasze konto bez adresu istnieć nie może.
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();

        // I nie ma w sesji tożsamości, na której dałoby się domknąć konto
        // bez adresu obok, w drugiej karcie.
        $this->get(route('facebook.finish'))->assertRedirect(route('login'));
    }

    // ─────────── reguła D-098: adres z Facebooka NIE ŁĄCZY kont ───────────

    /**
     * TO JEST NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Przy Google w tym miejscu proponujemy połączenie kont (D-069, reguła
     * 3), bo `email_verified: true` dowodzi kontroli nad skrzynką. Facebook
     * nie mówi o potwierdzeniu ANI SŁOWA, więc ta sama ścieżka byłaby
     * przejęciem konta na życzenie: zakładam konto na Facebooku, wpisuję
     * w nim cudzy adres, klikam „to moje konto" i wchodzę.
     *
     * Test sprawdza NAJTRUDNIEJSZY wariant: konto z adresem POTWIERDZONYM
     * u nas. Przy Google właśnie takie konto wolno połączyć; tutaj nie wolno
     * żadnego.
     */
    #[Test]
    public function test_adres_z_facebooka_nie_laczy_z_istniejacym_kontem(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
            'display_name' => 'Basia',
        ]);

        $odpowiedz = $this->wracamyZFacebooka();

        $odpowiedz->assertRedirect(route('login'));

        /*
         * KOMUNIKAT CZYTAMY TU, ZANIM ZROBIMY KOLEJNE ŻĄDANIE.
         *
         * `session('status')` trzyma JEDNĄ wartość, a każde następne żądanie
         * w tym teście może ją nadpisać własną. Wersja czytająca status na
         * końcu metody oblewała, bo dostawała komunikat z próby wejścia na
         * `facebook.link` („trwało zbyt długo"), a nie z odmowy, o którą
         * chodzi. Test nadpisywał własny dowód — i wyglądało to na usterkę
         * kodu, którym nie było.
         */
        $status = (string) session('status');

        // Nikt nie wszedł, nic nie powstało, drugie konto na ten adres też nie.
        $this->assertGuest();
        $this->assertNull($this->identyfikatorFacebooka($basia->refresh()));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 0);

        // I nie ma ekranu, na którym dałoby się to „potwierdzić" — tej drogi
        // po prostu nie ma dla niezalogowanego człowieka.
        $this->get(route('facebook.link'))->assertRedirect(route('login'));

        // Zdanie na ekranie mówi, CO ZROBIĆ — inaczej prawdziwa Basia
        // odbija się od odmowy i odchodzi. (Odczytane wyżej, patrz komentarz.)
        $this->assertStringContainsString('jest już konto', $status);
        $this->assertStringContainsString('hasłem', $status);
        $this->assertStringContainsString('Połącz konto Facebooka', $status);
    }

    /**
     * Właściciel konta DOWIADUJE SIĘ o próbie — bo odmowę widzi tylko ten,
     * kto ją wywołał.
     */
    #[Test]
    public function test_odmowa_powiadamia_wlasciciela_konta(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
        ]);

        $this->wracamyZFacebooka();

        Notification::assertSentTo($basia, ProbaWejsciaKontemFacebooka::class);
    }

    /**
     * TEN TEST PILNUJE NAJWAŻNIEJSZEJ RZECZY W TYM LIŚCIE: że list jest
     * POWIADOMIENIEM, A NIE KLUCZEM.
     *
     * Odnośnik „to ja, połącz konta" byłby wygodny i byłby dziurą, przez
     * którą przechodzi dokładnie ten atak, przed którym stoi odmowa: obcy
     * wpisuje cudzy adres w swoim koncie na Facebooku, a MY wysyłamy
     * właścicielowi wiarygodny list, którym ten jednym kliknięciem oddaje
     * obcemu wejście na swoje konto. Napastnik nie musiałby nawet mieć
     * dostępu do skrzynki.
     *
     * Sprawdzamy TREŚĆ listu, nie deklarację w komentarzu.
     */
    #[Test]
    public function test_list_nie_niesie_odnosnika_ktory_cokolwiek_laczy(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $tresc = (string) (new ProbaWejsciaKontemFacebooka)->toMail($basia)->render();

        $this->assertStringContainsString(route('login'), $tresc,
            'List ma prowadzić na zwykłe logowanie — bez tego człowiek nie wie, co zrobić.');

        foreach (['facebook.link', 'facebook.link.store', 'facebook.start', 'facebook.callback'] as $trasa) {
            $this->assertStringNotContainsString(route($trasa), $tresc,
                "List niesie odnośnik do trasy `{$trasa}` — a żaden list nie ma u nas prawa "
                .'nieść uprawnienia do zmiany stanu konta.');
        }
    }

    /**
     * Jeden list na godzinę na konto — inaczej ta funkcja jest zdalnym
     * zalewaniem cudzej skrzynki.
     *
     * Ogranicznik trasy liczy żądania NAPASTNIKA i jego nie boli; zalewana
     * jest skrzynka OFIARY, więc licznik musi stać przy koncie odbiorcy.
     */
    #[Test]
    public function test_drugie_podejscie_w_ciagu_godziny_nie_wysyla_drugiego_listu(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $this->wracamyZFacebooka();
        $druga = $this->wracamyZFacebooka();

        Notification::assertSentToTimes($basia, ProbaWejsciaKontemFacebooka::class, 1);

        // A ODPOWIEDŹ JEST TAKA SAMA. Gdyby pominięcie listu było widoczne
        // z zewnątrz, dałoby się nim sprawdzać, czy konto istnieje — czyli
        // pominięcie rozwiązałoby jeden problem i otworzyło drugi (D-056).
        $druga->assertRedirect(route('login'));
        $this->assertStringContainsString('jest już konto', (string) session('status'));
    }

    /** To samo dla konta z adresem NIEPOTWIERDZONYM (atak z wyprzedzeniem, #317). */ /** To samo dla konta z adresem NIEPOTWIERDZONYM (atak z wyprzedzeniem, #317). */
    #[Test]
    public function test_konta_z_niepotwierdzonym_adresem_tez_nie_laczymy(): void
    {
        $this->wlaczFacebooka();

        $kontoNapastnika = $this->user('napastnik', [
            'email' => 'basia@example.test',
            'email_verified_at' => null,
        ]);

        $this->wracamyZFacebooka()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($this->identyfikatorFacebooka($kontoNapastnika->refresh()));
        $this->assertDatabaseCount('users', 1);
    }

    // ─────────────────── nowe konto: adres NIEPOTWIERDZONY ───────────────────

    #[Test]
    public function test_pierwsze_wejscie_nie_zaklada_konta_po_cichu(): void
    {
        $this->wlaczFacebooka();

        $this->wracamyZFacebooka()->assertRedirect(route('facebook.finish'));

        // Konto powstaje dopiero po dwóch oświadczeniach.
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    #[Test]
    public function test_ekran_domkniecia_podpowiada_nazwe_i_nie_zaznacza_oswiadczen(): void
    {
        $this->wlaczFacebooka();

        $this->wracamyZFacebooka();

        $html = (string) $this->get(route('facebook.finish'))->assertOk()->getContent();

        // Podpowiedź z IMIENIA (pierwszy wyraz z `name`), nie z nazwiska
        // i nie z adresu e-mail.
        $this->assertStringContainsString('value="Basia"', $this->element($html, 'f-username'));
        $this->assertStringNotContainsString('Kowalska', $html);

        // OŚWIADCZENIA SĄ PUSTE — asercja WEWNĄTRZ elementu, bo słowo
        // „checked" pada w tym repozytorium w kilku miejscach layoutu.
        $this->assertStringNotContainsString('checked', $this->element($html, 'f-age_confirmed'));
        $this->assertStringNotContainsString('checked', $this->element($html, 'f-terms_accepted'));

        // Człowiek widzi, na jaki adres zakłada konto.
        $this->assertStringContainsString('basia@example.test', $html);
    }

    #[Test]
    public function test_bez_oswiadczen_konto_nie_powstaje(): void
    {
        $this->wlaczFacebooka();
        $this->wracamyZFacebooka();

        $this->post(route('facebook.finish.store'), [
            'display_name' => 'Basia',
            'username' => 'basia',
            'terms_accepted' => '1',
        ])->assertSessionHasErrors('age_confirmed');

        $this->assertDatabaseCount('users', 0);
    }

    /**
     * DRUGI NAJWAŻNIEJSZY TEST: adres z Facebooka jest NIEPOTWIERDZONY.
     *
     * Facebook nie mówi, czy adres jest potwierdzony, więc konto powstaje
     * tak jak przy rejestracji hasłem — z adresem niepotwierdzonym i z naszą
     * wiadomością „potwierdź adres". Wpisanie tam `true` byłoby przyjęciem
     * na słowo czegoś, czego nikt nie powiedział.
     */
    #[Test]
    public function test_nowe_konto_z_facebooka_ma_adres_niepotwierdzony(): void
    {
        $this->wlaczFacebooka();
        $this->wracamyZFacebooka();

        $this->post(route('facebook.finish.store'), [
            'display_name' => 'Basia',
            'username' => 'Basia z Podkarpacia',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect(route('onboarding.interests'));

        $basia = User::where('email', 'basia@example.test')->first();

        $this->assertNotNull($basia);
        $this->assertAuthenticatedAs($basia);
        $this->assertSame(self::FB_ID, $this->identyfikatorFacebooka($basia));

        // TO JEST TA JEDNA RÓŻNICA WZGLĘDEM GOOGLE (D-098).
        $this->assertNull($basia->email_verified_at,
            'Adres z Facebooka nie ma dowodu potwierdzenia — nie wolno go zapisać jako potwierdzony.');

        /*
         * Wiadomość z potwierdzeniem adresu WYCHODZI (przy Google nie wychodzi
         * wcale, bo tam adres jest potwierdzony).
         *
         * KLASA JEST NASZA, NIE LARAVELOWA — i to nie jest szczegół stylu.
         * `User::sendEmailVerificationNotification()` jest nadpisane
         * (`app/Models/User.php`) i wysyła `PotwierdzenieAdresu`, bo cała
         * nasza poczta jest po polsku i idzie przez EmailLabs. Asercja na
         * `Illuminate\Auth\Notifications\VerifyEmail` oblewała TU, choć list
         * wychodził — sprawdzała kopertę, której u nas nikt nie nadaje.
         */
        Notification::assertSentTo($basia, PotwierdzenieAdresu::class);

        // Reszta jest taka sama jak przy każdej innej drodze rejestracji.
        $this->assertSame('basia_z_podkarpacia', $basia->profile->username);
        $this->assertSame('Basia', $basia->profile->display_name);
        $this->assertNotNull($basia->age_confirmed_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.registered']);
    }

    #[Test]
    public function test_zamknieta_rejestracja_zamyka_takze_droge_przez_facebooka(): void
    {
        $this->wlaczFacebooka();
        $this->wracamyZFacebooka();

        config(['kuking.account.registration_open' => false]);

        $this->get(route('facebook.finish'))->assertRedirect(route('login'));
        $this->post(route('facebook.finish.store'), [
            'display_name' => 'Basia',
            'username' => 'basia',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertStatus(503);

        $this->assertDatabaseCount('users', 0);
    }

    // ─────────────────── kolejne wejścia i stany konta ───────────────────

    #[Test]
    public function test_kolejne_wejscie_rozpoznaje_konto_po_identyfikatorze_facebooka(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);

        // ADRES NA FACEBOOKU SIĘ ZMIENIŁ, identyfikator został ten sam — i to
        // on rozstrzyga, kim jest ta osoba. Adres nie rozstrzyga NIGDY.
        $this->wracamyZFacebooka(['email' => 'zupelnie-inny@example.test'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.login_facebook']);

        // Adres konta został nietknięty — adresu z dostawcy nie kopiujemy.
        $this->assertSame('basia@example.test', $basia->refresh()->email);
    }

    #[Test]
    public function test_konto_zablokowane_nie_wchodzi_i_czyta_uzasadnienie(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);
        $basia->ban();

        $this->wracamyZFacebooka()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertStringContainsString('zablokowane', (string) session('status'));
    }

    #[Test]
    public function test_konto_zawieszone_wchodzi_bo_kara_jest_tylko_do_odczytu(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);
        $basia->suspend(now()->addDays(3));

        $this->wracamyZFacebooka()->assertRedirect(route('home'));

        // Zawieszenie to „tylko do odczytu" — odmowa wejścia zamieniałaby je
        // w blokadę na zawsze (ten sam wywód co w `LoginController`).
        $this->assertAuthenticatedAs($basia);
    }

    #[Test]
    public function test_konto_obslugi_serwisu_nie_wchodzi_ta_droga(): void
    {
        $this->wlaczFacebooka();

        $moderator = $this->moderator();
        $moderator->connectFacebook(self::FB_ID);

        $this->wracamyZFacebooka()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertStringContainsString('hasłem i kodem', (string) session('status'));
    }

    #[Test]
    public function test_konto_z_2fa_trafia_na_ekran_kodu_a_nie_do_serwisu(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);

        $totp = app(TwoFactorAuthenticator::class);
        $basia->beginTwoFactorSetup($totp->generateSecret());
        $basia->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        $this->wracamyZFacebooka()->assertRedirect(route('login.two_factor'));

        // Facebook zastępuje HASŁO, nie drugi składnik.
        $this->assertGuest();
        $this->assertSame($basia->getKey(), session('logowanie.2fa.user_id'));
    }

    // ─────────── połączenie konta przez osobę JUŻ ZALOGOWANĄ (D-098) ───────────

    #[Test]
    public function test_zalogowany_czlowiek_laczy_konto_z_facebookiem(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test', 'display_name' => 'Basia']);
        $this->actingAs($basia);

        // Przycisk stoi w Ustawieniach → Bezpieczeństwo, bo to jest JEDYNA
        // bezpieczna droga powiązania istniejącego konta (D-098).
        $ustawienia = (string) $this->get(route('settings.security'))->assertOk()->getContent();
        $this->assertStringContainsString(route('facebook.start'), $ustawienia);
        $this->assertStringContainsString('Połącz konto Facebooka', $ustawienia);

        $this->wracamyZFacebooka()->assertRedirect(route('facebook.link'));

        // GET niczego nie zmienia.
        $this->get(route('facebook.link'))->assertOk()->assertSee('Basia');
        $this->assertNull($this->identyfikatorFacebooka($basia->refresh()));

        // Dopiero POST tworzy powiązanie.
        $this->post(route('facebook.link.store'))->assertRedirect(route('settings.security'));

        $this->assertSame(self::FB_ID, $this->identyfikatorFacebooka($basia->refresh()));
        $this->assertDatabaseHas('audit_log', ['action' => 'account.facebook_connected']);
        $this->assertAuthenticatedAs($basia);

        // Ekran ustawień mówi teraz, że jest połączone — i nie proponuje
        // drugiego połączenia.
        $ustawienia = (string) $this->get(route('settings.security'))->assertOk()->getContent();
        $this->assertStringContainsString('połączone z Twoim kontem Facebooka', $ustawienia);
        $this->assertStringNotContainsString('Połącz konto Facebooka', $ustawienia);
    }

    #[Test]
    public function test_ekran_polaczenia_niczego_nie_zmienia(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $this->actingAs($basia);

        $this->wracamyZFacebooka();

        // GET, i to kilka razy — skanery odnośników i przycisk „wstecz"
        // otwierają adresy same.
        $this->get(route('facebook.link'))->assertOk();
        $this->get(route('facebook.link'))->assertOk();

        $this->assertNull($this->identyfikatorFacebooka($basia->refresh()));
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 0);
    }

    #[Test]
    public function test_gosc_nie_polaczy_konta_ta_droga(): void
    {
        $this->wlaczFacebooka();

        // Bez zalogowania nie ma czego łączyć — a to jest cała różnica
        // względem Google, gdzie ten ekran jest właśnie dla gościa.
        $this->get(route('facebook.link'))->assertRedirect(route('login'));
        $this->post(route('facebook.link.store'))->assertRedirect(route('login'));

        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 0);
    }

    #[Test]
    public function test_blokada_konta_miedzy_ekranami_zatrzymuje_polaczenie(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $this->actingAs($basia);

        $this->wracamyZFacebooka()->assertRedirect(route('facebook.link'));

        // Między pokazaniem ekranu a kliknięciem zapada decyzja moderacyjna.
        // Rewalidacja idzie pod blokadą wiersza konta (`ZamekKonta`, D-079),
        // więc o dostępie nie rozstrzyga stan sprzed sprawdzenia.
        $basia->ban();

        $this->post(route('facebook.link.store'));

        $this->assertNull($this->identyfikatorFacebooka($basia->refresh()),
            'O dostępie do konta nie może rozstrzygać stan z przeszłości.');
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 0);
    }

    #[Test]
    public function test_awans_na_moderatora_miedzy_ekranami_zatrzymuje_polaczenie(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $this->actingAs($basia);

        $this->wracamyZFacebooka()->assertRedirect(route('facebook.link'));

        // Konta obsługi serwisu tą drogą nie wchodzą, więc nie mają po co
        // mieć powiązania — a rola mogła zostać nadana po pokazaniu ekranu.
        $basia->forceFill(['role' => User::ROLE_MODERATOR])->save();

        $this->post(route('facebook.link.store'));

        $this->assertNull($this->identyfikatorFacebooka($basia->refresh()));
    }

    #[Test]
    public function test_konto_facebooka_powiazane_z_kims_innym_nie_laczy_sie_drugi_raz(): void
    {
        $this->wlaczFacebooka();

        $ktosInny = $this->user('ktos_inny', ['email' => 'ktos@example.test']);
        $ktosInny->connectFacebook(self::FB_ID);

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $this->actingAs($basia);

        $this->wracamyZFacebooka()->assertRedirect(route('settings.security'));

        // Człowiek dostaje zdanie po polsku, nie błąd serwera z unikalnego
        // ograniczenia bazy — a powiązanie nie powstaje.
        $this->assertNull($this->identyfikatorFacebooka($basia->refresh()));
        $this->assertStringContainsString('połączone z innym kontem', (string) session('status'));

        // I nie zdradzamy, KTÓRE to konto.
        $this->assertStringNotContainsString('ktos@example.test', (string) session('status'));
        $this->assertStringNotContainsString('ktos_inny', (string) session('status'));
    }

    #[Test]
    public function test_drugie_polaczenie_tego_samego_konta_nie_doklada_wiersza(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);
        $this->actingAs($basia);

        $this->wracamyZFacebooka()->assertRedirect(route('settings.security'));

        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 1);
        $this->assertStringContainsString('już połączone', (string) session('status'));
    }

    // ─────────────────── co pilnuje BAZA, a nie PHP (D-098) ───────────────────

    /**
     * KONTROLA DODATNIA DO MIGRACJI: `'facebook'` jest od dziś na liście
     * CHECK-a, a wcześniej `INSERT` z tą nazwą się odbijał (test
     * `LogowanieKontemGoogleTest::test_baza_nie_przyjmuje_dostawcy_poza_zamknieta_lista`
     * pilnuje, że lista nadal JEST zamknięta).
     */
    #[Test]
    public function test_baza_przyjmuje_facebooka_po_migracji(): void
    {
        $basia = $this->user('basia');

        $basia->connectFacebook(self::FB_ID);

        $this->assertSame(self::FB_ID, $this->identyfikatorFacebooka($basia));
        $this->assertNotNull(TozsamoscZewnetrzna::query()
            ->where('dostawca', 'facebook')->value('connected_at'));
    }

    #[Test]
    public function test_jedno_konto_facebooka_wchodzi_na_jedno_konto_kuking(): void
    {
        $pierwsze = $this->user('pierwsze');
        $drugie = $this->user('drugie');

        $pierwsze->connectFacebook(self::FB_ID);

        // Pilnuje tego BAZA (`UNIQUE (dostawca, identyfikator)`), nie tylko
        // PHP — bo walidator obchodzi się drugim endpointem.
        $this->expectException(QueryException::class);
        $drugie->connectFacebook(self::FB_ID);
    }

    #[Test]
    public function test_jedno_konto_kuking_nie_ma_dwoch_polaczen_z_facebookiem(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        // Pilnuje tego BAZA (`UNIQUE (dostawca, user_id)`), nie PHP.
        $this->expectException(QueryException::class);
        $basia->connectFacebook('99988877766655544');
    }

    #[Test]
    public function test_google_i_facebook_mieszkaja_na_jednym_koncie_obok_siebie(): void
    {
        $basia = $this->user('basia');

        $basia->connectGoogle('109876543210987654321');
        $basia->connectFacebook(self::FB_ID);

        // Oba `UNIQUE` są PARAMI („dostawca, coś"), więc drugi dostawca nie
        // wypycha pierwszego — o to była cała ta tabela (D-098).
        $this->assertTrue($basia->hasGoogleConnected());
        $this->assertTrue($basia->hasFacebookConnected());
        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 2);
    }

    #[Test]
    public function test_powiazanie_z_facebookiem_znika_razem_z_wymazaniem_danych_konta(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);
        $basia->markForDeletion();

        app(EraseAccountData::class)->handle($basia->refresh());

        $this->assertNull($this->identyfikatorFacebooka($basia->refresh()),
            'Po wymazaniu danych konto nie ma właściciela — wejście kontem Facebooka musi zniknąć razem z hasłem.');
    }

    /**
     * POWRÓT PO ODEBRANIU DOSTĘPU — znacznik gaśnie, a człowiek wchodzi.
     *
     * Kto odebrał nam dostęp w ustawieniach Facebooka, a potem znów przeszedł
     * przez ekran zgody, właśnie tę zgodę oddał na nowo. Odmowa wejścia albo
     * zostawienie znacznika byłoby karą za skorzystanie z własnych ustawień
     * (issue #259).
     */
    #[Test]
    public function test_ponowne_wejscie_gasi_znacznik_odebrania_dostepu(): void
    {
        $this->wlaczFacebooka();

        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $basia->connectFacebook(self::FB_ID);
        $basia->oznaczOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);

        $this->assertTrue($basia->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK),
            'Kontrola wstępna: bez zapalonego znacznika ten test nie mierzyłby niczego.');

        $this->wracamyZFacebooka();

        $this->assertAuthenticatedAs($basia->fresh());
        $this->assertFalse($basia->fresh()->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK),
            'Po ponownej zgodzie u Facebooka znacznik ma zgasnąć — inaczej ekran bezpieczeństwa '
            .'pokazuje „dostęp odebrany" komuś, kto właśnie tą drogą wszedł.');
    }

    #[Test]
    public function test_w_bazie_nie_ma_gdzie_zapisac_tokenu_facebooka(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook(self::FB_ID);

        $wiersz = (array) DB::table('tozsamosci_zewnetrzne')->first();

        // Zakres danych o człowieku jest zamknięty: ani tokenu dostępu, ani
        // zdjęcia, ani adresu e-mail z dostawcy. Zmiana tego zakresu wymaga
        // decyzji, nie refaktoru (AGENTS.md §6).
        //
        // `dostep_odebrany_at` DOSZŁO 11 września (issue #259) i przeszło
        // przez tę bramkę świadomie: nie jest to dana O CZŁOWIEKU wzięta od
        // dostawcy, tylko NASZ znacznik o stanie powiązania — „Facebook
        // powiadomił nas, że ta osoba cofnęła zgodę". Nie da się nim wejść
        // na konto i nie ma go skąd wykraść, bo nie pochodzi z Facebooka;
        // pochodzi z faktu, że Facebook do nas zadzwonił.
        $this->assertSame(
            ['connected_at', 'dostawca', 'dostep_odebrany_at', 'id', 'identyfikator', 'user_id'],
            collect(array_keys($wiersz))->sort()->values()->all(),
        );

        // A NAJWAŻNIEJSZE ZOSTAJE: pola na token nadal nie ma i nie wolno go
        // dołożyć bez decyzji. Ta pętla mówi to wprost, zamiast liczyć na to,
        // że ktoś przeczyta listę wyżej ze zrozumieniem.
        foreach (['token', 'access_token', 'refresh_token', 'email', 'picture', 'zdjecie'] as $zakazane) {
            $this->assertArrayNotHasKey($zakazane, $wiersz,
                "W tabeli powiązań pojawiła się kolumna `{$zakazane}`. To jest zmiana zakresu "
                .'danych o człowieku i wymaga decyzji, nie refaktoru.');
        }
    }

    // ─────────────────── gdy Facebook nie odpowiada ───────────────────

    #[Test]
    public function test_gdy_facebook_nie_odpowiada_odsylamy_na_haslo(): void
    {
        $this->wlaczFacebooka();

        $sesja = $this->klikamyWejdz();

        Http::fake(['graph.facebook.com/*' => Http::response('', 500)]);

        $this->get(route('facebook.callback', ['code' => 'kod', 'state' => $sesja['state']]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);

        // Zdanie po polsku, bez angielskiego kodu od dostawcy.
        $status = (string) session('status');
        $this->assertStringContainsString('coś nie zagrało', $status);
        $this->assertStringContainsString('hasłem', $status);
    }

    #[Test]
    public function test_gdy_facebook_oddaje_odpowiedz_bez_tokenu_nikogo_nie_wpuszczamy(): void
    {
        $this->wlaczFacebooka();

        $sesja = $this->klikamyWejdz();

        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['token_type' => 'bearer']),
            'graph.facebook.com/*/me*' => Http::response(['id' => self::FB_ID]),
        ]);

        $this->get(route('facebook.callback', ['code' => 'kod', 'state' => $sesja['state']]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function test_odmowa_zgody_wraca_z_polskim_zdaniem(): void
    {
        $this->wlaczFacebooka();

        $sesja = $this->klikamyWejdz();
        Http::fake();

        $this->get(route('facebook.callback', [
            'error' => 'access_denied',
            'error_reason' => 'user_denied',
            'state' => $sesja['state'],
        ]))->assertRedirect(route('login'));

        $status = (string) session('status');
        $this->assertStringContainsString('zgoda nie została udzielona', $status);
        // Angielskiego kodu od dostawcy nie pokazujemy nigdy.
        $this->assertStringNotContainsString('access_denied', $status);

        Http::assertNothingSent();
    }

    #[Test]
    public function test_wywolanie_grafu_niesie_appsecret_proof_i_tylko_dwa_pola(): void
    {
        $this->wlaczFacebooka();

        $this->wracamyZFacebooka();

        Http::assertSent(function (Request $zadanie): bool {
            if (! str_contains($zadanie->url(), '/me')) {
                return false;
            }

            parse_str((string) parse_url($zadanie->url(), PHP_URL_QUERY), $parametry);

            return ($parametry['fields'] ?? '') === 'id,name,email'
                // `appsecret_proof` to HMAC tokenu liczony sekretem aplikacji:
                // token wykradziony z naszego serwera jest bez niego bezużyteczny.
                && ($parametry['appsecret_proof'] ?? '') === hash_hmac(
                    'sha256', 'token-dostepu-ktorego-nie-zapisujemy', 'sekret-testowy',
                );
        });
    }
}

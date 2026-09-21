<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\User;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\Turnstile;
use App\Turnstile\KlientTurnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cloudflare Turnstile (D-050, issue #217) — WARUNEK WYSŁANIA siedmiu
 * formularzy publicznych, nie filtr.
 *
 * TEN PLIK NAZYWAŁ SIĘ WCZEŚNIEJ `TurnstileNieZamykaDrzwiTest` i pilnował
 * DOKŁADNIE ODWROTNEJ obietnicy: „brak tokenu przechodzi, bo ważne funkcje
 * działają bez JavaScriptu". Właściciel zmienił tę zasadę 9 września 2026
 * dla tych sześciu miejsc: „w tych newralgicznych miejscach niech JS będzie
 * obowiązkowo jak ta rejestracja itp". Testy odwrócone razem z nazwą, żeby
 * nazwa nie obiecywała czegoś, czego kod już nie robi.
 *
 * CZEGO TE TESTY PILNUJĄ TERAZ — I DLACZEGO TO NIE JEST SAMO „ODRZUCA"
 * Zaciśnięcie samo w sobie to jedna linijka. Cała wartość jest w tym, żeby
 * nikt nie został przed martwym przyciskiem, więc każdy test odrzucenia
 * sprawdza DWIE rzeczy naraz: że formularz nie przeszedł ORAZ że człowiek
 * dostał zdanie mówiące, co zrobić. Do tego:
 *
 *  - `<noscript>` stoi na każdym z siedmiu formularzy, z osobnym zdaniem;
 *  - brak tokenu i token podrobiony mają RÓŻNE komunikaty (to dla człowieka
 *    dwie różne sytuacje: raz nie widzi niczego, co się nie udało, raz
 *    sprawdzenie było widoczne i wygasło);
 *  - odrzucenie z braku tokenu zostawia ślad w dzienniku, żeby po tygodniu
 *    dało się odpowiedzieć na pytanie „czy zamknęliśmy komuś drzwi";
 *  - awaria Cloudflare i zły sekret DALEJ PRZEPUSZCZAJĄ — te testy są
 *    nietknięte i nie wolno ich „dokręcić" przy okazji.
 */
class TurnstileWymagaPotwierdzeniaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Klucze testowe Cloudflare („always passes"). Trafiają tylko do
     * konfiguracji tego procesu — pod `siteverify` i tak stoi `Http::fake`.
     */
    private const KLUCZ_PUBLICZNY = '1x00000000000000000000AA';

    private const SEKRET = '1x0000000000000000000000000000000AA';

    /**
     * Komplet z D-050 (plus siódmy z D-056): formularze, na które wchodzi
     * ktoś niezalogowany,
     * razem ze zdaniem, które ma tam stać w `<noscript>`.
     *
     * Lista jest tu jawna, a nie wyliczana z `kuking.turnstile.miejsca`,
     * celowo — inaczej test przepuściłby wpis w konfiguracji, którego nikt
     * nie podpiął do żadnego widoku (martwy przełącznik). Zdania są wpisane
     * wprost, a nie brane z `Turnstile::zdanieBezJavaScriptu()`, bo test
     * czytający tekst z tego samego miejsca co widok przeszedłby także
     * wtedy, gdyby wszystkie siedem zdań brzmiało identycznie — a o to,
     * żeby brzmiały różnie, tu właśnie chodzi.
     *
     * @var array<string, string>
     */
    private const FORMULARZE = [
        '/register' => 'Do założenia konta potrzebny jest włączony JavaScript',
        '/login' => 'Do zalogowania się potrzebny jest włączony JavaScript',
        '/nie-pamietam-hasla' => 'Do wysłania linku do nowego hasła potrzebny jest włączony JavaScript',
        '/cofnij-usuniecie-konta' => 'Do cofnięcia usunięcia konta potrzebny jest włączony JavaScript',
        '/napisz-do-nas' => 'Do wysłania do nas wiadomości potrzebny jest włączony JavaScript',
        '/zglos-nielegalna-tresc' => 'Do wysłania zgłoszenia potrzebny jest włączony JavaScript',
        // Siódmy, dołożony 10 września 2026 (issue #25, D-056): „wyślij mi
        // link do zalogowania". Ta sama rodzina co `/nie-pamietam-hasla` —
        // formularz publiczny, który wysyła list na cudzy adres z puli
        // 300 listów na dobę.
        '/logowanie/link' => 'Do wysłania linku do zalogowania się potrzebny jest włączony JavaScript',
    ];

    // ------------------------------------------------------------------
    //  Weryfikacja tokenu
    // ------------------------------------------------------------------

    public function test_formularz_przechodzi_z_prawidlowym_tokenem(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => true, 'hostname' => 'kuking.pl']);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));

        // Token poszedł na `siteverify`, a NASZ SEKRET nie wyszedł nigdzie
        // indziej — to jest cała różnica między weryfikacją a ozdobą.
        Http::assertSent(function (Request $zadanie): bool {
            return $zadanie->url() === KlientTurnstile::ADRES
                && $zadanie['response'] === 'token-od-widgetu'
                && $zadanie['secret'] === self::SEKRET;
        });
    }

    // ------------------------------------------------------------------
    //  BRAK TOKENU = ODRZUCENIE — WSZYSTKIE SZEŚĆ FORMULARZY
    //
    //  Każdy z tych testów sprawdza DWIE rzeczy naraz:
    //
    //   1. SKUTEK MERYTORYCZNY SIĘ NIE WYDARZYŁ — konta nie ma, listu nie
    //      wysłano, wiersza w bazie nie ma, konto nie wróciło. Sam kod
    //      odpowiedzi to za mało: przekierowanie bez zapisu wygląda z zewnątrz
    //      dokładnie tak samo jak sukces.
    //   2. CZŁOWIEK DOSTAŁ ZDANIE MÓWIĄCE, CO ZROBIĆ. Odrzucenie bez
    //      zrozumiałego komunikatu jest gorsze niż brak captchy: przy
    //      niedociągniętym widgecie na ekranie NIE MA NICZEGO, czego brakuje,
    //      więc bez tego zdania człowiek widzi martwy przycisk.
    //
    //  Cloudflare nie jest przy tym pytany ani razu — nie ma o co pytać,
    //  a każde zbędne żądanie wychodzące to kolejne miejsce na awarię.
    // ------------------------------------------------------------------

    public function test_rejestracja_bez_tokenu_jest_odrzucana_ze_zrozumialym_komunikatem(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);

        $odpowiedz = $this->zarejestruj();

        $odpowiedz->assertRedirect('/register');

        $this->assertNull(
            User::findByLogin('basia@example.com'),
            'Od decyzji właściciela z 9 września 2026 rejestracja bez tokenu Turnstile ma być odrzucana.',
        );

        // Poprawnie wpisane dane wracają na ekran (docs/UX_50_PLUS.md).
        $odpowiedz->assertSessionHasInput('display_name', 'Basia');

        $this->assertKomunikatBrakuTokenu($odpowiedz);
        $this->assertNiePytalismyCloudflare();
    }

    public function test_logowanie_bez_tokenu_jest_odrzucane_ze_zrozumialym_komunikatem(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);

        $osoba = $this->user('basia', ['password' => Hash::make('zielonapietruszkarano')]);

        $odpowiedz = $this->from('/login')->post('/login', [
            'login' => $osoba->email,
            'password' => 'zielonapietruszkarano',
        ]);

        $this->assertGuest();
        $this->assertKomunikatBrakuTokenu($odpowiedz);
        $this->assertNiePytalismyCloudflare();
    }

    public function test_odzyskanie_hasla_bez_tokenu_nie_wysyla_listu(): void
    {
        $this->wlaczTurnstile();
        $this->pocztaDziala();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);
        Notification::fake();

        $basia = $this->user('basia');

        $odpowiedz = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $basia->email]);

        Notification::assertNothingSent();

        // Ten ekran mówi to samo o adresie istniejącym i nieistniejącym, więc
        // bez wyraźnego komunikatu człowiek czekałby na list, który nie idzie.
        $this->assertKomunikatBrakuTokenu($odpowiedz);
        $this->assertNiePytalismyCloudflare();
    }

    public function test_logowanie_linkiem_bez_tokenu_nie_wysyla_listu(): void
    {
        $this->wlaczTurnstile();
        $this->pocztaDziala();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);
        Notification::fake();

        $basia = $this->user('basia');

        $odpowiedz = $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $basia->email]);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('login_link_tokens', 0);

        // Ten ekran mówi to samo o adresie istniejącym i nieistniejącym, więc
        // bez wyraźnego komunikatu człowiek czekałby na list, który nie idzie.
        $this->assertKomunikatBrakuTokenu($odpowiedz);
        $this->assertNiePytalismyCloudflare();
    }

    public function test_cofniecie_usuniecia_bez_tokenu_nie_przywraca_konta(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);

        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);

        $odpowiedz = $this->from(route('account.delete.cancel'))->post(route('account.delete.cancel.store'), [
            'login' => 'basia',
            'password' => 'haslo-testowe-123',
        ]);

        $basia = $basia->fresh();

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->status);
        $this->assertNotNull($basia->delete_requested_at);

        // To jest droga ratunkowa dla osoby, która NIE MOŻE się zalogować,
        // i konto kasuje się samo po upływie karencji. Komunikat musi więc
        // powiedzieć, co zrobić, a nie tylko odmówić.
        $this->assertKomunikatBrakuTokenu($odpowiedz);
        $this->assertNiePytalismyCloudflare();
    }

    public function test_napisz_do_nas_bez_tokenu_nie_zapisuje_wiadomosci(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);

        $odpowiedz = $this->from(route('kontakt'))->post(route('kontakt.store'), [
            'kind' => ContactMessage::KIND_BLAD,
            'message' => 'Nie mogę wgrać zdjęcia z telefonu, po kliknięciu Opublikuj nic się nie dzieje.',
            'contact_email' => 'basia@example.com',
        ]);

        $this->assertDatabaseCount('contact_messages', 0);

        // Najostrzejszy przypadek w całej paczce: człowiek pisze do nas
        // WŁAŚNIE DLATEGO, że coś mu nie działa. Komunikat MUSI podać adres
        // e-mail, bo formularz kontaktowy przestał być dla niego drogą.
        $this->assertKomunikatBrakuTokenu($odpowiedz);
        $this->assertNiePytalismyCloudflare();
    }

    public function test_zgloszenie_dsa_bez_tokenu_nie_zapisuje_sprawy(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);
        Notification::fake();

        $odpowiedz = $this->from(route('zglos.nielegalna'))->post(route('zglos.nielegalna.store'), [
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepis/rosol-babci-zofii',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ]);

        $this->assertDatabaseCount('reports', 0);

        // Ta droga jest publiczna Z WYMOGU PRAWNEGO (DSA art. 16 ust. 1:
        // mechanizm „łatwo dostępny"). Skoro formularz może odmówić, to
        // komunikat musi wskazać drugą drogę — adres e-mail operatora.
        $this->assertKomunikatBrakuTokenu($odpowiedz);
        $this->assertNiePytalismyCloudflare();
    }

    /**
     * Reguła odrzuca pustą wartość TAKŻE przy wywołaniu poza formularzem.
     *
     * PO CO TEN TEST ISTNIEJE. Gałąź „brak tokenu" w
     * `TurnstileJestPotwierdzony` działa przez formularz wyłącznie dzięki
     * `public bool $implicit = true` — bez tego pola Laravel w ogóle nie
     * wołałby reguły dla wartości pustej ani nieobecnej, czyli dla każdego
     * wysłania bez skryptu, i zaciśnięcie byłoby pozorne. Sześć testów wyżej
     * pilnuje tego od strony HTTP, ten domyka regułę od strony własnego
     * `Validator`, `sometimes()` i przyszłego kodu sprawdzającego token wprost.
     */
    public function test_regula_wywolana_wprost_z_pustym_tokenem_odrzuca(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $regula = new TurnstileJestPotwierdzony('rejestracja');

        foreach ([null, '', []] as $puste) {
            $odrzucone = [];

            $regula->validate(
                Turnstile::POLE,
                $puste,
                function (string $komunikat) use (&$odrzucone): void {
                    $odrzucone[] = $komunikat;
                },
            );

            $this->assertCount(1, $odrzucone, 'Pusta wartość ma odrzucać.');
            $this->assertSame(Turnstile::komunikatBrakuTokenu(), $odrzucone[0]);
        }

        $this->assertNiePytalismyCloudflare();
    }

    /**
     * DWA ODRZUCENIA, DWA RÓŻNE ZDANIA — I TO JEST WYMÓG, NIE STYLISTYKA.
     *
     * „Nie ma tokenu" znaczy dla człowieka: nie widzę niczego, co się nie
     * udało (skrypt się nie dociągnął). „Token jest zły" znaczy: sprawdzenie
     * było na ekranie i wygasło. Pierwszemu trzeba powiedzieć o JavaScripcie
     * i blokadzie reklam, drugiemu — tylko „wyślij jeszcze raz". Wspólny
     * komunikat kazałby połowie osób szukać usterki, której u nich nie ma.
     */
    public function test_brak_tokenu_i_podrobiony_token_daja_rozne_komunikaty(): void
    {
        $this->wlaczTurnstile();

        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);
        $bezTokenu = $this->komunikatZOdpowiedzi($this->zarejestruj());

        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);
        $podrobiony = $this->komunikatZOdpowiedzi($this->zarejestruj(['cf-turnstile-response' => 'token-podrobiony']));

        $this->assertNotSame($bezTokenu, $podrobiony, 'Dwa różne powody odrzucenia nie mogą mieć jednego tekstu.');

        // Każdy z nich mówi o SWOIM przypadku i o tym, co z nim zrobić.
        $this->assertStringContainsString('wczytać sprawdzenia', $bezTokenu);
        $this->assertStringContainsString('JavaScript', $bezTokenu);
        $this->assertStringContainsString('blokadę reklam', $bezTokenu);

        $this->assertStringContainsString('mogło wygasnąć', $podrobiony);
        $this->assertStringNotContainsString('JavaScript', $podrobiony);

        // Oba dają drogę wyjścia: adres, pod którym siedzi człowiek.
        foreach ([$bezTokenu, $podrobiony] as $komunikat) {
            $this->assertStringContainsString(Turnstile::adresKontaktowy(), $komunikat);
        }
    }

    /**
     * ŚLAD W DZIENNIKU — ŻEBY PO TYGODNIU DAŁO SIĘ ODPOWIEDZIEĆ NA PYTANIE
     * „CZY ZAMKNĘLIŚMY KOMUŚ DRZWI".
     *
     * Zaciśnięcie jest zakładem: twierdzimy, że nasi ludzie mają JavaScript.
     * Zakład bez licznika jest wiarą, a nie decyzją. Test pilnuje przy okazji
     * tego, czego w tym wpisie BYĆ NIE MOŻE: adresu IP ani niczego, co
     * człowiek wpisał w formularz (AGENTS.md §7).
     */
    public function test_odrzucenie_z_braku_tokenu_zostawia_slad_w_dzienniku(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);

        $dziennik = Log::spy();

        $this->zarejestruj();

        $zapisane = [];

        $dziennik->shouldHaveReceived('warning')
            ->withArgs(function (string $komunikat, array $kontekst) use (&$zapisane): bool {
                if (! str_contains($komunikat, 'nie przyszedł token')) {
                    return false;
                }

                $zapisane[] = $kontekst;

                return true;
            })
            ->once();

        $this->assertSame('rejestracja', $zapisane[0]['miejsce'] ?? null);
        $this->assertSame('brak_tokenu', $zapisane[0]['powod'] ?? null);

        $tresc = (string) json_encode($zapisane[0], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('basia@example.com', $tresc);
        $this->assertStringNotContainsString('127.0.0.1', $tresc);
    }

    public function test_formularz_odrzuca_token_uznany_przez_cloudflare_za_nieprawidlowy(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $odpowiedz = $this->zarejestruj(['cf-turnstile-response' => 'token-podrobiony']);

        $odpowiedz->assertRedirect('/register');
        $odpowiedz->assertSessionHasErrors(Turnstile::POLE);

        $this->assertNull(
            User::findByLogin('basia@example.com'),
            'Podrobiony token musi zatrzymać rejestrację — inaczej widget jest ozdobą.',
        );

        // Komunikat mówi, CO ZROBIĆ, i nie każe odświeżać strony (to skasowałoby
        // wpisany tekst). Poprawne dane wracają przez `old()`.
        $blad = (string) session('errors')->first(Turnstile::POLE);
        $this->assertStringContainsString('wyślij formularz jeszcze raz', $blad);
        $this->assertStringNotContainsString('Odśwież', $blad);
        $odpowiedz->assertSessionHasInput('display_name', 'Basia');
    }

    public function test_zuzyty_albo_wygasly_token_tez_jest_odrzucany(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-sprzed-godziny'])
            ->assertSessionHasErrors(Turnstile::POLE);
    }

    // ------------------------------------------------------------------
    //  Awaria po drugiej stronie nie może zamykać rejestracji
    //
    //  TE TRZY TESTY SĄ NIETKNIĘTE PRZEZ ZMIANĘ Z 9 WRZEŚNIA i mają takie
    //  zostać. Zaciśnięcie dotyczyło człowieka, który nie przysłał tokenu,
    //  a nie naszej ani cudzej awarii: timeout, 5xx i zły sekret to nie jest
    //  wina osoby przed ekranem.
    // ------------------------------------------------------------------

    public function test_gdy_siteverify_nie_odpowiada_formularz_przechodzi(): void
    {
        $this->wlaczTurnstile();

        Http::fake([
            KlientTurnstile::ADRES => static function (): never {
                throw new ConnectionException('Connection timed out after 4000 milliseconds');
            },
        ]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(
            User::findByLogin('basia@example.com'),
            'Niedostępność Cloudflare nie może zamykać rejestracji.',
        );
    }

    public function test_gdy_siteverify_oddaje_piecsetke_formularz_przechodzi(): void
    {
        $this->wlaczTurnstile();

        Http::fake([KlientTurnstile::ADRES => Http::response('Bad gateway', 502)]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));
    }

    /**
     * Zły sekret w Railway to NASZ błąd konfiguracji, nie wina człowieka przed
     * ekranem. Gdyby zamykał rejestrację, jedna literówka w panelu wyłączałaby
     * wejście do serwisu — a z zewnątrz wyglądałoby to jak działający serwis.
     */
    public function test_zly_sekret_nie_zamyka_rejestracji(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-secret']]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));
    }

    // ------------------------------------------------------------------
    //  Brak kluczy: nic się nie renderuje, nic nie blokuje
    // ------------------------------------------------------------------

    public function test_bez_kluczy_widget_i_ostrzezenie_o_javascripcie_nie_sa_renderowane(): void
    {
        $this->wylaczTurnstile();
        $this->pocztaDziala();

        foreach (self::FORMULARZE as $adres => $zdanie) {
            $this->get($adres)
                ->assertOk()
                ->assertDontSee('cf-turnstile')
                ->assertDontSee('challenges.cloudflare.com')
                // Bez kluczy formularz przechodzi bez tokenu, więc straszenie
                // wtedy człowieka brakiem JavaScriptu byłoby nieprawdą.
                ->assertDontSee($zdanie);
        }
    }

    public function test_bez_kluczy_walidacja_nie_blokuje_i_nikogo_nie_pyta(): void
    {
        $this->wylaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        // Nawet z tokenem podstawionym ręcznie: bez kluczy nie mamy czym
        // sprawdzać i nie wolno nam nikogo z tego powodu zatrzymać. Bez tego
        // CI i praca lokalna (jedno i drugie bez kluczy) stanęłyby na siedmiu
        // formularzach naraz.
        $this->zarejestruj(['cf-turnstile-response' => 'cokolwiek'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));
        $this->assertNiePytalismyCloudflare();
    }

    public function test_bez_kluczy_wyslanie_bez_tokenu_dalej_przechodzi(): void
    {
        $this->wylaczTurnstile();

        $this->zarejestruj()->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));
        $this->assertNiePytalismyCloudflare();
    }

    // ------------------------------------------------------------------
    //  Gdzie widget jest, a gdzie świadomie go nie ma
    // ------------------------------------------------------------------

    public function test_widget_stoi_na_wszystkich_formularzach_publicznych(): void
    {
        $this->wlaczTurnstile();
        $this->pocztaDziala();

        foreach (self::FORMULARZE as $adres => $zdanie) {
            $odpowiedz = $this->get($adres)->assertOk();

            $odpowiedz->assertSee('cf-turnstile', escape: false);
            $odpowiedz->assertSee(self::KLUCZ_PUBLICZNY);

            // Widget nie jest jedynym nośnikiem informacji (docs/UX_50_PLUS.md):
            // nad obcą ramką stoi zdanie po polsku.
            $odpowiedz->assertSee('nie musisz nic robić', escape: false);

            // SEKRET NIE MA PRAWA POJAWIĆ SIĘ W HTML-u.
            $odpowiedz->assertDontSee(self::SEKRET);
        }
    }

    /**
     * `<noscript>` NA KAŻDYM Z SIEDMIU FORMULARZY — I ZA KAŻDYM RAZEM O TYM,
     * CZEGO KONKRETNIE NIE DA SIĘ TERAZ ZROBIĆ.
     *
     * To jest druga połowa zaciśnięcia i bez niej pierwsza jest szkodliwa:
     * osoba z wyłączonym skryptem nie zobaczy widgetu w ogóle, więc bez tego
     * bloku kliknęłaby „Załóż konto" i dostała komunikat o czymś, czego nie
     * ma na ekranie. „Wymagany JavaScript" nad formularzem odzyskiwania hasła
     * nie mówi jej, że właśnie nie odzyska hasła — stąd siedem różnych zdań.
     */
    public function test_noscript_stoi_na_wszystkich_formularzach_publicznych(): void
    {
        $this->wlaczTurnstile();
        $this->pocztaDziala();

        foreach (self::FORMULARZE as $adres => $zdanie) {
            $odpowiedz = $this->get($adres)->assertOk();

            $odpowiedz->assertSee('<noscript>', escape: false);
            $odpowiedz->assertSee($zdanie, escape: false);

            // DROGA WYJŚCIA. Odesłanie takiej osoby na `/napisz-do-nas` byłoby
            // ślepą uliczką — tamten formularz ma to samo sprawdzenie. Zostaje
            // adres e-mail, klikalny, nie sam tekst.
            $odpowiedz->assertSee('mailto:'.Turnstile::adresKontaktowy(), escape: false);
        }
    }

    /**
     * LOGOWANIE MA TURNSTILE — decyzja właściciela z 9 września 2026
     * (issue #217): „captcha trzeba normalnie zrobić, ten od cloudflare jest
     * nieinwazyjny". Trzy koszyki `login_limits` zostają bez zmian — captcha
     * ich nie zastępuje.
     */
    public function test_logowanie_z_podrobionym_tokenem_nie_przechodzi(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $osoba = $this->user('basia', ['password' => Hash::make('zielonapietruszkarano')]);

        $this->from('/login')->post('/login', [
            'login' => $osoba->email,
            'password' => 'zielonapietruszkarano',
            'cf-turnstile-response' => 'token-podrobiony',
        ])->assertSessionHasErrors(Turnstile::POLE);

        $this->assertGuest();
    }

    /**
     * Punktowe wyłączenie jednego miejsca naprawdę działa — przełącznik
     * w konfiguracji nie jest ozdobą. To jest ta sama klasa błędu, której
     * pilnuje reszta tego repozytorium: wpis, który wygląda, jakby coś robił
     * (martwy `kuking.media_disk`, limit `upload` niepodpięty do trasy).
     *
     * Od 9 września ten przełącznik jest też JEDYNĄ drogą wycofania
     * zaciśnięcia dla pojedynczego formularza — stąd sprawdzenie, że zdejmuje
     * również odrzucanie wysyłki BEZ tokenu.
     */
    public function test_wylaczenie_jednego_miejsca_zdejmuje_widget_i_walidacje(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->get('/register')->assertOk()->assertSee('cf-turnstile', escape: false);

        config(['kuking.turnstile.miejsca.rejestracja' => false]);

        $this->get('/register')->assertOk()->assertDontSee('cf-turnstile');

        // Bez tokenu i z tokenem podstawionym ręcznie: na formularzu, którego
        // Turnstile nie dotyczy, nie wolno nikogo zatrzymać ani wywołać ruchu
        // wychodzącego na cudze API.
        $this->zarejestruj()->assertRedirect(route('onboarding.interests'));

        $this->assertNiePytalismyCloudflare();
    }

    // ------------------------------------------------------------------
    //  Twardy sygnał: produkcja bez kluczy to błędna konfiguracja
    // ------------------------------------------------------------------

    public function test_produkcja_bez_kluczy_melduje_bledna_konfiguracje(): void
    {
        Artisan::call('storage:link');

        $this->wylaczTurnstile();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            // Świadomie 200, nie 503: healthcheck oddający 503 już raz położył
            // ten serwis. Monitoring pilnuje TREŚCI odpowiedzi.
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.turnstile.ok', false)
            ->assertJsonPath('checks.turnstile.error', 'turnstile_bez_kluczy')
            // Baza i zdjęcia są całe — sygnał dotyczy WYŁĄCZNIE konfiguracji.
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.media.ok', true);
    }

    public function test_produkcja_z_kluczami_jest_zdrowa(): void
    {
        Artisan::call('storage:link');

        $this->wlaczTurnstile();
        // `/health` sprawdza teraz też pocztę TYLKO na produkcji
        // (`HealthController::sprawdzPoczte()`) — bez tego domyślny
        // `MAIL_MAILER=array` testów zgłosiłby WŁASNĄ, niezwiązaną z
        // Turnstile awarię i ten test sprawdzałby coś innego, niż mówi jego
        // nazwa.
        $this->pocztaDziala();
        $this->wejsciaZewnetrzneWylaczone();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.turnstile.ok', true);
    }

    /**
     * Poza produkcją brak kluczy jest stanem NORMALNYM (tak stoi w
     * `.env.example`, tak chodzi CI). Stały `degraded` w tych środowiskach
     * byłby szumem, który uczy ignorować to pole.
     */
    public function test_poza_produkcja_brak_kluczy_nie_jest_awaria(): void
    {
        Artisan::call('storage:link');

        $this->wylaczTurnstile();

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.turnstile.ok', true);
    }

    /**
     * Świadome wyłączenie Turnstile wszędzie jest poprawną konfiguracją —
     * wtedy nic nie kłamie i nie ma o czym krzyczeć. Sygnał ma dotyczyć
     * ROZJAZDU między obietnicą a rzeczywistością, nie samego braku kluczy.
     */
    public function test_produkcja_z_turnstile_wylaczonym_wszedzie_jest_zdrowa(): void
    {
        Artisan::call('storage:link');

        $this->wylaczTurnstile();
        config(['kuking.turnstile.miejsca' => array_fill_keys(
            array_keys(Turnstile::miejsca()),
            false,
        )]);
        // Patrz komentarz w `test_produkcja_z_kluczami_jest_zdrowa` —
        // `/health` sprawdza pocztę tylko na produkcji, więc bez tego
        // domyślny `MAIL_MAILER=array` testów zepsułby ten test powodem
        // niezwiązanym z Turnstile.
        $this->pocztaDziala();
        $this->wejsciaZewnetrzneWylaczone();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.turnstile.ok', true);
    }

    // ------------------------------------------------------------------
    //  Polityka bezpieczeństwa musi wpuścić widget — i tylko wtedy, gdy jest
    // ------------------------------------------------------------------

    public function test_polityka_wpuszcza_widget_dopiero_z_kluczami(): void
    {
        $this->wylaczTurnstile();
        $bez = (string) $this->get('/register')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('challenges.cloudflare.com', $bez);
        $this->assertStringNotContainsString('frame-src', $bez);

        $this->wlaczTurnstile();
        $z = (string) $this->get('/register')->headers->get('Content-Security-Policy');

        // Widget dociąga własne skrypty i rysuje się w ramce — bez obu tych
        // dyrektyw przeglądarka pokazałaby puste miejsce zamiast sprawdzenia.
        $this->assertMatchesRegularExpression(
            '/script-src [^;]*https:\/\/challenges\.cloudflare\.com/',
            $z,
        );
        $this->assertMatchesRegularExpression(
            '/frame-src [^;]*https:\/\/challenges\.cloudflare\.com/',
            $z,
        );

        // I ANI KROKU DALEJ: `unsafe-inline` w stylach skasowałoby efekt #107.
        $this->assertStringNotContainsString("style-src 'self' 'unsafe-inline'", $z);
    }

    // ------------------------------------------------------------------

    private function wlaczTurnstile(): void
    {
        config([
            'kuking.turnstile.klucz_publiczny' => self::KLUCZ_PUBLICZNY,
            'kuking.turnstile.sekret' => self::SEKRET,
        ]);
    }

    /**
     * Ekran „Nie pamiętam hasła" chowa CAŁY formularz, gdy serwis nie ma czym
     * wysyłać poczty (`App\Support\Poczta`) — a w testach sterownikiem jest
     * `array`. Bez tego sprawdzalibyśmy brak widgetu na stronie, która i tak
     * nie ma formularza.
     */
    private function pocztaDziala(): void
    {
        config(['mail.default' => 'smtp']);
    }

    /**
     * `/health` sprawdza od 12 września 2026 także dwie dodatkowe drogi
     * wejścia — `google` i `facebook` (issue #258/#259). Ten sam wywód co przy
     * `pocztaDziala()` wyżej: na produkcji, z funkcją włączoną i bez kluczy,
     * każda z nich zgłasza WŁASNY powód, więc `status` byłby `degraded`
     * niezależnie od Turnstile i ten test sprawdzałby coś innego, niż mówi
     * jego nazwa. Kluczy w testach nie ma i mieć nie musi, więc wyłączamy obie
     * drogi świadomie — tym samym przełącznikiem, którym wyłącza się je na
     * produkcji.
     */
    /**
     * Ucisza sprawdzenia `/health`, które na produkcji zapalają się z WŁASNYCH
     * powodów, niezwiązanych z Turnstile: dwie dodatkowe drogi wejścia
     * (issue #258/#259) i analityka odwiedzin (D-092). Bez tego testy niżej
     * mierzyłyby cudzą awarię.
     *
     * Analityka nie ma przełącznika „wyłącz" i mieć go nie ma (obietnica stoi
     * w polityce prywatności, nie w konfiguracji — patrz
     * `HealthController::sprawdzAnalityke()`), więc uciszamy ją jedyną
     * uczciwą drogą: udawanym tokenem.
     */
    private function wejsciaZewnetrzneWylaczone(): void
    {
        config([
            'kuking.google.wlaczone' => false,
            'kuking.facebook.wlaczone' => false,
            'kuking.analytics.cloudflare.token' => 'udawany-token-analityki',
            // Czyszczenie cache CDN (audyt G-03) ma na produkcji własny sygnał
            // `czyszczenie_cdn_wylaczone` przy pustych `CLOUDFLARE_ZONE_ID`
            // i `CLOUDFLARE_PURGE_TOKEN` — w testach ich nie ma i mieć nie
            // musi. Udawana para ucisza go, żeby ten plik mierzył Turnstile.
            'kuking.media.cdn_purge.zone_id' => 'udawana-strefa',
            'kuking.media.cdn_purge.token' => 'udawany-token-czyszczenia',
        ]);
    }

    private function wylaczTurnstile(): void
    {
        config([
            'kuking.turnstile.klucz_publiczny' => '',
            'kuking.turnstile.sekret' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $tresc
     */
    private function udawajOdpowiedz(array $tresc): void
    {
        Http::fake([KlientTurnstile::ADRES => Http::response($tresc)]);
    }

    /**
     * `assertNotSent` z filtrem po adresie, a nie `assertNothingSent`:
     * rejestracja pyta jeszcze o wyciek hasła (`Password::uncompromised`),
     * więc „zero żądań" byłoby asercją o czymś innym.
     */
    private function assertNiePytalismyCloudflare(): void
    {
        Http::assertNotSent(
            static fn (Request $zadanie): bool => $zadanie->url() === KlientTurnstile::ADRES,
        );
    }

    /**
     * Odrzucenie z braku tokenu MUSI zostawić na ekranie zdanie, z którego
     * człowiek wie, co robić dalej — a nie samą pustkę albo „pole jest
     * wymagane". Trzy sprawdzenia, bo trzy różne rzeczy potrafią zniknąć
     * osobno: sam błąd, jego treść i droga wyjścia.
     */
    private function assertKomunikatBrakuTokenu(TestResponse $odpowiedz): void
    {
        $odpowiedz->assertSessionHasErrors(Turnstile::POLE);

        $komunikat = $this->komunikatZOdpowiedzi($odpowiedz);

        $this->assertStringContainsString('wczytać sprawdzenia', $komunikat);
        $this->assertStringContainsString('wyślij go jeszcze raz', $komunikat);
        $this->assertStringContainsString(Turnstile::adresKontaktowy(), $komunikat);
    }

    private function komunikatZOdpowiedzi(TestResponse $odpowiedz): string
    {
        $odpowiedz->assertSessionHasErrors(Turnstile::POLE);

        return (string) session('errors')->first(Turnstile::POLE);
    }

    /**
     * @param  array<string, string>  $dodatkowe
     */
    private function zarejestruj(array $dodatkowe = []): TestResponse
    {
        return $this->from('/register')->post('/register', [
            'display_name' => 'Basia',
            'username' => 'basia_z_podkarpacia',
            'email' => 'basia@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
            ...$dodatkowe,
        ]);
    }
}

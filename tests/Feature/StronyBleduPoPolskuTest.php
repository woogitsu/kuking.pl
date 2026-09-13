<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use RuntimeException;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Issue #81 — angielskie strony błędów i tekst znikający po wygaśnięciu sesji.
 *
 * Ważniejsza połowa to ta druga: człowiek pisze przepis pół godziny, odchodzi
 * do garnka, wraca, klika „Opublikuj" i dostaje angielskie „Page Expired"
 * oraz pusty formularz. AGENTS.md §5 mówi wprost: poprawnie wpisane dane
 * nigdy nie znikają.
 */
final class StronyBleduPoPolskuTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private const TEKST_WPISU = 'Rosół na niedzielę, z kaczki od sąsiada. Gotował się cztery godziny i wyszedł złoty.';

    // -----------------------------------------------------------------
    //  Strony błędów
    // -----------------------------------------------------------------

    public function test_404_jest_po_polsku_i_w_layoucie_serwisu(): void
    {
        $odpowiedz = $this->get('/nie-ma-takiego-adresu-w-kuking');

        $odpowiedz->assertStatus(404);

        // Najpierw dowód, że cokolwiek się wyrenderowało.
        $this->assertGreaterThan(500, mb_strlen($odpowiedz->getContent() ?: ''));

        // NA TREŚCI EKRANU, NIE NA CAŁYM DOKUMENCIE (pułapka 1): to zdanie jest
        // też `<title>` i `<meta>` tej strony, więc asercja na całej odpowiedzi
        // przechodziła również po skasowaniu nagłówka widocznego dla człowieka.
        $this->assertStringContainsString(
            'Nie znaleźliśmy tej strony',
            $this->trescEkranu((string) $odpowiedz->getContent()),
        );
        // Belka Kuking i droga powrotu — czyli layout serwisu, nie goła strona.
        $odpowiedz->assertSee('Przejdź do treści');
        $odpowiedz->assertSee('Strona główna');

        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_403_jest_po_polsku(): void
    {
        Route::get('/_test/403', fn () => abort(403))->middleware('web');

        $odpowiedz = $this->get('/_test/403');

        $odpowiedz->assertStatus(403);

        // NA TREŚCI EKRANU, NIE NA CAŁYM DOKUMENCIE (pułapka 1b) — dokładnie
        // z tego samego powodu co przy 404 wyżej: zdanie z nagłówka jest też
        // `<title>` tej strony, więc asercja na całej odpowiedzi przechodziła
        // po skasowaniu `<h1>`. Zmierzone 12.09.2026.
        $this->assertStringContainsString(
            'Ta strona nie jest dla Ciebie',
            $this->trescEkranu((string) $odpowiedz->getContent()),
        );
        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_429_mowi_co_zrobic_po_polsku(): void
    {
        Route::get('/_test/429', fn () => abort(429))->middleware('web');

        $odpowiedz = $this->get('/_test/429');

        $odpowiedz->assertStatus(429);

        // Na treści ekranu — „Za dużo prób" jest też `<title>` tej strony
        // (pułapka 1b, zmierzone 12.09.2026: po skasowaniu `<h1>` asercja
        // na całej odpowiedzi nadal przechodziła).
        $this->assertStringContainsString(
            'Za dużo prób',
            $this->trescEkranu((string) $odpowiedz->getContent()),
        );
        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_500_jest_po_polsku_i_nie_pyta_bazy(): void
    {
        Route::get('/_test/500', fn () => throw new RuntimeException('awaria testowa'))->middleware('web');

        // Bez tego Laravel pokazałby stronę dla programisty, a nie dla człowieka.
        config(['app.debug' => false]);

        $odpowiedz = $this->get('/_test/500');

        $odpowiedz->assertStatus(500);

        // Na treści ekranu — tytuł karty przeglądarki tej strony brzmi tak
        // samo jak nagłówek (`errors/500.blade.php` podaje jedno i drugie
        // do `errors/_prosty`), więc asercja na całej odpowiedzi przechodziła
        // po podmianie samego nagłówka. Zmierzone 12.09.2026.
        $this->assertStringContainsString(
            'Coś się u nas zepsuło',
            $this->trescEkranu((string) $odpowiedz->getContent()),
        );

        // „Czegoś nie ma" zostaje na CAŁYM dokumencie — komunikat wyjątku ma
        // nie wyjść nigdzie, także w `<title>` czy w `<meta>` (pułapka 1b).
        $odpowiedz->assertDontSee('awaria testowa');
        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_503_mowi_co_zrobic_bez_obietnicy_terminu(): void
    {
        $widok = $this->view('errors.503', ['exception' => null]);

        $tresc = (string) $widok;

        $this->assertGreaterThan(200, mb_strlen($tresc));
        $this->assertStringContainsString('Spróbuj otworzyć stronę później.', $this->trescEkranu($tresc));
        $widok->assertDontSee('Wrócimy dziś');
        $this->assertStringNotContainsString('Service Unavailable', $tresc);
        $this->assertStringNotContainsString('Be right back', $tresc);
    }

    // -----------------------------------------------------------------
    //  419 — wygasła sesja nie zjada wpisanego tekstu
    // -----------------------------------------------------------------

    public function test_419_zwraca_polska_strone_z_wpisanym_tekstem(): void
    {
        $user = $this->user();

        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => 'token-sesji'])
            ->post('/dodaj/zdjecie', [
                '_token' => 'token-z-wygaslej-strony',
                'body' => self::TEKST_WPISU,
                'visibility' => 'followers',
            ]));

        $odpowiedz->assertStatus(419);

        $this->assertGreaterThan(500, mb_strlen($odpowiedz->getContent() ?: ''));

        // Na treści ekranu — nagłówek 419 jest też `<title>` tej strony
        // (pułapka 1b, zmierzone 12.09.2026: po podmianie `<h1>` asercja na
        // całej odpowiedzi nadal przechodziła).
        $this->assertStringContainsString(
            'Ta strona była otwarta zbyt długo',
            $this->trescEkranu((string) $odpowiedz->getContent()),
        );
        $odpowiedz->assertSee('Twój tekst jest na miejscu');
        $odpowiedz->assertSee('Wyślij jeszcze raz');

        // Najważniejsze: treść wpisu wróciła w formularzu.
        $odpowiedz->assertSee(self::TEKST_WPISU, escape: false);
        $this->assertStringContainsString('name="visibility"', $odpowiedz->getContent() ?: '');
        $this->assertStringContainsString('value="followers"', $odpowiedz->getContent() ?: '');
        $this->assertStringContainsString('action="'.url('/dodaj/zdjecie').'"', $odpowiedz->getContent() ?: '');

        $this->assertBezAngielskiego($odpowiedz);
    }

    public function test_419_pozwala_wyslac_wpis_ponownie_jednym_kliknieciem(): void
    {
        $user = $this->user();

        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => 'token-sesji'])
            ->post('/dodaj/zdjecie', [
                '_token' => 'token-z-wygaslej-strony',
                'body' => self::TEKST_WPISU,
                'visibility' => 'public',
            ]));

        $odpowiedz->assertStatus(419);

        $swiezyToken = $this->tokenZFormularza($odpowiedz);

        $ponowna = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => $swiezyToken])
            ->post('/dodaj/zdjecie', [
                '_token' => $swiezyToken,
                'body' => self::TEKST_WPISU,
                'visibility' => 'public',
            ]));

        $ponowna->assertRedirect();
        $ponowna->assertSessionHasNoErrors();

        $this->assertDatabaseHas('posts', [
            'author_id' => $user->getKey(),
            'body' => self::TEKST_WPISU,
        ]);
    }

    /**
     * Naprawa nie może polegać na wyłączeniu ochrony. Żądanie BEZ tokenu
     * ma dalej odbijać się od CSRF — inaczej „naprawiliśmy" 419 przez
     * otwarcie formularzy na cudze strony.
     */
    public function test_zadanie_bez_tokenu_dalej_nie_przechodzi(): void
    {
        $user = $this->user();

        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->actingAs($user)
            ->withSession(['_token' => 'token-sesji'])
            ->post('/dodaj/zdjecie', [
                'body' => 'Wpis bez tokenu w ogóle.',
                'visibility' => 'public',
            ]));

        $odpowiedz->assertStatus(419);

        $this->assertDatabaseMissing('posts', ['body' => 'Wpis bez tokenu w ogóle.']);
    }

    /**
     * Lista wyjątków od CSRF jest ZAMKNIĘTA I WYMIENIONA Z IMIENIA.
     *
     * Ten test nie zabrania dopisywania do niej niczego — zabrania robienia
     * tego BEZ DECYZJI. Każdy wpis musi mieć powód tego samego rodzaju:
     * żądanie przychodzi od kogoś, kto tokenu CSRF nie ma skąd wziąć, a nie
     * od kogoś, komu tak wygodniej.
     *
     *  * `_csp` — zgłoszenia naruszeń polityki bezpieczeństwa treści wysyła
     *    SAMA PRZEGLĄDARKA: bez sesji, bez tokenu, często z innego kontekstu
     *    niż strona. Endpoint niczego nie zapisuje i zawsze oddaje 204.
     *  * `podsumowanie/wypisz/*` — wypisanie z tygodniowego podsumowania
     *    (issue #11, D-057). Ten adres wołają GMAIL I OUTLOOK, nie
     *    przeglądarka: nagłówki `List-Unsubscribe` i `List-Unsubscribe-Post`
     *    (RFC 8058) każą klientowi pocztowemu wysłać puste `POST` prosto
     *    z widoku listu. Trasa nie zostaje przez to bez ochrony — ma
     *    `middleware('signed')`, czyli podpis kluczem aplikacji — i robi
     *    jedną rzecz, wyłącznie na korzyść właściciela skrzynki: wyłącza
     *    wysyłkę. Droga POWROTNA (`podsumowanie/wracam/*`) świadomie tu nie
     *    wchodzi, bo klika ją człowiek na naszej stronie.
     *  * `wejdz/facebook/odebranie-dostepu` — powiadomienie „ta osoba
     *    odebrała nam dostęp" wysyła SERWER FACEBOOKA (pole `Deauthorize
     *    callback URL` w panelu Meta): bez sesji, bez ciasteczka, bez
     *    żadnego kontekstu przeglądarki, więc tokenu nie ma skąd wziąć.
     *    Autentyczność potwierdza PODPIS `signed_request` liczony na
     *    sekrecie aplikacji i porównywany przez `hash_equals` — czyli
     *    dowód mocniejszy niż token z sesji, bo nie da się go wytworzyć
     *    bez sekretu (issue #259, `FacebookDeauthorizeController`).
     */
    public function test_zadna_trasa_poza_wymienionymi_nie_jest_wyjeta_spod_csrf(): void
    {
        $middleware = app(PreventRequestForgery::class);

        $klasa = new ReflectionClass(PreventRequestForgery::class);

        /** @var array<int, string> $wlasne */
        $wlasne = $klasa->getProperty('except')->getValue($middleware);
        /** @var array<int, string> $globalne */
        $globalne = $klasa->getProperty('neverVerify')->getValue();

        $wyjatki = array_values(array_unique([...$wlasne, ...$globalne]));

        $this->assertSame(
            ['_csp', 'podsumowanie/wypisz/*', 'wejdz/facebook/odebranie-dostepu'],
            $wyjatki,
            'Ktoś dopisał trasę do wyjątków od CSRF. Jeśli to świadoma decyzja, '
            .'dopisz ją do listy w komentarzu nad tym testem — razem z powodem, '
            .'dla którego żądający nie ma skąd wziąć tokenu.',
        );
    }

    /**
     * Z formularza logowania nie wraca NIC (audyt W7-04).
     *
     * Ten test mówił wcześniej: hasło nie wraca, ale nazwa użytkownika już
     * tak, „bo to nie jest dana wrażliwa". Reguła była jednak realizowana
     * jako czarna lista fragmentów nazw pól — i ta lista nie znała pól
     * `code` ani `backup_code` z ekranu drugiego składnika, więc KOD
     * ZAPASOWY do 2FA wracał do HTML-a w ukrytym polu.
     *
     * Odzyskiwanie decyduje teraz po NAZWIE TRASY
     * (`App\Support\OdzyskiwalneDane`): tylko trasy, na których człowiek
     * pisze własnymi słowami. Logowanie na nią nie wchodzi i nie ma po co —
     * login wpisuje się z pamięci, a nie pisze przez kwadrans jak przepis.
     * Utrata nazwy użytkownika przy 419 jest ceną, którą płacimy za to,
     * że lista nie musi zgadywać, jak następny formularz nazwie swój sekret.
     */
    public function test_419_nie_odklada_niczego_z_logowania(): void
    {
        $odpowiedz = $this->zPrawdziwymCsrf(fn () => $this
            ->withSession(['_token' => 'token-sesji'])
            ->post('/login', [
                '_token' => 'token-z-wygaslej-strony',
                'login' => 'basia',
                'password' => 'TajneHaslo123!',
            ]));

        $odpowiedz->assertStatus(419);

        $tresc = $odpowiedz->getContent() ?: '';

        $this->assertStringNotContainsString('TajneHaslo123!', $tresc);
        $this->assertStringNotContainsString('name="password"', $tresc);
        // I nazwa użytkownika też nie — patrz uzasadnienie nad tym testem.
        $this->assertStringNotContainsString('basia', $tresc);
    }

    // -----------------------------------------------------------------
    //  Narzędzia
    // -----------------------------------------------------------------

    /**
     * ValidateCsrfToken pomija sprawdzanie tokenu, gdy aplikacja działa
     * w środowisku „testing" (Application::runningUnitTests()). Bez tej
     * podmiany test 419 sprawdzałby własną atrapę, a nie to, co dzieje
     * się u człowieka.
     *
     * @template T
     *
     * @param  callable(): T  $czynnosc
     * @return T
     */
    private function zPrawdziwymCsrf(callable $czynnosc): mixed
    {
        $poprzednie = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            return $czynnosc();
        } finally {
            $this->app['env'] = $poprzednie;
        }
    }

    private function tokenZFormularza(TestResponse $odpowiedz): string
    {
        $znaleziono = preg_match(
            '/name="_token"\s+value="([^"]+)"/',
            $odpowiedz->getContent() ?: '',
            $dopasowanie,
        );

        $this->assertSame(1, $znaleziono, 'Formularz odzyskiwania nie ma świeżego tokenu CSRF.');

        return $dopasowanie[1];
    }

    private function assertBezAngielskiego(TestResponse $odpowiedz): void
    {
        $tresc = $odpowiedz->getContent() ?: '';

        $this->assertGreaterThan(200, mb_strlen($tresc), 'Odpowiedź jest pusta — nie ma czego sprawdzać.');

        foreach ([
            'Page Expired',
            'Not Found',
            'Server Error',
            'Forbidden',
            'Too Many Requests',
            'Service Unavailable',
            'Whoops',
            'Go Home',
        ] as $fraza) {
            $this->assertStringNotContainsString(
                $fraza,
                $tresc,
                "Na stronie błędu został angielski tekst: „{$fraza}”.",
            );
        }
    }
}

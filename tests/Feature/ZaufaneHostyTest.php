<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\RequestEmailChange;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use App\Notifications\PotwierdzenieAdresu;
use App\Notifications\PotwierdzenieNowegoAdresu;
use App\Notifications\UstawienieHaslaZamiastLinku;
use App\Notifications\UstawienieNowegoHasla;
use App\Support\ZaufaneHosty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

/**
 * Granica zaufania do nagłówka `Host` (znalezisko S2, ustalenie D-071).
 *
 * ZMIERZONY STAN PRZED TĄ ZMIANĄ — bo bez pomiaru nie da się powiedzieć,
 * czy to naprawa dziury, czy hardening:
 *
 *   `X-Forwarded-Host: attacker.invalid`  → odpowiedź 200, a `url('/przepisy')`
 *   zwracało `http://attacker.invalid/przepisy`. Ten sam host trafiał
 *   do linku resetu hasła, potwierdzenia adresu i logowania linkiem —
 *   ale WYŁĄCZNIE wtedy, gdy adres powstawał w żądaniu HTTP. Wszystkie
 *   trzy te powiadomienia są `ShouldQueue`, a na produkcji kolejka to
 *   `database` (`.railway/railway.ts`), więc adres powstaje w workerze,
 *   gdzie żądania nie ma i Laravel bierze korzeń z `APP_URL`. Zmierzone
 *   w kontekście konsoli: `https://kuking.pl/nowe-haslo/…`.
 *
 *   Jeden link NIE MIAŁ tej osłony i to jest jedyne miejsce, w którym
 *   nagłówek żądania faktycznie decydował o treści listu na produkcji:
 *   potwierdzenie ZMIANY adresu e-mail. `RequestEmailChange` buduje
 *   podpisany adres w żądaniu, więc `X-Forwarded-Host` wchodził do niego
 *   wprost.
 *
 * Czyli: dla resetu hasła i logowania linkiem to jest HARDENING (granica
 * była formalnie otwarta, ale asynchroniczna kolejka ją zasłaniała), a dla
 * zmiany adresu e-mail — naprawa realnie otwartej drogi. Nie podnosimy tego
 * wyżej, niż wyszło z pomiaru.
 */
class ZaufaneHostyTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = '/_test/hosty';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get(self::SCIEZKA, fn () => response()->json([
            'host' => request()->getHost(),
            'url' => url('/przepisy'),
        ]));
    }

    /**
     * ZAUFANE HOSTY SĄ STANEM STATYCZNYM W SYMFONY, nie własnością aplikacji
     * Laravela. `Request::setTrustedHosts()` zapisuje je do statycznego pola
     * klasy, a to pole przeżywa odświeżenie aplikacji między testami i żyje
     * do końca procesu PHPUnita. Bez tego sprzątania pierwszy test, który
     * włączy `TrustHosts`, zostawiłby listę włączoną dla wszystkich
     * następnych testów w tym samym procesie — i psuł je w miejscach
     * niezwiązanych z hostami.
     */
    protected function tearDown(): void
    {
        SymfonyRequest::setTrustedHosts([]);

        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    //  1. Obcy `Host` jest odrzucany
    // -----------------------------------------------------------------------

    public function test_zadanie_z_obcym_hostem_jest_odrzucane(): void
    {
        $odpowiedz = $this->zWlaczonymTrustHosts(
            fn () => $this->get('http://attacker.invalid'.self::SCIEZKA),
        );

        $this->assertSame(
            400,
            $odpowiedz->getStatusCode(),
            'Aplikacja odpowiedziała na host, którego nie ma na liście. Znaczy to, '
            .'że `TrustHosts` nie działa, a host z żądania może wejść do adresów '
            .'generowanych przez `url()`.',
        );
    }

    /**
     * WZORCE MUSZĄ BYĆ ZAKOTWICZONE — pułapka, którą łatwo przeoczyć.
     *
     * `Request::setTrustedHosts()` traktuje wpisy jako wyrażenia regularne
     * i dokleja im wyłącznie ograniczniki. Goły `kuking.pl` na tej liście
     * dopasowałby się więc do `kuking-pl.attacker.test` (kropka to dowolny
     * znak, a wzorzec nie jest przypięty do początku ani końca) i cała lista
     * byłaby ozdobą. Ten test oblewa, gdy ktoś zamieni `wzorce()` na
     * `nazwy()`.
     */
    public function test_host_ktory_tylko_zawiera_nasza_nazwe_jest_odrzucany(): void
    {
        $odpowiedz = $this->zWlaczonymTrustHosts(
            fn () => $this->get('http://kuking-pl.attacker.test'.self::SCIEZKA),
        );

        $this->assertSame(400, $odpowiedz->getStatusCode());
    }

    // -----------------------------------------------------------------------
    //  2. `X-Forwarded-Host` nie wpływa już na nic
    // -----------------------------------------------------------------------

    public function test_x_forwarded_host_nie_zmienia_generowanego_adresu(): void
    {
        // `X-Forwarded-Proto` jest tu po to, żeby żądanie wyglądało dokładnie
        // jak to z produkcji: przyszło przez zaufane proxy (`trustProxies(at:
        // '*')`), więc nagłówki `X-Forwarded-*` SĄ brane pod uwagę. Gdyby
        // aplikacja nie ufała proxy, ten test przechodziłby, nie sprawdzając
        // niczego — bo wtedy żaden `X-Forwarded-*` nie ma znaczenia.
        $dane = $this->get(self::SCIEZKA, [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'attacker.invalid',
        ])->assertOk()->json();

        $this->assertSame(
            'localhost',
            $dane['host'],
            'Nagłówek `X-Forwarded-Host` przebił host żądania. Znaczy to, że wrócił '
            .'do bitmaski zaufanych nagłówków w `bootstrap/app.php`.',
        );

        $this->assertStringNotContainsString('attacker.invalid', $dane['url']);
    }

    // -----------------------------------------------------------------------
    //  3. Linki w listach zawsze prowadzą na kanoniczny adres
    // -----------------------------------------------------------------------

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function listyZLinkiem(): array
    {
        return [
            'reset hasła' => ['haslo', '/nowe-haslo/'],
            // Ta sama trasa, inna treść: konto z niepotwierdzonym adresem
            // dostaje ją zamiast linku do logowania (issue #317). Osobny
            // wiersz, bo to osobna klasa powiadomienia — a gdyby ktoś zbudował
            // w niej adres `route()`-em zamiast przez `AdresKanoniczny`,
            // wiersz „reset hasła" niczego by nie zauważył.
            'hasło zamiast linku' => ['haslo_zamiast_linku', '/nowe-haslo/'],
            'potwierdzenie adresu' => ['potwierdzenie', '/potwierdz-email/'],
            'logowanie linkiem' => ['logowanie', '/logowanie/link/'],
            'zmiana adresu e-mail' => ['zmiana', '/ustawienia/e-mail/potwierdz/'],
        ];
    }

    /**
     * ASERCJA NA SAMYM LINKU, NIE NA TREŚCI LISTU — i to jest cała różnica
     * między testem a jego pozorem. Każdy z tych listów ma `kuking.pl`
     * w stopce, więc `assertStringContainsString('kuking.pl', $tresc)`
     * przechodziłby także po zepsuciu kodu, gdy link prowadzi na cudzą
     * domenę. Sprawdzamy więc, że KONKRETNY adres z przycisku zaczyna się
     * od kanonicznego korzenia.
     */
    #[DataProvider('listyZLinkiem')]
    public function test_link_w_liscie_prowadzi_na_kanoniczny_adres(string $ktory, string $sciezka): void
    {
        config(['app.url' => 'https://kuking.pl']);

        // Wchodzimy na aplikację z CUDZEGO hosta, żeby `app('request')` —
        // czyli żądanie, z którego generator adresów bierze korzeń —
        // wskazywało `attacker.invalid`. To jest ten sam stan, w jakim
        // powiadomienie renderowałoby się przy wysyłce w żądaniu.
        $this->get('http://attacker.invalid'.self::SCIEZKA);

        $link = $this->linkZListu($ktory);

        $this->assertStringStartsWith(
            'https://kuking.pl'.$sciezka,
            $link,
            "Link z listu „{$ktory}\" nie prowadzi na kanoniczny adres: {$link}",
        );
    }

    // -----------------------------------------------------------------------
    //  4. To, co ma działać, nadal działa
    // -----------------------------------------------------------------------

    /**
     * KONTROLA W DRUGĄ STRONĘ: test, który oblewa, gdy zablokuje się za dużo.
     *
     * Railway odpytuje `/health` z hosta `healthcheck.railway.app` i dopiero
     * po odpowiedzi 2xx przełącza ruch na nowy kontener. Host poza listą
     * znaczy 400, healthcheck bez 2xx i deploy, który nigdy się nie kończy —
     * czyli produkcja położona przez własne zabezpieczenie.
     */
    public function test_healthcheck_railwaya_przechodzi(): void
    {
        $odpowiedz = $this->zWlaczonymTrustHosts(
            fn () => $this->get('http://'.ZaufaneHosty::HEALTHCHECK_RAILWAY.'/health'),
        );

        $this->assertSame(
            200,
            $odpowiedz->getStatusCode(),
            'Healthcheck Railwaya dostał inną odpowiedź niż 200. Przy takim stanie '
            .'KAŻDY deploy pada na „healthcheck failed with status 400" i nie kończy '
            .'się nigdy — patrz App\Support\ZaufaneHosty.',
        );
    }

    public function test_ruch_na_kanonicznym_hoscie_i_na_www_przechodzi(): void
    {
        foreach ([ZaufaneHosty::KANONICZNY, ZaufaneHosty::WWW] as $host) {
            $odpowiedz = $this->zWlaczonymTrustHosts(
                fn () => $this->get('https://'.$host.self::SCIEZKA),
            );

            $this->assertSame(
                200,
                $odpowiedz->getStatusCode(),
                "Serwis odrzucił własny host {$host}.",
            );
        }
    }

    public function test_host_z_app_url_jest_zawsze_dopuszczony(): void
    {
        // Środowisko preview dostaje adres `*.up.railway.app` wstrzyknięty
        // do `APP_URL` przez Railway (`.railway/railway.ts`). Gdyby lista
        // hostów go nie obejmowała, każdy Pull Request miałby środowisko,
        // które odpowiada wyłącznie 400.
        config(['app.url' => 'https://kuking-pl-pr-123.up.railway.app']);

        $odpowiedz = $this->zWlaczonymTrustHosts(
            fn () => $this->get('https://kuking-pl-pr-123.up.railway.app'.self::SCIEZKA),
        );

        $this->assertSame(200, $odpowiedz->getStatusCode());
    }

    public function test_zawor_z_konfiguracji_dopuszcza_dodatkowy_host(): void
    {
        // Zawór jest jedyną drogą naprawy, gdy Railway zmieni host
        // healthchecku: wdrożenie poprawki w kodzie stoi wtedy na tym samym
        // healthchecku, którego trzeba naprawić.
        config(['proxy.dodatkowe_hosty' => ['nowy-host.railway.app']]);

        $odpowiedz = $this->zWlaczonymTrustHosts(
            fn () => $this->get('http://nowy-host.railway.app'.self::SCIEZKA),
        );

        $this->assertSame(200, $odpowiedz->getStatusCode());
    }

    public function test_lista_domyslna_nie_wymaga_zadnej_nowej_zmiennej(): void
    {
        // Wymóg z zadania: brak zmiennej środowiskowej nie może położyć
        // serwisu po deployu. Domyślna lista musi więc być kompletna sama.
        config(['proxy.dodatkowe_hosty' => []]);

        $this->assertContains(ZaufaneHosty::HEALTHCHECK_RAILWAY, ZaufaneHosty::nazwy());
        $this->assertContains(ZaufaneHosty::KANONICZNY, ZaufaneHosty::nazwy());
        $this->assertContains(ZaufaneHosty::WWW, ZaufaneHosty::nazwy());
    }

    // -----------------------------------------------------------------------
    //  Narzędzia
    // -----------------------------------------------------------------------

    /**
     * `TrustHosts` Laravela sam się wyłącza w środowisku `local` i pod
     * PHPUnitem (`TrustHosts::shouldSpecifyTrustedHosts()`), żeby nie
     * przeszkadzać w pracy na `127.0.0.1` i na losowych portach. Testy
     * chodzą właśnie pod PHPUnitem, więc bez podmiany środowiska sprawdzałyby
     * middleware, który nic nie robi — i przechodziłyby też wtedy, gdyby
     * `trustHosts` w `bootstrap/app.php` w ogóle nie było.
     *
     * @template T
     *
     * @param  callable(): T  $co
     * @return T
     */
    private function zWlaczonymTrustHosts(callable $co): mixed
    {
        $poprzednie = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            return $co();
        } finally {
            $this->app['env'] = $poprzednie;
            SymfonyRequest::setTrustedHosts([]);
        }
    }

    private function linkZListu(string $ktory): string
    {
        $odbiorca = $this->user('adresat', ['email' => 'adresat@example.test']);

        return match ($ktory) {
            'haslo' => $this->linkZWiadomosci(
                (new UstawienieNowegoHasla('token-testowy'))->toMail($odbiorca),
            ),
            'haslo_zamiast_linku' => $this->linkZWiadomosci(
                (new UstawienieHaslaZamiastLinku('token-testowy'))->toMail($odbiorca),
            ),
            'potwierdzenie' => $this->linkZWiadomosci(
                (new PotwierdzenieAdresu)->toMail($odbiorca),
            ),
            'logowanie' => $this->linkZWiadomosci(
                (new LinkDoLogowania('token-testowy'))->toMail($odbiorca),
            ),
            'zmiana' => $this->linkZeZmianyAdresu($odbiorca),
            default => throw new \InvalidArgumentException($ktory),
        };
    }

    private function linkZeZmianyAdresu(User $odbiorca): string
    {
        Notification::fake();

        app(RequestEmailChange::class)->handle($odbiorca, 'nowy@example.test');

        $link = null;

        Notification::assertSentOnDemand(
            PotwierdzenieNowegoAdresu::class,
            function (PotwierdzenieNowegoAdresu $powiadomienie, array $kanaly, object $adresat) use (&$link): bool {
                $link = $this->linkZWiadomosci($powiadomienie->toMail($adresat));

                return true;
            },
        );

        $this->assertIsString($link, 'Z listu o zmianie adresu nie dało się wyjąć linku.');

        return $link;
    }

    private function linkZWiadomosci(MailMessage $wiadomosc): string
    {
        $link = $wiadomosc->viewData['linkUrl'] ?? null;

        $this->assertIsString($link, 'List nie ma pola `linkUrl` — zmienił się kształt widoku.');

        return $link;
    }
}

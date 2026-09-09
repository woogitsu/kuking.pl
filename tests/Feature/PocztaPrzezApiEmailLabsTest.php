<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Poczta\BrakKonfiguracjiEmailLabs;
use App\Poczta\OdmowaEmailLabs;
use App\Poczta\TransportEmailLabs;
use App\Support\Poczta;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Throwable;

/**
 * Wysyłka przez API HTTPS EmailLabs — `App\Poczta\TransportEmailLabs`.
 *
 * DLACZEGO TE TESTY NIE ŁĄCZĄ SIĘ Z DOSTAWCĄ
 * Bo test, który wymaga prawdziwego klucza i prawdziwego internetu, przestaje
 * chodzić na CI i po tygodniu nikt go nie uruchamia. Podstawiamy klienta HTTP
 * (`Http::fake()`) i sprawdzamy dokładnie te dwie rzeczy, które są nasze:
 * KSZTAŁT ŻĄDANIA (czy wysyłamy to, czego chce udokumentowane API) i REAKCJĘ
 * NA ODPOWIEDŹ (czy porażka na pewno wywraca zadanie i czy nie wynosi danych).
 *
 * Czego te testy NIE dowodzą: że EmailLabs przyjmie takie żądanie na żywym
 * koncie. Tego nie da się sprawdzić bez klucza — mówi o tym wprost opis PR-a
 * i `docs/infra/POCZTA_URUCHOMIENIE.md` §2A krok 5.
 */
class PocztaPrzezApiEmailLabsTest extends TestCase
{
    /** Wartości-atrapy, ale w kształcie prawdziwych — po to, żeby dało się ich szukać w komunikatach błędów. */
    private const KLUCZ_APLIKACJI = 'tajny-klucz-aplikacji-do-testu';

    private const KLUCZ_AUTORYZACJI = 'tajny-klucz-autoryzacyjny-do-testu';

    private const ADRES_API = 'https://api.emaillabs.io/v2.1/email';

    /** Zdanie, którego ma NIE być w żadnym komunikacie błędu. */
    private const TRESC_LISTU = 'Poufny akapit z listu, numer 42';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'emaillabs',
            'services.emaillabs.key' => self::KLUCZ_APLIKACJI,
            'services.emaillabs.secret' => self::KLUCZ_AUTORYZACJI,
            'services.emaillabs.smtp_account' => '1.kuking.smtp',
            'services.emaillabs.endpoint' => self::ADRES_API,
            'services.emaillabs.tracking' => false,
        ]);

        // Konfiguracja mailera jest zapamiętywana po pierwszym użyciu, więc
        // bez tego drugi test w klasie dostałby transport z ustawieniami
        // pierwszego.
        Mail::purge('emaillabs');
    }

    public function test_sterownik_emaillabs_jest_zarejestrowany(): void
    {
        $this->assertInstanceOf(
            TransportEmailLabs::class,
            Mail::mailer('emaillabs')->getSymfonyTransport(),
            'MAIL_MAILER=emaillabs nie buduje naszego transportu — sprawdź `App\Providers\PocztaServiceProvider` '
            .'i wpis `emaillabs` w `config/mail.php`.',
        );
    }

    public function test_zadanie_ma_ksztalt_z_dokumentacji_api(): void
    {
        Http::fake([self::ADRES_API => $this->odpowiedzSukcesu()]);

        Mail::raw(self::TRESC_LISTU, function (Message $wiadomosc): void {
            $wiadomosc->to('basia@wp.pl', 'Basia Kowalska')->subject('Ustaw nowe hasło');
        });

        Http::assertSent(function (Request $zadanie): bool {
            $tresc = $zadanie->data();

            $this->assertSame(self::ADRES_API, $zadanie->url());
            $this->assertSame('POST', $zadanie->method());

            // Uwierzytelnienie DWOMA nagłówkami — patrz
            // https://vercom.gitbook.io/emaillabs-api-docs/authentication
            $this->assertSame(self::KLUCZ_APLIKACJI, $zadanie->header('Application-Key')[0] ?? null);
            $this->assertSame(self::KLUCZ_AUTORYZACJI, $zadanie->header('Authorization')[0] ?? null);
            $this->assertStringContainsString('application/json', $zadanie->header('Content-Type')[0] ?? '');

            // Pola wymagane przez `EmailObject` ze specyfikacji OpenAPI.
            $this->assertSame('1.kuking.smtp', $tresc['smtpAccount']);
            $this->assertSame('Ustaw nowe hasło', $tresc['subject']);
            $this->assertSame((string) config('mail.from.address'), $tresc['from']['email']);
            $this->assertSame('basia@wp.pl', $tresc['to'][0]['email']);
            $this->assertSame('Basia Kowalska', $tresc['to'][0]['name']);
            $this->assertSame(self::TRESC_LISTU, trim((string) $tresc['content']['text']));

            return true;
        });
    }

    /**
     * Śledzenie odnośników jest domyślnie wyłączone. To nie jest kosmetyka:
     * przy włączonym EmailLabs podmienia link do zmiany hasła na własny adres
     * przekierowujący, a list z linkiem prowadzącym pod obcą domenę to dla
     * osoby 60+ dokładnie ten kształt, przed którym ostrzegają banki.
     */
    public function test_domyslnie_wylaczamy_sledzenie_odnosnikow(): void
    {
        Http::fake([self::ADRES_API => $this->odpowiedzSukcesu()]);

        $this->wyslijProbny();

        Http::assertSent(function (Request $zadanie): bool {
            $this->assertSame('1', $zadanie->data()['headers']['X-TRACKING-OFF'] ?? null);

            return true;
        });

        config(['services.emaillabs.tracking' => true]);
        Mail::purge('emaillabs');
        Http::fake([self::ADRES_API => $this->odpowiedzSukcesu()]);

        $this->wyslijProbny();

        Http::assertSent(function (Request $zadanie): bool {
            $this->assertArrayNotHasKey('X-TRACKING-OFF', $zadanie->data()['headers'] ?? []);

            return true;
        });
    }

    /**
     * Kopia ukryta NIE MOŻE trafić do widocznego pola `to`. Koperta Symfony
     * niesie komplet odbiorców razem, a API chce trzy osobne listy — bez
     * odejmowania odbiorca kopii ukrytej pokazałby się wszystkim pozostałym.
     */
    public function test_kopia_i_kopia_ukryta_ida_osobnymi_polami(): void
    {
        Http::fake([self::ADRES_API => $this->odpowiedzSukcesu()]);

        Mail::raw(self::TRESC_LISTU, function (Message $wiadomosc): void {
            $wiadomosc->to('basia@wp.pl')
                ->cc('jawna@o2.pl')
                ->bcc('ukryta@interia.pl')
                ->subject('Temat');
        });

        Http::assertSent(function (Request $zadanie): bool {
            $tresc = $zadanie->data();

            $this->assertSame(['basia@wp.pl'], array_column($tresc['to'], 'email'));
            $this->assertSame(['jawna@o2.pl'], array_column($tresc['cc'], 'email'));
            $this->assertSame(['ukryta@interia.pl'], array_column($tresc['bcc'], 'email'));

            return true;
        });
    }

    public function test_zalacznik_idzie_w_base64(): void
    {
        Http::fake([self::ADRES_API => $this->odpowiedzSukcesu()]);

        Mail::raw(self::TRESC_LISTU, function (Message $wiadomosc): void {
            $wiadomosc->to('basia@wp.pl')
                ->subject('Twoje dane są gotowe')
                ->attachData('zawartość paczki', 'dane.txt', ['mime' => 'text/plain']);
        });

        Http::assertSent(function (Request $zadanie): bool {
            $zalacznik = $zadanie->data()['attachments'][0];

            $this->assertSame('dane.txt', $zalacznik['fileName']);
            $this->assertSame('text/plain', $zalacznik['fileMime']);
            $this->assertSame('zawartość paczki', base64_decode((string) $zalacznik['fileContent'], true));

            return true;
        });
    }

    /** Odpowiedź z błędem MUSI wywrócić wysyłkę. Cicha porażka jest tu najgorsza z możliwych. */
    public function test_blad_api_wywraca_wysylke(): void
    {
        Http::fake([self::ADRES_API => Http::response([
            'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0, 'status' => 400, 'uniqId' => 'abc123'],
            'errors' => [[
                'title' => 'Invalid parameter',
                'message' => 'Parameter to is invalid: basia@wp.pl',
                'code' => 'E-1-002',
                'meta' => ['parameter' => 'to', 'value' => 'basia@wp.pl'],
            ]],
        ], 400)]);

        $wyjatek = $this->zlapPrzyWysylce();

        $this->assertInstanceOf(OdmowaEmailLabs::class, $wyjatek);
        $this->assertStringContainsString('HTTP 400', $wyjatek->getMessage());
        $this->assertStringContainsString('E-1-002', $wyjatek->getMessage());
        $this->assertStringContainsString('abc123', $wyjatek->getMessage());
        $this->assertStringContainsString('pole: to', $wyjatek->getMessage());
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Komunikat wyjątku wychodzi dalej, niż się wydaje: do `failed_jobs`, do
     * Sentry, do zgłoszenia błędu. Audyt A6-01 znalazł dokładnie taki wyciek
     * w `WebhookBleduHandler` — komunikat `QueryException` niósł adres e-mail
     * i hash hasła, bo zbudował go sterownik bazy, nie my. Tutaj tekst błędu
     * buduje dostawca i jego `errors[].message` cytuje wprost wartość, którą
     * odrzucił.
     */
    public function test_komunikat_bledu_nie_niesie_danych_osobowych_ani_kluczy(): void
    {
        Http::fake([self::ADRES_API => Http::response([
            'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0, 'status' => 400, 'uniqId' => 'abc123'],
            'errors' => [[
                'title' => 'Rejected recipient basia@wp.pl',
                'message' => 'Address basia@wp.pl is blacklisted; key '.self::KLUCZ_AUTORYZACJI,
                'code' => 'E-1-002',
                'meta' => ['parameter' => 'to', 'value' => 'basia@wp.pl'],
            ]],
        ], 400)]);

        $wyjatek = $this->zlapPrzyWysylce();

        $this->assertNotNull($wyjatek);
        $komunikat = $wyjatek->getMessage();

        $this->assertStringNotContainsString('basia@wp.pl', $komunikat, 'Adres odbiorcy wyszedł w komunikacie błędu.');
        $this->assertStringNotContainsString('blacklisted', $komunikat, '`errors[].message` od dostawcy nie ma prawa tu trafić.');
        $this->assertStringNotContainsString(self::KLUCZ_AUTORYZACJI, $komunikat, 'Klucz API wyszedł w komunikacie błędu.');
        $this->assertStringNotContainsString(self::KLUCZ_APLIKACJI, $komunikat, 'Klucz aplikacji wyszedł w komunikacie błędu.');
        $this->assertStringNotContainsString('Ustaw nowe hasło', $komunikat, 'Temat listu wyszedł w komunikacie błędu.');
        $this->assertStringNotContainsString(self::TRESC_LISTU, $komunikat, 'Treść listu wyszła w komunikacie błędu.');

        // Sam tytuł błędu zostaje, ale z wyciętym adresem — diagnostyka bez PII.
        $this->assertStringContainsString('[adres]', $komunikat);
    }

    /**
     * HTTP 207 to „część adresatów przyjęta". Przy naszych listach adresat
     * jest jeden, więc „część" znaczy „żaden" — i musi to być porażka, mimo
     * że kod odpowiedzi jest z rodziny 2xx.
     */
    public function test_czesciowe_przyjecie_tez_jest_porazka(): void
    {
        Http::fake([self::ADRES_API => Http::response([
            'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0, 'status' => 207, 'uniqId' => 'xyz789'],
            'data' => [],
            'errors' => [['title' => 'Recipient rejected', 'message' => 'x', 'code' => 'E-1-007']],
        ], 207)]);

        $this->assertInstanceOf(OdmowaEmailLabs::class, $this->zlapPrzyWysylce());
    }

    /** HTTP 200 bez ani jednej przyjętej wiadomości to też porażka, choć wygląda jak sukces. */
    public function test_dwiescie_bez_przyjetych_wiadomosci_jest_porazka(): void
    {
        Http::fake([self::ADRES_API => Http::response([
            'meta' => ['numberOfErrors' => 0, 'numberOfData' => 0, 'status' => 200, 'uniqId' => 'nic000'],
            'data' => [],
        ], 200)]);

        $wyjatek = $this->zlapPrzyWysylce();

        $this->assertInstanceOf(OdmowaEmailLabs::class, $wyjatek);
        $this->assertStringContainsString('numberOfData', $wyjatek->getMessage());
    }

    /** Odpowiedź w nieznanym kształcie (np. strona błędu proxy) to brak wiedzy, a nie sukces. */
    public function test_odpowiedz_bez_sekcji_meta_jest_porazka(): void
    {
        Http::fake([self::ADRES_API => Http::response('<html>OK</html>', 200)]);

        $this->assertInstanceOf(OdmowaEmailLabs::class, $this->zlapPrzyWysylce());
    }

    public function test_sukces_nie_rzuca_i_zapisuje_identyfikator_wiadomosci(): void
    {
        Http::fake([self::ADRES_API => $this->odpowiedzSukcesu()]);

        $wyslana = Mail::raw(self::TRESC_LISTU, function (Message $wiadomosc): void {
            $wiadomosc->to('basia@wp.pl')->subject('Ustaw nowe hasło');
        });

        $this->assertNotNull($wyslana);
        $this->assertSame('kuking0001@kuking.pl', $wyslana->getSymfonySentMessage()?->getMessageId());
    }

    /**
     * Pusty klucz nie może udawać działającej poczty. To jest ta sama klasa
     * awarii, co `MAIL_SCHEME=tls` z 9 września: wszystko wygląda dobrze,
     * a transportu nie da się nawet zbudować.
     */
    public function test_bez_kluczy_poczta_nie_dziala_i_mowi_ktorej_zmiennej_brakuje(): void
    {
        config(['services.emaillabs.key' => '']);
        Mail::purge('emaillabs');

        $this->assertFalse(Poczta::dziala(), 'Pusty klucz API, a klasa twierdzi, że poczta wychodzi.');
        $this->assertStringContainsString('EMAILLABS_APP_KEY', (string) Poczta::przeszkoda());

        config(['services.emaillabs.key' => self::KLUCZ_APLIKACJI, 'services.emaillabs.smtp_account' => '']);
        Mail::purge('emaillabs');

        $this->assertFalse(Poczta::dziala());
        $this->assertStringContainsString('EMAILLABS_SMTP_ACCOUNT', (string) Poczta::przeszkoda());
    }

    /** Komunikat o brakującej konfiguracji podaje NAZWĘ zmiennej, nigdy jej wartość. */
    public function test_komunikat_o_brakach_nie_pokazuje_wartosci_kluczy(): void
    {
        config(['services.emaillabs.smtp_account' => '']);
        Mail::purge('emaillabs');

        $wyjatek = null;

        try {
            Mail::mailer('emaillabs')->getSymfonyTransport();
        } catch (Throwable $e) {
            $wyjatek = $e;
        }

        $this->assertInstanceOf(BrakKonfiguracjiEmailLabs::class, $wyjatek);
        $this->assertStringNotContainsString(self::KLUCZ_APLIKACJI, $wyjatek->getMessage());
        $this->assertStringNotContainsString(self::KLUCZ_AUTORYZACJI, $wyjatek->getMessage());
    }

    /** Adres API tylko po HTTPS — przez zwykły HTTP klucz i treść listu szłyby otwartym tekstem. */
    public function test_adres_api_po_http_jest_odrzucany(): void
    {
        config(['services.emaillabs.endpoint' => 'http://api.emaillabs.io/v2.1/email']);
        Mail::purge('emaillabs');

        $this->expectException(BrakKonfiguracjiEmailLabs::class);

        Mail::mailer('emaillabs')->getSymfonyTransport();
    }

    /** Komplet ustawień = `Poczta::dziala()` mówi „tak" i nie ma przeszkody. */
    public function test_z_pelna_konfiguracja_poczta_dziala(): void
    {
        $this->assertTrue(Poczta::dziala());
        $this->assertNull(Poczta::przeszkoda());
    }

    /** `Http::response()` oddaje obietnicę Guzzle, nie gotową odpowiedź — stąd ten typ. */
    private function odpowiedzSukcesu(): PromiseInterface
    {
        return Http::response([
            'meta' => ['numberOfErrors' => 0, 'numberOfData' => 1, 'status' => 200, 'uniqId' => 'ok12345'],
            'data' => [[
                'subject' => 'Ustaw nowe hasło',
                'smtpAccount' => '1.kuking.smtp',
                'to' => [['email' => 'basia@wp.pl', 'messageId' => 'kuking0001@kuking.pl']],
                'status' => 'sent',
            ]],
        ], 200);
    }

    private function wyslijProbny(): void
    {
        Mail::raw(self::TRESC_LISTU, function (Message $wiadomosc): void {
            $wiadomosc->to('basia@wp.pl')->subject('Ustaw nowe hasło');
        });
    }

    private function zlapPrzyWysylce(): ?Throwable
    {
        try {
            $this->wyslijProbny();
        } catch (Throwable $e) {
            return $e;
        }

        return null;
    }
}

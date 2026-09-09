<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Kanał `blad_webhook` (`config/logging.php`) — powiadomienie o błędzie 500
 * na Slacku/Discordzie, na czas, zanim da się zainstalować Sentry
 * (`docs/infra/MONITORING_BLEDOW.md`).
 *
 * DWIE RZECZY, KTÓRE TEN TEST MUSI UDOWODNIĆ
 *
 *   1. Bez `LOG_BLAD_WEBHOOK_URL` kanał jest CAŁKOWICIE martwy: zero żądań
 *      HTTP, zero wyjątku z SAMEGO mechanizmu powiadamiania. To jest warunek
 *      konieczny — lokalnie, w CI i w każdym innym teście tej zmiennej nikt
 *      nie ustawia, więc strona ma działać dokładnie tak, jak działa dziś.
 *
 *   2. Gdy zmienna JEST ustawiona, treść wysłana na webhook nie zawiera
 *      argumentu wywołania, które doprowadziło do wyjątku. `$e->getTrace()`
 *      potrafi zawierać dokładne wartości przekazane do funkcji — adres
 *      e-mail, treść formularza, hasło podane wprost. AGENTS.md §7 zakazuje
 *      PII w logach, a to jest jedyny log w serwisie, który wychodzi do
 *      ZEWNĘTRZNEJ usługi.
 *
 * DLACZEGO `Http::fake()`, A NIE `Log::spy()`
 * `Log::spy()`/`Log::listen()` widziałyby wywołanie `Log::channel(...)
 * ->error(...)` z `bootstrap/app.php` — czyli SUROWY obiekt wyjątku, ZANIM
 * `App\Logging\WebhookBleduHandler` przytnie go do bezpiecznej treści. Test na
 * tym poziomie nie udowodniłby niczego o bajtach, które NAPRAWDĘ wychodzą
 * z serwisu. `Http::fake()` przechwytuje dokładnie tę granicę — dlatego
 * kanał jest zbudowany na `Illuminate\Support\Facades\Http`, a nie na
 * wbudowanym `Monolog\Handler\SlackWebhookHandler` (ten łączy się przez
 * `curl_init()` z pominięciem klienta HTTP Laravela i nie da się go tak
 * przetestować — patrz komentarz `App\Logging\WebhookBleduLogger`).
 */
final class BladTrafiaNaWebhookBezDanychOsobowychTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_WEBHOOKA = 'https://discord.example.test/api/webhooks/000/tajny-token/slack';

    /**
     * Trasa istnieje TYLKO w pamięci tego testu (ten sam wzorzec, co
     * `StronyBleduPoPolskuTest::test_500_jest_po_polsku_i_nie_pyta_bazy`) —
     * po to, żeby przejść PRAWDZIWĄ, całą ścieżkę Laravela: routing → wyjątek
     * → `Handler::report()` → `$exceptions->report()` z `bootstrap/app.php`
     * → kanał `blad_webhook`. Wywołanie samego `report()` z pominięciem
     * routingu nie dowodziłoby, że strona dalej odpowiada normalnie.
     */
    private function zarejestrujTraseWybuchajacaZParametrem(string $tajnyEmail): void
    {
        Route::get('/_test/blad-webhook', function () use ($tajnyEmail): void {
            $wywolajZParametrem = function (string $email): never {
                throw new RuntimeException('Testowa awaria do testu webhooka błędów.');
            };

            $wywolajZParametrem($tajnyEmail);
        })->middleware('web');
    }

    public function test_bez_zmiennej_srodowiskowej_kanal_jest_martwy_i_strona_dziala(): void
    {
        config(['logging.channels.blad_webhook.url' => null, 'app.debug' => false]);

        Http::fake();

        $this->zarejestrujTraseWybuchajacaZParametrem('ktos@example.com');

        // Strona 500 — tyle samo, co dziś, bez tej funkcji. Awaria SAMEGO
        // mechanizmu powiadamiania nie może zamienić strony błędu w coś
        // gorszego (nieobsłużony wyjątek zamiast czytelnej strony).
        $this->get('/_test/blad-webhook')->assertStatus(500);

        Http::assertNothingSent();
    }

    public function test_z_ustawiona_zmienna_blad_trafia_na_webhook_bez_argumentow_wywolania(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA, 'app.debug' => false]);

        Http::fake();

        $tajnyEmail = 'sekret+test@example.com';
        $this->zarejestrujTraseWybuchajacaZParametrem($tajnyEmail);

        $this->get('/_test/blad-webhook')->assertStatus(500);

        Http::assertSent(function ($request) use ($tajnyEmail): bool {
            if ($request->url() !== self::ADRES_WEBHOOKA) {
                return false;
            }

            $tresc = (string) ($request['text'] ?? '');

            // Kontrola metody pomiaru: patrzymy na TĘ awarię, nie na jakąś
            // inną. Po usunięciu komunikatu (A6-01) rolę „to na pewno ten
            // wyjątek" niosą nazwa klasy i wzorzec trasy.
            $this->assertStringContainsString('RuntimeException', $tresc);
            $this->assertStringContainsString('GET /_test/blad-webhook', $tresc);

            // KOMUNIKAT WYJĄTKU NIE WYCHODZI — zmiana z 9 września. Przy
            // `RuntimeException` był nieszkodliwy, ale przy `QueryException`
            // niesie e-mail i hash hasła (osobny przypadek niżej). Zasada
            // musi więc obowiązywać zawsze, a nie „gdy tekst wygląda groźnie".
            $this->assertStringNotContainsString('Testowa awaria do testu webhooka błędów.', $tresc);

            // Właściwy dowód wymogu #3: argument wywołania, które
            // doprowadziło do wyjątku, NIE WYCHODZI z serwisu.
            $this->assertStringNotContainsString($tajnyEmail, $tresc);

            return true;
        });
    }

    /**
     * PRAWDZIWY `QueryException` z PostgreSQL — to jest znalezisko A6-01
     * i jedyny przypadek w tym pliku, który tamtą usterkę odtwarza.
     *
     * Pozostałe przypadki rzucają `RuntimeException` z komunikatem, który
     * sami napisaliśmy — i właśnie dlatego przez trzy dni nic nie wykryły.
     * Zakładały to, co wprost stało w komentarzu klasy: „komunikat wyjątku to
     * tekst napisany przez kogoś z nas w kodzie". Przy błędzie bazy komunikat
     * pisze STEROWNIK i wkłada w niego SQL razem z wartościami — adres e-mail
     * i hash hasła.
     *
     * Nie udajemy tego wyjątku ręcznie: wywołujemy prawdziwe naruszenie
     * unikalności przez prawdziwą trasę HTTP, na PostgreSQL. Ręcznie
     * zbudowany `new QueryException(...)` miałby komunikat, który sami
     * wpisaliśmy — czyli znowu badalibyśmy własne założenie zamiast
     * zachowania sterownika.
     */
    public function test_blad_sql_nie_wynosi_maila_ani_hasha_hasla(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA, 'app.debug' => false]);

        Http::fake();

        $email = 'ktos-z-kolizja@example.com';
        $hash = Hash::make('haslo-ktorego-nikt-nie-ma-prawa-zobaczyc');

        $pierwszy = User::factory()->create(['email' => $email]);
        DB::table('users')->where('id', $pierwszy->getKey())->update(['password' => $hash]);

        Route::get('/_test/blad-sql', function (): void {
            // Kopia istniejącego wiersza z nowym identyfikatorem — czyli
            // kolizja na `users_email_unique`. Kopiujemy CAŁY wiersz, żeby
            // sterownik miał w SQL-u i e-mail, i hash, dokładnie tak jak przy
            // prawdziwym podwójnym zapisie.
            $wiersz = (array) DB::table('users')->first();
            $wiersz['id'] = (string) Str::uuid();

            DB::table('users')->insert($wiersz);
        })->middleware('web');

        $this->get('/_test/blad-sql')->assertStatus(500);

        Http::assertSent(function ($request) use ($email, $hash): bool {
            if ($request->url() !== self::ADRES_WEBHOOKA) {
                return false;
            }

            $tresc = (string) ($request['text'] ?? '');

            // Kontrola metody pomiaru — bez niej wszystko niżej przechodziłoby
            // także wtedy, gdyby na webhook poszedł jakiś zupełnie inny błąd
            // albo gdyby baza wcale nie odrzuciła zapisu.
            // Laravel zwęża `QueryException` do podklasy
            // `UniqueConstraintViolationException`, więc kontrolą jest sama
            // przestrzeń nazw warstwy bazy — nie konkretna klasa liścia,
            // która przy kolejnym wydaniu frameworka może się nazywać inaczej.
            $this->assertStringContainsString('Illuminate\\Database\\', $tresc,
                'Na webhook nie poszedł błąd warstwy bazy — ten przypadek nie bada wtedy niczego.');
            // WŁAŚCIWY DOWÓD A6-01 — i stoi PRZED resztą świadomie. Na starym
            // kodzie ten przypadek ma padać na wycieku, a nie na braku pola
            // z nowego formatu; inaczej „test jest czerwony przed łatką"
            // znaczyłoby tylko tyle, że format się zmienił.
            $this->assertStringNotContainsString($email, $tresc,
                'Adres e-mail człowieka wyszedł na zewnętrzny webhook w treści komunikatu błędu SQL.');
            $this->assertStringNotContainsString($hash, $tresc,
                'Hash hasła wyszedł na zewnętrzny webhook w treści komunikatu błędu SQL.');

            // Bez tego dwa sprawdzenia wyżej przechodziłyby również wtedy,
            // gdyby wiadomość niosła CAŁY SQL, tylko z innymi wartościami.
            $this->assertStringNotContainsString('insert into', mb_strtolower($tresc),
                'Na webhook poszedł SQL zapytania. Nawet bez tych konkretnych wartości niesie strukturę i treść żądania.');

            // Dopiero teraz: czy z alarmu zostało coś użytecznego. SQLSTATE
            // jest po usunięciu komunikatu najcenniejszą pojedynczą
            // informacją — bez niego zostałaby sama nazwa klasy.
            $this->assertStringContainsString('kod: 23505', $tresc,
                'Brakuje SQLSTATE naruszenia unikalności. Alarm bez niego mówi tylko „błąd bazy", a to za mało, żeby cokolwiek z nim zrobić.');

            return true;
        });
    }

    /**
     * Filtr POZIOMU samego kanału (`'level' => 'error'` w
     * `config/logging.php`) — sprawdzony NIEZALEŻNIE od tego, co Laravel
     * raportuje, żeby wykryć regresję nawet gdyby ktoś kiedyś zaczął pisać
     * na ten kanał z innego miejsca niż `bootstrap/app.php`.
     */
    public function test_wpisy_ponizej_poziomu_error_nie_trafiaja_na_webhook(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);

        Http::fake();

        Log::channel('blad_webhook')->info('To nie powinno wyjść na zewnątrz.');
        Log::channel('blad_webhook')->warning('To też nie.');

        Http::assertNothingSent();
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Moderation\KolejkiPanelu;
use App\Models\Appeal;
use App\Models\ContactMessage;
use App\Models\Report;
use App\Support\KomunikatZaDuzaWysylka;
use App\Support\OdmianaWalidacji;
use App\Support\Sesja\UchwytSesjiBezPelnegoAdresu;
use App\Support\Storage\DyskR2;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->wlaczTrybScislyEloquentPozaProdukcja();

        // Sterownik dysku `r2` — zapis do Cloudflare R2 BEZ nagłówka
        // `x-amz-acl` (issue #120, audyt G-02).
        //
        // Rejestracja stoi na początku `boot()`, przed czymkolwiek, co mogłoby
        // sięgnąć po dysk: `Storage::extend()` musi być znane wcześniej, niż
        // pierwszy `Storage::disk('r2')` zbuduje adapter. Dyski są tworzone
        // leniwie i zapamiętywane, więc rejestracja po pierwszym użyciu nie
        // zmieniłaby już nic — a wyglądałaby, jakby zmieniała.
        //
        // DLACZEGO NIE `register()`: rozstrzyganie fasady `Storage` w metodzie
        // `register()` wymusza zbudowanie menedżera dysków, zanim inni
        // dostawcy zdążą się zarejestrować. `boot()` jest właściwym miejscem
        // i to zaleca dokumentacja Laravela.
        Storage::extend('r2', fn ($app, array $konfiguracja) => DyskR2::utworz($konfiguracja));

        // Wbudowany `s3` (`r2_kopie`, `s3`) za tą samą kontrolą adresu
        // magazynu co `r2` (D-255): zły `AWS_ENDPOINT` → dysk się nie buduje.
        Storage::extend('s3', fn ($app, array $konfiguracja) => DyskR2::utworzS3($app, $konfiguracja));

        // Audyt A31: gdy ciało żądania przekracza `post_max_size`
        // z `docker/php.ini`, Laravel SAM już to wykrywa (globalny,
        // wbudowany middleware `ValidatePostSize`, uruchamiany przed
        // sesją i przed CSRF) i rzuca `PostTooLargeException`. Nie trzeba
        // (i nie wolno) pisać drugiej, własnej wersji tego porównania —
        // patrz `KomunikatZaDuzaWysylka`. Brakowało tylko polskiego,
        // konkretnego renderowania tego wyjątku — domyślnie dostawał
        // ogólną stronę błędu frameworka.
        //
        // Rejestracja `renderable()` normalnie idzie przez `withExceptions()`
        // w `bootstrap/app.php`, którego celowo nie ruszamy (poza zakresem
        // audytu) — `renderable()` daje się jednak dopisać do tego samego,
        // już skonfigurowanego handlera z DOWOLNEGO miejsca startowego
        // aplikacji, więc robimy to stąd.
        $this->app->make(ExceptionHandler::class)->renderable(
            function (PostTooLargeException $e, Request $request) {
                // Klient oczekujący JSON-a (API, fetch()) ma dostać JSON,
                // nie stronę HTML — `return null` oddaje sprawę domyślnej,
                // już istniejącej ścieżce renderowania Laravela.
                if ($request->expectsJson()) {
                    return null;
                }

                return KomunikatZaDuzaWysylka::odpowiedz($request);
            },
        );

        // Issue #86: `lang/pl/validation.php` nie potrafi sam odmienić
        // rzeczownika przy `:min`/`:max` (Laravel woła zwykłe `Lang::get()`,
        // nie `trans_choice()`), więc komunikaty size'owe niosą tam wzorzec
        // `:odmiana(jeden|kilka|wiele)`. Ten hak jest jedynym miejscem, które
        // go rozumie — i jedynym, które w ogóle PODSTAWIA `:min`/`:max`
        // rejestracja własnego replacera dla reguły wyłącza wbudowaną
        // podmianę Laravela dla TEJ reguły, więc musimy zrobić to sami
        // (patrz komentarz w `OdmianaWalidacji`).
        Validator::replacer(
            'min',
            fn (string $message, string $attribute, string $rule, array $parameters): string => OdmianaWalidacji::podstaw($message, (int) ($parameters[0] ?? 0)),
        );
        Validator::replacer(
            'max',
            fn (string $message, string $attribute, string $rule, array $parameters): string => OdmianaWalidacji::podstaw($message, (int) ($parameters[0] ?? 0)),
        );

        $this->zdejmijAdresZLinkuResetu();

        $this->odswiezajLicznikiKolejek();

        $this->zapisujWSesjiTylkoZgrubnyAdres();
    }

    /**
     * SESJA ZAPISUJE ZGRUBNY ADRES IP, NIE DOKŁADNY (RZ-01, 21.09.2026).
     *
     * `Session::extend()` z nazwą JUŻ ISTNIEJĄCEGO sterownika nie dokłada
     * piątego wariantu obok `database` — podmienia go. `Manager::createDriver()`
     * patrzy najpierw w `customCreators`, a dopiero potem szuka metody
     * `createDatabaseDriver()`. Dzięki temu nie trzeba ruszać `SESSION_DRIVER`
     * ani w `.env.example`, ani w `.railway/railway.ts`, ani w `ci.yml`:
     * wszędzie tam stoi nadal `database` i wszędzie znaczy to samo, tylko
     * z inną maską adresu.
     *
     * `SessionManager::callCustomCreator()` sam owija zwrócony uchwyt w `Store`
     * (i w `EncryptedStore`, gdy `SESSION_ENCRYPT=true`), więc domknięcie ma
     * oddać goły `SessionHandlerInterface`, a nie gotową sesję.
     *
     * DLACZEGO TO NIE JEST ODPOWIEDŹ NA `SESSION_ENCRYPT`
     * Bo tamta flaga tego nie dotyka: szyfruje wyłącznie kolumnę `payload`.
     * `ip_address` i `user_agent` są dokładane OBOK, jako osobne kolumny,
     * i przy `SESSION_ENCRYPT=true` zostają jawne tak samo jak bez niej.
     *
     * `boot()`, nie `register()` — z tego samego powodu co przy `Storage::extend()`
     * wyżej: sesja jest budowana leniwie, przy pierwszym żądaniu, a rozstrzyganie
     * fasady w `register()` wymuszałoby zbudowanie menedżera, zanim inni
     * dostawcy zdążą się zarejestrować.
     */
    private function zapisujWSesjiTylkoZgrubnyAdres(): void
    {
        Session::extend('database', function ($app): UchwytSesjiBezPelnegoAdresu {
            return new UchwytSesjiBezPelnegoAdresu(
                $app['db']->connection($app['config']->get('session.connection')),
                $app['config']->get('session.table'),
                $app['config']->get('session.lifetime'),
                $app,
            );
        });
    }

    /**
     * ADRES E-MAIL WYCHODZI Z ADRESU LINKU DO USTAWIENIA HASŁA.
     *
     * ────────────────────────────────────────────────────────────────────
     *  CO TO NAPRAWIA
     * ────────────────────────────────────────────────────────────────────
     *
     * `Illuminate\Auth\Notifications\ResetPassword::resetUrl()` buduje
     * domyślnie adres z DWOMA wartościami:
     *
     *     https://kuking.pl/nowe-haslo/<ŻETON>?email=basia@wp.pl
     *
     * Czyli żywy żeton resetu i adres, na który ten żeton pasuje — razem,
     * w jednym łańcuchu, w miejscu, które NIE JEST treścią żądania i które
     * po drodze zapisuje każdy: historia przeglądarki na wspólnym komputerze,
     * dziennik dostępu hostingu, proxy i (dopóki nie zdjęła go poprawka
     * w `AnalitykaCloudflare::wolnoNaTejStronie()`) beacon analityki.
     * Człowiek, który zobaczyłby ten jeden wiersz, ma komplet do wejścia na
     * cudze konto i wie, czyje ono jest.
     *
     * Żeton musi zostać — bez niego link nie działa. Adres NIE musi:
     * ekran `auth/reset-password.blade.php` ma własne, widoczne pole
     * „Twój adres e-mail" z `autocomplete="email"`, a `Password::reset()`
     * i tak czyta adres z ciała formularza, nie z adresu strony. Parametr
     * w adresie był wyłącznie wypełniaczem tego pola.
     *
     * ────────────────────────────────────────────────────────────────────
     *  DLACZEGO TUTAJ, A NIE PRZEZ NADPISANIE `resetUrl()` W POWIADOMIENIU
     * ────────────────────────────────────────────────────────────────────
     *
     * Bo adres budują DWA powiadomienia — `UstawienieNowegoHasla`
     * (odzyskiwanie hasła) i `UstawienieHaslaZamiastLinku` (wejście na konto
     * z niepotwierdzonym adresem, issue #317) — i oba wołają `resetUrl()`
     * z klasy nadrzędnej, obie z komentarzem mówiącym wprost, że robią to po
     * to, żeby hak `createUrlUsing()` działał. Dwie kopie jednej reguły
     * rozjeżdżają się przy pierwszej poprawce (ta sama lekcja co przy
     * `BezpiecznyKomunikat` i `DziennyBudzetListow`), a trzecie powiadomienie
     * dopisane kiedyś w przyszłości dostałoby poprawkę ZA DARMO tylko stąd.
     *
     * ────────────────────────────────────────────────────────────────────
     *  CO ZE STARYMI LINKAMI, KTÓRE JUŻ LEŻĄ W CZYICHŚ SKRZYNKACH
     * ────────────────────────────────────────────────────────────────────
     *
     * Działają bez zmian. `PasswordResetController::resetForm()` dalej czyta
     * `?email=` z adresu i wypełnia nim pole, więc link wysłany przed tą
     * poprawką zachowuje się dokładnie tak jak wcześniej. Zmienia się tylko
     * to, co od teraz WYCHODZI z serwera.
     */
    private function zdejmijAdresZLinkuResetu(): void
    {
        ResetPassword::createUrlUsing(
            // `url(route(..., absolute: false))` — dokładnie tak, jak robi to
            // klasa nadrzędna; różnica jest jedna: bez pola `email`.
            static fn (object $odbiorca, string $zeton): string => url(
                route('password.reset', ['token' => $zeton], false),
            ),
        );
    }

    /**
     * LICZNIKI PRZY POZYCJACH PANELU MODERACJI — przeliczanie przy ZAPISIE,
     * nigdy przy odsłonie (`App\Domain\Moderation\KolejkiPanelu`).
     *
     * Menu boczne panelu stoi na każdej stronie `/admin/**`, więc pięć
     * `COUNT(*)` na żądanie jest wykluczone. Zostają dwa źródła świeżości:
     * harmonogram co pięć minut (`kuking:policz-kolejki`) i te trzy haki.
     *
     * DLACZEGO WŁAŚNIE TE TRZY MODELE
     * `Appeal`, `Report` i `ContactMessage` to tabele, w których pojawienie
     * się i zamknięcie sprawy MA być widoczne od razu: licznik, który
     * pokazuje „1" po zamknięciu ostatniej sprawy, kłamie raz i traci
     * zaufanie na zawsze. Zmieniają się kilka razy na dobę, więc pięć
     * `COUNT(*)` przy takim zapisie jest niewidoczne.
     *
     * `Post` i `Comment` haka NIE MAJĄ świadomie — publikacja wpisu
     * i komentarz to główna akcja produktu (AGENTS.md §1) i nie dokładamy
     * do niej zapytań po to, żeby licznik miękkiej kolejki „Bez odpowiedzi"
     * (progi 6 i 24 godziny) był świeży co do sekundy. Tę jedną liczbę
     * odświeża harmonogram.
     *
     * `saved` ORAZ `deleted`: `saved` łapie i utworzenie, i zmianę statusu
     * (zamknięcie sprawy to `UPDATE`, nie `INSERT`), `deleted` — sprzątanie
     * retencyjne (`PrzedawnioneSprawyModeracyjne`), po którym kolejka
     * naprawdę jest krótsza.
     */
    private function odswiezajLicznikiKolejek(): void
    {
        $odswiez = fn () => app(KolejkiPanelu::class)->odswiez();

        foreach ([Appeal::class, Report::class, ContactMessage::class] as $model) {
            $model::saved($odswiez);
            $model::deleted($odswiez);
        }
    }

    /**
     * TRYB ŚCISŁY ELOQUENT POZA PRODUKCJĄ (issue #976).
     *
     * `shouldBeStrict()` włącza trzy ochrony naraz: `preventLazyLoading()`
     * (przypadkowe N+1), `preventSilentlyDiscardingAttributes()` (atrybut
     * spoza `$fillable` odrzucony po cichu) i `preventAccessingMissingAttributes()`
     * (odczyt kolumny, której nie pobrał częściowy `select()`).
     *
     * - `local` i `testing`: każde z tych przeoczeń rzuca wyjątek i przerywa
     *   test dokładnie w miejscu błędu.
     * - `staging` (także podglądy PR): ochrony są włączone, ale naruszenie
     *   trafia do logu jako OSTRZEŻENIE i nic nie przerywa. Zachowanie jest
     *   takie jak w produkcji — relacja doładowuje się leniwie, niepobrana
     *   kolumna czyta się jako `null`, pole spoza `$fillable` odpada — a joby
     *   i komendy spoza zasięgu testów zostawiają ślad do naprawy zamiast
     *   błędu 500. Decyzja właściciela z 25.09.2026.
     * - produkcja: bez zmian, ochrony wyłączone i bez logowania.
     *
     * Obsługę trzeba ustawić przy KAŻDYM starcie, także na `null`: wywołania
     * są statyczne i bez tego obsługa ze stagingu przeżyłaby w procesie
     * do następnego startu aplikacji (np. w testach).
     *
     * Świadomie BEZ automatycznego eager loadingu relacji — maskowałby brak
     * jawnego planu zapytań (`with()`, `loadMissing()`).
     */
    private function wlaczTrybScislyEloquentPozaProdukcja(): void
    {
        $staging = $this->app->environment('staging');

        Model::shouldBeStrict($this->app->environment('local', 'testing') || $staging);

        if (! $staging) {
            Model::handleLazyLoadingViolationUsing(null);
            Model::handleMissingAttributeViolationUsing(null);
            Model::handleDiscardedAttributeViolationUsing(null);

            return;
        }

        Model::handleLazyLoadingViolationUsing(static function (Model $model, string $relation): void {
            Log::warning('Tryb ścisły Eloquent: leniwe ładowanie relacji.', [
                'model' => $model::class,
                'relacja' => $relation,
            ]);
        });

        Model::handleMissingAttributeViolationUsing(static function (Model $model, string $key): mixed {
            Log::warning('Tryb ścisły Eloquent: odczyt niepobranej kolumny.', [
                'model' => $model::class,
                'kolumna' => $key,
            ]);

            return null;
        });

        Model::handleDiscardedAttributeViolationUsing(static function (Model $model, array $keys): void {
            Log::warning('Tryb ścisły Eloquent: pole spoza $fillable odrzucone.', [
                'model' => $model::class,
                'pola' => array_values($keys),
            ]);
        });
    }
}

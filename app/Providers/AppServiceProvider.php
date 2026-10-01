<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Collections\Wspoldzielenie\ZerwijWspoldzielenie;
use App\Domain\Recipes\Gotowanie\Wspolne\KoniecWspolnegoGotowaniaImpl;
use App\Domain\Import\BramkaPublikacjiOdczytu;
use App\Domain\Import\ModelFragmentow;
use App\Domain\Import\StrazImportu;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Domain\Import\Url\SystemowyRozwiazywaczNazw;
use App\Domain\Import\WyznaczaczFragmentow;
use App\Domain\Moderation\CofniecieUkryciaWersji;
use App\Domain\Moderation\KolejkiPanelu;
use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\TransportWebPush;
use App\Domain\Questions\PytaniaBezOdpowiedzi;
use App\Domain\Recipes\Actions\ZapiszSzkicZPaczki;
use App\Domain\Recipes\BramkaPublikacjiSzkicu;
use App\Domain\Recipes\Historia\DecyzjaOWersjiPrzepisu;
use App\Domain\Recipes\StrazPochodzeniaPrzepisu;
use App\Domain\Social\Actions\ObserwujGospodarza;
use App\Domain\Social\BlokadyZmienione;
use App\Domain\Social\ListyWidza;
use App\Domain\Users\Exports\ExportTempDirectory;
use App\Domain\Users\Import\ZapisSzkicuZPaczki;
use App\Domain\Users\KoniecWspolnegoGotowania;
use App\Domain\Users\KoniecWspolnychZeszytow;
use App\Domain\Users\ObserwowanieGospodarza;
use App\Http\Support\BlokadyWZadaniu;
use App\Http\Support\PamiecZadaniaHttp;
use App\Models\Appeal;
use App\Models\Comment;
use App\Models\ContactMessage;
use App\Models\Post;
use App\Models\PostTag;
use App\Models\Report;
use App\Models\User;
use App\Support\Baza\LimitBlokadMigracji;
use App\Support\Baza\LimitCzasuZapytanHttp;
use App\Support\KomunikatZaDuzaWysylka;
use App\Support\MapaStrony;
use App\Support\OdmianaWalidacji;
use App\Support\OdswiezanieLicznikowKolejek;
use App\Support\PamiecZadania;
use App\Support\Sesja\GeneracjaSesji;
use App\Support\Sesja\UchwytSesjiBezPelnegoAdresu;
use App\Support\Storage\DyskR2;
use App\Support\ZamrozonyCzas;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
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
        // Singleton, bo `odswiez()` trzyma flagę „już zaplanowane na commit"
        // — jedno przeliczenie liczników na transakcję (audyt B4 W3).
        $this->app->singleton(KolejkiPanelu::class);

        // Singleton, bo trzyma stan „limit włączony w tym żądaniu" i listę
        // połączeń do wyzerowania na końcu (#2290).
        $this->app->singleton(LimitCzasuZapytanHttp::class);

        // Retencja spraw (`Compliance`) odświeża liczniki przez kontrakt, bez
        // importu `Moderation` (#2149, etap 3) — ten sam singleton co wyżej.
        $this->app->bind(OdswiezanieLicznikowKolejek::class, fn ($app) => $app->make(KolejkiPanelu::class));

        // Rejestracja (`Users`) woła obserwowanie gospodarza przez kontrakt,
        // a implementację dostarcza `Social` (issue #971). To wiązanie jest
        // jedynym miejscem, które zna oba moduły — dzięki temu graf
        // `app/Domain` nie ma cyklu `Users ↔ Social`.
        $this->app->bind(ObserwowanieGospodarza::class, ObserwujGospodarza::class);
        // Cofnięcie ukrycia wersji po odwołaniu (#2270): Moderation → kontrakt, Recipes → implementacja.
        $this->app->bind(CofniecieUkryciaWersji::class, DecyzjaOWersjiPrzepisu::class);
        $this->app->bind(ZapisSzkicuZPaczki::class, ZapiszSzkicZPaczki::class);

        // Koniec wspólnych zeszytów przy blokadzie i wymazaniu konta (#1743,
        // D-302): kontrakt w `Users`, implementacja w `Collections` — bez
        // cyklu `Social ↔ Collections` i `Users → Collections → Social`.
        $this->app->bind(KoniecWspolnychZeszytow::class, ZerwijWspoldzielenie::class);

        // Koniec wspólnego gotowania przy blokadzie i wymazaniu konta (#2385):
        // ten sam wzór — kontrakt w `Users`, implementacja w `Recipes`.
        $this->app->bind(KoniecWspolnegoGotowania::class, KoniecWspolnegoGotowaniaImpl::class);

        // Import przepisu z adresu strony (D-300): DNS przez kontrakt, żeby
        // testy podstawiały własną mapę nazw i nie pytały prawdziwej sieci.
        $this->app->bind(RozwiazywaczNazw::class, SystemowyRozwiazywaczNazw::class);
        $this->app->bind(WyznaczaczFragmentow::class, ModelFragmentow::class);
        // Reguły pochodzenia przepisu (zablokowane źródło, „Sprawdziłem")
        // woła `PublishRecipe`; implementacja w module Import (bez cyklu).
        $this->app->bind(StrazPochodzeniaPrzepisu::class, StrazImportu::class);
        // Web Push (D-303). Testy podmieniają to fałszywym transportem —
        // żaden test nie wysyła prawdziwego pushu.
        $this->app->bind(TransportPush::class, TransportWebPush::class);
        // Pamięć jednego żądania dla domeny (`Ukrycia`, `SkrotyObserwowania`):
        // domena nie zna `Request`, adapter trzyma wartości w jego atrybutach (#970).
        $this->app->bind(PamiecZadania::class, PamiecZadaniaHttp::class);
        // Publikacja przepisu (`Recipes`) woła bramkę „Sprawdziłem odczytany
        // tekst" przez kontrakt, a implementację dostarcza `Import` (D-298,
        // issue #971). Wiązanie jest jedynym miejscem, które zna oba moduły
        // — dzięki temu graf `app/Domain` nie ma cyklu `Import ↔ Recipes`.
        $this->app->bind(BramkaPublikacjiSzkicu::class, BramkaPublikacjiOdczytu::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Issue #1836: zamrożenie zegara na jeden, wspólny moment dla joba CI
        // „Panel marki". Musi stanąć PRZED wszystkim, co czyta `now()` przy
        // starcie (np. `odswiezajLicznikiKolejek()` niżej) — inaczej to, co
        // liczy się od zegara, dostałoby prawdziwy czas mimo ustawionej
        // zmiennej. Poza `local`/`testing` `env(self::ZMIENNA)` jest zawsze
        // puste, więc to wywołanie nic nie robi na produkcji. Uzasadnienie
        // pełne w `App\Support\ZamrozonyCzas`.
        ZamrozonyCzas::zastosuj();

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

        // #1046: każde logowanie (hasło, link, Google, Facebook, 2FA,
        // rejestracja, recaller „zapamiętaj mnie”) zapisuje w sesji generację
        // konta. Zdarzenie, a nie wywołanie w każdym kontrolerze: kolejna
        // droga logowania dostaje to sama. Sprawdza `SprawdzGeneracjeSesji`.
        Event::listen(Login::class, function (Login $zdarzenie): void {
            $guard = Auth::guard($zdarzenie->guard);
            if ($zdarzenie->guard === 'web' && $guard instanceof SessionGuard && $zdarzenie->user instanceof User) {
                GeneracjaSesji::zapamietaj($guard->getSession(), $zdarzenie->user);
            }
        });

        // Audyt wydajności P3 W8 + W7: jedna pamięć blokad w żądaniu (`ListyWidza`,
        // z niej czyta też `BlokadyWZadaniu` dla polityk) przestaje obowiązywać
        // w chwili, gdy to samo żądanie zmienia blokady.
        Event::listen(BlokadyZmienione::class, fn () => ListyWidza::uniewaznij());
        Event::listen(RouteMatched::class, fn (RouteMatched $zdarzenie) => BlokadyWZadaniu::rozpocznij($zdarzenie->request));

        // Audyt B3 W3: każda migracja chodzi z `lock_timeout`, żeby DDL
        // czekający na blokadę gorącej tabeli nie ustawiał za sobą w kolejce
        // całego ruchu serwisu. Uzasadnienie w `LimitBlokadMigracji`.
        Event::listen(MigrationStarted::class, [LimitBlokadMigracji::class, 'przyStarcie']);
        Event::listen(MigrationEnded::class, [LimitBlokadMigracji::class, 'przyKoncu']);

        // #2290: połączenie otwarte W TRAKCIE żądania HTTP (pierwsze, albo po
        // zerwaniu) też dostaje `statement_timeout`. Poza żądaniem — nic.
        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $zdarzenie) => $this->app->make(LimitCzasuZapytanHttp::class)->naPolaczenie($zdarzenie->connection));

        // Zapytanie przerwane limitem: polski ekran „spróbuj za chwilę"
        // zamiast ogólnej pięćsetki. Wyjątek i tak idzie do raportu (alarm).
        $this->app->make(ExceptionHandler::class)->renderable(
            function (QueryException $e, Request $request) {
                if (! LimitCzasuZapytanHttp::toPrzerwaneZapytanie($e) || $request->expectsJson()) {
                    return null;
                }

                return response()->view('errors.zapytanie-za-dlugo', [], 503, ['Retry-After' => '30']);
            },
        );

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
        $this->odswiezajLicznikPytan();

        // Mapa strony nie może ogłaszać treści, która przestała być
        // publiczna (issue #1006) — opis w `App\Support\MapaStrony`.
        MapaStrony::zarejestrujHaki();

        $this->zapisujWSesjiTylkoZgrubnyAdres();

        // Issue #993: osierocone pliki pośrednie eksportu sprząta pętla
        // workera, na dysku, na którym powstały — nie tylko start następnego
        // eksportu. Dławik i uzasadnienie w `ExportTempDirectory::sweepStaleIfDue()`.
        Event::listen(Looping::class, fn () => ExportTempDirectory::sweepStaleIfDue());
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
     * zaufanie na zawsze. Hak liczy tylko cztery tanie `COUNT(*)`, po
     * commicie i raz na transakcję — sygnały automatu przychodzą falami
     * (audyt B4 W3, `KolejkiPanelu::odswiez()`). Drogie „Bez odpowiedzi"
     * zostaje harmonogramowi.
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

    /**
     * LICZNIK „CZEKA NA ODPOWIEDŹ (N)” NA /pytania — przeliczanie w tle po
     * zapisie (#372, `App\Domain\Questions\PytaniaBezOdpowiedzi`).
     *
     * To JEST hak na `Post` i `Comment`, których liczniki panelu świadomie
     * nie mają (wyżej) — ale wąski: reaguje tylko na pytania i komentarze
     * pod pytaniami, a samo liczenie idzie do kolejki po commicie. Koszt
     * w żądaniu: przy komentarzu jedno `exists()` po kluczu głównym wpisu
     * i jeden wiersz zadania; przy daniu — nic. Właściciel zdecydował
     * 25.09.2026, że nowa odpowiedź ma odświeżać licznik od razu, a nie po
     * pięciu minutach harmonogramu.
     *
     * `PostTag`: tagi przypinamy po utworzeniu wpisu, a licznik ma też
     * wersję per tag.
     */
    private function odswiezajLicznikPytan(): void
    {
        $pytanie = static function (Post $post): void {
            if ($post->kind === Post::KIND_QUESTION || $post->getOriginal('kind') === Post::KIND_QUESTION) {
                PytaniaBezOdpowiedzi::zlecPrzeliczenie();
            }
        };
        Post::saved($pytanie);
        Post::deleted($pytanie);

        $komentarz = static function (Comment $comment): void {
            if (PytaniaBezOdpowiedzi::dotyczyKomentarza($comment)) {
                PytaniaBezOdpowiedzi::zlecPrzeliczenie();
            }
        };
        Comment::saved($komentarz);
        Comment::deleted($komentarz);

        $tag = static function (PostTag $pivot): void {
            if (config('kuking.questions.enabled')
                && Post::withTrashed()->whereKey($pivot->post_id)->where('kind', Post::KIND_QUESTION)->exists()) {
                PytaniaBezOdpowiedzi::zlecPrzeliczenie();
            }
        };
        PostTag::saved($tag);
        PostTag::deleted($tag);
    }
}

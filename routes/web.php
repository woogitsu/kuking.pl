<?php

declare(strict_types=1);

use App\Domain\Media\PodgladOdRazu;
use App\Domain\Moderation\Actions\ZdejmijZUrzedu;
use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Admin\AppealController as AdminAppealController;
use App\Http\Controllers\Admin\BezOdpowiedziController;
use App\Http\Controllers\Admin\DailyBoardController;
use App\Http\Controllers\Admin\HeroKolazController;
use App\Http\Controllers\Admin\KolejkaController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\SygnalyController;
use App\Http\Controllers\Admin\TagPromotionController;
use App\Http\Controllers\Admin\UzytkownicyController;
use App\Http\Controllers\Admin\WiadomosciController;
use App\Http\Controllers\Admin\ZUrzeduController;
use App\Http\Controllers\AppealController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\FacebookDeauthorizeController;
use App\Http\Controllers\Auth\FacebookLoginController;
use App\Http\Controllers\Auth\GoogleLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LoginLinkController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\RegistrationInviteController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CookedEventController;
use App\Http\Controllers\CookingModeController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\ExternalLinkController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NapiszDoNasController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PodsumowanieTygodniaController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\PostMediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PwaInstallController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReporterAppealController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\AccessibilitySettingsController;
use App\Http\Controllers\Settings\AvatarSettingsController;
use App\Http\Controllers\Settings\DataSettingsController;
use App\Http\Controllers\Settings\EmailSettingsController;
use App\Http\Controllers\Settings\PrivacySettingsController;
use App\Http\Controllers\Settings\ProfileSettingsController;
use App\Http\Controllers\Settings\SecuritySettingsController;
use App\Http\Controllers\Settings\SettingsIndexController;
use App\Http\Controllers\Settings\TwoFactorSettingsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TagFollowController;
use App\Http\Controllers\TagSuggestionController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\WspomnienieController;
use App\Http\Controllers\ZgloszenieNielegalnejTresciController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Trasy Kuking
|--------------------------------------------------------------------------
|
| Adresy są PO POLSKU tam, gdzie użytkownik może je zobaczyć, przeczytać
| komuś przez telefon albo przepisać z kartki (/przepisy, /zeszyt,
| /ustawienia). Wyjątki: /home, /login, /register — te są na tyle
| umownymi standardami, że polska wersja bardziej myli niż pomaga.
|
| Limity zapytań pochodzą z config/kuking.php — jedno miejsce na wszystkie
| progi (AGENTS.md → Security: każdy endpoint ma auth, authorization,
| validation, rate limit i audit).
|
*/

$limits = config('kuking.limits');

// --------------------------------------------------------------------------
// Publiczne
// --------------------------------------------------------------------------

Route::get('/', [FeedController::class, 'landing'])->name('landing');
Route::get('/otworz-link', ExternalLinkController::class)->middleware("throttle:{$limits['external_link']},external_link")->name('links.external');
Route::get('/odkryj', [FeedController::class, 'discover'])->name('discover');
Route::get('/pytania', [QuestionController::class, 'index'])
    ->middleware("throttle:{$limits['search']},search")
    ->name('questions.index');
Route::get('/szukaj', [SearchController::class, 'index'])
    ->middleware("throttle:{$limits['search']},search")
    ->name('search');

Route::get('/health', HealthController::class)->name('health');
// `/wydanie` (#1012) NIE stoi tutaj — jest w `bootstrap/app.php` (`then:`),
// poza grupą `web`, żeby nie zakładać sesji ani nie stawiać ciasteczek.
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

/*
 * „NAPISZ DO NAS" — kontakt z operatorem serwisu.
 *
 * POZA GRUPĄ `auth`, ŚWIADOMIE. Najczęstsze zdanie, które ludzie mają nam do
 * powiedzenia na starcie, brzmi „nie mogę się zalogować" albo „nie udało mi
 * się założyć konta". Formularz za logowaniem wykluczałby dokładnie te osoby,
 * dla których w pierwszej kolejności istnieje. Ochroną jest limit zapytań
 * (`limits.kontakt` w `config/kuking.php`), nie konto.
 *
 * TO NIE JEST DROGA ZGŁASZANIA CUDZYCH TREŚCI. Skarga na czyjś wpis idzie
 * przyciskiem „Zgłoś" pod treścią (`reports.create`, za logowaniem), a treść
 * niezgodna z prawem — formularzem z DSA art. 16 (`zglos.nielegalna`, też
 * publicznym). Trzy drogi, trzy różne kolejki i trzy różne obowiązki; oba
 * formularze mówią o tym wprost i linkują do siebie nawzajem.
 *
 * `noindex` NIE JEST tu potrzebny odwrotnie niż przy DSA art. 16: tamten
 * musi dać się znaleźć w wyszukiwarce, bo przepis wymaga mechanizmu ŁATWO
 * DOSTĘPNEGO dla ludzi spoza serwisu. Ta strona też zostaje w indeksie —
 * z tego samego, praktycznego powodu: ktoś, kto nie może się zalogować,
 * wpisuje „kuking kontakt" w wyszukiwarkę, a nie szuka stopki.
 */
Route::get('/napisz-do-nas', [NapiszDoNasController::class, 'create'])->name('kontakt');
Route::post('/napisz-do-nas', [NapiszDoNasController::class, 'store'])
    ->middleware("throttle:{$limits['kontakt']},kontakt")
    ->name('kontakt.store');
Route::get('/napisz-do-nas/dziekujemy', [NapiszDoNasController::class, 'confirmation'])
    ->name('kontakt.potwierdzenie');

Route::get('/pomoc', [StaticPageController::class, 'help'])->name('help');
Route::get('/zasady', [StaticPageController::class, 'rules'])->name('rules');
Route::get('/o-kuking', [StaticPageController::class, 'about'])->name('about');
Route::get('/regulamin', [StaticPageController::class, 'terms'])->name('terms');
Route::get('/prywatnosc', [StaticPageController::class, 'privacy'])->name('privacy');

/*
|--------------------------------------------------------------------------
| Wypisanie z tygodniowego podsumowania (issue #11, D-057)
|--------------------------------------------------------------------------
|
| POZA GRUPĄ `auth` I TO JEST SEDNO TYCH DWÓCH TRAS. Człowiek, który chce
| przestać dostawać listy, nie może być zmuszony do zalogowania się — issue
| #11 pkt 6 mówi „jednym kliknięciem, bez logowania i bez ankiety", a powód
| jest twardszy niż wygoda: osoba, która nie pamięta hasła, zamiast wypisać
| się klika w skrzynce „to jest spam", a to psuje dostarczalność CAŁEJ poczty
| Kuking, łącznie z resetami haseł (`docs/decyzje/POCZTA.md` §3).
|
| Autoryzacją jest PODPIS, nie identyfikator w adresie — `AGENTS.md` §7
| („UUID w adresie NIE JEST autoryzacją") zostaje w mocy: `middleware('signed')`
| sprawdza, że ten adres wystawił Kuking kluczem aplikacji. Bez podpisu trasa
| oddaje 403, więc nie da się wypisać kogoś, znając samo konto.
|
| `match(['get', 'post'])`, nie samo `get`: `POST` obsługuje nagłówek
| `List-Unsubscribe-Post` (RFC 8058), którym Gmail i Outlook pokazują własny
| przycisk wypisania przy nadawcy. Ta jedna trasa jest też wyjęta spod CSRF
| (`bootstrap/app.php`) — klient pocztowy nie ma skąd wziąć tokenu.
|
| Limit z grupy `ustawienia`: dla gościa liczy się po adresie IP. Dolna
| granica jest tu ważniejsza od górnej — trasa MUSI przepuścić kilka osób
| z jednego łącza (dom opieki, mieszkanie rodzinne, biblioteka), bo inaczej
| druga z nich zobaczy „za dużo prób" zamiast wypisania.
*/
Route::match(['get', 'post'], '/podsumowanie/wypisz/{user}', [PodsumowanieTygodniaController::class, 'wypisz'])
    ->middleware(['signed', "throttle:{$limits['ustawienia']},ustawienia"])
    ->name('podsumowanie.wypisz');

// Droga powrotna: `GET` tylko pyta (strona z przyciskiem), zgodę włącza
// wyłącznie `POST` z tokenem CSRF — rozgałęzienie w kontrolerze (#1403).
Route::match(['get', 'post'], '/podsumowanie/wracam/{user}', [PodsumowanieTygodniaController::class, 'wracam'])
    ->middleware(['signed', "throttle:{$limits['ustawienia']},ustawienia"])
    ->name('podsumowanie.wracam');

// Jasny/ciemny wygląd — poza grupami `auth`/`guest` celowo: to jedyny
// przełącznik w serwisie, którego GOŚĆ (bez konta) też ma prawo użyć
// (docs/DECISIONS.md, D-019). Wybór zalogowanego kontroler i tak zapisuje
// na koncie — patrz ThemeController.
//
// Limit z grupy `ustawienia`, mimo że to nie jest ekran ustawień: dla gościa
// ta trasa liczy się PO ADRESIE IP i jest jedynym zapisem do bazy osiągalnym
// bez konta poza formularzami zgłoszeń. Próg dobrany tak, żeby zmieścić kilka
// osób za jednym łączem — uzasadnienie przy kluczu w config/kuking.php.
Route::post('/motyw', [ThemeController::class, 'update'])
    ->middleware("throttle:{$limits['ustawienia']},ustawienia")
    ->name('theme.update');

Route::get('/przepisy/{recipe}', [RecipeController::class, 'show'])->name('recipes.show');

// Tryb gotowania (issue #24). Widoczność jak strona przepisu — patrz
// komentarz nad CookingModeController — więc te trasy stoją tutaj, w bloku
// bez wymogu zalogowania, a nie w grupie `auth` niżej. Zapis (odznaczanie
// kroku) ma własny, niski limit zapytań: to POST na cudzy slug, więc nawet
// bez żadnego ryzyka dla danych zasługuje na ten sam refleks co reszta
// endpointów zmieniających stan.
Route::get('/przepisy/{recipe}/gotuj', [CookingModeController::class, 'show'])->name('cooking.show');
Route::post('/przepisy/{recipe}/gotuj/od-poczatku', [CookingModeController::class, 'restart'])
    ->middleware("throttle:{$limits['cooking_krok']},cooking_krok")
    ->name('cooking.restart');
Route::post('/przepisy/{recipe}/gotuj', [CookingModeController::class, 'zaznacz'])
    ->middleware("throttle:{$limits['cooking_krok']},cooking_krok")
    ->name('cooking.zaznacz');

Route::get('/wpisy/{post}', [PostController::class, 'show'])->name('posts.show');
Route::get('/pytania/zadaj', [PostController::class, 'create'])->middleware('auth')->name('questions.create');
Route::post('/pytania', [PostController::class, 'store'])
    ->middleware(['auth', "throttle:{$limits['post']},post"])->name('questions.store');
Route::get('/pytania/{post}', [PostController::class, 'show'])->whereUuid('post')->name('questions.show');
Route::get('/ugotowane/{cookedEvent}', [CookedEventController::class, 'show'])->name('cooked.show');

/*
 * Zdjęcia (audyt W7-02). ADRES ZDJĘCIA TO TA TRASA, nie plik w buckecie.
 *
 * Bucket wariantów nie ma już własnej domeny. `MediaController` pyta Policy
 * treści NADRZĘDNEJ (`App\Domain\Media\DostepDoZdjecia`) i przekierowuje na
 * krótko podpisany adres R2 — bajty nie idą przez PHP, idzie przez nie
 * wyłącznie decyzja.
 *
 * PUBLICZNA, POZA `auth`, ŚWIADOMIE: zdjęcie publicznego przepisu ma się
 * otwierać bez konta, tak jak sam przepis. Ochroną jest Policy plus limit
 * zapytań, nie logowanie.
 *
 * `whereUuid` nie jest ozdobnikiem. Bez niego identyfikator, który nie jest
 * UUID-em, leci wprost do Postgresa jako wartość kolumny `uuid`, a ten
 * odpowiada błędem składni — czyli 500 zamiast 404. Odmowa ma wyglądać
 * identycznie jak zdjęcie nieistniejące, a strona błędu serwera wygląda
 * inaczej niż jedno i drugie.
 */
Route::get('/zdjecia/{media}/{wariant}', [MediaController::class, 'show'])
    ->whereUuid('media')
    /*
     * LISTA NAZW JEST BIAŁĄ LISTĄ I TO JEST JEJ ROBOTA: nazwa wariantu
     * przychodzi z adresu, czyli od klienta. Cokolwiek spoza tej listy
     * dostaje 404 jeszcze przed kontrolerem — a więc zanim ktokolwiek
     * spróbuje zamienić ją na klucz w buckecie.
     *
     * `podglad` DOPISANY OSOBNO (issue #430) i to nie jest niedopatrzenie
     * konfiguracji. `kuking.media.variants` mówi, co liczy zadanie w tle;
     * podgląd powstaje wcześniej i gdzie indziej (`PodgladOdRazu`), więc
     * w tamtej liście go nie ma i być nie powinno. W adresie wystąpi —
     * `Media::url()` oddaje jego nazwę, dopóki nie ma prawdziwych wariantów.
     * Bez tej linii cała naprawa #430 kończyłaby się na 404 i nikt nie
     * zobaczyłby swojego zdjęcia ani o sekundę wcześniej.
     */
    ->whereIn('wariant', [
        PodgladOdRazu::NAZWA,
        ...array_keys((array) config('kuking.media.variants')),
    ])
    ->middleware("throttle:{$limits['zdjecie']},zdjecie")
    ->name('media.show');

// Listy relacji — publiczne jak sam profil, ale bez indeksowania (to nie
// jest treść dla wyszukiwarki, tylko widok pomocniczy). Muszą stać PRZED
// `/@{username}`, z tego samego powodu co reszta tras pod profilem.
Route::get('/@{username}/obserwujacy', [SocialController::class, 'followers'])->name('social.followers');
Route::get('/@{username}/obserwowani', [SocialController::class, 'following'])->name('social.following');

// Profil na końcu, bo /@nazwa nie może przechwycić innych adresów.
Route::get('/@{username}', [ProfileController::class, 'show'])->name('profile.show');

// --------------------------------------------------------------------------
// Gość — rejestracja i logowanie
// --------------------------------------------------------------------------

Route::middleware('guest')->group(function () use ($limits): void {
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware("throttle:{$limits['register']},register");

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware("throttle:{$limits['login']},login");

    Route::get('/nie-pamietam-hasla', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/nie-pamietam-hasla', [PasswordResetController::class, 'sendLink'])
        ->middleware("throttle:{$limits['password_reset']},password_reset")
        ->name('password.email');

    Route::get('/nowe-haslo/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/nowe-haslo', [PasswordResetController::class, 'reset'])
        ->middleware("throttle:{$limits['password_reset']},password_reset")
        ->name('password.update');

    /*
     * LOGOWANIE LINKIEM E-MAIL — „magic link" (issue #25, D-056).
     *
     * Trzy trasy, bo droga ma trzy kroki, i podział między nimi jest
     * BEZPIECZEŃSTWEM, nie estetyką:
     *
     *   GET  /logowanie/link        — formularz „wyślij mi link"
     *   POST /logowanie/link        — wysyłka listu (Turnstile + dwa limity)
     *   GET  /logowanie/link/{token} — ekran z przyciskiem. NIC NIE ZUŻYWA.
     *   POST /logowanie/link/wejdz  — dopiero tu token ginie i powstaje sesja
     *
     * Skanery odnośników w poczcie otwierają linki z listów ZANIM zrobi to
     * człowiek. Gdyby GET logował, skaner zużywałby jednorazowy token
     * i właściciel konta dostawałby „link już nie działa" za każdym razem.
     * Pełne uzasadnienie: `LoginLinkController`.
     *
     * `/logowanie/link/wejdz` nie koliduje z `/logowanie/link/{token}`, bo
     * to dwie różne metody HTTP (POST kontra GET). Kolejność deklaracji jest
     * tu więc kwestią czytelności, nie poprawności.
     *
     * DWA OSOBNE PREFIKSY LICZNIKA, choć oba dotyczą tej samej funkcji:
     * nieudane kliknięcie „Zaloguj mnie" nie ma prawa zjadać budżetu próśb
     * o list (i odwrotnie). To ta sama reguła, której pilnuje
     * `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`.
     *
     * W grupie `guest` z tego samego powodu co `/login` i `/logowanie/kod`:
     * to jeszcze nie jest sesja zalogowana, a ktoś już zalogowany nie ma
     * po co tu wracać.
     */
    Route::get('/logowanie/link', [LoginLinkController::class, 'requestForm'])->name('login.link');
    Route::post('/logowanie/link', [LoginLinkController::class, 'send'])
        ->middleware("throttle:{$limits['login_link']},login_link")
        ->name('login.link.send');

    Route::post('/logowanie/link/wejdz', [LoginLinkController::class, 'store'])
        ->middleware("throttle:{$limits['login_link_wejscie']},login_link_wejscie")
        ->name('login.link.store');

    Route::get('/logowanie/link/{token}', [LoginLinkController::class, 'confirmForm'])
        ->name('login.link.confirm');

    /*
     * WEJŚCIE KONTEM GOOGLE (issue #258, D-069) — droga DODATKOWA, nigdy
     * jedyna. Hasło i link e-mail zostają na ekranie logowania.
     *
     *   GET  /wejdz/google           — kliknięcie przycisku. Zakłada w sesji
     *                                  `state`, `nonce` i weryfikator PKCE
     *                                  i odsyła człowieka do Google.
     *   GET  /wejdz/google/wroc      — powrót z Google. Sprawdza `state`,
     *                                  wymienia kod, ROZSTRZYGA co dalej.
     *   GET  /wejdz/google/domknij   — ekran domknięcia konta dla nowej
     *                                  osoby (imię, nazwa, dwa oświadczenia)
     *   POST /wejdz/google/domknij   — dopiero tu powstaje konto
     *   GET  /wejdz/google/polacz    — „na tym adresie jest już konto,
     *                                  połączyć?". NIC NIE ZMIENIA.
     *   POST /wejdz/google/polacz    — dopiero tu powstaje powiązanie
     *
     * ROZDZIAŁ „POKAŻ" OD „ZRÓB" JEST TU BEZPIECZEŃSTWEM, nie estetyką —
     * ten sam powód co przy logowaniu linkiem: GET nie zmienia stanu.
     * Konta łączy i konta zakłada wyłącznie POST z tokenem CSRF.
     *
     * DWA OSOBNE KOSZYKI LIMITÓW: kliknięcia i powroty (`google_wejscie`)
     * nie mają prawa zjadać budżetu wysłań formularza, który naprawdę
     * zakłada konto (`google_domkniecie`) — i odwrotnie. To ta sama reguła,
     * której pilnuje `LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`.
     *
     * BEZ KLUCZY GOOGLE te trasy odsyłają na `/login` ze zdaniem po polsku,
     * a przycisku nie ma nigdzie na ekranie — środowisko bez kluczy (CI,
     * lokalnie) zachowuje się dokładnie jak przed tą zmianą.
     *
     * W grupie `guest` z tego samego powodu co `/login`: to jeszcze nie jest
     * sesja zalogowana, a ktoś już zalogowany nie ma po co tu wracać.
     */
    Route::get('/wejdz/google', [GoogleLoginController::class, 'start'])
        ->middleware("throttle:{$limits['google_wejscie']},google_wejscie")
        ->name('google.start');

    Route::get('/wejdz/google/wroc', [GoogleLoginController::class, 'callback'])
        ->middleware("throttle:{$limits['google_wejscie']},google_wejscie")
        ->name('google.callback');

    Route::get('/wejdz/google/domknij', [GoogleLoginController::class, 'finishForm'])
        ->name('google.finish');
    Route::post('/wejdz/google/domknij', [GoogleLoginController::class, 'finish'])
        ->middleware("throttle:{$limits['google_domkniecie']},google_domkniecie")
        ->name('google.finish.store');

    Route::get('/wejdz/google/polacz', [GoogleLoginController::class, 'linkForm'])
        ->name('google.link');
    Route::post('/wejdz/google/polacz', [GoogleLoginController::class, 'link'])
        ->middleware("throttle:{$limits['google_domkniecie']},google_domkniecie")
        ->name('google.link.store');

    /*
     * ZAPROSZENIE DO ZAŁOŻENIA KONTA — druga połowa tej samej drogi (D-085).
     *
     * Kto poprosi o „link do zalogowania" dla adresu, na którym NIE MA konta,
     * dostaje wiadomość prowadzącą tutaj. Do 10 września 2026 nie dostawał
     * niczego i widział przy tym zielone „wysłaliśmy wiadomość" — odbiła się
     * o to prawdziwa osoba (uzasadnienie w `RegistrationInviteController`).
     *
     *   GET  /zaproszenie/{token}      — ekran z przyciskiem. NIC NIE ZUŻYWA.
     *   POST /zaproszenie/zakladam     — zaproszenie do sesji i na /register
     *   POST /zaproszenie/inny-adres   — „chcę konto na inny adres"
     *
     * Zaproszenia nie zużywa nawet POST — kasuje je dopiero utworzenie konta
     * (`RegisterController::store()`), bo za tym POST-em stoi jeszcze cały
     * formularz rejestracji, o który ta osoba już raz się odbiła.
     *
     * TRASY POST STOJĄ PRZED TRASĄ Z TOKENEM z tego samego powodu co przy
     * logowaniu linkiem: kolizji nie ma (różne metody HTTP), więc kolejność
     * jest kwestią czytelności, nie poprawności.
     *
     * WŁASNY PREFIKS LICZNIKA (`zaproszenie`), osobny od `login_link_wejscie`
     * — nieudane klikanie „Zaloguj mnie" nie ma prawa zjadać prób „Załóż
     * konto" i odwrotnie (`LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`).
     *
     * BEZ TURNSTILE, tak jak `POST /logowanie/link/wejdz`: captcha stoi na
     * formularzu „wyślij mi link", przez który każde zaproszenie musi przejść,
     * a tutaj nie ma czego wysyłać ani zapisywać. Formularz `/register`, na
     * który te trasy przenoszą, Turnstile ma i mieć musi (D-050).
     *
     * W grupie `guest` z tego samego powodu co `/register` i `/logowanie/link`
     * — kto jest już zalogowany, nie ma po co zakładać konta.
     */
    Route::post('/zaproszenie/zakladam', [RegistrationInviteController::class, 'przyjmij'])
        ->middleware("throttle:{$limits['zaproszenie']},zaproszenie")
        ->name('zaproszenie.przyjmij');

    Route::post('/zaproszenie/inny-adres', [RegistrationInviteController::class, 'porzuc'])
        ->middleware("throttle:{$limits['zaproszenie']},zaproszenie")
        ->name('zaproszenie.porzuc');

    Route::get('/zaproszenie/{token}', [RegistrationInviteController::class, 'pokaz'])
        ->name('zaproszenie.pokaz');

    // Drugi krok logowania dla konta z potwierdzonym 2FA (issue #12).
    // Zostaje w grupie `guest` z tego samego powodu co /login: to jeszcze
    // NIE JEST sesja zalogowana (LoginController zapisuje tu tylko
    // identyfikator konta w sesji, patrz TwoFactorChallengeController) —
    // ktoś już zalogowany nie ma po co tu wracać.
    Route::get('/logowanie/kod', [TwoFactorChallengeController::class, 'show'])->name('login.two_factor');
    Route::post('/logowanie/kod', [TwoFactorChallengeController::class, 'store'])
        ->middleware("throttle:{$limits['two_factor']},two_factor")
        ->name('login.two_factor.store');

    // Cofnięcie zgłoszonego usunięcia konta — dla osoby, którą
    // `EnsureAccountIsActive` już wylogowało (status `pending_delete`
    // nie loguje się, patrz `LoginController`). Publiczne z konieczności,
    // tak samo jak formularz odwołań dla zablokowanych kont (issue #10) —
    // patrz komentarz w `AccountDeletionController` (audyt A8).
    Route::get('/cofnij-usuniecie-konta', [AccountDeletionController::class, 'showCancelForm'])
        ->name('account.delete.cancel');
    Route::post('/cofnij-usuniecie-konta', [AccountDeletionController::class, 'cancel'])
        ->middleware("throttle:{$limits['cancel_delete']},cancel_delete")
        ->name('account.delete.cancel.store');
});

// WYLOGOWANIE ŚWIADOMIE BEZ LIMITU ZAPYTAŃ (BRAMKA_BETY §7a).
//
// To jedyna trasa zapisująca, której limitu NIE dodaliśmy, i jest to decyzja,
// nie przeoczenie. Wylogowanie unieważnia sesję i nic nie tworzy — powtórzone
// nie robi nic. Za to 429 na tej trasie zostawia OTWARTĄ sesję na cudzym albo
// wspólnym komputerze, u kogoś, kto właśnie próbuje ją zamknąć. Zysk zerowy,
// koszt realny.
Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// --------------------------------------------------------------------------
// WEJŚCIE KONTEM FACEBOOKA (issue #259, D-069, D-098)
// --------------------------------------------------------------------------
//
//   GET  /wejdz/facebook          — kliknięcie „Wejdź kontem Facebooka"
//                                   albo „Połącz konto Facebooka". Zakłada
//                                   w sesji `state` i odsyła do Facebooka.
//   GET  /wejdz/facebook/wroc     — powrót z Facebooka. Sprawdza `state`,
//                                   wymienia kod, ROZSTRZYGA co dalej.
//                                   TEN ADRES JEST WPISANY W PANELU META,
//                                   znak w znak (runbook §4.2) — zmiana tej
//                                   ścieżki wymaga zmiany także tam.
//   GET  /wejdz/facebook/domknij  — ekran domknięcia konta dla nowej osoby
//                                   (imię, nazwa, dwa oświadczenia)
//   POST /wejdz/facebook/domknij  — dopiero tu powstaje konto
//   GET  /wejdz/facebook/polacz   — „połączyć to konto z Facebookiem?"
//                                   dla osoby JUŻ ZALOGOWANEJ. NIC NIE ZMIENIA.
//   POST /wejdz/facebook/polacz   — dopiero tu powstaje powiązanie
//
// TE TRASY NIE SĄ W GRUPIE `guest` — I TO JEST RÓŻNICA WZGLĘDEM GOOGLE,
// KTÓRA MA UZASADNIENIE, A NIE JEST NIEDOPATRZENIEM.
//
// Facebook nie mówi, czy adres e-mail jest potwierdzony, więc adres
// z Facebooka NIE MOŻE łączyć kont (D-098) — a to znaczy, że jedyna
// bezpieczna droga powiązania istniejącego konta prowadzi przez człowieka,
// który JUŻ JEST NA NIM ZALOGOWANY (hasłem albo linkiem e-mail) i klika
// „Połącz konto Facebooka" w Ustawieniach → Bezpieczeństwo. Gdyby te trasy
// stały w grupie `guest`, ta droga byłaby nieosiągalna, a odmowa „na ten
// adres jest już konto" nie miałaby dokąd odesłać człowieka.
//
// Rozstrzyga więc `Auth::check()` w kontrolerze, w jednym miejscu i jawnie.
// Grupa `guest` przekierowywałaby zalogowanego na `/home` i zabierałaby mu
// tę możliwość bez słowa wyjaśnienia.
//
// ROZDZIAŁ „POKAŻ" OD „ZRÓB" JEST TU BEZPIECZEŃSTWEM, nie estetyką —
// ten sam powód co przy Google i przy logowaniu linkiem: GET nie zmienia
// stanu. Konta zakłada i powiązania tworzy wyłącznie POST z tokenem CSRF.
//
// DWA OSOBNE KOSZYKI LIMITÓW, osobne także od tych od Google
// (`facebook_wejscie`, `facebook_domkniecie`) — uzasadnienie stoi przy nich
// w `config/kuking.php`.
//
// BEZ KLUCZY FACEBOOKA te trasy odsyłają na `/login` ze zdaniem po polsku,
// a przycisku nie ma nigdzie na ekranie: środowisko bez kluczy (CI, lokalnie,
// każde środowisko preview) zachowuje się dokładnie jak przed tą zmianą.
Route::get('/wejdz/facebook', [FacebookLoginController::class, 'start'])
    ->middleware("throttle:{$limits['facebook_wejscie']},facebook_wejscie")
    ->name('facebook.start');

Route::get('/wejdz/facebook/wroc', [FacebookLoginController::class, 'callback'])
    ->middleware("throttle:{$limits['facebook_wejscie']},facebook_wejscie")
    ->name('facebook.callback');

Route::get('/wejdz/facebook/domknij', [FacebookLoginController::class, 'finishForm'])
    ->name('facebook.finish');
Route::post('/wejdz/facebook/domknij', [FacebookLoginController::class, 'finish'])
    ->middleware("throttle:{$limits['facebook_domkniecie']},facebook_domkniecie")
    ->name('facebook.finish.store');

/*
 * ODEBRANIE DOSTĘPU U FACEBOOKA (issue #259).
 *
 * Woła to POST-em serwer Facebooka, nie przeglądarka człowieka — więc trasa
 * jest WYŁĄCZONA Z OCHRONY CSRF w `bootstrap/app.php`, a autentyczność
 * potwierdza podpis `signed_request`, nie sesja. Pełne uzasadnienie stoi
 * w `FacebookDeauthorizeController`.
 *
 * OGRANICZENIE PANELU META: pole `Deauthorize callback URL` jest jedno na
 * aplikację, a jedna aplikacja obsługuje u nas produkcję i staging — więc
 * STAGING TYCH POWIADOMIEŃ NIE DOSTANIE. To nie jest usterka do naprawienia
 * w kodzie.
 *
 * Bez ogranicznika liczby żądań: każde żądanie bez poprawnego podpisu kończy
 * się odrzuceniem po jednym `hash_hmac`, a ogranicznik ustawiony za nisko
 * zaczyna gubić prawdziwe powiadomienia — których Facebook nie ponawia
 * w nieskończoność.
 */
Route::post('/wejdz/facebook/odebranie-dostepu', FacebookDeauthorizeController::class)
    ->name('facebook.deauthorize');

Route::get('/wejdz/facebook/polacz', [FacebookLoginController::class, 'linkForm'])
    ->name('facebook.link');
Route::post('/wejdz/facebook/polacz', [FacebookLoginController::class, 'link'])
    ->middleware("throttle:{$limits['facebook_domkniecie']},facebook_domkniecie")
    ->name('facebook.link.store');

// --------------------------------------------------------------------------
// Odwołanie od decyzji moderacyjnej — droga dla osób ZABLOKOWANYCH (#10)
// --------------------------------------------------------------------------
//
// Ta trasa NIE jest w grupie `auth` ani `guest` i to jest jej sedno: człowiek,
// któremu zamknięto konto, nie wejdzie do serwisu, a DSA art. 20 daje mu prawo
// do odwołania właśnie od tej decyzji. Formularz zamiast tego prosi o login
// i hasło — sprawdzamy je, ale NIE logujemy nikogo i nie zdejmujemy blokady.
//
// Uzasadnienie wyboru „formularz zamknięty hasłem" zamiast formularza
// całkiem otwartego albo samego adresu e-mail: `AppealController`.
Route::get('/odwolanie', [AppealController::class, 'guestForm'])->name('appeals.guest');
Route::post('/odwolanie', [AppealController::class, 'guestStore'])
    ->middleware("throttle:{$limits['appeal']},appeal")
    ->name('appeals.guest.store');

// --------------------------------------------------------------------------
// Odwołanie od decyzji moderacyjnej — droga dla ZGŁASZAJĄCEGO (issue #23,
// DSA art. 20 ust. 1)
// --------------------------------------------------------------------------
//
// Zgłaszający może nie mieć konta wcale (art. 16 ust. 2 lit. c) — nie ma więc
// sesji ani hasła, którym mógłby się rozliczyć jak autor treści powyżej.
// Autoryzacją jest PODPISANY, WYGASAJĄCY link (`middleware('signed')`, ten
// sam mechanizm co `verification.verify` wyżej i `settings.data.download`),
// wysyłany mailem razem z decyzją (`DecyzjaWSprawieZgloszenia`). UUID
// zgłoszenia w adresie sam w sobie NIE JEST autoryzacją (AGENTS.md §7) —
// to podpis czyni ten link nie do podrobienia, nie sam identyfikator.
//
// Jedna trasa, dwie metody: formularz (GET) i wysyłka (POST) dzielą ten sam
// adres, więc jeden podpisany link z maila obsługuje obie — inaczej trzeba
// by podpisywać dwa osobne adresy i wygasłby tylko jeden z nich.
Route::match(['get', 'post'], '/zgloszenie/{report}/odwolanie', [ReporterAppealController::class, 'handle'])
    ->middleware(['signed', "throttle:{$limits['appeal']},appeal"])
    ->name('appeals.reporter');

// --------------------------------------------------------------------------
// Zalogowany
// --------------------------------------------------------------------------

Route::middleware('auth')->group(function () use ($limits): void {
    Route::get('/tagi/podpowiedzi', TagSuggestionController::class)
        ->middleware("throttle:{$limits['tag_suggestions']},tag_suggestions")
        ->name('tags.suggestions');

    Route::get('/home', [FeedController::class, 'home'])->name('home');
    Route::post('/instalacja/decyzja', [PwaInstallController::class, 'update'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia")
        ->name('pwa.decision');

    // Weryfikacja e-maila. Świadomie NIE blokuje publikowania — patrz
    // RegisterController. Wymagamy jej tylko przy eksporcie danych.
    Route::get('/potwierdz-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/potwierdz-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
        ->name('verification.verify');
    // Liczba przeniesiona do config/kuking.php (klucz `verification_resend`),
    // wartość bez zmian — AGENTS.md §7 mówi, że limity mieszkają w konfiguracji,
    // a nie w tym pliku.
    Route::post('/potwierdz-email/wyslij-ponownie', [EmailVerificationController::class, 'resend'])
        ->middleware("throttle:{$limits['verification_resend']},verification_resend")
        ->name('verification.send');

    // Onboarding
    Route::get('/witaj/zainteresowania', [OnboardingController::class, 'interests'])->name('onboarding.interests');
    Route::post('/witaj/zainteresowania', [OnboardingController::class, 'saveInterests'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia");
    // Ten sam koszyk wielkości co `search` (config/kuking.php) — ten krok od
    // teraz przyjmuje `?q=`, czyli odpytuje `SearchQuery::people()` tak samo
    // jak /szukaj. Osobna nazwa koszyka (jak przy `admin_uzytkownicy` niżej
    // w tym pliku), bo to inny ekran i inny licznik nadużyć.
    Route::get('/witaj/ludzie', [OnboardingController::class, 'people'])
        ->middleware("throttle:{$limits['search']},onboarding_ludzie")
        ->name('onboarding.people');
    // OSOBNY, DUŻO NIŻSZY LIMIT NIŻ RESZTA OBSERWOWANIA. To jedyny formularz
    // w serwisie, w którym JEDNO żądanie tworzy powiadomienia u WIELU osób
    // naraz — więc liczenie go do wspólnego koszyka `obserwowanie` byłoby
    // ochroną tylko z nazwy. Długość samej listy pilnuje walidacja
    // w `OnboardingController::saveFollows()`.
    Route::post('/witaj/ludzie', [OnboardingController::class, 'saveFollows'])
        ->middleware("throttle:{$limits['masowe_obserwowanie']},masowe_obserwowanie");
    Route::get('/witaj/gotowe', [OnboardingController::class, 'done'])->name('onboarding.done');

    // Dodawanie treści
    Route::view('/dodaj', 'pages.add')->name('add');

    Route::get('/dodaj/zdjecie', [PostController::class, 'create'])->name('posts.create');
    Route::post('/dodaj/zdjecie', [PostController::class, 'store'])
        ->middleware("throttle:{$limits['post']},post")
        ->name('posts.store');
    Route::post('/wpisy/{post}/komentarz', [PostController::class, 'comment'])
        ->middleware("throttle:{$limits['comment']},comment")
        ->name('posts.comment');
    // Edycja wpisu: tekst, widoczność, temat — nie zdjęcia (patrz komentarz
    // w PostController::edit i w EditPost). Ten sam limit co przy publikacji,
    // z config/kuking.php, a nie osobno wpisana liczba (AGENTS.md §7).
    Route::get('/wpisy/{post}/edycja', [PostController::class, 'edit'])->name('posts.edit');
    Route::put('/wpisy/{post}', [PostController::class, 'update'])
        ->middleware("throttle:{$limits['post']},post")
        ->name('posts.update');
    Route::delete('/wpisy/{post}', [PostController::class, 'destroy'])
        ->middleware("throttle:{$limits['usuwanie']},usuwanie")
        ->name('posts.destroy');

    // Zdjęcia w opublikowanym wpisie: kolejność i sposób wyświetlania (#92).
    // Zwykły GET i zwykły POST — bez JavaScriptu, bo to jedyna droga, na
    // której serwer w ogóle WIE, ile zdjęć ma wpis (patrz komentarz
    // w PostMediaController).
    Route::get('/wpisy/{post}/zdjecia', [PostMediaController::class, 'edit'])->name('posts.media.edit');
    Route::post('/wpisy/{post}/zdjecia', [PostMediaController::class, 'update'])
        ->middleware("throttle:{$limits['post']},post")
        ->name('posts.media.update');

    // „Nie pokazuj mi tego więcej" — ukrycie jednego wspomnienia (issue #34).
    // To jedno kliknięcie na własnym wpisie, ustawiające flagę na `true`,
    // i powtórzenie nie zmienia niczego — dlatego limit jest tu najluźniejszy
    // z możliwych (grupa `ustawienia`), a nie żaden. Wcześniej trasa nie miała
    // go wcale; „powtórzenie nic nie zmienia" nie jest jednak powodem, żeby
    // wpuścić na nią pętlę zapisów do bazy (AGENTS.md §7).
    Route::post('/wspomnienia/{post}/ukryj', [WspomnienieController::class, 'ukryj'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia")
        ->name('wspomnienia.ukryj');

    // Edycja i usunięcie komentarza — niezależne od tego, pod czym on wisi
    // (wpis, przepis czy "Ugotowałem"). Reguły kto-może-co żyją w CommentPolicy.
    Route::put('/komentarze/{comment}', [CommentController::class, 'update'])
        ->middleware("throttle:{$limits['comment']},comment")
        ->name('comments.update');
    Route::delete('/komentarze/{comment}', [CommentController::class, 'destroy'])
        ->middleware("throttle:{$limits['comment']},comment")
        ->name('comments.destroy');

    /*
     * DODAWANIE ≠ DOPISYWANIE SZCZEGÓŁÓW (issue #364).
     *
     * /dodaj/przepis                — sześć rzeczy i „Opublikuj". Zwykły POST,
     *                                 bez JavaScriptu. `?szkic={uuid}` wraca
     *                                 do niedokończonego szkicu w kreatorze.
     * /przepisy/{slug}/szczegoly    — „Dopisz szczegóły" w trzech krokach
     *                                 (Livewire, wymaga JS).
     * /przepisy/{slug}/edycja       — te same szczegóły na jednej stronie,
     *                                 zwykłym POST-em. Bez niej słaby zasięg
     *                                 zostawia człowieka z martwym kreatorem.
     * /dodaj/przepis/jedna-strona   — pełny formularz dodawania. Zostaje dla
     *                                 adresów, które ludzie mają zapisane;
     *                                 nic już do niego nie linkuje.
     */
    Route::get('/dodaj/przepis', [RecipeController::class, 'create'])->name('recipes.create');
    Route::get('/dodaj/szkice', [RecipeController::class, 'drafts'])->name('recipes.drafts');
    Route::get('/dodaj/przepis/jedna-strona', [RecipeController::class, 'createSimple'])->name('recipes.create.simple');
    Route::post('/dodaj/przepis', [RecipeController::class, 'store'])
        ->middleware("throttle:{$limits['post']},post")
        ->name('recipes.store');
    Route::get('/przepisy/{recipe}/szczegoly', [RecipeController::class, 'details'])->name('recipes.details');
    Route::get('/przepisy/{recipe}/edycja', [RecipeController::class, 'edit'])->name('recipes.edit');
    // Ten sam limit co przy publikacji i ten sam powód co przy `posts.update`:
    // każdy zapis przepisu tworzy nową wersję (`recipe_versions`), czyli jest
    // wytwarzaniem treści, a nie drobną poprawką pola.
    Route::put('/przepisy/{recipe}', [RecipeController::class, 'update'])
        ->middleware("throttle:{$limits['post']},post")
        ->name('recipes.update');
    Route::post('/przepisy/{recipe}/komentarz', [RecipeController::class, 'comment'])
        ->middleware("throttle:{$limits['comment']},comment")
        ->name('recipes.comment');
    Route::delete('/przepisy/{recipe}', [RecipeController::class, 'destroy'])
        ->middleware("throttle:{$limits['usuwanie']},usuwanie")
        ->name('recipes.destroy');

    // "Ugotowałem" — najważniejsza akcja w produkcie.
    Route::get('/przepisy/{recipe}/ugotowalem', [CookedEventController::class, 'create'])->name('cooked.create');
    Route::post('/przepisy/{recipe}/ugotowalem', [CookedEventController::class, 'store'])
        ->middleware("throttle:{$limits['post']},post")
        ->name('cooked.store');
    Route::post('/ugotowane/{cookedEvent}/komentarz', [CookedEventController::class, 'comment'])
        ->middleware("throttle:{$limits['comment']},comment")
        ->name('cooked.comment');
    Route::delete('/ugotowane/{cookedEvent}', [CookedEventController::class, 'destroy'])
        ->middleware("throttle:{$limits['usuwanie']},usuwanie")
        ->name('cooked.destroy');

    // „Komuś wyszło" (issue #17) — pełnoekranowa celebracja, wyłącznie dla
    // autora przepisu, osiągana z linku w powiadomieniu. Adres celowo inny
    // niż `cooked.show`, bo to inny ekran z inną autoryzacją (Policy::celebrate).
    Route::get('/ugotowane/{cookedEvent}/wyszlo', [CookedEventController::class, 'celebrate'])->name('cooked.celebrate');
    Route::post('/ugotowane/{cookedEvent}/podziekuj', [CookedEventController::class, 'thank'])
        ->middleware("throttle:{$limits['comment']},comment")
        ->name('cooked.thank');

    // Zeszyt (kolekcje)
    //
    // Zapis i wypisanie chodzą pod WŁASNYM kluczem `zeszyt`, a nie pod
    // budżetem publikacji — to prywatna, odwracalna akcja, nikogo nie
    // powiadamia i nikt jej nie widzi. Skasowanie CAŁEGO zeszytu jest już
    // czym innym i idzie do grupy `usuwanie`.
    Route::get('/zeszyt', [CollectionController::class, 'index'])->name('collections.index');
    Route::post('/zeszyt', [CollectionController::class, 'store'])
        ->middleware("throttle:{$limits['zeszyt']},zeszyt")
        ->name('collections.store');
    Route::get('/zeszyt/{collection}', [CollectionController::class, 'show'])->name('collections.show');
    // Cofnięcie publicznego udostępnienia bez kasowania zeszytu (issue #777).
    // Własny klucz `zeszyt`, nie `usuwanie` — to nie jest akcja destrukcyjna.
    Route::get('/zeszyt/{collection}/edytuj', [CollectionController::class, 'edit'])->name('collections.edit');
    Route::patch('/zeszyt/{collection}', [CollectionController::class, 'update'])
        ->middleware("throttle:{$limits['zeszyt']},zeszyt")
        ->name('collections.update');
    // Wyjęcie z zeszytu samych niedostępnych zapisów (#773). Kasuje powiązania,
    // nie treść — ale bez drogi powrotu, więc budżet `usuwanie`.
    Route::delete('/zeszyt/{collection}/niedostepne', [CollectionController::class, 'removeUnavailable'])
        ->middleware("throttle:{$limits['usuwanie']},usuwanie")
        ->name('collections.unavailable.destroy');
    Route::delete('/zeszyt/{collection}', [CollectionController::class, 'destroy'])
        ->middleware("throttle:{$limits['usuwanie']},usuwanie")
        ->name('collections.destroy');
    Route::post('/przepisy/{recipe}/zapisz', [CollectionController::class, 'saveRecipe'])
        ->middleware("throttle:{$limits['zeszyt']},zeszyt")
        ->name('collections.save');
    Route::delete('/przepisy/{recipe}/zapisz', [CollectionController::class, 'removeRecipe'])
        ->middleware("throttle:{$limits['zeszyt']},zeszyt")
        ->name('collections.unsave');

    // Zeszyt przyjmuje też WPISY (UI kit v2, ekran 01 — decyzja właściciela).
    //
    // PRZENIESIONE Z PREFIKSU `post` DO `zeszyt`. Wcześniej zapisywanie
    // cudzych wpisów zjadało budżet PUBLIKOWANIA własnych — czyli dokładnie
    // ten sam kształt usterki co w zgłoszeniu „429 przy pierwszym zdjęciu",
    // tylko na innej parze tras. Wieczór spędzony na przeglądaniu „Odkryj"
    // nie ma prawa odbierać prawa głosu.
    Route::post('/wpisy/{post}/zapisz', [CollectionController::class, 'savePost'])
        ->middleware("throttle:{$limits['zeszyt']},zeszyt")
        ->name('collections.save-post');
    Route::delete('/wpisy/{post}/zapisz', [CollectionController::class, 'removePost'])
        ->middleware("throttle:{$limits['zeszyt']},zeszyt")
        ->name('collections.unsave-post');

    // Relacje społeczne
    // Obserwowanie tagu (D-021, zastępuje usunięty już Temat z issue #31):
    // zwykłe formularze, bez JavaScriptu, bez powiadomienia (tag nie jest
    // człowiekiem, więc nikogo nie powiadamiamy).
    // JEDEN WSPÓLNY LICZNIK `obserwowanie` NA OBA KIERUNKI I NA OBA RODZAJE
    // (osoba, tag). Osobne liczniki dawałyby cyklowi obserwuj→przestań
    // podwójny budżet, czyli dokładnie tyle, ile potrzebuje wzorzec, przed
    // którym ten limit stoi. Tag nikogo nie powiadamia, ale dzieli koszyk
    // z osobą, bo próg ustawia groźniejsza połowa grupy.
    Route::post('/tag/{tag}/obserwuj', [TagFollowController::class, 'follow'])
        ->middleware("throttle:{$limits['obserwowanie']},obserwowanie")
        ->name('tags.follow');
    Route::delete('/tag/{tag}/obserwuj', [TagFollowController::class, 'unfollow'])
        ->middleware("throttle:{$limits['obserwowanie']},obserwowanie")
        ->name('tags.unfollow');

    Route::post('/@{username}/obserwuj', [SocialController::class, 'follow'])
        ->middleware("throttle:{$limits['obserwowanie']},obserwowanie")
        ->name('social.follow');
    Route::delete('/@{username}/obserwuj', [SocialController::class, 'unfollow'])
        ->middleware("throttle:{$limits['obserwowanie']},obserwowanie")
        ->name('social.unfollow');

    // BLOKADA MA WŁASNY KOSZYK, ODDZIELONY OD OBSERWOWANIA — świadomie.
    // To narzędzie bezpieczeństwa: sięga po nie ktoś, komu ktoś inny właśnie
    // uprzykrza życie. Gdyby dzieliła budżet z obserwowaniem, wieczór spędzony
    // w „Odkryj" mógłby odebrać prawo do zasłonięcia się przed nękaniem.
    Route::post('/@{username}/blokuj', [SocialController::class, 'block'])
        ->middleware("throttle:{$limits['blokada']},blokada")
        ->name('social.block');
    Route::delete('/@{username}/blokuj', [SocialController::class, 'unblock'])
        ->middleware("throttle:{$limits['blokada']},blokada")
        ->name('social.unblock');

    Route::get('/powiadomienia', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/powiadomienia/przeczytane', [NotificationController::class, 'markAllRead'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia")
        ->name('notifications.read');

    // „Zobacz" przy pojedynczym powiadomieniu: oznacza JE jako przeczytane
    // i odsyła do treści. POST, nie GET, bo to zapis — pełne uzasadnienie
    // w `NotificationController::open()`.
    Route::post('/powiadomienia/{notification}/zobacz', [NotificationController::class, 'open'])
        ->middleware("throttle:{$limits['powiadomienia']},powiadomienia")
        ->name('notifications.open');

    // Ustawienia

    /*
     * ROZDROŻE — `/ustawienia` (issue #344, koszt zapisany w D-168).
     *
     * Do dziś ten adres NIE ISTNIAŁ, a napis „Ustawienia" w obu miejscach
     * serwisu (nawigacja boczna na komputerze, rząd akcji własnego profilu)
     * prowadził na `settings.accessibility`, czyli na ekran o nagłówku
     * „Czytelność". D-168 przyjęło to świadomie jako koszt mniejszy niż jeden
     * napis o dwóch różnych celach — i zapisało, że zdjęcie tego kosztu
     * wymaga osobnej decyzji. Ta decyzja zapadła 12 września 2026.
     *
     * TRASA JEST PIERWSZA W BLOKU, bo jest wejściem do pozostałych — kolejność
     * w tym pliku ma odpowiadać kolejności, w jakiej się po nich chodzi.
     *
     * BEZ IDENTYFIKATORA W ADRESIE i bez Policy: ekran pokazuje wyłącznie
     * nazwy ekranów ustawień, identyczne dla każdego zalogowanego. Nie ma tu
     * cudzego zasobu, który dałoby się podmienić w adresie — uzasadnienie
     * pełne w `SettingsIndexController`.
     *
     * BEZ LIMITU `throttle`: to zwykły GET bez zapisu, jak `settings.profile`
     * niżej. Limity w tej grupie wiszą wyłącznie na czasownikach zapisujących
     * (pilnuje tego `LimityTrasZapisujacychTest`).
     */
    Route::get('/ustawienia', SettingsIndexController::class)->name('settings.index');

    Route::get('/ustawienia/profil', [ProfileSettingsController::class, 'edit'])->name('settings.profile');
    Route::put('/ustawienia/profil', [ProfileSettingsController::class, 'update'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia");

    /*
     * ZDJĘCIE PROFILOWE — OSOBNY, KRÓTKI EKRAN.
     *
     * Pole zdjęcia stało dotąd jako szóste pole formularza `/ustawienia/profil`.
     * Funkcja była, ale droga do niej wiodła przez menu → Ustawienia → Profil
     * → przewinięcie pod pięcioma polami, których człowiek nie zamierzał ruszać.
     * Skróty prowadzące tutaj (awatar na własnym profilu i zachęta „Dodaj swoje
     * zdjęcie") mają sens tylko wtedy, gdy prowadzą na ekran O JEDNEJ RZECZY —
     * kotwica `#f-avatar` w tamtym formularzu wyrzucała na telefonie w środek
     * ekranu pełnego innych pól.
     *
     * ADRES NIE MA IDENTYFIKATORA i to jest celowe: trasa działa zawsze na
     * profilu osoby zalogowanej, więc nie ma czego podmienić. Autoryzacja i tak
     * idzie przez `ProfilePolicy::update` w kontrolerze (AGENTS.md §7).
     *
     * LIMIT `ustawienia_profil` PRZENIÓSŁ SIĘ TUTAJ RAZEM Z POLEM PLIKU.
     * To jest teraz jedyny ekran ustawień, który przyjmuje PLIK, czyli ten,
     * którego jedno żądanie kosztuje serwer sekundy procesora i megabajty
     * (magic bytes, limit megapikseli, zapis do R2, zadanie w tle). Zapis
     * profilu bez zdjęcia to znowu zwykły UPDATE jednego wiersza, więc wraca
     * do wspólnej grupy `ustawienia`.
     *
     * Usunięcie zdjęcia zostaje w grupie `ustawienia`: to UPDATE jednej
     * kolumny i skasowanie plików, które i tak są własne.
     */
    Route::get('/ustawienia/zdjecie', [AvatarSettingsController::class, 'edit'])->name('settings.avatar');
    Route::post('/ustawienia/zdjecie', [AvatarSettingsController::class, 'update'])
        ->middleware("throttle:{$limits['ustawienia_profil']},ustawienia_profil")
        ->name('settings.avatar.update');
    Route::delete('/ustawienia/zdjecie', [AvatarSettingsController::class, 'destroy'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia")
        ->name('settings.avatar.destroy');

    // „Twoje tagi" (D-021, zastępuje usunięty już `/ustawienia/tematy`).
    Route::get('/ustawienia/tagi', [TagFollowController::class, 'edit'])->name('settings.tags');
    Route::put('/ustawienia/tagi', [TagFollowController::class, 'update'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia")
        ->name('settings.tags.update');
    // Filtr i „Pokaż kolejne…" — PRZEGLĄDANIE, nie zapis (#858, decyzja
    // właściciela z 20.09.2026, punkt 1). Osobna trasa i osobny koszyk
    // limitera, żeby szukanie tagu nie zjadało budżetu zapisu (`ustawienia`,
    // 30/10) i odwrotnie. Przyciski trafiają tu przez `formaction` w widoku
    // — bez tego wciąż jeden `<form>`, bez jednej linii JavaScriptu.
    Route::put('/ustawienia/tagi/przegladaj', [TagFollowController::class, 'przegladaj'])
        ->middleware("throttle:{$limits['tagi_przegladanie']},tagi_przegladanie")
        ->name('settings.tags.przegladaj');

    Route::get('/ustawienia/czytelnosc', [AccessibilitySettingsController::class, 'edit'])->name('settings.accessibility');
    Route::put('/ustawienia/czytelnosc', [AccessibilitySettingsController::class, 'update'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia");

    Route::get('/ustawienia/prywatnosc', [PrivacySettingsController::class, 'edit'])->name('settings.privacy');
    Route::put('/ustawienia/prywatnosc', [PrivacySettingsController::class, 'update'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia");

    Route::get('/ustawienia/twoje-dane', [DataSettingsController::class, 'show'])->name('settings.data');
    // Paczka RODO to najdroższe pojedyncze żądanie w serwisie — własny klucz
    // `eksport` w oknie godzinnym. Kontroler i tak odrzuca kolejne zgłoszenie,
    // gdy poprzednia paczka jeszcze się robi, więc z tych dziesięciu żądań
    // pracą stanie się co najwyżej jedno; limit łapie pętlę, nie człowieka,
    // który klika drugi raz, bo nie widzi postępu.
    Route::post('/ustawienia/twoje-dane/eksport', [DataSettingsController::class, 'requestExport'])
        ->middleware("throttle:{$limits['eksport']},eksport")
        ->name('settings.data.export');
    // Zgłoszenie usunięcia konta prosi o HASŁO (`Hash::check`), więc jest tą
    // samą wyrocznią co zmiana hasła i idzie do koszyka `confirm_password`,
    // a nie do zwykłych ustawień.
    Route::post('/ustawienia/twoje-dane/usun-konto', [DataSettingsController::class, 'requestDeletion'])
        ->middleware("throttle:{$limits['confirm_password']},confirm_password")
        ->name('settings.data.delete');

    // Pobranie paczki z danymi. `signed` = adres musi być podpisany przez nas
    // i nieprzedawniony; właściciela sprawdza dodatkowo kontroler, bo podpis
    // to nie autoryzacja (AGENTS.md, sekcja 7).
    Route::get('/ustawienia/twoje-dane/pobierz/{export}', [DataSettingsController::class, 'download'])
        ->middleware('signed')
        ->name('settings.data.download');

    // Bezpieczeństwo konta (issue #12): zmiana hasła i „wyloguj mnie z innych
    // urządzeń”. Obie akcje POST/PUT proszą o hasło, więc dostają ten sam
    // limit co reszta miejsc, w których ktoś zgaduje cudze hasło.
    Route::get('/ustawienia/bezpieczenstwo', [SecuritySettingsController::class, 'edit'])->name('settings.security');
    Route::put('/ustawienia/bezpieczenstwo/haslo', [SecuritySettingsController::class, 'updatePassword'])
        ->middleware("throttle:{$limits['confirm_password']},confirm_password")
        ->name('settings.security.password');
    Route::post('/ustawienia/bezpieczenstwo/wyloguj-inne', [SecuritySettingsController::class, 'logoutOtherSessions'])
        ->middleware("throttle:{$limits['confirm_password']},confirm_password")
        ->name('settings.security.logout-others');
    /*
     * Adres e-mail (issue #195).
     *
     * Do 9 września 2026 zalogowany człowiek NIGDZIE nie widział własnego
     * adresu i nie miał jak go poprawić — a od tego samego dnia poczta
     * naprawdę wysyła listy, więc literówka przy rejestracji znaczyła
     * konto bez drogi powrotu (RODO art. 16 też nie miał tu żadnej
     * realizacji).
     *
     * `POST /ustawienia/e-mail` prosi o obecne hasło i robi `Hash::check()`,
     * więc idzie do koszyka `confirm_password` — tego samego, co zmiana
     * hasła i wyłączenie 2FA. Jest tą samą wyrocznią i nie ma prawa mieć
     * luźniejszego limitu.
     *
     * Potwierdzenie ma `signed`, ten sam mechanizm co `verification.verify`
     * wyżej: identyfikator żądania w adresie NIE JEST autoryzacją
     * (AGENTS.md §7) — podpis czyni ten link nie do podrobienia, a właściciela
     * sprawdza jeszcze raz kontroler. Jest w grupie `auth`, więc kliknięcie
     * z niezalogowanej przeglądarki prowadzi najpierw na logowanie i wraca
     * tu samo (`redirect()->intended()` w `LoginController`).
     */
    Route::get('/ustawienia/e-mail', [EmailSettingsController::class, 'edit'])->name('settings.email');
    Route::post('/ustawienia/e-mail', [EmailSettingsController::class, 'request'])
        ->middleware("throttle:{$limits['confirm_password']},confirm_password")
        ->name('settings.email.request');
    Route::get('/ustawienia/e-mail/potwierdz/{zmiana}', [EmailSettingsController::class, 'confirm'])
        ->middleware(['signed', "throttle:{$limits['ustawienia']},ustawienia"])
        ->name('settings.email.confirm');
    Route::post('/ustawienia/e-mail/anuluj', [EmailSettingsController::class, 'cancel'])
        ->middleware("throttle:{$limits['ustawienia']},ustawienia")
        ->name('settings.email.cancel');

    // Weryfikacja dwuetapowa (2FA), issue #12. Obowiązkowa do wejścia
    // w panel moderacji (patrz middleware 'moderator.2fa' w grupie /admin
    // niżej), dla zwykłego konta zostaje opcjonalna.
    Route::get('/ustawienia/2fa', [TwoFactorSettingsController::class, 'edit'])->name('settings.two_factor.edit');
    Route::get('/ustawienia/2fa/wlacz', [TwoFactorSettingsController::class, 'create'])->name('settings.two_factor.enable');
    // Włączenie prosi o kod z NOWEGO telefonu ORAZ o obecne hasło (#1376,
    // D-245). Dwa limity naraz: kod TOTP ma swój koszyk, a `Hash::check()`
    // na haśle z formularza jest tą samą wyrocznią co wyłączenie 2FA, więc
    // nie ma prawa mieć luźniejszego limitu niż ono.
    Route::post('/ustawienia/2fa/wlacz', [TwoFactorSettingsController::class, 'confirm'])
        ->middleware(["throttle:{$limits['two_factor']},two_factor", "throttle:{$limits['confirm_password']},confirm_password"])
        ->name('settings.two_factor.confirm');
    Route::get('/ustawienia/2fa/kody-zapasowe', [TwoFactorSettingsController::class, 'codes'])->name('settings.two_factor.codes');
    // Nowy komplet kodów zapasowych bez zdejmowania 2FA. Ten sam limit co
    // reszta miejsc proszących o hasło — bo o hasło właśnie prosi.
    Route::post('/ustawienia/2fa/nowe-kody', [TwoFactorSettingsController::class, 'regenerateCodes'])
        ->middleware("throttle:{$limits['confirm_password']},confirm_password")
        ->name('settings.two_factor.regenerate');
    // Wyłączenie 2FA też prosi o hasło — ten sam koszyk co zmiana hasła.
    // Wcześniej ta trasa nie miała limitu wcale, mimo że sąsiadująca z nią
    // `settings.two_factor.regenerate` (również na hasło) już go miała.
    Route::post('/ustawienia/2fa/wylacz', [TwoFactorSettingsController::class, 'disable'])
        ->middleware("throttle:{$limits['confirm_password']},confirm_password")
        ->name('settings.two_factor.disable');

    // Odwołanie od decyzji moderacyjnej — droga dla osób, które MOGĄ wejść
    // do serwisu (aktywnych i zawieszonych). Wejście jest z powiadomienia
    // o decyzji, więc adres zawiera identyfikator TEJ decyzji.
    //
    // `EnsureAccountIsActive` przepuszcza tu POST mimo zawieszenia —
    // odwołanie, którego zawieszony nie może wysłać, nie jest odwołaniem.
    Route::get('/odwolanie/{action}', [AppealController::class, 'show'])->name('appeals.show');
    Route::post('/odwolanie/{action}', [AppealController::class, 'store'])
        ->middleware("throttle:{$limits['appeal']},appeal")
        ->name('appeals.store');

    // Zgłaszanie treści
    Route::get('/zglos/{type}/{id}', [ReportController::class, 'create'])->name('reports.create');
    Route::post('/zglos/{type}/{id}', [ReportController::class, 'store'])
        ->middleware("throttle:{$limits['report']},report")
        ->name('reports.store');

    // „Twoje zgłoszenia" — strona ZGŁASZAJĄCEGO (issue #10, DSA art. 16
    // ust. 4 i 5). Nie mylić z `/admin/zgloszenia`: tamto jest kolejką
    // moderatora i pokazuje wszystkie sprawy razem z danymi zgłaszających.
    //
    // Tu każdy widzi wyłącznie własne pisma — o co dba `ReportPolicy::view()`
    // wołane w kontrolerze, nie sam fakt, że w adresie stoi UUID
    // (AGENTS.md §7). Obie trasy tylko czytają, więc bez limitu zapytań:
    // limit ma sens tam, gdzie coś powstaje.
    Route::get('/zgloszenia', [ReportController::class, 'index'])->name('reports.mine');
    Route::get('/zgloszenia/{report}', [ReportController::class, 'show'])->name('reports.mine.show');
});

// --------------------------------------------------------------------------
// Moderacja
// --------------------------------------------------------------------------

// 'moderator.2fa' (issue #12) idzie ZAWSZE po 'moderator': zwykły
// użytkownik ma dalej dostawać 404 z EnsureUserIsModerator, nie dotrzeć
// do sprawdzenia 2FA, o którym nie musi wiedzieć, że istnieje.
//
// LIMIT ZAPYTAŃ NA WSZYSTKICH ZAPISUJĄCYCH TRASACH TEJ GRUPY (klucz
// `moderacja`) jest CELOWO NAJWYŻSZY W CAŁYM SERWISIE. Nie chroni przed
// spamem — przed nim chronią trzy warstwy stojące wcześniej (`auth`,
// `moderator`, obowiązkowe 2FA) — tylko przed przejętą sesją moderatora
// użytą maszynowo. Zespół to jedna–dwie osoby (D-012), więc limit, który
// zatrzymałby jedynego moderatora w środku fali spamu, byłby awarią
// gorszą niż jego brak. Uzasadnienie liczby: config/kuking.php.
Route::middleware(['auth', 'moderator', 'moderator.2fa'])->prefix('admin')->group(function () use ($limits): void {
    Route::get('/zgloszenia', [ModerationController::class, 'reports'])->name('admin.reports');
    Route::post('/zgloszenia/{report}', [ModerationController::class, 'decide'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.reports.decide');

    // Cofnięcie ukrycia albo usunięcia treści (#65). Osobno od `decide`, bo
    // przywrócenie przychodzi PO rozstrzygnięciu zgłoszenia, a `decide`
    // słusznie nie przyjmuje drugiej decyzji do tego samego zgłoszenia.
    Route::post('/zgloszenia/{report}/przywroc', [ModerationController::class, 'restore'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.reports.restore');

    // „Zdejmij z urzędu” — treść bez zgłoszenia (G31, D-251). Wejście przyciskiem
    // przy treści; Policy `removeExOfficio` pyta drugi raz, niezależnie od grupy.
    Route::get('/z-urzedu/{typ}/{id}', [ZUrzeduController::class, 'create'])
        ->whereIn('typ', array_keys(ZdejmijZUrzedu::TYPY))
        ->whereUuid('id')
        ->name('admin.z-urzedu.create');
    Route::post('/z-urzedu/{typ}/{id}', [ZUrzeduController::class, 'store'])
        ->whereIn('typ', array_keys(ZdejmijZUrzedu::TYPY))
        ->whereUuid('id')
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.z-urzedu.store');

    /*
     * Kolejka AUTOMATU (D-052) — treści oznaczone do przeglądu przez
     * wykrywacz sygnałów, których NIKT nie zgłosił.
     *
     * Osobny adres, bo to osobna praca: tam czekają ludzie i terminy z DSA
     * art. 16 ust. 5, tu leżą maszynowe podejrzenia, z których większość
     * okaże się niczym. Decyzja o pojedynczej treści zapada dalej przez
     * `admin.reports.decide` — automat nie dostaje własnej ścieżki decyzji,
     * bo nie ma własnego rodzaju decyzji.
     */
    Route::get('/sygnaly', [SygnalyController::class, 'index'])->name('admin.sygnaly');
    Route::post('/sygnaly/odrzuc', [SygnalyController::class, 'odrzucGrupe'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.sygnaly.dismiss');

    /*
     * Wiadomości z „Napisz do nas" — OSOBNA kolejka, nie zakładka zgłoszeń.
     *
     * Stoi w tej samej grupie co moderacja (te same trzy warstwy: `auth`,
     * `moderator`, obowiązkowe 2FA), bo czyta to ten sam człowiek — ale jest
     * osobnym ekranem, bo to jest inna praca: tu nie zapada decyzja, od której
     * ktoś się odwołuje, tylko odpisuje się człowiekowi albo poprawia kod.
     *
     * KAŻDA Z TYCH TRZECH TRAS I TAK PYTA POLITYKĘ
     * (`App\Policies\ContactMessagePolicy`) — middleware pilnuje wejścia do
     * panelu, nie prawa do konkretnego wiersza, a UUID w adresie nie jest
     * autoryzacją (AGENTS.md §7).
     */
    Route::get('/wiadomosci', [WiadomosciController::class, 'index'])->name('admin.contact');
    Route::get('/wiadomosci/{wiadomosc}', [WiadomosciController::class, 'show'])->name('admin.contact.show');
    Route::post('/wiadomosci/{wiadomosc}', [WiadomosciController::class, 'update'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.contact.update');

    /*
     * ODPOWIEDŹ POCZTĄ DO OSOBY, KTÓRA NAPISAŁA (D-058).
     *
     * OSOBNA TRASA OD `admin.contact.update`, bo to są dwie rzeczy o różnej
     * odwracalności: tam zapisuje się stan i notatkę (do poprawienia
     * w każdej chwili), tutaj wychodzi list, którego nie da się odwołać.
     * Jedna trasa znaczyłaby, że poprawienie literówki w notatce wysyła
     * drugi list.
     *
     * WŁASNY KLUCZ LIMITU (`kontakt_odpowiedz`, 20/10), NIE WSPÓLNY
     * `moderacja` (120/10). To jedyna trasa w panelu, która wysyła pocztę
     * na zewnątrz, a dzienny budżet EmailLabs to 300 listów dzielonych
     * z przypomnieniami hasła — pełne wyliczenie stoi przy kluczu
     * w `config/kuking.php`.
     *
     * Polityka i tak jest pytana w kontrolerze (`ContactMessagePolicy::reply`),
     * osobną zdolnością niż `handle` — wysłanie listu do człowieka z zewnątrz
     * nie jest tym samym co przestawienie stanu w naszej kolejce.
     */
    Route::post('/wiadomosci/{wiadomosc}/odpowiedz', [WiadomosciController::class, 'odpowiedz'])
        ->middleware("throttle:{$limits['kontakt_odpowiedz']},kontakt_odpowiedz")
        ->name('admin.contact.reply');

    // Kolejka odwołań (#10).
    Route::get('/odwolania', [AdminAppealController::class, 'index'])->name('admin.appeals');
    Route::post('/odwolania/{appeal}', [AdminAppealController::class, 'resolve'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.appeals.resolve');

    // Wybór redakcyjny na tablicę „kuKINGi na dziś".
    // Wpisy bez odpowiedzi (issue #6). To nie jest panel statystyk, tylko
    // lista rzeczy do zrobienia dzisiaj — odpowiedź od człowieka w ciągu doby
    // na pierwszy wpis jest ważniejsza niż którakolwiek funkcja z MVP.
    Route::get('/bez-odpowiedzi', [BezOdpowiedziController::class, 'index'])->name('admin.unanswered');
    Route::post('/bez-odpowiedzi/{post}', [BezOdpowiedziController::class, 'odpowiedz'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.unanswered.reply');

    /*
     * Kolaż zdjęć w hero strony powitalnej — zgłoszenie właściciela:
     * „dodaj funkcję w panelu admina by ustawiać te zdjęcia spośród
     * wszystkich publicznych od użytkowników".
     *
     * Ta sama rodzina co `kuking-na-dzis` niżej i ten sam kształt tras:
     * `GET` do obejrzenia, `PUT` do zapisania całego wyboru naraz, `DELETE`
     * do wyczyszczenia. Oba zapisy z limitem `moderacja` z `config/kuking.php`.
     *
     * O prawie do wejścia rozstrzyga `UserPolicy::moderate` w kontrolerze,
     * nie samo middleware grupy: w formularzu latają UUID-y cudzych zdjęć,
     * a UUID nie jest autoryzacją (AGENTS.md §7).
     */
    Route::get('/kolaz-powitalny', [HeroKolazController::class, 'edit'])->name('admin.hero-kolaz');
    Route::put('/kolaz-powitalny', [HeroKolazController::class, 'update'])
        ->middleware("throttle:{$limits['moderacja']},moderacja");
    Route::delete('/kolaz-powitalny', [HeroKolazController::class, 'destroy'])
        ->middleware("throttle:{$limits['moderacja']},moderacja");

    Route::get('/kuking-na-dzis', [DailyBoardController::class, 'edit'])->name('admin.daily-board');
    Route::put('/kuking-na-dzis', [DailyBoardController::class, 'update'])
        ->middleware("throttle:{$limits['moderacja']},moderacja");
    Route::delete('/kuking-na-dzis', [DailyBoardController::class, 'destroy'])
        ->middleware("throttle:{$limits['moderacja']},moderacja");

    // Tagi promowane (D-021, „tag promowany — lista gospodarza") — panel
    // zastępujący redakcyjną rolę dawnego Tematu. Trasa z `{tag}` wiąże się
    // po slugu (Tag::getRouteKeyName()), tak jak publiczna strona tagu.
    Route::get('/tagi-promowane', [TagPromotionController::class, 'edit'])->name('admin.tag-promotions');
    Route::post('/tagi-promowane', [TagPromotionController::class, 'store'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.tag-promotions.store');
    Route::put('/tagi-promowane/{tag}', [TagPromotionController::class, 'update'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.tag-promotions.update');
    Route::delete('/tagi-promowane/{tag}', [TagPromotionController::class, 'destroy'])
        ->middleware("throttle:{$limits['moderacja']},moderacja")
        ->name('admin.tag-promotions.destroy');

    /*
     * Konta użytkowników — lista do wglądu i karta pojedynczego konta.
     *
     * LIMIT NA LIŚCIE, MIMO ŻE TO `GET` I MIMO TRZECH WARSTW PRZED NIM.
     * Jedyna droga, którą ten ekran robi cokolwiek drogiego, to wyszukiwanie:
     * `LIKE '%…%'` po trzech kolumnach przy tysiącach kont. Bierzemy limit
     * `search` z `config/kuking.php` — ten sam, którym chroniona jest
     * wyszukiwarka publiczna, bo to jest ten sam rodzaj zapytania — ale
     * z WŁASNYM koszykiem (`admin_uzytkownicy`), żeby moderator szukający
     * konta nie zjadał sobie budżetu zwykłego szukania przepisów.
     * `config/kuking.php` zostaje jedynym źródłem prawdy o liczbie
     * (AGENTS.md §7).
     *
     * KARTA (`{user}`) BEZ LIMITU: to jest jedno zapytanie po kluczu głównym,
     * a każde wejście zostawia wpis w `audit_log` — limiter odcinałby wtedy
     * nie tyle nadużycie, ile ślad po nim.
     *
     * Obie trasy i tak przechodzą przez `UserPolicy::moderate` w kontrolerze:
     * middleware pilnuje wejścia do panelu, nie prawa do konkretnego wiersza,
     * a UUID w adresie nie jest autoryzacją (AGENTS.md §7).
     */
    Route::get('/uzytkownicy', [UzytkownicyController::class, 'index'])
        ->middleware("throttle:{$limits['search']},admin_uzytkownicy")
        ->name('admin.users');
    Route::get('/uzytkownicy/{user}', [UzytkownicyController::class, 'show'])->name('admin.users.show');

    /*
     * Nieudane zadania kolejki — DLACZEGO `/health` mówi `degraded`.
     *
     * Trasa stoi w tej grupie, więc przechodzi przez `auth`, `moderator`
     * i obowiązkowe 2FA — ale to NIE jest jej autoryzacja. Bramką jest
     * `UserPolicy::diagnozujKolejke()` w kontrolerze, a ona pyta o rolę
     * `admin`. Moderator wchodzi więc na `/admin`, ale tutaj dostaje 403:
     * ekran mówi, co się psuje w infrastrukturze, a to jest praca osoby
     * prowadzącej wdrożenie, nie osoby moderującej treści (D-039).
     *
     * Bez `{parametru}` w adresie i bez żadnej metody `POST`: ten ekran
     * wyłącznie CZYTA. Ponawianie i kasowanie zostało w
     * `kuking:martwe-zadania`, gdzie decyzję podejmuje człowiek po
     * zobaczeniu, kogo dotyczy.
     */
    Route::get('/kolejka', [KolejkaController::class, 'index'])->name('admin.kolejka');
});

// --------------------------------------------------------------------------
// Tagi (D-021, zastępuje usunięty już Temat z issue #31)
// --------------------------------------------------------------------------
//
// Spis wszystkich tematów (#273, druga połowa — D-026 dała słownik, ta
// trasa daje wejście do niego). PUBLICZNA, z tego samego powodu co strona
// tagu niżej: to jest odpowiednik Garnkowej „fotofory" — jawna, zamknięta
// lista, bez logowania (docs/product/PROSTOTA_JAK_GARNEK.md §5a).
// NAD `/tag/{tag}`: gdyby kolejność była odwrotna, nic by się nie zepsuło
// (inny literał ścieżki), ale trasy publiczne stoją tu razem, w kolejności
// „lista, potem karta", żeby nie trzeba było ich szukać w dwóch miejscach.
Route::get('/tagi', [TagController::class, 'index'])->name('tags.index');

// Strona tagu jest PUBLICZNA i celowo poza `auth`: to jedno z niewielu
// miejsc, w które ma sens trafić z wyszukiwarki. Sama lista wpisów jest
// filtrowana przez widoczność (Post::scopeWidoczneDla), więc gość widzi
// wyłącznie treści publiczne.
Route::get('/tag/{tag}', [TagController::class, 'show'])->name('tags.show');

/*
 * ZGŁOSZENIE NIELEGALNEJ TREŚCI — DROGA PUBLICZNA (DSA art. 16).
 *
 * POZA GRUPĄ `auth`, ŚWIADOMIE I Z KONIECZNOŚCI. Art. 16 wymaga mechanizmu
 * dostępnego dla KAŻDEJ osoby i każdego podmiotu. Zgłasza prawnik w imieniu
 * klienta, rodzic, który rozpoznał dziecko na cudzym zdjęciu, albo autor
 * tekstu przepisanego tu bez zgody. Żadna z tych osób nie ma konta w serwisie
 * kulinarnym i nie ma powodu, żeby je zakładać.
 *
 * Formularz „Zgłoś" pod treścią ZOSTAJE za logowaniem (`reports.create`
 * wyżej) — on obsługuje nasze zasady, nie obowiązek z przepisu.
 *
 * Ochroną jest limit żądań, nie logowanie. Logowanie wykluczyłoby tych,
 * dla których ten mechanizm istnieje.
 */
Route::get('/zglos-nielegalna-tresc', [ZgloszenieNielegalnejTresciController::class, 'create'])
    ->name('zglos.nielegalna');
Route::post('/zglos-nielegalna-tresc', [ZgloszenieNielegalnejTresciController::class, 'store'])
    ->middleware('throttle:'.$limits['legal_notice'].',legal_notice')
    ->name('zglos.nielegalna.store');
Route::get('/zglos-nielegalna-tresc/przyjete', [ZgloszenieNielegalnejTresciController::class, 'confirmation'])
    ->name('zglos.nielegalna.potwierdzenie');

// --------------------------------------------------------------------------
// Zgłoszenia naruszeń CSP
// --------------------------------------------------------------------------
//
// Adres podawany przeglądarce w nagłówku Content-Security-Policy. Wysyła tu
// SAMA PRZEGLĄDARKA, bez sesji i bez tokenu CSRF — dlatego trasa musi być
// wyjęta spod ochrony CSRF (bootstrap/app.php) i mieć własny limit zapytań.
//
// Limit jest tu ważniejszy niż przy zwykłych formularzach: jedna zapętlona
// wtyczka w przeglądarce potrafi wysłać setki zgłoszeń na minutę, a każde
// z nich to wpis w logu.
Route::post('/_csp', CspReportController::class)
    ->middleware('throttle:'.$limits['csp_report'].',csp_report')
    ->name('csp.report');

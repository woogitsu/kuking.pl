<?php

declare(strict_types=1);

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Admin\AppealController as AdminAppealController;
use App\Http\Controllers\Admin\BezOdpowiedziController;
use App\Http\Controllers\Admin\DailyBoardController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\SygnalyController;
use App\Http\Controllers\Admin\TagPromotionController;
use App\Http\Controllers\Admin\UzytkownicyController;
use App\Http\Controllers\Admin\WiadomosciController;
use App\Http\Controllers\AppealController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CookedEventController;
use App\Http\Controllers\CookingModeController;
use App\Http\Controllers\CspReportController;
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
use App\Http\Controllers\Settings\TwoFactorSettingsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\TagFollowController;
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
Route::get('/odkryj', [FeedController::class, 'discover'])->name('discover');
Route::get('/szukaj', [SearchController::class, 'index'])
    ->middleware("throttle:{$limits['search']},search")
    ->name('search');

Route::get('/health', HealthController::class)->name('health');
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
Route::post('/przepisy/{recipe}/gotuj', [CookingModeController::class, 'zaznacz'])
    ->middleware("throttle:{$limits['cooking_krok']},cooking_krok")
    ->name('cooking.zaznacz');

Route::get('/wpisy/{post}', [PostController::class, 'show'])->name('posts.show');
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
    ->whereIn('wariant', array_keys((array) config('kuking.media.variants')))
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
    Route::get('/home', [FeedController::class, 'home'])->name('home');

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
    Route::get('/witaj/ludzie', [OnboardingController::class, 'people'])->name('onboarding.people');
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

    // Dwie drogi do tego samego przepisu i obie są prawdziwe:
    // /dodaj/przepis to kreator w krokach (Livewire, wymaga JS),
    // /dodaj/przepis/jedna-strona to ten sam formularz zwykłym POST-em,
    // bez JavaScriptu. Druga trasa nie jest zaszłością — bez niej słaby
    // zasięg zostawia użytkownika z martwym formularzem.
    Route::get('/dodaj/przepis', [RecipeController::class, 'create'])->name('recipes.create');
    Route::get('/dodaj/przepis/jedna-strona', [RecipeController::class, 'createSimple'])->name('recipes.create.simple');
    Route::post('/dodaj/przepis', [RecipeController::class, 'store'])
        ->middleware("throttle:{$limits['post']},post")
        ->name('recipes.store');
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
    Route::post('/ustawienia/2fa/wlacz', [TwoFactorSettingsController::class, 'confirm'])
        ->middleware("throttle:{$limits['two_factor']},two_factor")
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
});

// --------------------------------------------------------------------------
// Tagi (D-021, zastępuje usunięty już Temat z issue #31)
// --------------------------------------------------------------------------
//
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

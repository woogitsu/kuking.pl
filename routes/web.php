<?php

declare(strict_types=1);

use App\Http\Controllers\AccountDeletionController;
use App\Http\Controllers\Admin\AppealController as AdminAppealController;
use App\Http\Controllers\Admin\BezOdpowiedziController;
use App\Http\Controllers\Admin\DailyBoardController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\AppealController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\CookedEventController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\AccessibilitySettingsController;
use App\Http\Controllers\Settings\DataSettingsController;
use App\Http\Controllers\Settings\PrivacySettingsController;
use App\Http\Controllers\Settings\ProfileSettingsController;
use App\Http\Controllers\Settings\TwoFactorSettingsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\StaticPageController;
use App\Http\Controllers\TopicController;
use App\Http\Controllers\TopicFollowController;
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
    ->middleware("throttle:{$limits['search']}")
    ->name('search');

Route::get('/health', HealthController::class)->name('health');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])->name('robots');

Route::get('/pomoc', [StaticPageController::class, 'help'])->name('help');
Route::get('/zasady', [StaticPageController::class, 'rules'])->name('rules');
Route::get('/o-kuking', [StaticPageController::class, 'about'])->name('about');
Route::get('/regulamin', [StaticPageController::class, 'terms'])->name('terms');
Route::get('/prywatnosc', [StaticPageController::class, 'privacy'])->name('privacy');

Route::get('/przepisy/{recipe}', [RecipeController::class, 'show'])->name('recipes.show');
Route::get('/wpisy/{post}', [PostController::class, 'show'])->name('posts.show');
Route::get('/ugotowane/{cookedEvent}', [CookedEventController::class, 'show'])->name('cooked.show');

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
        ->middleware("throttle:{$limits['register']}");

    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware("throttle:{$limits['login']}");

    Route::get('/nie-pamietam-hasla', [PasswordResetController::class, 'requestForm'])->name('password.request');
    Route::post('/nie-pamietam-hasla', [PasswordResetController::class, 'sendLink'])
        ->middleware("throttle:{$limits['password_reset']}")
        ->name('password.email');

    Route::get('/nowe-haslo/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/nowe-haslo', [PasswordResetController::class, 'reset'])
        ->middleware("throttle:{$limits['password_reset']}")
        ->name('password.update');

    // Drugi krok logowania dla konta z potwierdzonym 2FA (issue #12).
    // Zostaje w grupie `guest` z tego samego powodu co /login: to jeszcze
    // NIE JEST sesja zalogowana (LoginController zapisuje tu tylko
    // identyfikator konta w sesji, patrz TwoFactorChallengeController) —
    // ktoś już zalogowany nie ma po co tu wracać.
    Route::get('/logowanie/kod', [TwoFactorChallengeController::class, 'show'])->name('login.two_factor');
    Route::post('/logowanie/kod', [TwoFactorChallengeController::class, 'store'])
        ->middleware("throttle:{$limits['two_factor']}")
        ->name('login.two_factor.store');

    // Cofnięcie zgłoszonego usunięcia konta — dla osoby, którą
    // `EnsureAccountIsActive` już wylogowało (status `pending_delete`
    // nie loguje się, patrz `LoginController`). Publiczne z konieczności,
    // tak samo jak formularz odwołań dla zablokowanych kont (issue #10) —
    // patrz komentarz w `AccountDeletionController` (audyt A8).
    Route::get('/cofnij-usuniecie-konta', [AccountDeletionController::class, 'showCancelForm'])
        ->name('account.delete.cancel');
    Route::post('/cofnij-usuniecie-konta', [AccountDeletionController::class, 'cancel'])
        ->middleware("throttle:{$limits['cancel_delete']}")
        ->name('account.delete.cancel.store');
});

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
    ->middleware("throttle:{$limits['appeal']}")
    ->name('appeals.guest.store');

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
    Route::post('/potwierdz-email/wyslij-ponownie', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Onboarding
    Route::get('/witaj/zainteresowania', [OnboardingController::class, 'interests'])->name('onboarding.interests');
    Route::post('/witaj/zainteresowania', [OnboardingController::class, 'saveInterests']);
    Route::get('/witaj/ludzie', [OnboardingController::class, 'people'])->name('onboarding.people');
    Route::post('/witaj/ludzie', [OnboardingController::class, 'saveFollows']);
    Route::get('/witaj/gotowe', [OnboardingController::class, 'done'])->name('onboarding.done');

    // Dodawanie treści
    Route::view('/dodaj', 'pages.add')->name('add');

    Route::get('/dodaj/zdjecie', [PostController::class, 'create'])->name('posts.create');
    Route::post('/dodaj/zdjecie', [PostController::class, 'store'])
        ->middleware("throttle:{$limits['post']}")
        ->name('posts.store');
    Route::post('/wpisy/{post}/komentarz', [PostController::class, 'comment'])
        ->middleware("throttle:{$limits['comment']}")
        ->name('posts.comment');
    Route::delete('/wpisy/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

    // Edycja i usunięcie komentarza — niezależne od tego, pod czym on wisi
    // (wpis, przepis czy "Ugotowałem"). Reguły kto-może-co żyją w CommentPolicy.
    Route::put('/komentarze/{comment}', [CommentController::class, 'update'])
        ->middleware("throttle:{$limits['comment']}")
        ->name('comments.update');
    Route::delete('/komentarze/{comment}', [CommentController::class, 'destroy'])
        ->middleware("throttle:{$limits['comment']}")
        ->name('comments.destroy');

    // Dwie drogi do tego samego przepisu i obie są prawdziwe:
    // /dodaj/przepis to kreator w krokach (Livewire, wymaga JS),
    // /dodaj/przepis/jedna-strona to ten sam formularz zwykłym POST-em,
    // bez JavaScriptu. Druga trasa nie jest zaszłością — bez niej słaby
    // zasięg zostawia użytkownika z martwym formularzem.
    Route::get('/dodaj/przepis', [RecipeController::class, 'create'])->name('recipes.create');
    Route::get('/dodaj/przepis/jedna-strona', [RecipeController::class, 'createSimple'])->name('recipes.create.simple');
    Route::post('/dodaj/przepis', [RecipeController::class, 'store'])
        ->middleware("throttle:{$limits['post']}")
        ->name('recipes.store');
    Route::get('/przepisy/{recipe}/edycja', [RecipeController::class, 'edit'])->name('recipes.edit');
    Route::put('/przepisy/{recipe}', [RecipeController::class, 'update'])->name('recipes.update');
    Route::post('/przepisy/{recipe}/komentarz', [RecipeController::class, 'comment'])
        ->middleware("throttle:{$limits['comment']}")
        ->name('recipes.comment');
    Route::delete('/przepisy/{recipe}', [RecipeController::class, 'destroy'])->name('recipes.destroy');

    // "Ugotowałem" — najważniejsza akcja w produkcie.
    Route::get('/przepisy/{recipe}/ugotowalem', [CookedEventController::class, 'create'])->name('cooked.create');
    Route::post('/przepisy/{recipe}/ugotowalem', [CookedEventController::class, 'store'])
        ->middleware("throttle:{$limits['post']}")
        ->name('cooked.store');
    Route::post('/ugotowane/{cookedEvent}/komentarz', [CookedEventController::class, 'comment'])
        ->middleware("throttle:{$limits['comment']}")
        ->name('cooked.comment');
    Route::delete('/ugotowane/{cookedEvent}', [CookedEventController::class, 'destroy'])->name('cooked.destroy');

    // Zeszyt (kolekcje)
    Route::get('/zeszyt', [CollectionController::class, 'index'])->name('collections.index');
    Route::post('/zeszyt', [CollectionController::class, 'store'])->name('collections.store');
    Route::get('/zeszyt/{collection}', [CollectionController::class, 'show'])->name('collections.show');
    Route::delete('/zeszyt/{collection}', [CollectionController::class, 'destroy'])->name('collections.destroy');
    Route::post('/przepisy/{recipe}/zapisz', [CollectionController::class, 'saveRecipe'])->name('collections.save');
    Route::delete('/przepisy/{recipe}/zapisz', [CollectionController::class, 'removeRecipe'])->name('collections.unsave');

    // Relacje społeczne
    // Obserwowanie tematu: zwykłe formularze, bez JavaScriptu. Temat nie
    // jest człowiekiem, więc nikogo nie powiadamiamy (issue #31).
    Route::post('/temat/{topic}/obserwuj', [TopicFollowController::class, 'follow'])->name('topics.follow');
    Route::delete('/temat/{topic}/obserwuj', [TopicFollowController::class, 'unfollow'])->name('topics.unfollow');

    Route::post('/@{username}/obserwuj', [SocialController::class, 'follow'])->name('social.follow');
    Route::delete('/@{username}/obserwuj', [SocialController::class, 'unfollow'])->name('social.unfollow');
    Route::post('/@{username}/blokuj', [SocialController::class, 'block'])->name('social.block');
    Route::delete('/@{username}/blokuj', [SocialController::class, 'unblock'])->name('social.unblock');

    Route::get('/powiadomienia', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/powiadomienia/przeczytane', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    // Ustawienia
    Route::get('/ustawienia/profil', [ProfileSettingsController::class, 'edit'])->name('settings.profile');
    Route::put('/ustawienia/profil', [ProfileSettingsController::class, 'update']);

    Route::get('/ustawienia/tematy', [TopicFollowController::class, 'edit'])->name('settings.topics');
    Route::put('/ustawienia/tematy', [TopicFollowController::class, 'update'])->name('settings.topics.update');

    Route::get('/ustawienia/czytelnosc', [AccessibilitySettingsController::class, 'edit'])->name('settings.accessibility');
    Route::put('/ustawienia/czytelnosc', [AccessibilitySettingsController::class, 'update']);

    Route::get('/ustawienia/prywatnosc', [PrivacySettingsController::class, 'edit'])->name('settings.privacy');
    Route::put('/ustawienia/prywatnosc', [PrivacySettingsController::class, 'update']);

    Route::get('/ustawienia/twoje-dane', [DataSettingsController::class, 'show'])->name('settings.data');
    Route::post('/ustawienia/twoje-dane/eksport', [DataSettingsController::class, 'requestExport'])->name('settings.data.export');
    Route::post('/ustawienia/twoje-dane/usun-konto', [DataSettingsController::class, 'requestDeletion'])->name('settings.data.delete');

    // Pobranie paczki z danymi. `signed` = adres musi być podpisany przez nas
    // i nieprzedawniony; właściciela sprawdza dodatkowo kontroler, bo podpis
    // to nie autoryzacja (AGENTS.md, sekcja 7).
    Route::get('/ustawienia/twoje-dane/pobierz/{export}', [DataSettingsController::class, 'download'])
        ->middleware('signed')
        ->name('settings.data.download');

    // Weryfikacja dwuetapowa (2FA), issue #12. Obowiązkowa do wejścia
    // w panel moderacji (patrz middleware 'moderator.2fa' w grupie /admin
    // niżej), dla zwykłego konta zostaje opcjonalna.
    Route::get('/ustawienia/2fa', [TwoFactorSettingsController::class, 'edit'])->name('settings.two_factor.edit');
    Route::get('/ustawienia/2fa/wlacz', [TwoFactorSettingsController::class, 'create'])->name('settings.two_factor.enable');
    Route::post('/ustawienia/2fa/wlacz', [TwoFactorSettingsController::class, 'confirm'])
        ->middleware("throttle:{$limits['two_factor']}")
        ->name('settings.two_factor.confirm');
    Route::get('/ustawienia/2fa/kody-zapasowe', [TwoFactorSettingsController::class, 'codes'])->name('settings.two_factor.codes');
    Route::post('/ustawienia/2fa/wylacz', [TwoFactorSettingsController::class, 'disable'])->name('settings.two_factor.disable');

    // Odwołanie od decyzji moderacyjnej — droga dla osób, które MOGĄ wejść
    // do serwisu (aktywnych i zawieszonych). Wejście jest z powiadomienia
    // o decyzji, więc adres zawiera identyfikator TEJ decyzji.
    //
    // `EnsureAccountIsActive` przepuszcza tu POST mimo zawieszenia —
    // odwołanie, którego zawieszony nie może wysłać, nie jest odwołaniem.
    Route::get('/odwolanie/{action}', [AppealController::class, 'show'])->name('appeals.show');
    Route::post('/odwolanie/{action}', [AppealController::class, 'store'])
        ->middleware("throttle:{$limits['appeal']}")
        ->name('appeals.store');

    // Zgłaszanie treści
    Route::get('/zglos/{type}/{id}', [ReportController::class, 'create'])->name('reports.create');
    Route::post('/zglos/{type}/{id}', [ReportController::class, 'store'])
        ->middleware("throttle:{$limits['report']}")
        ->name('reports.store');
});

// --------------------------------------------------------------------------
// Moderacja
// --------------------------------------------------------------------------

// 'moderator.2fa' (issue #12) idzie ZAWSZE po 'moderator': zwykły
// użytkownik ma dalej dostawać 404 z EnsureUserIsModerator, nie dotrzeć
// do sprawdzenia 2FA, o którym nie musi wiedzieć, że istnieje.
Route::middleware(['auth', 'moderator', 'moderator.2fa'])->prefix('admin')->group(function (): void {
    Route::get('/zgloszenia', [ModerationController::class, 'reports'])->name('admin.reports');
    Route::post('/zgloszenia/{report}', [ModerationController::class, 'decide'])->name('admin.reports.decide');

    // Cofnięcie ukrycia albo usunięcia treści (#65). Osobno od `decide`, bo
    // przywrócenie przychodzi PO rozstrzygnięciu zgłoszenia, a `decide`
    // słusznie nie przyjmuje drugiej decyzji do tego samego zgłoszenia.
    Route::post('/zgloszenia/{report}/przywroc', [ModerationController::class, 'restore'])
        ->name('admin.reports.restore');

    // Kolejka odwołań (#10).
    Route::get('/odwolania', [AdminAppealController::class, 'index'])->name('admin.appeals');
    Route::post('/odwolania/{appeal}', [AdminAppealController::class, 'resolve'])->name('admin.appeals.resolve');

    // Wybór redakcyjny na tablicę „kuKINGi na dziś".
    // Wpisy bez odpowiedzi (issue #6). To nie jest panel statystyk, tylko
    // lista rzeczy do zrobienia dzisiaj — odpowiedź od człowieka w ciągu doby
    // na pierwszy wpis jest ważniejsza niż którakolwiek funkcja z MVP.
    Route::get('/bez-odpowiedzi', [BezOdpowiedziController::class, 'index'])->name('admin.unanswered');
    Route::post('/bez-odpowiedzi/{post}', [BezOdpowiedziController::class, 'odpowiedz'])->name('admin.unanswered.reply');

    Route::get('/kuking-na-dzis', [DailyBoardController::class, 'edit'])->name('admin.daily-board');
    Route::put('/kuking-na-dzis', [DailyBoardController::class, 'update']);
    Route::delete('/kuking-na-dzis', [DailyBoardController::class, 'destroy']);
});

// --------------------------------------------------------------------------
// Tematy
// --------------------------------------------------------------------------
//
// Strona tematu jest PUBLICZNA i celowo poza `auth`: to jedno z niewielu
// miejsc, w które ma sens trafić z wyszukiwarki. Sama lista wpisów jest
// filtrowana przez widoczność (Post::scopeWidoczneDla), więc gość widzi
// wyłącznie treści publiczne.
Route::get('/temat/{topic}', [TopicController::class, 'show'])->name('topics.show');

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
    ->middleware('throttle:'.$limits['csp_report'])
    ->name('csp.report');

<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CookedEventController;
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
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\StaticPageController;
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
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

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
    Route::post('/@{username}/obserwuj', [SocialController::class, 'follow'])->name('social.follow');
    Route::delete('/@{username}/obserwuj', [SocialController::class, 'unfollow'])->name('social.unfollow');
    Route::post('/@{username}/blokuj', [SocialController::class, 'block'])->name('social.block');
    Route::delete('/@{username}/blokuj', [SocialController::class, 'unblock'])->name('social.unblock');

    Route::get('/powiadomienia', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/powiadomienia/przeczytane', [NotificationController::class, 'markAllRead'])->name('notifications.read');

    // Ustawienia
    Route::get('/ustawienia/profil', [ProfileSettingsController::class, 'edit'])->name('settings.profile');
    Route::put('/ustawienia/profil', [ProfileSettingsController::class, 'update']);

    Route::get('/ustawienia/czytelnosc', [AccessibilitySettingsController::class, 'edit'])->name('settings.accessibility');
    Route::put('/ustawienia/czytelnosc', [AccessibilitySettingsController::class, 'update']);

    Route::get('/ustawienia/prywatnosc', [PrivacySettingsController::class, 'edit'])->name('settings.privacy');
    Route::put('/ustawienia/prywatnosc', [PrivacySettingsController::class, 'update']);

    Route::get('/ustawienia/twoje-dane', [DataSettingsController::class, 'show'])->name('settings.data');
    Route::post('/ustawienia/twoje-dane/eksport', [DataSettingsController::class, 'requestExport'])->name('settings.data.export');
    Route::post('/ustawienia/twoje-dane/usun-konto', [DataSettingsController::class, 'requestDeletion'])->name('settings.data.delete');

    // Zgłaszanie treści
    Route::get('/zglos/{type}/{id}', [ReportController::class, 'create'])->name('reports.create');
    Route::post('/zglos/{type}/{id}', [ReportController::class, 'store'])
        ->middleware("throttle:{$limits['report']}")
        ->name('reports.store');
});

// --------------------------------------------------------------------------
// Moderacja
// --------------------------------------------------------------------------

Route::middleware(['auth', 'moderator'])->prefix('admin')->group(function (): void {
    Route::get('/zgloszenia', [ModerationController::class, 'reports'])->name('admin.reports');
    Route::post('/zgloszenia/{report}', [ModerationController::class, 'decide'])->name('admin.reports.decide');
});

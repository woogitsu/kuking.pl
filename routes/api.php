<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Publiczne API v1 — dla aplikacji mobilnej (D-014, D-270)
|--------------------------------------------------------------------------
|
| Każda trasa tutaj dostaje z `bootstrap/app.php` prefiks `/api/v1` i grupę
| `api`: `BramaApi` (wyłącznik `KUKING_API_ENABLED`, domyślnie zamknięty),
| limiter `api` (na token i na adres IP) i wiązanie modeli.
|
| ZASADY, KTÓRYCH TEN PLIK NIE ŁAMIE:
|
|  - kontroler API jest ADAPTEREM: waliduje, woła tę samą Akcję z
|    `app/Domain/…/Actions` co kontroler HTML i zwraca zasób JSON. Reguła
|    domenowa w kontrolerze API to reguła, którą da się obejść drugim
|    endpointem (AGENTS.md §4);
|  - każde wejście na cudzą treść przechodzi przez TĘ SAMĄ Policy co WWW —
|    UUID w adresie nie jest autoryzacją (AGENTS.md §7);
|  - każda trasa poza wydaniem tokenu ma `auth:sanctum`.
*/

use App\Domain\Media\PodgladOdRazu;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\JaController;
use App\Http\Controllers\Api\V1\PostController;
use App\Http\Controllers\Api\V1\ProfilController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\MediaController;
use App\Http\Middleware\EnsureApiAccountIsActive;
use Illuminate\Support\Facades\Route;

$limits = config('kuking.limits');

/*
|--------------------------------------------------------------------------
| Logowanie — jedyne trasy bez `auth:sanctum`
|--------------------------------------------------------------------------
|
| Limity TE SAME CO NA WWW, z tymi samymi prefiksami: `login` jak `POST
| /login`, `two_factor` jak `POST /logowanie/kod`. Wspólny prefiks znaczy
| wspólne wiadro — zgadujący nie podwoi budżetu, przeskakując między
| formularzem a aplikacją. Prawdziwą ochronę hasła niosą i tak trzy koszyki
| `LimitProbHasla` (w `SprawdzHasloPrzyLogowaniu`), też wspólne.
*/
Route::post('/tokeny', [TokenController::class, 'store'])
    ->middleware("throttle:{$limits['login']},login")
    ->name('api.tokeny.store');

Route::post('/tokeny/kod', [TokenController::class, 'storeKod'])
    ->middleware("throttle:{$limits['two_factor']},two_factor")
    ->name('api.tokeny.kod');

/*
|--------------------------------------------------------------------------
| Z tokenem
|--------------------------------------------------------------------------
|
| `EnsureApiAccountIsActive` ZA `auth:sanctum` — potrzebuje rozpoznanej
| osoby. Kolejność w tablicy jest kolejnością wykonania: ta klasa nie stoi
| na liście priorytetów frameworka, więc sortowanie jej nie przestawia.
*/
Route::middleware(['auth:sanctum', EnsureApiAccountIsActive::class])->group(function () use ($limits): void {
    Route::get('/ja', JaController::class)->name('api.ja');

    Route::delete('/tokeny/biezacy', [TokenController::class, 'destroyCurrent'])
        ->name('api.tokeny.biezacy.destroy');

    /*
     * CZYTANIE (D-272). Każda trasa z identyfikatorem przechodzi przez tę
     * samą Policy co jej odpowiednik na WWW — i jest w
     * `KazdaTrasaZIdentyfikatoremPodPolicyTest`. `whereUuid` z tego samego
     * powodu co na WWW: identyfikator, który nie jest UUID-em, ma dać 404,
     * nie błąd składni z PostgreSQL-a.
     */
    Route::get('/feed', FeedController::class)->name('api.feed');

    Route::get('/wpisy/{post}', [PostController::class, 'show'])
        ->whereUuid('post')
        ->name('api.wpisy.show');
    Route::get('/wpisy/{post}/komentarze', [PostController::class, 'comments'])
        ->whereUuid('post')
        ->name('api.wpisy.komentarze');

    Route::get('/przepisy/{przepis}', [RecipeController::class, 'show'])
        ->whereUuid('przepis')
        ->name('api.przepisy.show');
    Route::get('/przepisy/{przepis}/komentarze', [RecipeController::class, 'comments'])
        ->whereUuid('przepis')
        ->name('api.przepisy.komentarze');

    Route::get('/profile/{username}', [ProfilController::class, 'show'])
        ->name('api.profile.show');

    /*
     * ZDJĘCIA — TEN SAM KONTROLER CO WWW (`media.show`), bez kopii logiki.
     * `MediaController` pyta `DostepDoZdjecia` (Policy rodzica), wybiera
     * wariant i odsyła podpisany adres R2 albo plik; żądanie z nagłówkiem
     * `Authorization` nigdy nie trafia do wspólnego cache'u. Ten sam limit
     * i prefiks `zdjecie` co na WWW.
     */
    Route::get('/zdjecia/{media}/{wariant}', [MediaController::class, 'show'])
        ->whereUuid('media')
        ->whereIn('wariant', [
            PodgladOdRazu::NAZWA,
            ...array_keys((array) config('kuking.media.variants')),
        ])
        ->middleware("throttle:{$limits['zdjecie']},zdjecie")
        ->name('api.zdjecia.show');
});

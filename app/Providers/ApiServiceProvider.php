<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiter as Limiter;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Publiczne API dla aplikacji mobilnej (D-014, D-270): model tokenu
 * i limiter `api`. Osobny provider, żeby całe API dało się znaleźć w jednym
 * miejscu, a nie w środku `AppServiceProvider`.
 */
class ApiServiceProvider extends ServiceProvider
{
    /**
     * Prefiksy liczników. Osobne od WSZYSTKICH prefiksów z
     * `config('kuking.limits')` — jeden prefiks to zawsze jeden limit
     * (`LicznikiLimitowNieMieszajaSieMiedzyTrasamiTest`).
     */
    public const PREFIKS_TOKENU = 'api-token:';

    public const PREFIKS_ADRESU = 'api-adres:';

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        /*
         * LIMIT NA TOKEN. Liczony po identyfikatorze TOKENU, nie konta: dwa
         * telefony tej samej osoby to dwa urządzenia i dwa budżety.
         *
         * Limit na adres IP NIE stoi tutaj, tylko w `BramaApi` — powód jest
         * w komentarzu tamtej klasy (framework sortuje `throttle:` za
         * `auth:sanctum`, więc fałszywy token nie byłby tu nigdy policzony).
         * Żądanie bez ważnego tokenu nie ma więc w tym limiterze koszyka.
         *
         * REJESTRACJA DOPIERO PRZY PIERWSZYM UŻYCIU LIMITERA, nie w `boot()`.
         * Fasada `RateLimiter::for()` tworzy singleton od razu, z magazynem
         * cache wybranym W CHWILI STARTU — do końca procesu. Test, który
         * potem przełącza `cache.default` (StartKonteneraNieCzysciCacheTest),
         * liczyłby próby w innym magazynie niż ten, który czyści, i żaden
         * provider z `main` tak wcześnie limitera nie tworzy.
         */
        $this->callAfterResolving(Limiter::class, function (Limiter $limiter): void {
            $limiter->for('api', function (Request $request): Limit {
                $token = $request->user('sanctum')?->currentAccessToken();

                if (! $token instanceof PersonalAccessToken) {
                    return Limit::none();
                }

                [$proby, $minuty] = self::limit('na_token');

                return Limit::perMinutes($minuty, $proby)->by(self::PREFIKS_TOKENU.$token->getKey());
            });
        });
    }

    /**
     * Limit z `config('kuking.api.limity')` w formacie „próby,minuty".
     *
     * @return array{0: int, 1: int} próby, minuty
     */
    public static function limit(string $klucz): array
    {
        [$proby, $minuty] = array_map('intval', explode(',', (string) config("kuking.api.limity.{$klucz}")) + [1 => 1]);

        return [max(1, $proby), max(1, $minuty)];
    }
}

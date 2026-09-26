<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Actions\OdwolajTokenAplikacji;
use App\Domain\Api\Actions\WydajTokenAplikacji;
use App\Domain\Api\WyzwanieDwuetapowe;
use App\Domain\Security\Actions\SprawdzHasloPrzyLogowaniu;
use App\Domain\Security\Actions\SprawdzKodDrugiegoSkladnika;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\JaResource;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Logowanie aplikacji mobilnej: wydanie i odwołanie tokenu (D-270).
 *
 * ADAPTER, NIE LOGIKA (D-014). Hasło i trzy koszyki limitu:
 * `SprawdzHasloPrzyLogowaniu` — ta sama akcja co `LoginController`. Kod 2FA:
 * `SprawdzKodDrugiegoSkladnika` — ta sama co `TwoFactorChallengeController`.
 * Tutaj zostaje wyłącznie walidacja pól i kształt odpowiedzi.
 *
 * CZEGO API NIE MA, A WWW MA: Turnstile (D-050). To captcha przeglądarkowa,
 * aplikacja nie ma jej jak pokazać. Zostają trzy koszyki `LimitProbHasla`
 * (wspólne z WWW), limit `login` na trasie (ten sam prefiks i próg co
 * formularz) i limit na adres IP z `BramaApi`. Zapisane w D-270 jako znany
 * ubytek, nie przeoczenie.
 */
class TokenController extends Controller
{
    public function __construct(
        private readonly SprawdzHasloPrzyLogowaniu $sprawdzHaslo,
        private readonly SprawdzKodDrugiegoSkladnika $sprawdzKod,
        private readonly WydajTokenAplikacji $wydaj,
    ) {}

    /**
     * `POST /api/v1/tokeny` — login + hasło + nazwa urządzenia.
     *
     * 201 z tokenem, albo — dla konta z 2FA — 202 z wyzwaniem i BEZ tokenu.
     * Token dla takiego konta powstaje dopiero w `storeKod()`.
     */
    public function store(Request $request): JsonResponse
    {
        $dane = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ], [
            'login.required' => 'Podaj swój adres e-mail albo nazwę użytkownika.',
            'password.required' => 'Wpisz hasło.',
            'device_name.required' => 'Aplikacja nie podała nazwy urządzenia. Zaktualizuj aplikację i spróbuj jeszcze raz.',
            'device_name.max' => 'Nazwa urządzenia może mieć najwyżej 100 znaków.',
        ]);

        $user = $this->sprawdzHaslo->handle($dane['login'], $dane['password'], (string) $request->ip());

        if ($user->hasTwoFactorConfirmed()) {
            $wyzwanie = WyzwanieDwuetapowe::wystaw($user, $dane['device_name']);

            return new JsonResponse([
                'two_factor_required' => true,
                'challenge' => $wyzwanie['wyzwanie'],
                'expires_at' => $wyzwanie['wazne_do']->toIso8601String(),
                'message' => 'Wpisz sześciocyfrowy kod z aplikacji do kodów albo jeden z kodów zapasowych.',
            ], 202, [], JSON_UNESCAPED_UNICODE);
        }

        return $this->wydany($user, $dane['device_name'], $request);
    }

    /**
     * `POST /api/v1/tokeny/kod` — drugi krok dla konta z 2FA.
     */
    public function storeKod(Request $request): JsonResponse
    {
        $dane = $request->validate([
            'challenge' => ['required', 'string', 'max:4000'],
            'code' => ['nullable', 'string', 'max:20', 'required_without:backup_code'],
            'backup_code' => ['nullable', 'string', 'max:40'],
        ], [
            'challenge.required' => 'Zaloguj się jeszcze raz: brakuje kroku z hasłem.',
            'code.required_without' => 'Wpisz sześciocyfrowy kod z aplikacji albo jeden z kodów zapasowych.',
        ]);

        $odczyt = WyzwanieDwuetapowe::odczytaj($dane['challenge']);

        if ($odczyt === null) {
            throw ValidationException::withMessages([
                'challenge' => 'Zaloguj się jeszcze raz: minęło za dużo czasu albo od rozpoczęcia logowania zmieniło się coś na koncie (na przykład hasło).',
            ]);
        }

        [$user, $urzadzenie] = $odczyt;
        $pole = ($dane['code'] ?? '') === '' ? 'backup_code' : 'code';

        [$wynik, $minuty] = $this->sprawdzKod->handle(
            $user,
            trim((string) ($dane['code'] ?? '')),
            trim((string) ($dane['backup_code'] ?? '')),
        );

        if ($wynik === SprawdzKodDrugiegoSkladnika::ZA_DUZO_PROB) {
            throw ValidationException::withMessages([$pole => "Za dużo prób. Spróbuj ponownie za {$minuty} min."]);
        }

        if ($wynik === SprawdzKodDrugiegoSkladnika::BLEDNY) {
            throw ValidationException::withMessages([
                $pole => $pole === 'backup_code'
                    ? 'Ten kod nie pozwala się zalogować. Wpisz inny niewykorzystany kod zapasowy.'
                    : 'Kod jest nieprawidłowy albo już wykorzystany. Sprawdź godzinę w telefonie i spróbuj ponownie.',
            ]);
        }

        return $this->wydany($user, $urzadzenie, $request);
    }

    /**
     * `DELETE /api/v1/tokeny/biezacy` — „Wyloguj" w aplikacji. Odwołuje
     * WYŁĄCZNIE token, którym przyszło żądanie; inne urządzenia zostają.
     */
    public function destroyCurrent(Request $request, OdwolajTokenAplikacji $odwolaj): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->authorize('delete', $token);
            $odwolaj->handle($request->user(), $token, $request->ip());
        }

        return response()->noContent();
    }

    private function wydany(User $user, string $urzadzenie, Request $request): JsonResponse
    {
        $nowy = $this->wydaj->handle($user, $urzadzenie, $request->ip());

        return new JsonResponse([
            'token' => $nowy->plainTextToken,
            'token_type' => 'Bearer',
            'device' => [
                'id' => $nowy->accessToken->getKey(),
                'name' => $nowy->accessToken->name,
                'created_at' => $nowy->accessToken->created_at?->toIso8601String(),
            ],
            'user' => (new JaResource($user->loadMissing('profile')))->resolve($request),
        ], 201, [
            // Odpowiedź z poświadczeniem nie ma prawa leżeć w żadnym cache'u.
            'Cache-Control' => 'no-store',
        ], JSON_UNESCAPED_UNICODE);
    }
}

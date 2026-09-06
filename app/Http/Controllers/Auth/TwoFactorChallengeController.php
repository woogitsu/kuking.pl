<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Drugi krok logowania dla konta z potwierdzonym 2FA (issue #12).
 *
 * Ekran istnieje TYLKO między poprawnym hasłem (LoginController) a pełnym
 * zalogowaniem. Klucz `logowanie.2fa.user_id` w sesji to jedyny ślad
 * pierwszego kroku — bez niego (wejście na ten adres wprost) trasa odsyła
 * do zwykłego logowania, żeby nie dało się tu trafić z pominięciem hasła.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('logowanie.2fa.user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two_factor_challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('logowanie.2fa.user_id');
        $user = $userId === null ? null : User::find($userId);

        if ($user === null || ! $user->hasTwoFactorConfirmed()) {
            $request->session()->forget('logowanie.2fa.user_id');

            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'backup_code' => ['nullable', 'string'],
        ]);

        $kod = trim((string) ($data['code'] ?? ''));
        $kodZapasowy = trim((string) ($data['backup_code'] ?? ''));

        if ($kod === '' && $kodZapasowy === '') {
            throw ValidationException::withMessages([
                'code' => 'Wpisz sześciocyfrowy kod z aplikacji albo jeden z kodów zapasowych.',
            ]);
        }

        // Limit liczony PO KONCIE, nie po adresie IP — kod ma sześć cyfr,
        // więc bez limitu prób jest do odgadnięcia, a rozproszony atak
        // z wielu adresów miałby ominąć zwykły throttle po IP.
        [$maxProb, $decayMinuty] = $this->limity();
        $throttleKey = 'weryfikacja-2fa|'.$user->getKey();

        if (RateLimiter::tooManyAttempts($throttleKey, $maxProb)) {
            $sekundy = RateLimiter::availableIn($throttleKey);
            $minuty = max(1, (int) ceil($sekundy / 60));

            throw ValidationException::withMessages([
                'code' => "Za dużo prób. Spróbuj ponownie za {$minuty} min.",
            ]);
        }

        $poprawny = false;

        if ($kod !== '') {
            $poprawny = $this->totp->verifyCode($user, $user->two_factor_secret, $kod);
        }

        if (! $poprawny && $kodZapasowy !== '') {
            $poprawny = $this->totp->consumeBackupCode($user, $kodZapasowy);
        }

        if (! $poprawny) {
            RateLimiter::hit($throttleKey, $decayMinuty * 60);

            throw ValidationException::withMessages([
                'code' => 'Kod jest nieprawidłowy albo już wykorzystany. Sprawdź godzinę w telefonie i spróbuj ponownie.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->forget('logowanie.2fa.user_id');
        $request->session()->regenerate();

        // `remember: false` CELOWO, na stałe — nie jest to opcja do wyłączenia
        // przez użytkownika. Trwałe ciasteczko „zapamiętaj mnie" pozwoliłoby
        // przeglądarce wrócić do zalogowanego stanu PO WYGAŚNIĘCIU sesji, z
        // pominięciem tego ekranu — czyli z pominięciem kodu, który miał być
        // wymagany przy KAŻDYM logowaniu. Konto bez 2FA nadal jest
        // zapamiętywane normalnie (LoginController::store()).
        Auth::login($user, remember: false);

        return redirect()->intended(route('home'));
    }

    /**
     * @return array{0: int, 1: int} [maksimum prób, minuty do odblokowania]
     */
    private function limity(): array
    {
        [$max, $minuty] = explode(',', config('kuking.limits.two_factor'));

        return [(int) $max, (int) $minuty];
    }
}

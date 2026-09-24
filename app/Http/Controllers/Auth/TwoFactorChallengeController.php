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
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

/**
 * Drugi krok logowania dla konta z potwierdzonym 2FA (issue #12).
 *
 * Ekran istnieje TYLKO między poprawnym hasłem (LoginController) a pełnym
 * zalogowaniem. Klucz `logowanie.2fa.user_id` w sesji to jedyny ślad
 * pierwszego kroku — bez niego (wejście na ten adres wprost) trasa odsyła
 * do zwykłego logowania, żeby nie dało się tu trafić z pominięciem hasła.
 * Obok leży `logowanie.2fa.odcisk` — stan konta z chwili pierwszego kroku
 * (issue #931); gdy się nie zgadza, pierwszy krok trzeba powtórzyć.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('logowanie.2fa.user_id')) {
            return redirect()->route('login');
        }

        if ($this->oczekujacyUzytkownik($request) === null) {
            return $this->odeslijDoPierwszegoKroku($request);
        }

        return view('auth.two_factor_challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! $request->session()->has('logowanie.2fa.user_id')) {
            return redirect()->route('login');
        }

        // Sprawdzenie PRZED weryfikacją kodu (issue #931): odmowa z powodu
        // zmienionego stanu konta nie może zużyć ważnego kodu zapasowego.
        $user = $this->oczekujacyUzytkownik($request);

        if ($user === null) {
            return $this->odeslijDoPierwszegoKroku($request);
        }

        $field = $request->has('backup_code') ? 'backup_code' : 'code';
        // Odkładamy wyłącznie błędy. Walidacja przez wyjątek zachowywała
        // także code/backup_code w old input (sekrety drugiego składnika).
        $validator = Validator::make($request->only(['code', 'backup_code']), [
            'code' => ['nullable', 'string'],
            'backup_code' => ['nullable', 'string'],
        ], [
            'code.string' => 'Wpisz sześciocyfrowy kod z aplikacji.',
            'backup_code.string' => 'Wpisz niewykorzystany kod zapasowy.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput([]);
        }

        $data = $validator->validated();
        $kod = trim((string) ($data['code'] ?? ''));
        $kodZapasowy = trim((string) ($data['backup_code'] ?? ''));

        if ($kod === '' && $kodZapasowy === '') {
            return back()->withErrors([
                $field => $field === 'backup_code'
                    ? 'Wpisz niewykorzystany kod zapasowy.'
                    : 'Wpisz sześciocyfrowy kod z aplikacji albo jeden z kodów zapasowych.',
            ])->withInput([]);
        }

        // Limit liczony PO KONCIE, nie po adresie IP — kod ma sześć cyfr,
        // więc bez limitu prób jest do odgadnięcia, a rozproszony atak
        // z wielu adresów miałby ominąć zwykły throttle po IP.
        [$maxProb, $decayMinuty] = TwoFactorAuthenticator::limitProb();
        $throttleKey = TwoFactorAuthenticator::kluczLimituProb($user);

        if (RateLimiter::tooManyAttempts($throttleKey, $maxProb)) {
            $sekundy = RateLimiter::availableIn($throttleKey);
            $minuty = max(1, (int) ceil($sekundy / 60));

            return back()->withErrors([
                $field => "Za dużo prób. Spróbuj ponownie za {$minuty} min.",
            ])->withInput([]);
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

            return back()->withErrors([
                $field => $field === 'backup_code'
                    ? 'Ten kod nie pozwala się zalogować. Wpisz inny niewykorzystany kod zapasowy.'
                    : 'Kod jest nieprawidłowy albo już wykorzystany. Sprawdź godzinę w telefonie i spróbuj ponownie.',
            ])->withInput([]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->forget(['logowanie.2fa.user_id', 'logowanie.2fa.odcisk']);
        $request->session()->regenerate();

        // `remember: false` CELOWO, na stałe — nie jest to opcja do wyłączenia
        // przez użytkownika. Trwałe ciasteczko „zapamiętaj mnie" pozwoliłoby
        // przeglądarce wrócić do zalogowanego stanu PO WYGAŚNIĘCIU sesji, z
        // pominięciem tego ekranu — czyli z pominięciem kodu, który miał być
        // wymagany przy KAŻDYM logowaniu. Konto bez 2FA nadal jest
        // zapamiętywane normalnie (LoginController::store()).
        Auth::login($user, remember: false);

        // Dowód kodu w TEJ sesji — tylko z nim `moderator.2fa` wpuszcza do panelu (#930).
        $request->session()->put(TwoFactorAuthenticator::dowodSesji($user));

        return redirect()->intended(route('home'));
    }

    /**
     * Konto z pierwszego kroku — o ile od tamtej chwili nic go nie odwołało
     * (issue #931): zmiana lub reset hasła, „wyloguj inne urządzenia”, ban,
     * zawieszenie, zgłoszenie usunięcia, wyłączenie 2FA.
     */
    private function oczekujacyUzytkownik(Request $request): ?User
    {
        $userId = $request->session()->get('logowanie.2fa.user_id');
        $user = $userId === null ? null : User::find($userId);

        if ($user === null || ! $user->hasTwoFactorConfirmed()) {
            return null;
        }

        $odcisk = $request->session()->get('logowanie.2fa.odcisk');

        return TwoFactorAuthenticator::oczekujaceLogowanieAktualne($user, $odcisk) ? $user : null;
    }

    private function odeslijDoPierwszegoKroku(Request $request): RedirectResponse
    {
        $request->session()->forget(['logowanie.2fa.user_id', 'logowanie.2fa.odcisk']);

        return redirect()->route('login')->withErrors([
            'login' => 'Zaloguj się jeszcze raz: od rozpoczęcia logowania zmieniło się coś na koncie (na przykład hasło). Wpisz adres e-mail i aktualne hasło.',
        ]);
    }
}

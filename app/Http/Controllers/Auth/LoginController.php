<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Logowanie.
 *
 * Można podać e-mail ALBO nazwę użytkownika. Badania nad grupą 50+ pokazują,
 * że e-mail jest częstą barierą (ludzie go zapominają albo nie mają do niego
 * dostępu na telefonie), a swoją nazwę użytkownika pamiętają.
 *
 * Komunikat błędu jest celowo jednakowy dla złego loginu i złego hasła —
 * inaczej dałoby się sprawdzać, czy dane konto istnieje (enumeracja kont).
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Podaj swój adres e-mail albo nazwę użytkownika.',
            'password.required' => 'Wpisz hasło.',
        ]);

        $throttleKey = mb_strtolower($data['login']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = max(1, (int) ceil($seconds / 60));

            throw ValidationException::withMessages([
                'login' => "Za dużo prób logowania. Spróbuj ponownie za {$minutes} min.",
            ]);
        }

        $user = $this->findUser($data['login']);

        if ($user === null || ! Auth::attempt(['email' => $user->email, 'password' => $data['password']], remember: true)) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);

            throw ValidationException::withMessages([
                'login' => 'Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.',
            ]);
        }

        if (! $user->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'To konto jest obecnie zablokowane. Napisz do nas: '.config('kuking.community.contact_email'),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('status', 'Wylogowano. Do zobaczenia.');
    }

    private function findUser(string $login): ?User
    {
        if (str_contains($login, '@')) {
            return User::where('email', User::normalizeEmail($login))->first();
        }

        // Nazwa użytkownika też bez rozróżniania wielkości liter — „Basia"
        // i „basia" to ta sama osoba, a klawiatura telefonu podnosi pierwszą
        // literę bez pytania.
        // Od migracji `..._add_username_case_insensitive_unique_index` baza
        // gwarantuje, że pasujący wiersz jest najwyżej jeden. Wcześniej przy
        // parze „basia" / „Basia" `first()` bez `ORDER BY` zwracał ten, który
        // baza akurat podała pierwszy — więc prawdziwa Basia mogła dostawać
        // „nieprawidłowe hasło" przy poprawnym haśle (audyt A25).
        return Profile::whereRaw('lower(username) = ?', [mb_strtolower(trim($login))])->first()?->user;
    }
}

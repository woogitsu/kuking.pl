<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\Actions\SprawdzHasloPrzyLogowaniu;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Http\Controllers\Controller;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(private readonly SprawdzHasloPrzyLogowaniu $sprawdzHaslo) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            /*
             * Turnstile (D-050) — WARUNEK WYSŁANIA, nie filtr.
             *
             * Brak tokenu ODRZUCA (decyzja właściciela z 9 września 2026:
             * w tych sześciu newralgicznych miejscach JavaScript jest
             * obowiązkowy). `required` tu nie stoi i nie dokładaj go:
             * obecność pola pilnuje `$implicit` w regule, a laravelowy
             * komunikat mówiłby o „polu cf-turnstile-response".
             *
             * Razem z tym idzie `<noscript>` w widoku i osobny komunikat dla
             * przypadku „skrypt się nie dociągnął" — bez nich zaciśnięcie
             * zostawia ludzi przed martwym przyciskiem.
             * `App\Rules\TurnstileJestPotwierdzony`.
             */
            Turnstile::POLE => TurnstileJestPotwierdzony::reguly('logowanie'),
        ], [
            'login.required' => 'Podaj swój adres e-mail albo nazwę użytkownika.',
            'password.required' => 'Wpisz hasło.',
        ]);

        // Hasło, trzy koszyki limitu i odmowa dla konta zamkniętego żyją
        // w akcji wspólnej z API (D-270) — uzasadnienie każdej z tych reguł
        // stoi tam, przy kodzie, który je wykonuje.
        $user = $this->sprawdzHaslo->handle($data['login'], $data['password'], (string) $request->ip());

        // Hasło się zgadza. Jeśli konto ma potwierdzone 2FA (issue #12),
        // logowanie NIE KOŃCZY SIĘ TUTAJ — dopiero po podaniu kodu z aplikacji
        // na osobnym ekranie. Zapisujemy w sesji WYŁĄCZNIE identyfikator
        // konta i odcisk jego stanu (#931), nie loginujemy go: sesja
        // nieuwierzytelniona nie daje dostępu do niczego, a
        // `TwoFactorChallengeController` sam sprawdza ten klucz i odcisk.
        if ($user->hasTwoFactorConfirmed()) {
            $request->session()->regenerate();
            $request->session()->put(TwoFactorAuthenticator::oczekujaceLogowanie($user));

            return redirect()->route('login.two_factor');
        }

        $request->session()->regenerate();
        Auth::login($user, remember: true);

        return redirect()->intended(route('home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('status', 'Wylogowano. Do zobaczenia.');
    }
}

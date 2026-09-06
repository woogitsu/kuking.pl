<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

        // `Auth::validate()`, NIE `Auth::attempt()` — sprawdza hasło BEZ
        // logowania. Różnica jest tu istotna: konto z potwierdzonym 2FA
        // (niżej) nie może dostać zalogowanej sesji, dopóki nie poda też
        // kodu z aplikacji. `attempt()` logowałby od razu, na chwilę
        // otwierając serwis samym hasłem.
        if ($user === null || ! Auth::validate(['email' => $user->email, 'password' => $data['password']])) {
            RateLimiter::hit($throttleKey, decaySeconds: 300);

            throw ValidationException::withMessages([
                'login' => 'Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie. Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.',
            ]);
        }

        // UWAGA: warunkiem NIE jest `isActive()`.
        //
        // Zawieszenie jest z założenia karą „tylko do odczytu": konto żyje,
        // treści są widoczne, nie da się nic opublikować (EnsureAccountIsActive,
        // pasek w layoucie, issue #40). Odmowa logowania wywracała ten projekt
        // do góry nogami — `suspend()` kasuje sesje, więc osoba wylatywała
        // z serwisu i NIE MOGŁA WRÓCIĆ. Nie zobaczyłaby ani wiadomości od
        // moderacji, ani terminu końca kary, ani własnych przepisów. Kara
        // czasowa działała jak blokada na zawsze.
        //
        // Konto z minioną karą też przechodzi: `EnsureAccountIsActive`
        // przywraca je przy pierwszym żądaniu.
        if ($user->isBanned() || $user->status === User::STATUS_PENDING_DELETE) {
            // Bez `Auth::logout()` — `Auth::validate()` wyżej niczego nie
            // zalogowało, więc nie ma z czego wylogowywać.
            throw ValidationException::withMessages([
                'login' => $this->komunikatOdmowy($user),
            ]);
        }

        RateLimiter::clear($throttleKey);

        // Hasło się zgadza. Jeśli konto ma potwierdzone 2FA (issue #12),
        // logowanie NIE KOŃCZY SIĘ TUTAJ — dopiero po podaniu kodu z aplikacji
        // na osobnym ekranie. Zapisujemy w sesji WYŁĄCZNIE identyfikator
        // konta, nie loginujemy go: sesja nieuwierzytelniona nie daje dostępu
        // do niczego, a `TwoFactorChallengeController` sam sprawdza, czy ten
        // klucz w ogóle istnieje.
        if ($user->hasTwoFactorConfirmed()) {
            $request->session()->regenerate();
            $request->session()->put('logowanie.2fa.user_id', $user->getKey());

            return redirect()->route('login.two_factor');
        }

        $request->session()->regenerate();
        Auth::login($user, remember: true);

        return redirect()->intended(route('home'));
    }

    /**
     * Dlaczego nie wpuszczamy — z treścią napisaną przez moderatora.
     *
     * Powiadomienie o decyzji leży w serwisie, do którego ta osoba właśnie nie
     * weszła. Ekran logowania jest jedynym miejscem, w którym zbanowany
     * człowiek cokolwiek od nas przeczyta, więc to tutaj musi trafić odpowiedź
     * na pytanie „za co" — inaczej DSA art. 17 zostaje spełniony tylko
     * na papierze.
     */
    private function komunikatOdmowy(User $user): string
    {
        if ($user->status === User::STATUS_PENDING_DELETE) {
            return 'To konto jest oznaczone do usunięcia, dlatego logowanie jest zamknięte. Jeśli chcesz je odzyskać, '
                .'wejdź na stronę „Cofnij usunięcie konta” ('.route('account.delete.cancel').') i potwierdź '
                .'hasłem, że to Ty. Jeśli dane zostały już usunięte na stałe, ta strona Cię o tym poinformuje — '
                .'wtedy napisz do nas: '.config('kuking.community.contact_email');
        }

        $odModeratora = $user->latestModerationMessage();

        return 'To konto zostało zablokowane. '
            .($odModeratora !== null ? $odModeratora.' ' : '')
            .'Jeśli uważasz, że to pomyłka, napisz do nas: '
            .config('kuking.community.contact_email');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with('status', 'Wylogowano. Do zobaczenia.');
    }

    /**
     * Nazwa użytkownika i e-mail bez rozróżniania wielkości liter — „Basia"
     * i „basia" to ta sama osoba, a klawiatura telefonu podnosi pierwszą
     * literę bez pytania. Wcześniej przy parze „basia" / „Basia" `first()`
     * bez `ORDER BY` zwracał ten wiersz, który baza akurat podała pierwszy —
     * więc prawdziwa Basia mogła dostawać „nieprawidłowe hasło" przy
     * poprawnym haśle (audyt A25).
     *
     * Sama logika przeniosła się do `User::findByLogin()`, bo pytają o to samo
     * DWA formularze dostępne PRZED zalogowaniem: odwołanie dla osób
     * zablokowanych (#10) i cofnięcie usunięcia konta (audyt A8). Obie te
     * osoby nie mogą wejść do serwisu, a muszą dać się rozpoznać.
     */
    private function findUser(string $login): ?User
    {
        return User::findByLogin($login);
    }
}

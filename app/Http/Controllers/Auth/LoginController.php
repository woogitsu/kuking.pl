<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Notifications\Push\OdlaczUrzadzeniePush;
use App\Domain\Security\Actions\SprawdzHasloPrzyLogowaniu;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\Komunikat;
use App\Support\PowrotDoRozmowy;
use App\Support\Turnstile;
use App\Support\ZamiarObserwowania;
use App\Support\ZamiarZapisu;
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

    public function show(Request $request, ZamiarObserwowania $zamiar, PowrotDoRozmowy $rozmowa, ZamiarZapisu $zapis): View
    {
        if ($cel = $zamiar->celDoLogowania($request)) {
            $request->session()->put('url.intended', $cel);
        }

        // Odnośnik z wątku komentarzy (#2027) jest nowszym zamiarem niż
        // zapamiętane „Obserwuj”, więc nadpisuje cel.
        if ($cel = $rozmowa->celDoLogowania($request)) {
            $request->session()->put('url.intended', $cel);
        }

        // Odnośnik „Zaloguj się, żeby zapisać” (#2028) — najnowszy jawny zamiar.
        if ($cel = $zapis->celDoLogowania($request)) {
            $request->session()->put('url.intended', $cel);
        }

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
        $adres = (string) $request->ip();
        $user = $this->sprawdzHaslo->handle($data['login'], $data['password'], $adres);

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
        AuditLogEntry::recordBezWywracania('account.password_login_succeeded', $user, $user, ip: $adres);

        return redirect()->intended(route('home'));
    }

    /**
     * Wylogowanie gasi też powiadomienia poza serwisem na TYM urządzeniu
     * (#1979): na wspólnym komputerze prywatne „ktoś ugotował…" nie ma prawa
     * pokazywać się dalej po wyjściu z konta. Wygaśnięcie sesji tego nie
     * robi — patrz `OdlaczUrzadzeniePush`.
     */
    public function destroy(Request $request, OdlaczUrzadzeniePush $odlaczPush): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $odlaczPush->handle(
                $user,
                $request->session()->get(OdlaczUrzadzeniePush::KLUCZ_SESJI),
                $request->input('push_endpoint'),
            );
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('landing')->with(Komunikat::sukces('Wylogowano. Do zobaczenia.'));
    }
}

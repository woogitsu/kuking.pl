<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\KomunikatZamknietegoKonta;
use App\Domain\Security\LimitProbHasla;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(private readonly LimitProbHasla $limit) {}

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

        // TRZY KOSZYKI, NIE JEDEN (W7-01, R3 §5). Liczby w
        // `config/kuking.php` → `login_limits`, klucze w `KluczeLimitow`.
        //
        // Wcześniej był jeden licznik po kluczu `login|ip`. Zmierzone: 20
        // nieudanych prób na TO SAMO konto z 20 różnych adresów nie
        // wywoływało żadnej blokady — bo licznik przywiązany do adresu
        // strukturalnie nie widzi ataku rozproszonego. To nie jest skutek
        // `trustProxies`: zmiana adresu jest dla napastnika tania także bez
        // podszywania się pod proxy (botnet, sieć mobilna, chmura).
        //
        // Koszyk KONTA zamyka tę lukę i jest jedyny, który nie zależy od
        // adresu wcale.
        // KOSZYKI ŻYJĄ TERAZ W `App\Domain\Security\LimitProbHasla`, bo ta
        // sama wyrocznia hasła stoi jeszcze w dwóch publicznych formularzach
        // (`/odwolanie`, `/cofnij-usuniecie-konta`), a licznik konta miało
        // tylko to jedno. Liczby, klucze i reguła „czyść parę i konto, nigdy
        // adres" są bez zmian — zmieniło się miejsce, w którym się je czyta,
        // i liczba drzwi, których pilnują.
        //
        // JEDEN KOMUNIKAT DLA WSZYSTKICH TRZECH KOSZYKÓW, świadomie.
        // Rozróżnienie („to Twoje konto jest zablokowane" kontra „to Twój
        // adres") powiedziałoby napastnikowi, który licznik trafił — czyli
        // czy konto o tym loginie w ogóle istnieje. Komunikat nazywa też
        // drogę wyjścia, bo koszyk konta z definicji pozwala OBCEMU
        // zablokować cudze konto (patrz `LimitProbHasla`).
        $adres = (string) $request->ip();

        $this->limit->zatrzymajJesliZaDuzo($data['login'], $adres);

        $user = $this->findUser($data['login']);

        // `Auth::validate()`, NIE `Auth::attempt()` — sprawdza hasło BEZ
        // logowania. Różnica jest tu istotna: konto z potwierdzonym 2FA
        // (niżej) nie może dostać zalogowanej sesji, dopóki nie poda też
        // kodu z aplikacji. `attempt()` logowałby od razu, na chwilę
        // otwierając serwis samym hasłem.
        if ($user === null || ! Auth::validate(['email' => $user->email, 'password' => $data['password']])) {
            $this->limit->zapiszNieudanaProbe($data['login'], $adres);

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
        // `STATUSY_ZAMKNIETEGO_KONTA` zamiast dwóch wypisanych statusów:
        // od D-022 jest trzeci (`erased`) i to jest właśnie ten status, przy
        // którym wpuszczenie kogokolwiek byłoby najgorsze — konto po
        // wymazaniu danych nie ma już właściciela, a jego hasło jest losowe.
        if (in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
            // Bez `Auth::logout()` — `Auth::validate()` wyżej niczego nie
            // zalogowało, więc nie ma z czego wylogowywać.
            throw ValidationException::withMessages([
                'login' => KomunikatZamknietegoKonta::dla($user),
            ]);
        }

        // CZYŚCIMY PARĘ I KONTO, NIGDY ADRES.
        //
        // Gdyby poprawne logowanie czyściło koszyk ADRESU, napastnik
        // zalogowałby się na WŁASNE, jednorazowe konto z tego samego
        // adresu, żeby zresetować licznik adresowy — i wrócił do rozpylania
        // po cudzych kontach z czystym licznikiem. To jednozdaniowa reguła,
        // bardzo łatwa do pominięcia, więc pilnuje jej osobny test.
        $this->limit->wyczyscPoUdanej($data['login'], $adres);

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

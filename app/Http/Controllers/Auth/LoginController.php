<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Moderation\UzasadnienieDecyzji;
use App\Http\Controllers\Controller;
use App\Models\ModerationAction;
use App\Models\User;
use App\Support\KluczeLimitow;
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
        $koszyki = $this->koszyki($data['login'], (string) $request->ip());

        foreach ($koszyki as $koszyk) {
            if (RateLimiter::tooManyAttempts($koszyk['klucz'], $koszyk['proby'])) {
                $minutes = max(1, (int) ceil(RateLimiter::availableIn($koszyk['klucz']) / 60));

                // JEDEN KOMUNIKAT DLA WSZYSTKICH TRZECH KOSZYKÓW, świadomie.
                // Rozróżnienie („to Twoje konto jest zablokowane" kontra „to
                // Twój adres") powiedziałoby napastnikowi, który licznik
                // trafił — czyli czy konto o tym loginie w ogóle istnieje.
                throw ValidationException::withMessages([
                    'login' => "Za dużo prób logowania. Spróbuj ponownie za {$minutes} min.",
                ]);
            }
        }

        $user = $this->findUser($data['login']);

        // `Auth::validate()`, NIE `Auth::attempt()` — sprawdza hasło BEZ
        // logowania. Różnica jest tu istotna: konto z potwierdzonym 2FA
        // (niżej) nie może dostać zalogowanej sesji, dopóki nie poda też
        // kodu z aplikacji. `attempt()` logowałby od razu, na chwilę
        // otwierając serwis samym hasłem.
        if ($user === null || ! Auth::validate(['email' => $user->email, 'password' => $data['password']])) {
            foreach ($koszyki as $koszyk) {
                RateLimiter::hit($koszyk['klucz'], decaySeconds: $koszyk['sekundy']);
            }

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
                'login' => $this->komunikatOdmowy($user),
            ]);
        }

        // CZYŚCIMY PARĘ I KONTO, NIGDY ADRES.
        //
        // Gdyby poprawne logowanie czyściło koszyk ADRESU, napastnik
        // zalogowałby się na WŁASNE, jednorazowe konto z tego samego
        // adresu, żeby zresetować licznik adresowy — i wrócił do rozpylania
        // po cudzych kontach z czystym licznikiem. To jednozdaniowa reguła,
        // bardzo łatwa do pominięcia, więc pilnuje jej osobny test.
        RateLimiter::clear($koszyki['para']['klucz']);
        RateLimiter::clear($koszyki['konto']['klucz']);

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
        // Konto po wykonanej karencji (D-022): nie ma czego odzyskiwać
        // i trzeba to powiedzieć wprost, a nie odsyłać do formularza
        // cofnięcia, który tej osobie odmówi.
        if ($user->isErased()) {
            return 'To konto zostało usunięte na Twoją prośbę, razem z danymi do logowania, '
                .'i nie da się go odzyskać. Jeśli chcesz wrócić do Kuking, założysz nowe konto. '
                .'Jeśli to pomyłka, napisz do nas: '.config('kuking.community.contact_email');
        }

        if ($user->status === User::STATUS_PENDING_DELETE) {
            return 'To konto jest oznaczone do usunięcia, dlatego logowanie jest zamknięte. Jeśli chcesz je odzyskać, '
                .'wejdź na stronę „Cofnij usunięcie konta” ('.route('account.delete.cancel').') i potwierdź '
                .'hasłem, że to Ty. Jeśli dane zostały już usunięte na stałe, ta strona Cię o tym poinformuje — '
                .'wtedy napisz do nas: '.config('kuking.community.contact_email');
        }

        $odModeratora = $user->latestModerationMessage();

        /*
         * UZASADNIENIE Z ART. 17 UST. 3 TEŻ MUSI BYĆ TUTAJ.
         *
         * Powiadomienie w serwisie niesie od dziś podstawę decyzji, informację
         * o tym, czy sprawa zaczęła się od zgłoszenia, zdanie o braku automatu
         * i pełne pouczenie o środkach odwoławczych z terminem
         * (`UzasadnienieDecyzji`). Osoba ZABLOKOWANA tego powiadomienia nie
         * przeczyta — do serwisu nie wejdzie. Gdyby uzasadnienie zostało tylko
         * tam, art. 17 byłby spełniony dla wszystkich POZA tymi, których
         * dotyczy najmocniejsza z decyzji.
         *
         * Bierzemy ostatnią BLOKADĘ tej osoby, nie ostatnią decyzję w ogóle:
         * komunikat wyżej mówi „to konto zostało zablokowane" i uzasadnienie
         * musi dotyczyć tej samej decyzji, a nie ukrycia wpisu z zeszłego roku.
         */
        $blokada = ModerationAction::query()
            ->where('subject_user_id', $user->getKey())
            ->where('action', ModerationAction::ACTION_BAN)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $uzasadnienie = $blokada === null ? [] : UzasadnienieDecyzji::zdania($blokada);

        return 'To konto zostało zablokowane. '
            .($odModeratora !== null ? $odModeratora.' ' : '')
            .($uzasadnienie === [] ? '' : implode(' ', $uzasadnienie).' ')
            // Odwołanie dla osoby zablokowanej ma osobny, PUBLICZNY formularz
            // (#10) — bez niego zdanie „możesz się odwołać" wyżej nie miałoby
            // dokąd prowadzić, bo do serwisu ta osoba nie wejdzie.
            .($blokada !== null && $blokada->isAppealable()
                ? 'Odwołanie złożysz na '.route('appeals.guest').'. '
                : '')
            // Dwa warianty ostatniego zdania, bo uzasadnienie mówi już
            // „jeśli uważasz, że to pomyłka, możesz się odwołać". Powtórzenie
            // tego samego wtrętu dwa zdania później wygląda jak usterka
            // i wydłuża komunikat, który i tak jest długi.
            .($uzasadnienie === []
                ? 'Jeśli uważasz, że to pomyłka, napisz do nas: '
                : 'Możesz też napisać do nas: ')
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
    /**
     * Trzy koszyki limitera z kluczami i liczbami z konfiguracji.
     *
     * @return array{para: array{klucz: string, proby: int, sekundy: int}, konto: array{klucz: string, proby: int, sekundy: int}, adres: array{klucz: string, proby: int, sekundy: int}}
     */
    private function koszyki(string $login, string $adres): array
    {
        $klucze = app(KluczeLimitow::class);
        $limity = (array) config('kuking.login_limits');

        $koszyk = fn (string $nazwa, string $klucz): array => [
            'klucz' => $klucz,
            'proby' => (int) ($limity[$nazwa]['proby'] ?? 5),
            'sekundy' => (int) ($limity[$nazwa]['sekundy'] ?? 60),
        ];

        return [
            'para' => $koszyk('para', $klucze->para($login, $adres)),
            'konto' => $koszyk('konto', $klucze->konto($login)),
            'adres' => $koszyk('adres', $klucze->adres($adres)),
        ];
    }

    private function findUser(string $login): ?User
    {
        return User::findByLogin($login);
    }
}

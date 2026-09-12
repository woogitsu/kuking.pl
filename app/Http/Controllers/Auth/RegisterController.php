<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\ZaproszenieWSesji;
use App\Domain\Users\Actions\ZalozKonto;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\ReservedUsername;
use App\Rules\TurnstileJestPotwierdzony;
use App\Rules\UsernameNotTaken;
use App\Support\NazwaUzytkownika;
use App\Support\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Rejestracja.
 *
 * Formularz jest JEDNOSTRONICOWY i ma cztery pola. Każde dodatkowe pole to
 * kolejne miejsce, w którym ktoś odpada. Nie pytamy o telefon, płeć, datę
 * urodzenia ani miasto (docs/SECURITY_PRIVACY_LEGAL.md — minimalizacja danych).
 *
 * Weryfikacja e-maila NIE BLOKUJE pierwszej publikacji. Osoba, która właśnie
 * z trudem założyła konto, nie może zostać odesłana do skrzynki, której
 * być może nie umie otworzyć na telefonie. Weryfikacja jest wymagana dopiero
 * do rzeczy nieodwracalnych (zmiana e-maila, eksport danych).
 */
class RegisterController extends Controller
{
    public function show(ZaproszenieWSesji $sesja): View
    {
        abort_unless(config('kuking.account.registration_open'), 503, 'Rejestracja jest chwilowo zamknięta.');

        // `biezace()` sprawdza ważność przy każdym odczycie i czyści martwy
        // klucz w sesji — zaproszenie mogło wygasnąć albo zostać zużyte między
        // jednym żądaniem a drugim. `null` znaczy „zwykła rejestracja".
        return view('auth.register', ['zaproszenie' => $sesja->biezace()]);
    }

    public function store(Request $request, ZalozKonto $zalozKonto, ZaproszenieWSesji $sesja): RedirectResponse
    {
        abort_unless(config('kuking.account.registration_open'), 503);

        /*
         * ═════════════════════════════════════════════════════════════════
         *  REJESTRACJA Z ZAPROSZENIA — ADRES POCHODZI Z BAZY (D-085)
         * ═════════════════════════════════════════════════════════════════
         *
         * Kto przyszedł z linku w wiadomości, ma adres potwierdzony samym
         * kliknięciem — dowodem posiadania skrzynki. Ten dowód wolno przyjąć
         * TYLKO dla adresu, na który wiadomość naprawdę poszła, więc adres
         * bierzemy z WIERSZA W BAZIE wskazanego przez sesję, a nie z pola
         * `email` w żądaniu. To, co przyszło z przeglądarki, jest w tym
         * przypadku po prostu nadpisywane i nigdzie nie czytane.
         *
         * Gdyby było odwrotnie, powstałoby konto z `email_verified_at`
         * ustawionym na adres, którego nikt nigdy nie potwierdził — czyli
         * gotowa droga do konta na cudzej skrzynce, z „nie pamiętam hasła"
         * jako dalszym ciągiem.
         *
         * Zaproszenie jest tu jeszcze NIEZUŻYTE. Zużywa je dopiero transakcja
         * zakładająca konto, niżej — bo dopiero tam wiadomo, że reszta
         * formularza jest poprawna. Człowiek, który pomyli hasło, ma wrócić
         * do formularza z żywym zaproszeniem, a nie stracić link.
         */
        $zaproszenie = $sesja->biezace();

        if ($zaproszenie !== null) {
            $request->merge(['email' => $zaproszenie->email]);
        }

        $minAge = (int) config('kuking.account.min_age');

        // Adres normalizujemy PRZED walidacją, a nie dopiero przy zapisie
        // (audyt A25). `User` zapisuje e-mail małymi literami, więc pytanie
        // bazy o wartość surową sprawdzało coś innego, niż trafiało do bazy:
        // dla „Jan@Example.com" `Rule::unique` nie znajdowało nic, zapis szedł
        // dalej i dopiero PostgreSQL odbijał duplikat kluczem unikalnym.
        // Zamiast komunikatu „na ten adres jest już konto" człowiek dostawał
        // HTTP 500 — i nie miał pojęcia, że po prostu ma już konto.
        $request->merge([
            'email' => User::normalizeEmail((string) $request->input('email', '')),

            /*
             * NAZWĘ UŻYTKOWNIKA UKŁADAMY Z TEGO, CO CZŁOWIEK WPISAŁ — też
             * PRZED walidacją, dokładnie z tego samego powodu co adres wyżej.
             *
             * Powód nie jest teoretyczny: 63-letnia osoba z grupy docelowej
             * odbiła się przy rejestracji o komunikat „tylko litery bez
             * polskich znaków, cyfry i podkreślnik" i nie zrozumiała, o co
             * chodzi. Wpisała imię tak, jak się je pisze. Teraz „Małgorzata
             * Kowalska" zamienia się w `malgorzata_kowalska` i rejestracja
             * idzie dalej, zamiast kończyć się pouczeniem na pierwszym
             * ekranie produktu.
             *
             * KOLEJNOŚĆ JEST WARUNKIEM BEZPIECZEŃSTWA: normalizacja stoi
             * PRZED `ReservedUsername` i `UsernameNotTaken`, więc „ądmin"
             * jest sprawdzane jako `admin`, a nie jako nazwa nieznana.
             * Wartość już poprawna nie jest ruszana (patrz `NazwaUzytkownika`).
             */
            'username' => NazwaUzytkownika::znormalizuj((string) $request->input('username', '')),
        ]);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:'.config('kuking.profil.dlugosc_nazwy')],
            'username' => [
                'required', 'string', 'min:3', 'max:40',
                'regex:/^[a-zA-Z0-9_]+$/',
                // Nazwy obsługi serwisu (issue #42). Lista jest w configu,
                // porównanie odporne na warianty zapisu — patrz klasa reguły.
                new ReservedUsername,
                // Bez rozróżniania wielkości liter (audyt A25). `Rule::unique`
                // porównuje przez `=`, więc „Basia" przechodziła obok „basia" —
                // a logowanie szuka nazwy JUŻ bez rozróżniania, przez co
                // prawdziwa Basia zaczynała dostawać „nieprawidłowe hasło".
                new UsernameNotTaken,
            ],
            // Świadomie 'email:rfc' bez 'dns'. Sprawdzanie rekordów DNS wygląda
            // na darmowe zabezpieczenie, ale w praktyce blokuje rejestrację przy
            // chwilowej awarii resolvera i przy poprawnych, rzadkich domenach.
            // Literówki w adresie wyłapuje weryfikacja e-maila, nie walidator.
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(10)->uncompromised()],
            'age_confirmed' => ['accepted'],
            'terms_accepted' => ['accepted'],
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
            Turnstile::POLE => TurnstileJestPotwierdzony::reguly('rejestracja'),
        ], [
            'display_name.required' => 'Podaj imię, którym mamy Cię nazywać.',
            /*
             * KOMUNIKATY PO NORMALIZACJI, WIĘC MÓWIĄ O CZYMŚ INNYM NIŻ WCZEŚNIEJ.
             *
             * Do `regex` i `min` dochodzi się już tylko wtedy, gdy z wpisanego
             * tekstu NIE DA SIĘ nic ułożyć (same emoji, same znaki
             * interpunkcyjne, jedna litera). Dlatego komunikat nie wylicza
             * dozwolonych znaków — bo nie o to tu chodzi — tylko prosi o coś,
             * co człowiek umie podać: imię i miejscowość.
             */
            'username.required' => 'Wpisz nazwę, która ma być w adresie Twojego profilu — na przykład imię i miejscowość: basia z podkarpacia.',
            'username.regex' => 'Z tej nazwy nie da się ułożyć adresu. Wpisz imię albo imię i miejscowość, na przykład: basia z podkarpacia.',
            'username.unique' => 'Ta nazwa jest już zajęta. Spróbuj dodać coś na końcu.',
            'email.required' => 'Podaj swój adres e-mail — będzie potrzebny, jeśli zapomnisz hasła.',
            'email.email' => 'Ten adres e-mail wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
            'email.unique' => 'Na ten adres jest już założone konto. Możesz się zalogować albo odzyskać hasło.',
            'password.required' => 'Wpisz hasło.',
            // Ten sam przykład co w pomocy przy polu (`auth/register.blade.php`):
            // słowa rozdzielone myślnikami i wprost powiedziane, żeby wpisać
            // swoje. Przykład bez separatorów uczył wzorca, który łamie się
            // słownikowo, a przy okazji był gotowym hasłem do przepisania.
            'password.min' => 'Hasło musi mieć co najmniej 10 znaków. Najprościej połączyć myślnikami trzy swoje słowa, na przykład: parasol-wtorek-cebula. Wymyśl własne, nie przepisuj tych z przykładu.',
            'password.uncompromised' => 'To hasło pojawiło się już w wyciekach danych z innych serwisów. Wybierz inne.',
            'age_confirmed.accepted' => "Kuking jest dla osób od {$minAge} lat. Potwierdź, że masz tyle lat.",
            'terms_accepted.accepted' => 'Zaznacz, że znasz zasady Kuking.',
        ]);

        /*
         * ZAKŁADANIE KONTA MIESZKA W `app/Domain`, NIE TUTAJ (D-069).
         *
         * Ta lista czynności — profil, powitanie, oświadczenie o wieku,
         * wiadomość z potwierdzeniem adresu, wpis w dzienniku, obserwowanie
         * gospodarza — jest od 10 września wołana z DWÓCH miejsc: tego
         * formularza i drogi przez konto Google. Dwie kopie rozjechałyby się
         * przy pierwszej zmianie (AGENTS.md §4), a rozjazd byłby cichy:
         * konto bez powitania albo bez obserwowanego gospodarza wygląda jak
         * konto, tylko pusto się z niego patrzy.
         *
         * ZAPROSZENIE (D-085) WCHODZI TU JAKO DOWÓD ADRESU, nie jako drugi
         * zapis konta. `ZalozKonto` zużywa je WEWNĄTRZ swojej transakcji,
         * więc zaproszenie, które przestało działać między wejściem na
         * formularz a jego wysłaniem, wycofuje całe założenie konta — a adres
         * potwierdzony pochodzi dowodnie z wiersza w bazie, nie z żądania.
         */
        try {
            $user = $zalozKonto->handle(
                email: $data['email'],
                displayName: $data['display_name'],
                username: $data['username'],
                haslo: $data['password'],
                ip: $request->ip(),
                dziennik: ['droga' => 'haslo'],
                dowodAdresu: $zaproszenie === null ? null : function () use ($sesja, $zaproszenie): string {
                    $adres = $sesja->zuzyj($zaproszenie);

                    if ($adres === null) {
                        throw new BladDlaCzlowieka(
                            'To zaproszenie przestało działać, zanim zdążyliśmy założyć konto — mogło wygasnąć '
                            .'albo zostać już użyte. Wpisz swój adres e-mail poniżej i spróbuj jeszcze raz; '
                            .'wyślemy na niego jedną wiadomość z potwierdzeniem.',
                        );
                    }

                    return $adres;
                },
            );
        } catch (BladDlaCzlowieka $e) {
            /*
             * POPRAWNE DANE NIE ZNIKAJĄ Z FORMULARZA (docs/UX_50_PLUS.md).
             * Człowiek stracił zaproszenie, a nie wpisane imię, nazwę i zgody
             * — wracają razem z komunikatem mówiącym, CO ZROBIĆ. Hasła nie
             * oddajemy nigdy (`except`), więc trzeba je wpisać jeszcze raz.
             *
             * Zaproszenia w sesji już nie ma (`zuzyj()` skasowało martwy wiersz,
             * a `biezace()` czyści klucz), więc formularz pokaże z powrotem
             * zwykłe pole na adres e-mail.
             */
            return redirect()->route('register')
                ->withInput($request->except(['password', Turnstile::POLE]))
                ->withErrors(['email' => $e->getMessage()]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('onboarding.interests')
            ->with('status', 'Konto gotowe. Miło Cię widzieć w Kuking.');
    }
}

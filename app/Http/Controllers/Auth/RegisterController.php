<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Users\Actions\ZalozKonto;
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
    public function show(): View
    {
        abort_unless(config('kuking.account.registration_open'), 503, 'Rejestracja jest chwilowo zamknięta.');

        return view('auth.register');
    }

    public function store(Request $request, ZalozKonto $zalozKonto): RedirectResponse
    {
        abort_unless(config('kuking.account.registration_open'), 503);

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
            'display_name' => ['required', 'string', 'min:2', 'max:100'],
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
            'username.regex' => 'Z tego, co wpisałeś, nie da się ułożyć nazwy do adresu. Wpisz imię albo imię i miejscowość, na przykład: basia z podkarpacia.',
            'username.unique' => 'Ta nazwa jest już zajęta. Spróbuj dodać coś na końcu.',
            'email.required' => 'Podaj swój adres e-mail — będzie potrzebny, jeśli zapomnisz hasła.',
            'email.email' => 'Ten adres e-mail wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
            'email.unique' => 'Na ten adres jest już założone konto. Możesz się zalogować albo odzyskać hasło.',
            'password.required' => 'Wpisz hasło.',
            'password.min' => 'Hasło musi mieć co najmniej 10 znaków. Najprościej wpisać trzy słowa, na przykład: zielonapietruszkarano.',
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
         */
        $user = $zalozKonto->handle(
            email: $data['email'],
            displayName: $data['display_name'],
            username: $data['username'],
            haslo: $data['password'],
            ip: $request->ip(),
            dziennik: ['droga' => 'haslo'],
        );

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('onboarding.interests')
            ->with('status', 'Konto gotowe. Miło Cię widzieć w Kuking.');
    }
}

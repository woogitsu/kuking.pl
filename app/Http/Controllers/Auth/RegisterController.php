<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Social\Actions\FollowUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Rules\ReservedUsername;
use App\Rules\TurnstileJestPotwierdzony;
use App\Rules\UsernameNotTaken;
use App\Support\Turnstile;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

    public function store(Request $request): RedirectResponse
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
            'username.required' => 'Wybierz swoją nazwę użytkownika.',
            'username.regex' => 'Nazwa użytkownika może zawierać tylko litery bez polskich znaków, cyfry i podkreślnik. Na przykład: basia_z_podkarpacia.',
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

        $user = DB::transaction(function () use ($data): User {
            // `email` NIE JEST w `$fillable` (issue #195, ten sam powód co
            // `status` i `role`), więc adres wchodzi jawnie, przez
            // `assignEmail()`. Bez potwierdzenia — potwierdzi je dopiero
            // kliknięcie w list, który zaraz wyjdzie (`event(new Registered)`).
            $user = (new User([
                'password' => Hash::make($data['password']),
                'locale' => 'pl',
                'text_scale' => config('kuking.text.default_scale'),
                'age_confirmed_at' => now(),
            ]))->assignEmail($data['email']);

            $user->save();

            Profile::create([
                'user_id' => $user->getKey(),
                'username' => $data['username'],
                'display_name' => $data['display_name'],
            ]);

            Notification::create([
                'user_id' => $user->getKey(),
                'type' => Notification::TYPE_WELCOME,
                'data' => ['display_name' => $data['display_name']],
            ]);

            return $user;
        });

        event(new Registered($user));

        AuditLogEntry::record(
            action: 'account.registered',
            actor: $user,
            subject: $user,
            ip: $request->ip(),
        );

        $this->zaobserwujGospodarza($user);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('onboarding.interests')
            ->with('status', 'Konto gotowe. Miło Cię widzieć w Kuking.');
    }

    /**
     * Nowe konto zaczyna obserwować gospodarza (docs/product/COLD_START.md).
     *
     * DLACZEGO POZA TRANSAKCJĄ
     * Rejestracja MUSI się udać. Gdyby konto gospodarza było źle wpisane
     * w konfiguracji, zawieszone albo skasowane, wyjątek wewnątrz transakcji
     * wycofałby całe założenie konta — i człowiek nie miałby gdzie wrócić.
     * Brak jednego obserwowania jest problemem mniejszym o kilka rzędów
     * wielkości niż rejestracja, która się nie udała.
     *
     * DLACZEGO NIE CICHY `catch` NA WSZYSTKO
     * Łapiemy tylko `BladDlaCzlowieka`, który `FollowUser` rzuca świadomie
     * (konto niedostępne, blokada, próba obserwowania samego siebie).
     * Błąd programisty ma dalej wybuchać głośno — a przez pewien czas nie
     * wybuchał: stało tu `RuntimeException`, po którym dziedziczy
     * `PDOException`, więc awaria bazy w tym miejscu była nieodróżnialna
     * od „gospodarz źle wpisany w konfiguracji".
     */
    private function zaobserwujGospodarza(User $user): void
    {
        $nazwa = (string) config('kuking.community.host_username');

        if ($nazwa === '') {
            return;
        }

        $gospodarz = Profile::where('username', $nazwa)->first()?->user;

        if ($gospodarz === null || $gospodarz->getKey() === $user->getKey()) {
            return;
        }

        try {
            app(FollowUser::class)->handle($user, $gospodarz);
        } catch (BladDlaCzlowieka) {
            // Gospodarz zawieszony albo źle wpisany w konfiguracji. Rejestracja
            // idzie dalej; feed ratują tematy z onboardingu (#31).
        }
    }
}

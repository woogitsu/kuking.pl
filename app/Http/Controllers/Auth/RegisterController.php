<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use App\Rules\ReservedUsername;
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

        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:100'],
            'username' => [
                'required', 'string', 'min:3', 'max:40',
                'regex:/^[a-zA-Z0-9_]+$/',
                // Nazwy obsługi serwisu (issue #42). Lista jest w configu,
                // porównanie odporne na warianty zapisu — patrz klasa reguły.
                new ReservedUsername,
                Rule::unique('profiles', 'username'),
            ],
            // Świadomie 'email:rfc' bez 'dns'. Sprawdzanie rekordów DNS wygląda
            // na darmowe zabezpieczenie, ale w praktyce blokuje rejestrację przy
            // chwilowej awarii resolvera i przy poprawnych, rzadkich domenach.
            // Literówki w adresie wyłapuje weryfikacja e-maila, nie walidator.
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(10)->uncompromised()],
            'age_confirmed' => ['accepted'],
            'terms_accepted' => ['accepted'],
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
            $user = User::create([
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'locale' => 'pl',
                'text_scale' => config('kuking.text.default_scale'),
                'age_confirmed_at' => now(),
            ]);

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

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('onboarding.interests')
            ->with('status', 'Konto gotowe. Miło Cię widzieć w Kuking.');
    }
}

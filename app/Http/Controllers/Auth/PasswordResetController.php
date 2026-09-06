<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Odzyskiwanie hasła.
 *
 * Komunikat po wysłaniu jest ZAWSZE ten sam, niezależnie od tego, czy konto
 * istnieje — inaczej formularz służyłby do sprawdzania, kto ma tu konto.
 */
class PasswordResetController extends Controller
{
    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ], [
            'email.required' => 'Podaj adres e-mail, na który założone jest konto.',
            'email.email' => 'Ten adres wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
        ]);

        // Adres z wielkiej litery musi trafić na to samo konto (audyt A25).
        // `Password::sendResetLink` szuka przez `where email = ?`, a w bazie
        // adres leży małymi literami — więc „Jan@Example.com" nie znajdowało
        // niczego i wiadomość po prostu nie wychodziła. Bez śladu: odpowiedź
        // niżej jest z założenia ta sama dla adresu istniejącego
        // i nieistniejącego, więc człowiek czekał na list, który nigdy nie
        // miał przyjść, i nie miał jak się domyślić dlaczego.
        Password::sendResetLink([
            'email' => User::normalizeEmail((string) $request->input('email', '')),
        ]);

        return back()->with('status',
            'Jeśli na ten adres jest założone konto, wysłaliśmy na niego wiadomość z linkiem do ustawienia nowego hasła. Sprawdź też folder „Spam”.',
        );
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->uncompromised()],
        ], [
            'password.confirmed' => 'Oba hasła muszą być takie same.',
            'password.min' => 'Hasło musi mieć co najmniej 10 znaków.',
            'password.uncompromised' => 'To hasło pojawiło się w wyciekach danych. Wybierz inne.',
        ]);

        // Ten sam powód co przy wysyłce linku: token jest przypisany do adresu
        // zapisanego małymi literami. Formularz podstawia adres z linku, ale
        // pole jest edytowalne i klawiatura telefonu podnosi pierwszą literę.
        $status = Password::reset(
            [
                ...$request->only('password', 'password_confirmation', 'token'),
                'email' => User::normalizeEmail((string) $request->input('email', '')),
            ],
            function ($user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Rotacja sesji (issue #12): to jest DOKŁADNIE sytuacja, w
                // której zmiana hasła musi kasować stare sesje — ktoś prosi
                // o reset właśnie DLATEGO, że podejrzewa, że jego hasło zna
                // ktoś inny. Bez tego druga osoba zostałaby zalogowana dalej,
                // a resetujący/a miałby/aby złudne poczucie, że problem
                // zniknął. W tej ścieżce nie ma „bieżącej sesji do
                // zachowania" — resetujący/a nie jest tu zalogowany/a
                // (formularz jest publiczny), więc kasujemy WSZYSTKIE sesje
                // bez wyjątku.
                $user->invalidateSessions();

                AuditLogEntry::record('account.password_reset', $user, $user, ip: $request->ip());

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PasswordReset) {
            return back()->withErrors([
                'email' => 'Ten link do ustawienia hasła jest już nieaktualny. Poproś o nowy.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Hasło zmienione. Możesz się zalogować.');
    }
}

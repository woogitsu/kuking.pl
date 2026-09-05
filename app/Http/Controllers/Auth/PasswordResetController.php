<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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

        Password::sendResetLink($request->only('email'));

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

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

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

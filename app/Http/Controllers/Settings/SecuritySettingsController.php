<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Bezpieczeństwo konta — issue #12.
 *
 * Dwie rzeczy na jednym ekranie, bo w głowie osoby, która tu trafia, to
 * jedna sprawa: „ktoś inny mógł mieć dostęp do mojego konta”.
 *
 *  - Zmiana hasła — kończy sprawę, jeśli hasło wyciekło albo było zgadnięte.
 *  - „Wyloguj mnie z innych urządzeń” — kończy sprawę, jeśli ktoś jest
 *    zalogowany NA telefonie/komputerze, do którego ta osoba już nie ma
 *    dostępu (zostawiony u wnuka, w bibliotece, u znajomych) — samo hasło
 *    tego nie rozwiązuje, bo tamto urządzenie ma ważną sesję.
 *
 * Obie akcje kasują sesje z INNYCH przeglądarek przez
 * `User::invalidateSessions()` (ten sam mechanizm co blokada konta), ale
 * z wyjątkiem dla bieżącej sesji — patrz komentarz przy tej metodzie.
 * Wylogowanie kogoś z własnej przeglądarki zaraz po tym, jak zrobił dobrą
 * rzecz, wyglądałoby jak awaria serwisu.
 */
class SecuritySettingsController extends Controller
{
    public function edit(): View
    {
        return view('pages.settings.security');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->uncompromised()],
        ], [
            'current_password.required' => 'Wpisz obecne hasło.',
            'password.required' => 'Wpisz nowe hasło.',
            'password.confirmed' => 'Oba nowe hasła muszą być takie same.',
            'password.min' => 'Nowe hasło musi mieć co najmniej 10 znaków.',
            'password.uncompromised' => 'To hasło pojawiło się już w wyciekach danych z innych serwisów. Wybierz inne.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'To hasło jest nieprawidłowe.']);
        }

        $user->forceFill(['password' => Hash::make($data['password'])])->save();

        // Rotacja sesji (issue #12): stare sesje — te, w których mogła siedzieć
        // osoba, przez którą hasło było zmieniane — przestają działać od razu.
        // Bieżąca sesja (ta, w której właśnie ktoś ustawił nowe hasło) zostaje
        // ważna: to jego własna, dobra decyzja, nie powód do wylogowania.
        $user->invalidateSessions($request->session()->getId());

        AuditLogEntry::record('account.password_changed', $user, $user, ip: $request->ip());

        return back()->with('status',
            'Hasło zmienione. Wylogowaliśmy wszystkie inne urządzenia zalogowane na to konto — ten komputer/telefon zostaje zalogowany.',
        );
    }

    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Wpisz swoje hasło, żeby to potwierdzić.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'To hasło jest nieprawidłowe.']);
        }

        $user->invalidateSessions($request->session()->getId());

        AuditLogEntry::record('account.sessions_logged_out_others', $user, $user, ip: $request->ip());

        return back()->with('status',
            'Gotowe. Wylogowaliśmy wszystkie inne urządzenia zalogowane na to konto. Ten komputer/telefon zostaje zalogowany.',
        );
    }
}

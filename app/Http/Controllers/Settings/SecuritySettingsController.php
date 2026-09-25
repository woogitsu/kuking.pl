<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Actions\CancelEmailChange;
use App\Domain\Users\Actions\UstawNoweHaslo;
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

    public function updatePassword(Request $request, UstawNoweHaslo $ustaw): RedirectResponse
    {
        // Kontekst ustala obsługiwana akcja, nie wartość przysłana z formularza.
        $request->merge(['_wiersz' => 'zmiana']);

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->uncompromised()],
        ], [
            'current_password.required' => 'Wpisz obecne hasło.',
            'password.required' => 'Wpisz nowe hasło.',
            // KAŻDE ZDANIE KOŃCZY SIĘ POLECENIEM. Same „muszą być takie same"
            // i „musi mieć 10 znaków" mówią tylko, co jest źle.
            'password.confirmed' => 'Oba nowe hasła muszą być takie same. Wpisz jeszcze raz to samo w obu polach.',
            'password.min' => 'Nowe hasło musi mieć co najmniej 10 znaków. Najprościej połączyć myślnikami trzy swoje słowa, na przykład: parasol-wtorek-cebula. Wymyśl własne, nie przepisuj tych z przykładu.',
            'password.uncompromised' => 'To hasło pojawiło się już w wyciekach danych z innych serwisów. Wybierz inne.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Wpisz poprawne obecne hasło.'])
                ->withInput(['_wiersz' => 'zmiana']);
        }

        // Hasło, sesje, link resetu i zamówiona zmiana adresu — jedną
        // transakcją pod blokadą konta (#1358). Stare sesje przestają
        // działać, bieżąca zostaje: to własna, dobra decyzja (issue #12).
        // Zmiana hasła kasuje też zamówioną zmianę adresu (issue #195):
        // list ostrzegawczy mówi „jeśli to nie Ty — zmień hasło".
        // Uzasadnienie kolejności w `UstawNoweHaslo`.
        $anulowanaZmianaAdresu = $ustaw->handle(
            $user,
            $data['password'],
            CancelEmailChange::POWOD_ZMIANA_HASLA,
            $request->ip(),
            $request->session()->getId(),
        );

        // Nowy identyfikator bieżącej sesji po zmianie hasła. Gdyby stary
        // znał ktoś obcy, sam wyjątek dla bieżącej sesji zostawiłby go na
        // koncie. `true` kasuje stary wiersz z tabeli sesji.
        $request->session()->regenerate(true);

        return back()->with('status',
            'Hasło zmienione. Wylogowaliśmy wszystkie inne urządzenia zalogowane na to konto — ten komputer/telefon zostaje zalogowany.'
            .($anulowanaZmianaAdresu
                ? ' Anulowaliśmy też zamówioną zmianę adresu e-mail — odnośnik z tamtego listu już nie działa.'
                : ''),
        );
    }

    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        $request->merge(['_wiersz' => 'wyloguj']);

        $data = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Wpisz swoje hasło, żeby to potwierdzić.',
        ]);

        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            return back()->withErrors(['password' => 'Wpisz poprawne hasło, żeby wylogować inne urządzenia.'])
                ->withInput(['_wiersz' => 'wyloguj']);
        }

        $user->invalidateSessions($request->session()->getId());

        AuditLogEntry::record('account.sessions_logged_out_others', $user, $user, ip: $request->ip());

        return back()->with('status',
            'Gotowe. Wylogowaliśmy wszystkie inne urządzenia zalogowane na to konto. Ten komputer/telefon zostaje zalogowany.',
        );
    }
}

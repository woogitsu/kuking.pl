<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Users\Actions\CancelAccountDeletion;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Cofnięcie zgłoszonego usunięcia konta — dla osoby, która NIE MOŻE się
 * zalogować (audyt A8).
 *
 * PROBLEM, KTÓRY TO ZAMYKA
 * Konto `pending_delete` jest wylogowywane przy pierwszym żądaniu
 * (`EnsureAccountIsActive`), a `LoginController` odmawia mu zalogowania —
 * to zamierzone (patrz komentarze tam), ale efekt uboczny był taki, że
 * ekran „Twoje dane" obiecywał „zaloguj się, żeby to cofnąć", a zalogować
 * się było wprost nie da. Karencja bez wykonalnej drogi powrotu jest gorsza
 * niż jej brak: obiecuje wyjście, którego nie ma.
 *
 * GDZIE SZUKAĆ WZORCA I DLACZEGO TU GO NIE MA WPROST
 * W tym repozytorium istnieje formularz TEJ SAMEJ klasy problemu — odwołanie
 * od blokady konta dla osoby zablokowanej, przed zalogowaniem (issue #10,
 * `AppealController::guestForm/guestStore`). Ten kontroler świadomie
 * powtarza jego wzorzec identyfikacji (login + hasło, ten sam nieinformacyjny
 * komunikat błędu, ten sam limit zapytań), a NIE wymyśla drugiego sposobu —
 * ale nie DZIEDZICZY z niego ani go nie woła, bo w gałęzi, w której to
 * powstało, #10 jeszcze nie było scalone (scalane równolegle). Gdy oba trafią
 * na `main`, te dwa formularze będą już spójne co do metody, więc scalenie
 * nie powinno wymagać przeprojektowania — tylko literalnie dwa niemal
 * identyczne pliki obok siebie do ewentualnego wspólnego wyciągnięcia.
 *
 * DLACZEGO LOGIN + HASŁO, A NIE SAM LINK Z MAILA
 * Rozważone i odrzucone: token wysłany na e-mail jako JEDYNY czynnik.
 * Nawet z terminem ważności i jednorazowością token potwierdza tylko
 * „ktoś ma w tej chwili dostęp do tej skrzynki", nie „to właściciel konta
 * wpisujący dane, które zna". UUID/token w adresie to nie autoryzacja
 * (AGENTS.md §7) — a maile bywają przekazywane dalej i czytane z automatu
 * (podglądy, skanery antyspamowe), które potrafią „kliknąć" link same.
 * Hasło jest silniejszym dowodem własności konta i to jest już przyjęty
 * wzorzec repozytorium dla dokładnie tej sytuacji (#10) — nie wymyślamy
 * drugiego. Kto zapomniał hasła, ma „Nie pamiętam hasła" (działa niezależnie
 * od statusu konta — `PasswordResetController` nic nie sprawdza) i adres
 * kontaktowy jako drogę zapasową.
 *
 * Rozważone i odrzucone: link z maila JAKO DODATEK do hasła (2FA na tę jedną
 * akcję). Odrzucone jako nieproporcjonalne dla MVP i niespójne z #10 — jeśli
 * audyt bezpieczeństwa uzna, że sama karencja na usunięcie konta zasługuje na
 * silniejsze potwierdzenie niż odwołanie od bana, to samo rozumowanie
 * powinno objąć oba formularze naraz, nie tylko ten.
 */
class AccountDeletionController extends Controller
{
    public function __construct(private readonly CancelAccountDeletion $cofnij) {}

    public function showCancelForm(): View
    {
        return view('pages.account.cancel-deletion', [
            'graceDays' => (int) config('kuking.account.delete_grace_days'),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'login.required' => 'Podaj swój adres e-mail albo nazwę użytkownika.',
            'password.required' => 'Wpisz hasło do swojego konta.',
        ]);

        $osoba = User::findByLogin($data['login']);

        // Komunikat jednakowy dla złego loginu i złego hasła — inaczej ten
        // formularz byłby wygodnym sprawdzaczem, czy dane konto istnieje
        // (ta sama zasada co w LoginController i w formularzu odwołań #10).
        if ($osoba === null || ! Hash::check($data['password'], (string) $osoba->password)) {
            throw ValidationException::withMessages([
                'login' => 'Nie rozpoznajemy tych danych. Sprawdź, czy adres/nazwa i hasło są wpisane poprawnie. '
                    .'Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła” — to działa także dla konta '
                    .'oznaczonego do usunięcia.',
            ]);
        }

        try {
            $this->cofnij->handle($osoba);
        } catch (RuntimeException $blad) {
            throw ValidationException::withMessages(['login' => $blad->getMessage()]);
        }

        AuditLogEntry::record('account.delete_cancelled', $osoba, $osoba, ip: $request->ip());

        return redirect()->route('login')->with('status',
            'Usunięcie konta zostało cofnięte. Możesz się teraz zalogować jak wcześniej.',
        );
    }
}

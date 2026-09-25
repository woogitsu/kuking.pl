<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Security\LimitProbHasla;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Users\Actions\CancelAccountDeletion;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\Turnstile;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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
 *
 * KONTO Z WŁĄCZONĄ WERYFIKACJĄ DWUETAPOWĄ (issue #1314)
 * Akapit wyżej odrzuca link z maila jako DODATKOWY składnik — i to zostaje.
 * Czym innym jest konto, którego właściciel SAM włączył kod z aplikacji: tam
 * samo hasło nie loguje (`LoginController` → `TwoFactorChallengeController`),
 * więc nie może też cofać usunięcia. Inaczej ten publiczny formularz byłby
 * furtką obok logowania — kto zna tylko hasło, przywracałby cudze konto
 * i mógł się potem na nie zalogować hasłem z tego samego wycieku. Kod
 * sprawdza TEN SAM `TwoFactorAuthenticator` co przy logowaniu (ochrona
 * przed powtórzeniem kodu, kody zapasowe jednorazowe) i próby liczą się
 * w TYM SAMYM koszyku konta. Konto bez 2FA — bez zmian.
 */
class AccountDeletionController extends Controller
{
    public function __construct(
        private readonly CancelAccountDeletion $cofnij,
        private readonly LimitProbHasla $limit,
        private readonly TwoFactorAuthenticator $totp,
    ) {}

    public function showCancelForm(): View
    {
        return view('pages.account.cancel-deletion', [
            'graceDays' => (int) config('kuking.account.delete_grace_days'),
        ]);
    }

    public function cancel(Request $request): RedirectResponse
    {
        // `Validator::make` zamiast `$request->validate()`: wyjątek walidacji
        // odkłada w sesji całe wejście poza hasłem, czyli także `code` —
        // a kod zapasowy jest sekretem (ta sama zasada co w
        // `TwoFactorChallengeController`). Wraca wyłącznie `login`.
        $walidator = Validator::make($request->all(), [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:64'],
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
            Turnstile::POLE => TurnstileJestPotwierdzony::reguly('cofniecie_usuniecia'),
        ], [
            'login.required' => 'Podaj swój adres e-mail albo nazwę użytkownika.',
            'password.required' => 'Wpisz hasło do swojego konta.',
            'code.string' => 'Wpisz kod z aplikacji albo kod zapasowy.',
            'code.max' => 'Ten kod jest za długi. Wpisz sześciocyfrowy kod z aplikacji albo kod zapasowy.',
        ]);

        if ($walidator->fails()) {
            $this->odmow($request, $walidator->errors()->toArray());
        }

        $data = $walidator->validated();

        // TEN FORMULARZ SPRAWDZA HASŁO, więc chodzi po TYCH SAMYCH TRZECH
        // KOSZYKACH CO `/login` (`App\Domain\Security\LimitProbHasla`) —
        // dokładnie to wspólne wyciągnięcie, które komentarz na górze tej
        // klasy zapowiadał na chwilę, gdy #10 i A8 spotkają się na `main`.
        //
        // Sam `throttle:cancel_delete` (5/60 min) nie wystarczał: liczy się
        // po ADRESIE, a `User::findByLogin()` + `Hash::check()` odpowiada tu
        // na pytanie o hasło do DOWOLNEGO konta, nie tylko oznaczonego do
        // usunięcia (status sprawdza dopiero `CancelAccountDeletion` niżej).
        // Zmierzone przed tą zmianą: 60 prób hasła do jednego konta z 60
        // różnych adresów — ZERO odmów.
        $adres = (string) $request->ip();

        // `LimitProbHasla` rzuca zwykły `ValidationException`, a ten przy
        // przekierowaniu odkłada w sesji całe wejście poza hasłem — razem
        // z `code`, który `x-field` wstawiłby potem jawnie w `value` pola.
        // Kod zapasowy w sesji i w HTML-u to dokładnie to, przed czym broni
        // `odmow()`, więc odmowa limitu idzie tą samą drogą.
        try {
            $this->limit->zatrzymajJesliZaDuzo($data['login'], $adres);
        } catch (ValidationException $odmowaLimitu) {
            $this->odmow($request, $odmowaLimitu->errors());
        }

        $osoba = User::findByLogin($data['login']);

        // Komunikat jednakowy dla złego loginu i złego hasła — inaczej ten
        // formularz byłby wygodnym sprawdzaczem, czy dane konto istnieje
        // (ta sama zasada co w LoginController i w formularzu odwołań #10).
        if ($osoba === null || ! Hash::check($data['password'], (string) $osoba->password)) {
            $this->limit->zapiszNieudanaProbe($data['login'], $adres);

            $this->odmow($request, [
                'login' => 'Nie rozpoznajemy tych danych. Sprawdź, czy e-mail albo nazwa i hasło są wpisane poprawnie. '
                    .'Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła” — to działa także dla konta '
                    .'oznaczonego do usunięcia.',
            ]);
        }

        // DOBRE HASŁO CZYŚCI PARĘ I KONTO, NIGDY ADRES (`KluczeLimitow`).
        $this->limit->wyczyscPoUdanej($data['login'], $adres);

        // Czy jest co cofać — liczone TERAZ, ale ogłaszane dopiero po kodzie.
        // Kod zapasowy jest jednorazowy, więc na koncie, którego nie ma czego
        // cofać (aktywne, wymazane), poprawny kod ma zostać sprawdzony, ale
        // NIE zużyty — inaczej pomyłka co do stanu własnego konta kosztuje
        // człowieka jeden z kilku kodów ratunkowych.
        $powodOdmowy = $this->cofnij->powodOdmowy($osoba);

        // Drugi składnik PRZED ogłoszeniem stanu: bez kodu formularz nie
        // mówi nawet, w jakim stanie jest konto.
        if ($osoba->hasTwoFactorConfirmed()) {
            $this->sprawdzKodDwuetapowy(
                $request,
                $osoba,
                trim((string) ($data['code'] ?? '')),
                zuzyjKodZapasowy: $powodOdmowy === null,
            );
        }

        // Odmowa bez wołania `handle()`: gdyby konto w międzyczasie stało się
        // `pending_delete`, cofnięcie przeszłoby na NIEZUŻYTYM kodzie zapasowym.
        if ($powodOdmowy !== null) {
            $this->odmow($request, ['login' => $powodOdmowy]);
        }

        try {
            $this->cofnij->handle($osoba);
        } catch (BladDlaCzlowieka $blad) {
            $this->odmow($request, ['login' => $blad->getMessage()]);
        }

        // Kara sprzed zgłoszenia (albo nałożona w karencji) wraca razem
        // z kontem (#980). Audyt zapisuje stan, do którego konto wróciło —
        // decyzja o danych i decyzja o prawie do konta to dwie różne rzeczy.
        $przywrocony = (string) $osoba->fresh()?->status;

        AuditLogEntry::record('account.delete_cancelled', $osoba, $osoba,
            metadata: ['status' => $przywrocony], ip: $request->ip());

        return redirect()->route('login')->with('status', $przywrocony === User::STATUS_ACTIVE
            ? 'Usunięcie konta zostało cofnięte. Możesz się teraz zalogować jak wcześniej.'
            : 'Usunięcie konta zostało cofnięte. Konto wraca do stanu sprzed zgłoszenia — '
                .'nadal obowiązuje decyzja moderacji, o której pisaliśmy. Szczegóły zobaczysz przy logowaniu.',
        );
    }

    /**
     * Kod z aplikacji albo kod zapasowy — jedno pole, bo to jeden formularz.
     *
     * Same cyfry idą do `verifyCode()`, reszta do `consumeBackupCode()`
     * (kody zapasowe mają litery, `XXXXX-XXXXX`). Limit to ten sam koszyk
     * konta co na ekranie logowania (`TwoFactorAuthenticator::kluczLimituProb`)
     * i tak samo liczy się tylko ZŁY kod, nie puste pole.
     *
     * `$zuzyjKodZapasowy = false` sprawdza kod zapasowy bez skreślania go
     * z listy — dla konta, którego nie ma czego cofać (patrz `cancel()`).
     */
    private function sprawdzKodDwuetapowy(Request $request, User $osoba, string $kod, bool $zuzyjKodZapasowy): void
    {
        if ($kod === '') {
            $this->odmow($request, [
                'code' => 'To konto ma włączoną weryfikację dwuetapową. Otwórz aplikację uwierzytelniającą '
                    .'w telefonie i wpisz sześciocyfrowy kod (albo jeden z kodów zapasowych), '
                    .'a potem wpisz jeszcze raz hasło i kliknij „Cofnij usunięcie konta”.',
            ]);
        }

        [$maxProb, $decayMinuty] = TwoFactorAuthenticator::limitProb();
        $klucz = TwoFactorAuthenticator::kluczLimituProb($osoba);

        if (RateLimiter::tooManyAttempts($klucz, $maxProb)) {
            $minuty = max(1, (int) ceil(RateLimiter::availableIn($klucz) / 60));

            $this->odmow($request, [
                'code' => "Za dużo prób kodu. Spróbuj ponownie za {$minuty} min.",
            ]);
        }

        $cyfry = (string) preg_replace('/\s+/', '', $kod);

        $poprawny = ctype_digit($cyfry)
            ? $this->totp->verifyCode($osoba, (string) $osoba->two_factor_secret, $cyfry)
            : ($zuzyjKodZapasowy
                ? $this->totp->consumeBackupCode($osoba, $kod)
                : $this->totp->backupCodeMatches($osoba, $kod));

        if (! $poprawny) {
            RateLimiter::hit($klucz, $decayMinuty * 60);

            $this->odmow($request, [
                'code' => 'Kod jest nieprawidłowy albo już wykorzystany. Sprawdź godzinę w telefonie, '
                    .'wpisz nowy kod z aplikacji (albo niewykorzystany kod zapasowy) i jeszcze raz hasło.',
            ]);
        }

        RateLimiter::clear($klucz);
    }

    /**
     * Powrót na formularz z błędami. Wraca wyłącznie `login` — nigdy hasło
     * ani kod (sekrety, patrz `Validator::make` wyżej).
     *
     * @param  array<string, string|array<int, string>>  $bledy
     */
    private function odmow(Request $request, array $bledy): never
    {
        throw new HttpResponseException(
            back()->withErrors($bledy)->withInput($request->only('login')),
        );
    }
}

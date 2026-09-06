<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stan konta sprawdzany przy KAŻDYM uwierzytelnionym żądaniu (issue #39).
 *
 * PROBLEM, KTÓRY TO ZAMYKA
 * `ban()` i `suspend()` zmieniały `status`, ale `isActive()` było pytane tylko
 * przy logowaniu i w części Policies. Osoba zbanowana za nękanie działała
 * dalej, dopóki nie wylogowała się sama — przy `SESSION_LIFETIME=10080` to
 * jest siedem dni. Ban „działał" (kolumna się zmieniała), tylko nie robił
 * tego, po co istnieje.
 *
 * Dlatego middleware jest wpięty GLOBALNIE w grupie `web`, a nie wybiórczo na
 * kontrolerach. Wybiórczo znaczy: następny nowy kontroler go zgubi, a nikt
 * tego nie zauważy, bo brak tej kontroli niczego nie wywala — po prostu
 * przepuszcza.
 *
 * PODZIAŁ ODPOWIEDZIALNOŚCI
 * - `banned`, `pending_delete` → wylogowanie natychmiast, przy pierwszym żądaniu.
 * - `suspended` → dostęp tylko do ODCZYTU. Konto żyje, treści są widoczne,
 *   ale nie da się nic opublikować. Wylogowanie musi działać, inaczej osoba
 *   zostaje uwięziona w serwisie bez wyjścia.
 * - kara z minionym terminem → konto wraca do `active` OD RAZU, bez czekania
 *   na zadanie w harmonogramie (issue #40).
 *
 * Sesje karanego użytkownika kasuje `User::suspend()` / `User::ban()` —
 * to odcina go w innych przeglądarkach. Ten middleware jest drugą warstwą:
 * łapie także konta, których status zmieniono inaczej niż przez te metody
 * (np. ręcznie w psql podczas incydentu).
 */
class EnsureAccountIsActive
{
    /**
     * Trasy dostępne mimo zawieszenia — inaczej konto jest w pułapce.
     *
     * `logout` musi zostać, bo to jedyne wyjście. `settings.data` zostaje,
     * bo prawo do kopii swoich danych (RODO art. 15 i 20) nie znika przez
     * decyzję moderacyjną.
     *
     * `appeals.store` zostaje z tego samego powodu, tylko mocniejszego:
     * zawieszenie JEST decyzją, od której człowiek ma prawo się odwołać
     * (DSA art. 20). Blokowanie tu zapisu znaczyłoby, że kara odbiera prawo
     * do jej zakwestionowania — czyli odwołanie istnieje dla wszystkich poza
     * tymi, których dotyczy (#10).
     */
    private const DOZWOLONE_MIMO_ZAWIESZENIA = [
        'logout',
        'settings.data',
        'settings.data.export',
        'appeals.store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Kara minęła — przywracamy dostęp od razu. Zadanie w harmonogramie
        // chodzi co jakiś czas, a użytkownik nie powinien czekać na crona,
        // żeby wrócić do serwisu w dniu, w którym kara się skończyła.
        if ($user->punishmentHasExpired()) {
            $user->reinstate();
        }

        if ($user->isBanned() || $user->status === User::STATUS_PENDING_DELETE) {
            return $this->wyloguj($request, $user);
        }

        if ($user->isSuspended() && $this->tozZapis($request)) {
            return back()->withErrors(['konto' => $this->komunikatZawieszenia($user)]);
        }

        return $next($request);
    }

    /**
     * Czy to żądanie próbuje coś ZMIENIĆ.
     *
     * Odczyt zostawiamy zawieszonemu kontu celowo: zabranie dostępu do
     * własnych treści i do wiadomości od moderacji zamieniłoby karę czasową
     * w zniknięcie serwisu, a osoba nie miałaby jak sprawdzić, za co i na jak
     * długo.
     */
    private function tozZapis(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return false;
        }

        return ! in_array($request->route()?->getName(), self::DOZWOLONE_MIMO_ZAWIESZENIA, true);
    }

    private function komunikatZawieszenia(User $user): string
    {
        // Bez gry słowem „kuKING" — D-009 zabrania jej w wiadomościach
        // moderacyjnych i komunikatach błędu.
        if ($user->status_expires_at === null) {
            return 'Twoje konto jest zawieszone, więc nie można teraz nic opublikować. '
                .'Czytać możesz dalej. Napisz do nas: '.config('kuking.community.contact_email');
        }

        // Data po polsku, nie ISO 8601 — `docs/UX_50_PLUS.md`. Carbon ma
        // ustawione `pl` (APP_LOCALE), więc `translatedFormat` daje
        // „12 września 2026" bez własnej tablicy miesięcy.
        return 'Twoje konto jest zawieszone do '
            .$user->status_expires_at->translatedFormat('j F Y')
            .'. Do tego czasu możesz czytać, ale nie opublikujesz wpisu ani komentarza.';
    }

    private function wyloguj(Request $request, User $user): Response
    {
        // Przy blokadzie doklejamy treść napisaną przez moderatora. Osoba
        // zbanowana nie zobaczy powiadomienia o decyzji — leży ono w serwisie,
        // z którego właśnie ją wylogowaliśmy. Ten komunikat jest jedynym,
        // który do niej dotrze (DSA art. 17).
        $odModeratora = $user->isBanned() ? $user->latestModerationMessage() : null;

        $powod = $user->isBanned()
            ? 'To konto zostało zablokowane. '
                .($odModeratora !== null ? $odModeratora.' ' : '')
                .'Jeśli uważasz, że to pomyłka, napisz do nas: '
                .config('kuking.community.contact_email')
            : 'To konto jest oznaczone do usunięcia, dlatego zostałeś/aś wylogowany/a. Jeśli chcesz je odzyskać, '
                .'wejdź na stronę „Cofnij usunięcie konta” ('.route('account.delete.cancel').') i potwierdź '
                .'hasłem, że to Ty. Jeśli dane zostały już usunięte na stałe, ta strona Cię o tym poinformuje — '
                .'wtedy napisz do nas: '.config('kuking.community.contact_email');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['login' => $powod]);
    }
}

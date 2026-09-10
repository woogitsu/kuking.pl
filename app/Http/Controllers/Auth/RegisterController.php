<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\ZaproszenieWSesji;
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
use App\Support\NazwaUzytkownika;
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
    public function show(ZaproszenieWSesji $sesja): View
    {
        abort_unless(config('kuking.account.registration_open'), 503, 'Rejestracja jest chwilowo zamknięta.');

        // `biezace()` sprawdza ważność przy każdym odczycie i czyści martwy
        // klucz w sesji — zaproszenie mogło wygasnąć albo zostać zużyte między
        // jednym żądaniem a drugim. `null` znaczy „zwykła rejestracja".
        return view('auth.register', ['zaproszenie' => $sesja->biezace()]);
    }

    public function store(Request $request, ZaproszenieWSesji $sesja): RedirectResponse
    {
        abort_unless(config('kuking.account.registration_open'), 503);

        /*
         * ═════════════════════════════════════════════════════════════════
         *  REJESTRACJA Z ZAPROSZENIA — ADRES POCHODZI Z BAZY (D-085)
         * ═════════════════════════════════════════════════════════════════
         *
         * Kto przyszedł z linku w wiadomości, ma adres potwierdzony samym
         * kliknięciem — dowodem posiadania skrzynki. Ten dowód wolno przyjąć
         * TYLKO dla adresu, na który wiadomość naprawdę poszła, więc adres
         * bierzemy z WIERSZA W BAZIE wskazanego przez sesję, a nie z pola
         * `email` w żądaniu. To, co przyszło z przeglądarki, jest w tym
         * przypadku po prostu nadpisywane i nigdzie nie czytane.
         *
         * Gdyby było odwrotnie, powstałoby konto z `email_verified_at`
         * ustawionym na adres, którego nikt nigdy nie potwierdził — czyli
         * gotowa droga do konta na cudzej skrzynce, z „nie pamiętam hasła"
         * jako dalszym ciągiem.
         *
         * Zaproszenie jest tu jeszcze NIEZUŻYTE. Zużywa je dopiero transakcja
         * zakładająca konto, niżej — bo dopiero tam wiadomo, że reszta
         * formularza jest poprawna. Człowiek, który pomyli hasło, ma wrócić
         * do formularza z żywym zaproszeniem, a nie stracić link.
         */
        $zaproszenie = $sesja->biezace();

        if ($zaproszenie !== null) {
            $request->merge(['email' => $zaproszenie->email]);
        }

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
            'username.regex' => 'Z tej nazwy nie da się ułożyć adresu. Wpisz imię albo imię i miejscowość, na przykład: basia z podkarpacia.',
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

        try {
            $user = DB::transaction(function () use ($data, $zaproszenie, $sesja): User {
                /*
                 * ZUŻYCIE ZAPROSZENIA W TEJ SAMEJ TRANSAKCJI, W KTÓREJ POWSTAJE
                 * KONTO — i to jest cała jednorazowość tej drogi (D-085).
                 *
                 * Nie na ekranie zaproszenia i nie przy przyjęciu go do sesji:
                 * za tamtymi krokami stoi jeszcze cały formularz, o który ta
                 * osoba już raz się odbiła. Gdyby link ginął wcześniej, pierwsza
                 * pomyłka w nazwie użytkownika odbierałaby go bezpowrotnie.
                 *
                 * `zuzyj()` bierze wiersz pod `lockForUpdate()` i kasuje go tutaj,
                 * więc drugie żądanie z tej samej sesji (dwuklik „Załóż konto")
                 * zastaje albo blokadę, albo pustkę — a nie drugie konto.
                 *
                 * ADRES ODDAJE WIERSZ, NIE FORMULARZ. `$data['email']` jest w tym
                 * przypadku kopią tej samej wartości (nadpisaną wyżej), ale bierzemy
                 * ją stąd, żeby potwierdzony adres pochodził dowodnie z bazy nawet
                 * wtedy, gdyby ktoś kiedyś ruszył kolejność wyżej.
                 */
                $potwierdzony = false;
                $adres = $data['email'];

                if ($zaproszenie !== null) {
                    $zZaproszenia = $sesja->zuzyj($zaproszenie);

                    if ($zZaproszenia === null) {
                        // Zaproszenie przestało działać między wejściem na
                        // formularz a jego wysłaniem. Konto z potwierdzonym
                        // adresem bez ważnego dowodu posiadania skrzynki nie ma
                        // prawa powstać — wycofujemy całą transakcję.
                        throw new BladDlaCzlowieka(
                            'To zaproszenie przestało działać, zanim zdążyliśmy założyć konto — mogło wygasnąć '
                            .'albo zostać już użyte. Wpisz swój adres e-mail poniżej i spróbuj jeszcze raz; '
                            .'wyślemy na niego jedną wiadomość z potwierdzeniem.',
                        );
                    }

                    $adres = $zZaproszenia;
                    $potwierdzony = true;
                }

                // `email` NIE JEST w `$fillable` (issue #195, ten sam powód co
                // `status` i `role`), więc adres wchodzi jawnie, przez
                // `assignEmail()`. Bez zaproszenia idzie BEZ potwierdzenia —
                // potwierdzi je dopiero kliknięcie w list, który zaraz wyjdzie
                // (`event(new Registered)`). Z zaproszeniem jest już potwierdzony
                // i wtedy listener Laravela nie ma czego wysyłać.
                $user = (new User([
                    'password' => Hash::make($data['password']),
                    'locale' => 'pl',
                    'text_scale' => config('kuking.text.default_scale'),
                    'age_confirmed_at' => now(),
                ]))->assignEmail($adres, potwierdzony: $potwierdzony);

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
        } catch (BladDlaCzlowieka $e) {
            /*
             * POPRAWNE DANE NIE ZNIKAJĄ Z FORMULARZA (docs/UX_50_PLUS.md).
             * Człowiek stracił zaproszenie, a nie wpisane imię, nazwę i zgody
             * — wracają razem z komunikatem mówiącym, CO ZROBIĆ. Hasła nie
             * oddajemy nigdy (`except`), więc trzeba je wpisać jeszcze raz.
             *
             * Zaproszenia w sesji już nie ma (`zuzyj()` skasowało martwy wiersz,
             * a `biezace()` czyści klucz), więc formularz pokaże z powrotem
             * zwykłe pole na adres e-mail.
             */
            return redirect()->route('register')
                ->withInput($request->except(['password', Turnstile::POLE]))
                ->withErrors(['email' => $e->getMessage()]);
        }

        // Z zaproszeniem adres jest już potwierdzony, więc listener Laravela
        // (`SendEmailVerificationNotification`) sam nic nie wyśle — pyta
        // `hasVerifiedEmail()`. Zdarzenie wypuszczamy mimo to, bo słucha go
        // także wszystko inne, co ma się dziać po założeniu konta.
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

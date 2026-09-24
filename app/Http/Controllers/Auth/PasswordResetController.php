<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\DziennyBudzetListow;
use App\Domain\Security\LimitProbHasla;
use App\Domain\Users\Actions\CancelEmailChange;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\Komunikat;
use App\Support\Poczta;
use App\Support\Turnstile;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
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
            Turnstile::POLE => TurnstileJestPotwierdzony::reguly('odzyskanie_hasla'),
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
        // POCZTA MOŻE NIE DZIAŁAĆ, a `sendResetLink` i tak zwróci sukces —
        // przy `MAIL_MAILER=log` wiadomość idzie do dziennika. Formularz jest
        // wtedy schowany (`auth/forgot-password.blade.php`), ale ta trasa
        // pozostaje osiągalna wprost, więc odpowiedź musi mówić prawdę także
        // tutaj. Bez tego człowiek, który trafił tu ze starego adresu albo
        // z zakładki, dostawał „wysłaliśmy wiadomość" i czekał.
        if (! Poczta::dziala()) {
            return back()->with(Komunikat::blad(Poczta::komunikatBrakuPoczty()));
        }

        /*
         |------------------------------------------------------------------
         | BUDŻET POCZTY — MIEJSCE REZERWUJEMY PRZED WYSYŁKĄ (20.09.2026)
         |------------------------------------------------------------------
         |
         | Do tej pory ta droga nie miała ANI sufitu na adres, ANI budżetu
         | poczty. Zmierzone: `limits.password_reset` to 5 próśb na 10 minut
         | z adresu IP, czyli 720 na dobę — a każda z nich mogła iść na INNY
         | adres. Jeden sprawca z jednego łącza wysyłał listy na 300 różnych
         | skrzynek i opróżniał całą dobową pulę EmailLabs w około 70 minut.
         | Pierwszą rzeczą, która wtedy przestawała działać, było potwierdzenie
         | rejestracji i logowanie linkiem — czyli wejście dla nowych ludzi.
         |
         | KLASA `zwykla`, NIE `wejscie`, i to jest cała treść tej zmiany.
         | Przypomnienie hasła też jest drogą powrotu na konto, ale prosi
         | o nie KTOKOLWIEK Z ZEWNĄTRZ, na CUDZY adres, bez dowodu, że ten
         | adres do niego należy. Gdyby dostało próg zero, dzieliłoby ostatnie
         | listy doby z listem, który ma chronić. Gaśnie więc nad rezerwą
         | transakcyjną: przestaje wysyłać, gdy w puli zostaje 100 listów,
         | i tych stu nie dotyka (`kuking.poczta.progi_wygaszania`).
         |
         | Rezerwacja stoi PRZED wysyłką, bo po wysyłce jest już za późno —
         | a miejsce, z którego nie wyszedł żaden list (adres bez konta),
         | wraca do puli niżej. Bez tego automat wpisujący zmyślone adresy
         | wyczerpywałby klasę `zwykla` w kilka minut, nie wysławszy nic.
         */
        $budzet = DziennyBudzetListow::dlaOdzyskaniaHasla();

        if (! $budzet->sprobujZarezerwowac()) {
            return back()->with('status', $this->komunikatWyczerpanejPuli($budzet));
        }

        $status = Password::sendResetLink([
            'email' => User::normalizeEmail((string) $request->input('email', '')),
        ]);

        if ($status !== Password::ResetLinkSent) {
            // NA TYM ADRESIE NIE MA KONTA (albo trwa własny limit Laravela na
            // powtórne wysłanie tego samego tokenu), więc żaden list nie
            // wyszedł i miejsce wraca do puli. Odpowiedź dla człowieka NIE
            // ZMIENIA SIĘ ani o słowo — inaczej formularz odpowiadałby na
            // pytanie, kto ma tu konto.
            $budzet->zwolnij();
        }

        return back()->with('status',
            'Jeśli na ten adres jest założone konto, wysłaliśmy na niego wiadomość z linkiem do ustawienia nowego hasła. Sprawdź też folder „Spam”.',
        );
    }

    /**
     * DWA POWODY ODMOWY, DWA RÓŻNE ZDANIA — wzorzec z `LoginLinkController`.
     * Przy pustej puli czekanie na list jest bezcelowe i trzeba to powiedzieć
     * wprost; przy ścisku na blokadzie licznika drugie kliknięcie wystarcza.
     *
     * Odczyt puli służy WYŁĄCZNIE doborowi treści — o wysyłce rozstrzygnęła
     * już atomowa rezerwacja (D-076). Oba zdania są takie same niezależnie od
     * tego, czy na podanym adresie jest konto.
     */
    private function komunikatWyczerpanejPuli(DziennyBudzetListow $budzet): string
    {
        $adres = (string) config('kuking.community.contact_email');

        if ($budzet->jestMiejsce()) {
            return 'Nie udało nam się w tej chwili wypuścić tego listu — kilka próśb trafiło na siebie '
                .'w tej samej sekundzie. Kliknij „Wyślij link” jeszcze raz. Jeśli znowu nie wyjdzie, '
                .'napisz do nas na '.$adres.', a pomożemy Ci wrócić na konto. Odpisuje człowiek.';
        }

        return 'Dzisiaj wysłaliśmy już wszystkie listy z linkiem do nowego hasła, jakie mieliśmy na dziś, '
            .'więc ten nie wyjdzie — nie czekaj na niego. Spróbuj jutro albo napisz do nas na '
            .$adres.', a pomożemy Ci wrócić na konto. Odpisuje człowiek.';
    }

    public function resetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function reset(Request $request, CancelEmailChange $anuluj, LimitProbHasla $limit): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->uncompromised()],
        ], [
            /*
             * TRZY PIERWSZE KOMUNIKATY DOPISANE PRZY PRZEGLĄDZIE KOMUNIKATÓW.
             *
             * Wcześniej `token`, `email` i `password` nie miały tu własnego
             * zdania, więc wypadał szablon ogólny z `lang/pl/validation.php`:
             * „Pole «link do ustawienia hasła» jest wymagane. Uzupełnij je,
             * żeby wysłać formularz." — zmierzone prawdziwym żądaniem, nie
             * odczytane z pliku.
             *
             * Przy `token` było to wręcz mylące: tego pola NIE MA na ekranie
             * (jest ukryte, wartość przychodzi z odnośnika w liście), więc
             * człowiek dostawał polecenie uzupełnienia czegoś, czego nie
             * widzi. Prawdziwa przyczyna to obcięty albo zużyty odnośnik —
             * i o tym mówi nowe zdanie.
             */
            'token.required' => 'Ten odnośnik jest niepełny — mógł się obciąć przy kopiowaniu z listu. Otwórz go w liście jeszcze raz albo poproś o nowy na stronie „Nie pamiętam hasła”.',
            'email.required' => 'Wpisz adres e-mail, na który założone jest konto.',
            'email.email' => 'Ten adres wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
            'password.required' => 'Wpisz nowe hasło w polu „Nowe hasło”.',
            'password.confirmed' => 'Oba hasła muszą być takie same. Wpisz jeszcze raz to samo w obu polach.',
            'password.min' => 'Hasło musi mieć co najmniej 10 znaków. Najprościej połączyć myślnikami trzy swoje słowa, na przykład: parasol-wtorek-cebula. Wymyśl własne, nie przepisuj tych z przykładu.',
            'password.uncompromised' => 'To hasło pojawiło się już w wyciekach danych z innych serwisów. Wybierz inne.',
        ]);

        // Ten sam powód co przy wysyłce linku: token jest przypisany do adresu
        // zapisanego małymi literami. Formularz podstawia adres z linku, ale
        // pole jest edytowalne i klawiatura telefonu podnosi pierwszą literę.
        $status = Password::reset(
            [
                ...$request->only('password', 'password_confirmation', 'token'),
                'email' => User::normalizeEmail((string) $request->input('email', '')),
            ],
            function ($user, string $password) use ($request, $anuluj, $limit): void {
                // Hasło jedną nazwaną drogą (`assignPassword()`), bo
                // `password` jest poza `$fillable`. Wspólna metoda niżej
                // odwołuje również token „zapamiętaj mnie” (#584).
                $user->assignPassword($password)->save();

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

                // KLIKNIĘCIE W TEN LINK POTWIERDZA ADRES (issue #317).
                //
                // Kliknięcie jest dowodem dostępu do skrzynki — dokładnie
                // tym samym, który `User::assignEmail()` przyjmuje jako
                // podstawę do postawienia znacznika przy rejestracji
                // z zaproszenia (D-085) i przy zmianie adresu (D-048).
                // Trzymanie tu `NULL` po takim kliknięciu znaczyłoby, że
                // uznajemy dowód za dobry w dwóch miejscach, a w trzecim nie.
                //
                // BEZ TEGO NAPRAWA #317 ZAMYKA PĘTLĘ NA CZŁOWIEKU. Konto
                // z niepotwierdzonym adresem dostaje od teraz ten list
                // ZAMIAST linku do logowania (`WyslijOdzyskanieKonta`);
                // gdyby ustawienie hasła nie potwierdzało adresu, taka
                // osoba nie odzyskałaby wygodnej drogi wejścia NIGDY —
                // każda kolejna prośba o link kończyłaby się tym samym
                // listem, i tak w kółko.
                //
                // Dla konta z adresem już potwierdzonym ta linijka nie
                // zmienia nic: `markEmailAsVerified()` sprawdza znacznik
                // sama i przy wypełnionym nie rusza wiersza.
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }

                // ...I UNIEWAŻNIA ZAMÓWIONĄ ZMIANĘ ADRESU E-MAIL (issue #195).
                //
                // Ten sam powód co przy zmianie hasła w ustawieniach:
                // reset hasła jest drogą powrotu na konto, do którego ktoś
                // inny mógł mieć dostęp. Gdyby oczekująca zmiana adresu
                // przetrwała reset, napastnik dokończyłby przejęcie konta
                // swoim odnośnikiem — właśnie w chwili, w której właściciel
                // odzyskuje kontrolę. Pełne uzasadnienie:
                // `App\Domain\Users\Actions\CancelEmailChange`.
                $anuluj->handle($user, CancelEmailChange::POWOD_RESET_HASLA, $request->ip());

                // ...I ZDEJMUJE BLOKADĘ KONTA Z LIMITERA LOGOWANIA.
                //
                // Koszyk konta (15 prób / 15 min, `config/kuking.php` →
                // `login_limits`) nie odróżnia właściciela od napastnika —
                // to jest jego zamierzona cena: kto zna cudzy login, umie
                // wyczerpać ten licznik cudzym loginem i zamknąć drogę
                // hasłem osobie, która nic złego nie zrobiła.
                //
                // Bez tej linijki podpowiedź „Jeśli nie pamiętasz hasła,
                // kliknij «Nie pamiętam hasła»", którą serwis pokazuje przy
                // KAŻDEJ nieudanej próbie logowania, prowadziła donikąd:
                // człowiek przechodził całą drogę przez skrzynkę, ustawiał
                // nowe hasło — i wracając na `/login` dostawał tę samą
                // odmowę, bo licznik nadal stał pełny. Dokładnie ten kształt
                // błędu, o który chodzi w AGENTS.md §5: obietnica wyjścia,
                // którego nie ma.
                //
                // BEZPIECZNE, bo tu już nie ma czego chronić: próby
                // napastnika dotyczyły hasła, które przed chwilą przestało
                // istnieć, a żeby tu dojść, trzeba było odebrać list z tej
                // skrzynki. Koszyk ADRESU zostaje nietknięty — inaczej
                // wystarczyłoby zresetować hasło własnego, jednorazowego
                // konta, żeby wyczyścić licznik adresowy przed powrotem do
                // rozpylania (`App\Support\KluczeLimitow`).
                $limit->zdejmijBlokadeKonta($user);

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

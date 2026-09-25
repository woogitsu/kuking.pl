<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\DziennyBudzetListow;
use App\Domain\Security\WynikPonowieniaPotwierdzenia;
use App\Domain\Security\WyslijPotwierdzenieAdresu;
use App\Http\Controllers\Controller;
use App\Models\MailFailure;
use App\Notifications\PotwierdzenieAdresu;
use App\Support\Poczta;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    /**
     * Ekran „Potwierdź swój adres e-mail".
     *
     * MÓWI PRAWDĘ TAKŻE WTEDY, GDY LIST NIE WYSZEDŁ (issue #234, D-062).
     * Do 10 września 2026 ten ekran zawsze twierdził „Wysłaliśmy wiadomość"
     * i kończył radą „Zajrzyj do folderu «Spam»". Gdy dostawca odmówił
     * przyjęcia listu — najczęściej przy wyczerpanym dobowym limicie — było
     * to nieprawdą, a rada wysyłała człowieka na poszukiwanie wiadomości,
     * która nigdy nie powstała. W grupie 50+ taka osoba nie pisze reklamacji;
     * uznaje, że serwis nie działa, i odchodzi.
     *
     * Skoro więc od tej pory WIEMY, że list przepadł (`mail_failures`), ekran
     * ma o tym powiedzieć — jednym zdaniem o fakcie z przeszłości, z datą.
     * Zdanie o zdarzeniu, a nie o stanie, zostaje prawdziwe także po udanym
     * ponowieniu i nie wymaga żadnego dodatkowego znacznika w bazie.
     *
     * NIE OBIECUJE LISTU, GDY POCZTA NIE WYSYŁA (issue #1335). Przy
     * `MAIL_MAILER=log`/`array` albo transporcie, którego nie da się
     * zbudować, ekran mówił „Wysłaliśmy wiadomość" i radził zajrzeć do
     * „Spamu". `Poczta::dziala()` — ta sama klasa co na ekranie „Nie pamiętam
     * hasła" — rozstrzyga, czy widok obiecuje list i pokazuje przycisk
     * ponowienia. `true` nie znaczy „dojdzie": późniejszą odmowę dostawcy
     * nadal pokazuje `MailFailure` wyżej.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        $uzytkownik = $request->user();

        if ($uzytkownik->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        return view('auth.verify-email', [
            'nieudanaWysylka' => MailFailure::przepadlListDo(
                $uzytkownik->getKey(),
                PotwierdzenieAdresu::class,
                (int) config('kuking.poczta.okno_prawdy_godzin', 24),
            ),
            'pocztaDziala' => Poczta::dziala(),
        ]);
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->route('home')->with('status', 'Adres e-mail potwierdzony. Dziękujemy.');
    }

    /**
     * „Wyślij wiadomość jeszcze raz".
     *
     * NIE MÓWI „WYSŁALIŚMY", GDY NIC NIE WYSZŁO (20 września 2026). Ten
     * przycisk ma limit 6 na minutę (`limits.verification_resend`) i nie miał
     * żadnego sufitu dobowego, więc jedno niepotwierdzone konto wypalało całą
     * pulę 300 listów w około 50 minut — a ekran przy każdym kliknięciu
     * zapewniał, że wiadomość poszła. Od teraz list jest liczony we wspólnej
     * puli poczty, a gdy pula odmówi, człowiek czyta, co z tym zrobić.
     *
     * SAM WSPÓLNY LICZNIK TEGO NIE ZATRZYMAŁ (D-246, 23 września 2026).
     * Ponowienie stało w klasie `wejscie` z progiem zero, więc jedno konto
     * dalej zużywało całą pulę w te same 50 minut — tylko że teraz odmawiała
     * wszystkim już sama aplikacja. Od D-246 ponowienie ma sufit dobowy na
     * konto i własną klasę `ponowienie`, która gaśnie, zanim sięgnie po
     * ostatnie listy rejestracji i logowania linkiem
     * (`WyslijPotwierdzenieAdresu::ponow()`).
     */
    public function resend(Request $request, WyslijPotwierdzenieAdresu $wyslij): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        // Przed rezerwacją z puli (issue #1335): list do sterownika, który
        // nic nie dostarcza, nie zjada przydziału i nie dostaje „Wysłaliśmy".
        if (! Poczta::dziala()) {
            return back()->with('status', self::komunikatBrakuPoczty());
        }

        return match ($wyslij->ponow($request->user())) {
            WynikPonowieniaPotwierdzenia::Wyslano => back()->with('status', 'Wysłaliśmy wiadomość jeszcze raz. Sprawdź też folder „Spam”.'),
            WynikPonowieniaPotwierdzenia::SufitKonta => back()->with('status', $this->komunikatSufituKonta()),
            WynikPonowieniaPotwierdzenia::BrakMiejscaWPuli => back()->with('status', $this->komunikatOdmowy()),
        };
    }

    /**
     * Zdanie na czas, w którym serwis nie wysyła poczty (issue #1335).
     *
     * WŁASNE, a nie `Poczta::komunikatBrakuPoczty()` — tamto obiecuje pomoc
     * w „powrocie na konto", a tu człowiek jest zalogowany i konto działa.
     * Bez rady o „Spamie": listu nie ma, więc nie ma czego tam szukać.
     */
    public static function komunikatBrakuPoczty(): string
    {
        return 'Nie wysyłamy teraz wiadomości e-mail, więc wiadomość z potwierdzeniem adresu nie przyjdzie — '
            .'nie czekaj na nią. Z konta korzystasz normalnie także bez potwierdzonego adresu. Jeśli potwierdzenie '
            .'jest Ci potrzebne, napisz do nas na '.(string) config('kuking.community.contact_email')
            .' — odpisuje człowiek i potwierdzimy adres inaczej.';
    }

    /**
     * TO KONTO DOSTAŁO JUŻ DZIŚ SWÓJ PRZYDZIAŁ (D-246).
     *
     * Zdanie mówi trzy rzeczy, w tej kolejności: ILE już wysłaliśmy (żeby
     * człowiek wiedział, że te wiadomości istnieją i warto ich poszukać),
     * KIEDY może poprosić znowu (jutro — licznik liczy dobę kalendarzową)
     * i CO ZROBIĆ DZIŚ (konto działa bez potwierdzenia; gdy potwierdzenie
     * jest potrzebne od razu — człowiek pod adresem kontaktowym). Ten sam
     * układ co przy wyczerpanej puli niżej, bez obietnicy, że list przyjdzie.
     */
    private function komunikatSufituKonta(): string
    {
        $ile = WyslijPotwierdzenieAdresu::sufitPonowienNaDobe();
        $adres = (string) config('kuking.community.contact_email');

        $dzis = 'Z konta korzystasz normalnie także bez potwierdzonego adresu. Jeśli potwierdzenie jest Ci '
            .'potrzebne dziś — napisz do nas na '.$adres.'. Odpisuje człowiek.';

        if ($ile < 1) {
            return 'Dzisiaj nie wysyłamy ponownie wiadomości z potwierdzeniem adresu. Spróbuj jutro, po północy. '.$dzis;
        }

        $wyslane = match (true) {
            $ile === 1 => 'jedną dodatkową wiadomość',
            in_array($ile % 10, [2, 3, 4], true) && ! in_array($ile % 100, [12, 13, 14], true) => $ile.' dodatkowe wiadomości',
            default => $ile.' dodatkowych wiadomości',
        };

        return 'Dzisiaj wysłaliśmy Ci już '.$wyslane.' z potwierdzeniem adresu. Tyle wysyłamy jednej osobie '
            .'w ciągu dnia, więc kolejnej dziś nie wyślemy. Poszukaj '.($ile === 1 ? 'jej' : 'ich')
            .' w skrzynce i w folderze „Spam”. Kolejną wiadomość możesz zamówić jutro, po północy. '.$dzis;
    }

    /**
     * DWA POWODY ODMOWY, DWA RÓŻNE ZDANIA — ta sama zasada i ten sam wzorzec
     * co przy wyczerpanym budżecie logowania linkiem
     * (`LoginLinkController::send`). Rezerwacja mówi tylko „nie", a te dwa
     * „nie" znaczą dla człowieka coś zupełnie innego: przy pustej puli
     * czekanie na list jest bezcelowe, a przy ścisku na blokadzie drugie
     * kliknięcie zwykle wystarcza.
     *
     * Odczyt puli jest tu WYŁĄCZNIE doborem treści komunikatu — o wysyłce
     * rozstrzygnęła już atomowa rezerwacja w akcji i nic tego nie odwraca
     * (D-076).
     */
    private function komunikatOdmowy(): string
    {
        $adres = (string) config('kuking.community.contact_email');

        if (DziennyBudzetListow::dlaPonowieniaPotwierdzenia()->jestMiejsce()) {
            return 'Nie udało nam się w tej chwili wypuścić tej wiadomości — kilka próśb trafiło na siebie '
                .'w tej samej sekundzie. Kliknij „Wyślij wiadomość jeszcze raz” za moment. Jeśli znowu nie '
                .'wyjdzie, napisz do nas na '.$adres.'. Odpisuje człowiek.';
        }

        // „Na ponowne wysyłki", nie „wszystkie e-maile": od D-246 ta klasa
        // gaśnie, gdy w puli zostaje jeszcze rezerwa dla rejestracji
        // i logowania linkiem — zdanie o pustej puli byłoby tu nieprawdą.
        return 'Na dziś skończyły się e-maile, które możemy przeznaczyć na ponowne wysyłki, więc ta wiadomość '
            .'nie wyjdzie — nie czekaj na nią. Z konta korzystasz normalnie także bez potwierdzonego adresu. '
            .'Kliknij ten przycisk jutro, a jeśli potwierdzenie jest Ci potrzebne dziś — napisz do nas na '
            .$adres.'. Odpisuje człowiek.';
    }
}

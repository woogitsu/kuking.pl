<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\DziennyBudzetListow;
use App\Domain\Security\WyslijPotwierdzenieAdresu;
use App\Http\Controllers\Controller;
use App\Models\MailFailure;
use App\Notifications\PotwierdzenieAdresu;
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
     * TEN LIST GAŚNIE OSTATNI ZE WSZYSTKICH (klasa `wejscie`, próg 0), więc
     * odmowa tutaj znaczy, że na dziś nie ma ANI JEDNEGO listu — nie że ta
     * jedna funkcja wyczerpała swój przydział.
     */
    public function resend(Request $request, WyslijPotwierdzenieAdresu $wyslij): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        if (! $wyslij->handle($request->user())) {
            return back()->with('status', $this->komunikatOdmowy());
        }

        return back()->with('status', 'Wysłaliśmy wiadomość jeszcze raz. Sprawdź też folder „Spam”.');
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

        if (DziennyBudzetListow::dlaPotwierdzeniaAdresu()->jestMiejsce()) {
            return 'Nie udało nam się w tej chwili wypuścić tej wiadomości — kilka próśb trafiło na siebie '
                .'w tej samej sekundzie. Kliknij „Wyślij wiadomość jeszcze raz” za moment. Jeśli znowu nie '
                .'wyjdzie, napisz do nas na '.$adres.'. Odpisuje człowiek.';
        }

        return 'Dzisiaj wysłaliśmy już wszystkie e-maile, jakie mieliśmy na dziś, więc ta wiadomość nie '
            .'wyjdzie — nie czekaj na nią. Z konta korzystasz normalnie także bez potwierdzonego adresu. '
            .'Kliknij ten przycisk jutro, a jeśli potwierdzenie jest Ci potrzebne dziś — napisz do nas na '
            .$adres.'. Odpisuje człowiek.';
    }
}

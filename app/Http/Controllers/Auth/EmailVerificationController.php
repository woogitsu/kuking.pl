<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

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

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'Wysłaliśmy wiadomość jeszcze raz. Sprawdź też folder „Spam”.');
    }
}

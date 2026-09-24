<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Users\Actions\CancelEmailChange;
use App\Domain\Users\Actions\ConfirmEmailChange;
use App\Domain\Users\Actions\RequestEmailChange;
use App\Exceptions\BladDlaCzlowieka;
use App\Http\Controllers\Controller;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Support\Komunikat;
use App\Support\Poczta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * „Adres e-mail" — pokazanie i zmiana adresu konta (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO OSOBNY EKRAN, A NIE POLE W `/ustawienia/profil`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo adres e-mail nie jest częścią profilu. Profil to nazwa, zdjęcie
 * i kilka słów o sobie — rzeczy, które widzą inni i które zapisuje się
 * jednym `PUT`. Adres e-mail to JEDYNA droga odzyskania konta: kto go
 * przestawi, przejmuje konto resetem hasła. Pole obok „bio", zapisywane tym
 * samym przyciskiem, byłoby przejęciem konta na jedno kliknięcie u każdego,
 * kto usiadł przy niezablokowanej przeglądarce.
 *
 * Dlatego droga jest osobna i pełna:
 *
 *   obecne hasło  →  list na NOWY adres  →  kliknięcie  →  zmiana
 *                 →  ostrzeżenie na STARY adres (od razu)
 *
 * ────────────────────────────────────────────────────────────────────────
 *  BEZ JAVASCRIPTU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Cały ekran to zwykłe formularze POST i zwykły odnośnik GET z listu.
 * Żaden krok nie potrzebuje skryptu — AGENTS.md §5. Osoba, której przy
 * słabym zasięgu nie dociągnął się JavaScript, ma tu zmienić adres tak samo
 * jak każda inna.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PIĘĆ PYTAŃ Z AGENTS.md §7 DLA TEGO EKRANU
 * ────────────────────────────────────────────────────────────────────────
 *
 *  auth          — cała grupa tras stoi w `middleware('auth')`;
 *  authorization — człowiek dotyka WYŁĄCZNIE własnego konta; przy
 *                  potwierdzeniu sprawdzamy właściciela żądania wprost,
 *                  bo UUID w adresie nie jest autoryzacją (AGENTS.md §7);
 *  validation    — niżej, z komunikatami po polsku;
 *  rate limit    — `confirm_password` na zamówieniu zmiany (to jest ta sama
 *                  wyrocznia na hasło co zmiana hasła, więc ten sam koszyk),
 *                  `ustawienia` na anulowaniu i potwierdzeniu;
 *  audit         — `account.email_change_requested`, `account.email_changed`
 *                  i `account.email_change_cancelled` w akcjach domenowych.
 */
class EmailSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('pages.settings.email', [
            'user' => $user,
            'oczekujaca' => $this->oczekujaca($user),
            'pocztaDziala' => Poczta::dziala(),
        ]);
    }

    /**
     * Krok pierwszy: obecne hasło + nowy adres → list na nowy adres.
     */
    public function request(Request $request, RequestEmailChange $zamow): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ], [
            'current_password.required' => 'Wpisz obecne hasło, żeby potwierdzić, że to Ty.',
            'email.required' => 'Podaj nowy adres e-mail.',
            'email.email' => 'Ten adres wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
            'email.max' => 'Ten adres jest za długi. Adres e-mail może mieć najwyżej 255 znaków.',
        ]);

        $user = $request->user();
        $nowyAdres = User::normalizeEmail($data['email']);

        // HASŁO PIERWSZE, PRZED CZYMKOLWIEK INNYM. Gdyby najpierw szła
        // walidacja adresu, formularz odpowiadałby różnie osobie znającej
        // hasło i nieznającej go — czyli ten ekran mówiłby coś komuś, kto
        // tylko usiadł przy cudzej przeglądarce.
        //
        // `ValidationException`, A NIE `back()->withErrors()` — i to nie jest
        // upodobanie stylistyczne (UX 50+: poprawnie wpisane dane nigdy nie
        // znikają). Samo `withErrors()` NIE odkłada wpisanych wartości, więc
        // pomyłka w haśle kasowałaby przepisany z kartki adres e-mail.
        //
        // Gołego `back()->withInput()` też tu nie ma, i to jest ta sama
        // pułapka, którą opisuje `App\Support\OdzyskiwalneDane`: odłożyłoby
        // do sesji CAŁE żądanie, razem z `current_password`. Procedura
        // obsługi `ValidationException` odkłada wejście POMNIEJSZONE
        // o `current_password`, `password` i `password_confirmation` — czyli
        // dokładnie to, czego tu trzeba, bez własnej listy pól do pilnowania.
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'To hasło jest nieprawidłowe.',
            ]);
        }

        if ($nowyAdres === User::normalizeEmail((string) $user->email)) {
            throw ValidationException::withMessages([
                'email' => 'To jest adres, który już masz na koncie. Wpisz ten nowy, na który chcesz przenieść konto.',
            ]);
        }

        // POCZTA MUSI DZIAŁAĆ, INACZEJ NIE ZACZYNAMY (ten sam powód co
        // w `PasswordResetController::sendLink()`). Przy `MAIL_MAILER=log`
        // Laravel zgłasza sukces, a list nie powstaje — zostawilibyśmy więc
        // człowieka z żądaniem, którego nie da się potwierdzić, i ze zdaniem
        // „sprawdź skrzynkę" pod nosem.
        if (! Poczta::dziala()) {
            return back()->with(Komunikat::blad($this->komunikatBrakuPoczty()));
        }

        // ADRESU ZAJĘTEGO PRZEZ INNE KONTO TU NIE SPRAWDZAMY — odpowiedź
        // musi być identyczna dla adresu wolnego i zajętego, inaczej ten
        // formularz służy do sprawdzania, kto ma konto w Kuking. Rozstrzyga
        // to `ConfirmEmailChange`, przy kliknięciu w link; pełne
        // uzasadnienie jest w komentarzu tamtej klasy.
        $zamow->handle($user, $nowyAdres, $request->ip());

        return redirect()->route('settings.email')->with('status',
            "Wysłaliśmy list na adres {$nowyAdres}. Kliknij w nim odnośnik — do tego czasu logujesz się "
            .'starym adresem i na stary adres przychodzi link do zmiany hasła. '
            .'Na stary adres wysłaliśmy też wiadomość o tej prośbie.',
        );
    }

    /**
     * Krok drugi: kliknięcie odnośnika z listu wysłanego na NOWY adres.
     *
     * Trasa ma `middleware('signed')` — adresu nie da się ułożyć samemu.
     * Podpis to jednak WYŁĄCZNIE dowód, że link wystawiliśmy my; nie mówi,
     * kto go klika. Dlatego niżej pytamy jeszcze o dwie rzeczy:
     *
     *  1. czy żądanie w ogóle istnieje (mogło zostać potwierdzone,
     *     anulowane, zastąpione nowszym albo sprzątnięte po wygaśnięciu),
     *  2. czy klika je WŁAŚCICIEL — bo UUID w adresie nie jest autoryzacją
     *     (AGENTS.md §7).
     *
     * Odpowiedzią na cudze żądanie jest ta sama strona co na nieistniejące:
     * nie potwierdzamy nawet tego, że taki wiersz jest w bazie.
     */
    public function confirm(Request $request, string $zmiana, ConfirmEmailChange $potwierdz): RedirectResponse
    {
        $user = $request->user();

        $oczekujaca = PendingEmailChange::query()
            ->whereKey($zmiana)
            ->where('user_id', $user->getKey())
            ->first();

        if ($oczekujaca === null) {
            return redirect()->route('settings.email')->with(Komunikat::blad(
                'Ten odnośnik już nie działa — zmiana adresu została potwierdzona, anulowana albo minął jej termin. '
                .'Jeśli nadal chcesz zmienić adres, zamów zmianę jeszcze raz.',
            ));
        }

        try {
            $nowyAdres = $potwierdz->handle($user, $oczekujaca, $request->ip(), $request->session()->getId());
        } catch (BladDlaCzlowieka $e) {
            return redirect()->route('settings.email')->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('settings.email')->with('status',
            "Gotowe. Od teraz Twoje konto ma adres {$nowyAdres} — tym adresem się logujesz i na niego "
            .'przyjdzie link, gdyby trzeba było ustawić nowe hasło. Adres jest już potwierdzony.',
        );
    }

    /**
     * „Anuluj zmianę adresu" — bez hasła, świadomie.
     *
     * Ta akcja niczego nie zmienia na koncie; przywraca stan sprzed
     * zamówienia. Najgorsze, co potrafi zrobić ktoś obcy w cudzej sesji, to
     * przeszkodzić w zmianie, którą i tak trzeba potwierdzić z drugiej
     * skrzynki. Proszenie tu o hasło dokładałoby bariery po stronie
     * WYCOFANIA SIĘ — a wycofanie się ma być zawsze łatwiejsze niż
     * czynność, którą się wycofuje.
     */
    public function cancel(Request $request, CancelEmailChange $anuluj): RedirectResponse
    {
        $bylo = $anuluj->handle(
            $request->user(),
            CancelEmailChange::POWOD_RECZNIE,
            $request->ip(),
        );

        return redirect()->route('settings.email')->with($bylo
            ? Komunikat::sukces('Anulowaliśmy zmianę adresu. Twoje konto zostaje przy dotychczasowym adresie, a odnośnik z listu już nie działa.')
            : Komunikat::informacja('Nie było czego anulować — Twoje konto nie ma zamówionej zmiany adresu.'),
        );
    }

    /**
     * Oczekujące żądanie, ale TYLKO takie, które da się jeszcze potwierdzić.
     *
     * Wiersz wygasły kasuje raz na dobę `kuking:sprzataj-zmiany-adresu`,
     * więc między wygaśnięciem a sprzątaniem mogłaby na ekranie wisieć
     * pozycja „oczekuje na potwierdzenie", której potwierdzić się nie da.
     * Ekran ma mówić prawdę co do minuty, a nie co do doby.
     */
    private function oczekujaca(User $user): ?PendingEmailChange
    {
        $zmiana = $user->pendingEmailChange()->first();

        return $zmiana?->jestWazne() === true ? $zmiana : null;
    }

    /**
     * Zdanie na czas, w którym serwis nie wysyła jeszcze poczty.
     *
     * WŁASNE, a nie `Poczta::komunikatBrakuPoczty()` — tamto mówi wprost
     * o „linku do nowego hasła" i na tym ekranie byłoby odpowiedzią na
     * pytanie, którego nikt nie zadał.
     */
    private function komunikatBrakuPoczty(): string
    {
        return 'Nie wysyłamy jeszcze wiadomości e-mail, więc nie mamy jak potwierdzić nowego adresu — '
            .'nie zmieniliśmy niczego. Napisz do nas na '.(string) config('kuking.community.contact_email')
            .', a zmienimy adres ręcznie. Odpisuje człowiek.';
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\ZaproszenieWSesji;
use App\Http\Controllers\Controller;
use App\Models\RegistrationInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Zaproszenie do założenia konta — ekran z linku w wiadomości (D-067).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA DROGA ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * 63-letnia osoba chciała założyć konto, odbiła się o walidację nazwy
 * użytkownika, przeszła na ekran „Wyślij mi link do zalogowania" (bo jego
 * tekst brzmiał „podaj adres, na który zakładasz konto"), wpisała swój adres
 * i dostała zielone „Wysłaliśmy wiadomość". Nie przyszło nic — pod tym adresem
 * nie było konta, a ekran świadomie odpowiada identycznie dla adresu z kontem
 * i bez konta (D-056). Czekała na wiadomość, która nie miała przyjść.
 *
 * Teraz przychodzi — i prowadzi tutaj.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DWA KROKI I DLACZEGO PIERWSZY NICZEGO NIE ZMIENIA
 * ────────────────────────────────────────────────────────────────────────
 *
 *   1. EKRAN Z PRZYCISKIEM (`pokaz`) — GET z linku w wiadomości. NICZEGO NIE
 *      ZUŻYWA i niczego nie zapisuje.
 *   2. PRZYJĘCIE (`przyjmij`) — POST z tego ekranu. Zapamiętuje zaproszenie
 *      w sesji i przenosi na formularz rejestracji.
 *
 * KROK PIERWSZY NIE JEST OZDOBĄ — to dokładnie ta sama lekcja, którą D-056
 * odrobiło na własnej skórze: skanery odnośników w programach pocztowych
 * i w bramkach antywirusowych (Outlook Safe Links, filtry operatorów)
 * OTWIERAJĄ każdy adres z wiadomości, zanim zrobi to człowiek — i robią to
 * metodą GET. Gdyby GET cokolwiek zużywał, właściciel skrzynki dostawałby
 * „ten link już nie działa" przy pierwszym własnym kliknięciu.
 *
 * Tutaj nawet POST jeszcze NIE ZUŻYWA zaproszenia — i to jest różnica wobec
 * logowania linkiem, świadoma. Tam POST kończy drogę (powstaje sesja), więc
 * zużycie tokenu jest naturalnym końcem. Tu POST tylko OTWIERA drogę: za nim
 * jest jeszcze cały formularz rejestracji, o który ta osoba już raz się
 * odbiła. Gdyby zaproszenie ginęło na tym kroku, pierwsza pomyłka w nazwie
 * użytkownika albo zamknięta zakładka odbierałyby jej link bezpowrotnie —
 * czyli powtarzałyby ten sam błąd, który to wszystko naprawia.
 *
 * JEDNORAZOWOŚĆ LICZY SIĘ WIĘC NA UTWORZENIU KONTA, nie na przejściu ekranu:
 * `RegisterController::store()` kasuje wiersz w tej samej transakcji, w której
 * powstaje konto, pod `lockForUpdate()`. Zaproszenie zużyte nie otwiera już
 * niczego, także tego ekranu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  BEZ TURNSTILE — I TO JEST WYBÓR, NIE PRZEOCZENIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Captcha stoi tam, gdzie ktoś nieznany wysyła coś ze skutkiem na zewnątrz
 * (D-050) — czyli na formularzu „wyślij mi link", który jest PRZED tą drogą
 * i przez który każde zaproszenie musi przejść. Tutaj nie ma czego wysyłać
 * ani czego zapisać: kto ma ważny token, ten już raz przez Turnstile
 * przeszedł, a jego wiadomość naprawdę dotarła. Ta sama decyzja co przy
 * `POST /logowanie/link/wejdz` (D-056).
 *
 * Za to formularz rejestracji, na który ten ekran przenosi, Turnstile ma
 * i mieć musi — nic tu tego nie zdejmuje.
 */
class RegistrationInviteController extends Controller
{
    /**
     * Ekran z linku w wiadomości. NICZEGO NIE ZUŻYWA — patrz komentarz klasy.
     */
    public function pokaz(string $token): View
    {
        /*
         * REJESTRACJA ZAMKNIĘTA MA WŁASNY EKRAN, a nie 503 z `/register`.
         *
         * Zaproszeń nie wysyłamy, gdy rejestracja jest zamknięta — ale
         * między wysłaniem i kliknięciem mogła zostać zamknięta. Człowiek
         * z ważnym zaproszeniem w skrzynce ma wtedy przeczytać zdanie po
         * polsku, a nie zobaczyć stronę błędu serwera.
         */
        if (! self::wlaczone() || ! self::rejestracjaOtwarta()) {
            return $this->ekranNiedostepny(
                'Zakładanie konta z zaproszenia jest teraz wyłączone',
                'Ten link przestał działać. Jeśli rejestracja jest otwarta, konto założysz normalną '
                .'drogą — potrwa to minutę dłużej, bo poprosimy Cię o potwierdzenie adresu e-mail.',
            );
        }

        $zaproszenie = RegistrationInvite::znajdzPoTokenie($token);

        if ($zaproszenie === null || ! $zaproszenie->jestWazne()) {
            return $this->ekranNieaktualnegoZaproszenia();
        }

        return view('auth.zaproszenie', [
            'token' => $token,
            // ADRES W CAŁOŚCI, nie w skrócie — i to jest ta sama decyzja co na
            // `/ustawienia/e-mail`. Patrzy na to właściciel skrzynki, do której
            // ta wiadomość przyszła, na swój własny adres; pełny zapis jest
            // jedynym sposobem, żeby zobaczyć w nim cudzą literówkę
            // („janek@wp.pl" kontra „jankek@wp.pl"). Skrót ukrywałby dokładnie
            // tę informację, dla której ten ekran ma sens.
            'adres' => $zaproszenie->email,
        ]);
    }

    /**
     * Przyjęcie zaproszenia: do sesji i na formularz rejestracji.
     *
     * Zaproszenia TU NIE ZUŻYWAMY (patrz komentarz klasy) — kasuje je dopiero
     * utworzenie konta.
     */
    public function przyjmij(Request $request, ZaproszenieWSesji $sesja): RedirectResponse|View
    {
        if (! self::wlaczone() || ! self::rejestracjaOtwarta()) {
            return $this->ekranNiedostepny(
                'Zakładanie konta z zaproszenia jest teraz wyłączone',
                'Ten link przestał działać. Jeśli rejestracja jest otwarta, konto założysz normalną '
                .'drogą — potrwa to minutę dłużej, bo poprosimy Cię o potwierdzenie adresu e-mail.',
            );
        }

        $token = (string) $request->input('token', '');
        $zaproszenie = RegistrationInvite::znajdzPoTokenie($token);

        if ($zaproszenie === null || ! $zaproszenie->jestWazne()) {
            // TEN SAM EKRAN CO PRZY NIEAKTUALNYM GET-cie. Token wygasły, token
            // zużyty i token, którego nigdy nie było, wyglądają identycznie —
            // gdyby różniły się choćby kodem odpowiedzi, zgadywanie tokenów
            // dostałoby wyrocznię „ten istniał, tamten nie". Człowiekowi i tak
            // nie zmienia to niczego: w każdym z tych przypadków ma zrobić
            // dokładnie to samo.
            return redirect()->route('register')->with('status',
                'To zaproszenie już nie działa — mogło wygasnąć albo zostać użyte. Nic się nie stało: '
                .'załóż konto poniżej, a adres e-mail potwierdzisz jedną wiadomością.',
            );
        }

        $sesja->zapamietaj($zaproszenie);

        return redirect()->route('register');
    }

    /**
     * „Chcę konto na inny adres" — porzucenie zaproszenia.
     *
     * BEZ TEGO PRZYCISKU DROGA JEST PUŁAPKĄ. Adres z zaproszenia jest
     * w formularzu NIEZMIENNY (zmiana unieważniałaby dowód posiadania
     * skrzynki), a sesja żyje długo — więc bez wyjścia człowiek, który
     * kliknął link ze złej skrzynki, widziałby ten sam wpisany adres przy
     * każdym wejściu na `/register` i nie miałby jak z tego wyjść. To jest
     * dokładnie ten kształt ślepej ściany, którego zabrania
     * `docs/UX_50_PLUS.md`.
     *
     * POST, nie odnośnik: to zmiana stanu sesji, a GET nie zmienia stanu.
     */
    public function porzuc(ZaproszenieWSesji $sesja): RedirectResponse
    {
        $sesja->zapomnij();

        return redirect()->route('register')->with('status',
            'Dobrze. Wpisz adres e-mail, na który chcesz mieć konto — wyślemy na niego wiadomość '
            .'z potwierdzeniem.',
        );
    }

    private function ekranNieaktualnegoZaproszenia(): View
    {
        return $this->ekranNiedostepny(
            'To zaproszenie już nie działa',
            'Link do zakładania konta działa tylko raz i tylko przez pewien czas — ten mógł wygasnąć '
            .'albo zostać już użyty. Nic się nie stało: konto założysz poniżej, a adres e-mail '
            .'potwierdzisz jedną wiadomością.',
        );
    }

    private function ekranNiedostepny(string $naglowek, string $tresc): View
    {
        return view('auth.zaproszenie-nieaktualne', [
            'naglowek' => $naglowek,
            'tresc' => $tresc,
            'mozeZalozycKonto' => self::rejestracjaOtwarta(),
        ]);
    }

    private static function wlaczone(): bool
    {
        return (bool) config('kuking.login_link.zaproszenia.wlaczone', false);
    }

    private static function rejestracjaOtwarta(): bool
    {
        return (bool) config('kuking.account.registration_open');
    }
}

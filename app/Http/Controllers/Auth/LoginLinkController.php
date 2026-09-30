<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\Actions\ZuzyjLinkDoLogowania;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Security\WejscieLinkiemWycofane;
use App\Domain\Security\WyslijLinkDoLogowania;
use App\Http\Controllers\Controller;
use App\Models\LoginLinkToken;
use App\Models\User;
use App\Poczta\DziennyBudzetListow;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\AdresEmail;
use App\Support\Komunikat;
use App\Support\Poczta;
use App\Support\Skrot;
use App\Support\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use App\Support\Wejscie;

/**
 * Logowanie linkiem e-mail — „magic link" (issue #25, D-056).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TA DROGA W OGÓLE ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie z wygody. `docs/research/AUDIENCE_50_PLUS.md`: tylko 12,3% osób
 * w wieku 65-74 ma podstawowe umiejętności cyfrowe, hasło i e-mail są murem,
 * a „ktoś mi pomógł założyć konto" jest normą. Dla dużej części naszych
 * ludzi to jest droga PODSTAWOWA, nie awaryjna — i tak jest zaprojektowana:
 * wejście z ekranu logowania jest równorzędne z hasłem, nie schowane pod
 * „inne opcje". Hasło zostaje jako droga równoległa; nikomu nie odbieramy
 * tego, co już umie.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TRZY KROKI I DLACZEGO ŚRODKOWY MUSI ISTNIEĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 *   1. FORMULARZ (`requestForm`/`send`) — „podaj adres, wyślemy link".
 *   2. EKRAN Z PRZYCISKIEM (`confirmForm`) — GET z linku z listu. NICZEGO
 *      NIE ZUŻYWA i NIKOGO NIE LOGUJE.
 *   3. WEJŚCIE (`store`) — POST z tego ekranu. Dopiero tutaj token zostaje
 *      zużyty (skasowany) i dopiero tutaj powstaje sesja.
 *
 * KROK 2 NIE JEST OZDOBĄ. Skanery odnośników w programach pocztowych
 * i w bramkach antywirusowych (Outlook Safe Links, filtry operatorów)
 * OTWIERAJĄ każdy adres z listu, zanim zrobi to człowiek. Gdyby samo wejście
 * pod adres logowało i kasowało token, taki skaner zużywałby link, a jego
 * właściciel dostawałby „ten link już nie działa" — przy pierwszej próbie,
 * bez żadnego wytłumaczenia. To jest ryzyko wypisane wprost w issue #25.
 * Skaner wykonuje GET, prawie nigdy POST z tokenem CSRF, więc rozdzielenie
 * „pokaż" od „zrób" zamyka tę sprawę — i przy okazji wraca do zasady, którą
 * HTTP ma od zawsze: GET nie zmienia stanu.
 *
 * Drugi zysk jest ludzki: człowiek widzi, NA JAKIE KONTO się zaloguje,
 * zanim to zrobi. Ekran mówi to wprost.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TA DROGA NIE OMIJA
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - 2FA. Konto z potwierdzoną weryfikacją dwuetapową trafia po kliknięciu
 *    dokładnie tam, gdzie trafia po poprawnym haśle: na `/logowanie/kod`,
 *    tą samą sesyjną ścieżką (`logowanie.2fa.user_id`), obsługiwaną przez
 *    `TwoFactorChallengeController`. Link zastępuje HASŁO, nie drugi
 *    składnik.
 *  - Blokadę i zawieszenie konta. Stan konta sprawdzamy PONOWNIE przy
 *    wejściu, bo między prośbą a kliknięciem mogła zapaść decyzja
 *    moderacyjna.
 *  - Konta obsługi serwisu. Moderator i administrator linku nie dostaną
 *    w ogóle (`WyslijLinkDoLogowania`), więc `EnsureModeratorHasTwoFactor`
 *    nie ma tu czego rozluźniać.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  JEDNA ODPOWIEDŹ DLA ADRESU Z KONTEM I BEZ KONTA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Komunikat po wysłaniu formularza jest ZAWSZE ten sam i zawsze pokazuje ten
 * sam skrót adresu — zbudowany z tego, co człowiek wpisał, a nie z tego, co
 * jest w bazie. Ta sama zasada co przy „Nie pamiętam hasła"; różnica jest
 * taka, że tutaj wyroczni nie może zrobić także limit zapytań, dlatego
 * licznik po adresie e-mail rusza przy KAŻDYM wysłaniu, również dla adresu,
 * na którym konta nie ma.
 */
class LoginLinkController extends Controller
{
    /** Prefiks licznika próśb liczonego po adresie e-mail. */
    private const KLUCZ_LIMITU = 'link-logowania:adres:';

    public function __construct(private readonly ZuzyjLinkDoLogowania $zuzyjLink) {}

    public function requestForm(): View
    {
        if (! self::wlaczone()) {
            return $this->ekranNiedostepny(
                'Logowanie linkiem jest teraz wyłączone',
                'Chwilowo nie wysyłamy linków do logowania. Zaloguj się hasłem — Twoje konto działa normalnie.',
            );
        }

        return view('auth.login-link');
    }

    public function send(Request $request, WyslijLinkDoLogowania $wyslij): RedirectResponse
    {
        // WYTWÓRNIA, NIE KONTENER (20 września 2026). Do tej pory budżet
        // przychodził tu wstrzyknięciem, a konstruktor miał domyślne wartości
        // z tej funkcji. Po dołożeniu WSPÓLNEGO licznika poczty kontener
        // zbudowałby obiekt bez licznika nadrzędnego — sufit własny działałby
        // dalej, a wspólnej puli ten list by nie zajął. Konstruktor jest od
        // tamtej zmiany prywatny, żeby ta pomyłka nie była możliwa.
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        if (! self::wlaczone()) {
            return redirect()->route('login')->with(Komunikat::blad(
                'Logowanie linkiem jest teraz wyłączone. Zaloguj się hasłem — Twoje konto działa normalnie.',
            ));
        }

        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            /*
             * Turnstile (D-050, D-053) — WARUNEK WYSŁANIA, nie filtr.
             *
             * Ten formularz jest publiczny i wysyła list na CUDZY adres
             * z puli, która wystarcza na 300 listów dziennie — czyli należy
             * do tej samej rodziny co `/nie-pamietam-hasla`. `required` tu
             * nie stoi i nie dokładaj go: obecność pola pilnuje `$implicit`
             * w regule, a laravelowy komunikat mówiłby o „polu
             * cf-turnstile-response".
             */
            Turnstile::POLE => TurnstileJestPotwierdzony::reguly('logowanie_linkiem'),
        ], [
            'email.required' => 'Podaj adres e-mail, na który założone jest konto.',
            'email.email' => 'Ten adres wygląda na niepełny. Sprawdź, czy nie brakuje kropki albo znaku @.',
        ]);

        $adres = User::normalizeEmail((string) $request->input('email', ''));

        // POCZTA MOŻE NIE DZIAŁAĆ, a wysyłka i tak zgłosi sukces (przy
        // `MAIL_MAILER=log` list idzie do dziennika). Formularz jest wtedy
        // schowany w widoku, ale ta trasa pozostaje osiągalna wprost — więc
        // odpowiedź musi mówić prawdę także tutaj. Ten sam powód co
        // w `PasswordResetController`.
        if (! Poczta::dziala()) {
            return back()->with(Komunikat::blad(Poczta::komunikatBrakuPoczty('link do zalogowania')));
        }

        // LICZNIK PO ADRESIE E-MAIL RUSZA PRZED CZYMKOLWIEK INNYM i dla
        // KAŻDEGO adresu — także takiego, na którym nie ma konta. Gdyby
        // liczył tylko udane wysyłki, samo „ten formularz mnie jeszcze nie
        // zatrzymał" odpowiadałoby na pytanie, czy konto istnieje.
        if (! $this->wolnoPytacOAdres($adres)) {
            throw ValidationException::withMessages([
                'email' => 'Wysłaliśmy już na ten adres kilka linków w krótkim czasie. '
                    .'Sprawdź skrzynkę (także folder „Spam”), a jeśli wiadomości nie ma — '
                    .'spróbuj za godzinę albo zaloguj się hasłem.',
            ]);
        }

        // BUDŻET DOBOWY POCZTY — patrz `DziennyBudzetListow`. Miejsce
        // REZERWUJEMY (jedna atomowa operacja) PRZED wysyłką, a nie
        // sprawdzamy teraz i zajmujemy dziesięć linii niżej: para
        // „sprawdź, a potem zajmij" przepuszczała dwa równoległe żądania
        // przez ostatnie wolne miejsce i wysyłała 121 listów przy suficie
        // 120 (audyt MAIL-01/RACE-03, D-076).
        //
        // Rezerwacja przed wysyłką nie zjada budżetu automatom wpisującym
        // nieistniejące adresy — miejsce, z którego nic nie wyszło, wraca
        // niżej przez `zwolnij()`.
        if (! $budzet->sprobujZarezerwowac()) {
            // WPISANY ADRES ZOSTAJE W POLU (#890). Komunikat mówi „kliknij
            // jeszcze raz" — bez `withInput` to kliknięcie trafiałoby
            // w puste, wymagane pole. Tylko `email`: tokenu Turnstile nie
            // odtwarzamy, bo jest jednorazowy i nie jest daną człowieka.
            return back()->withInput($request->only('email'))->with(Komunikat::blad(
                // DWA POWODY ODMOWY, DWA RÓŻNE ZDANIA. Rezerwacja mówi
                // tylko „nie", a te dwa „nie" znaczą dla człowieka coś
                // zupełnie innego: przy wyczerpanym budżecie czekanie na
                // list jest bezcelowe, a przy ścisku na blokadzie drugie
                // kliknięcie zwykle wystarcza. Odczyt jest tu WYŁĄCZNIE
                // doborem treści komunikatu — o wysyłce rozstrzygnęła już
                // linia wyżej i nic tego nie odwraca.
                $budzet->jestMiejsce()
                    ? 'Nie udało nam się w tej chwili wypuścić tego listu — kilka próśb trafiło na siebie '
                        .'w tej samej sekundzie. Kliknij „Wyślij mi link” jeszcze raz. Jeśli znowu nie wyjdzie, '
                        .'zaloguj się hasłem albo napisz do nas na '
                        .(string) config('kuking.community.contact_email')
                        .', a pomożemy Ci wejść na konto. Odpisuje człowiek.'
                    : 'Dzisiaj wysłaliśmy już wszystkie e-maile z linkiem, jakie mieliśmy na dziś, więc ten nie wyjdzie '
                        .'— nie czekaj na niego. Zaloguj się hasłem albo napisz do nas na '
                        .(string) config('kuking.community.contact_email')
                        .', a pomożemy Ci wejść na konto. Odpisuje człowiek.',
            ));
        }

        if (! $wyslij->handle($adres, $request->ip())) {
            // NA TYM ADRESIE NIE MA KONTA, więc nic nie wyszło i miejsce
            // w budżecie wraca do puli. Bez tego byle automat wpisujący
            // nieistniejące adresy wyczerpałby dobowy sufit w kilka minut
            // i zamknął drogę prawdziwym ludziom — pilnuje tego
            // `test_adresy_bez_konta_nie_zjadaja_dobowego_budzetu`.
            $budzet->zwolnij();
        }

        // JEDEN KOMUNIKAT, ZAWSZE TEN SAM. Skrót adresu bierze się z tego, co
        // człowiek WPISAŁ — nie z bazy — więc wygląda identycznie dla adresu
        // z kontem i bez konta.
        return back()->with(Komunikat::informacja(
            // KOMUNIKAT NIE MOŻE OBIECYWAĆ CZEGOŚ, CO SIĘ NIE STAŁO.
            //
            // Poprzedni mówił „Wysłaliśmy list na adres…" ZAWSZE — także dla
            // adresu, pod którym nie ma konta, czyli wtedy, gdy nic nie
            // wyszło. 63-letnia osoba z grupy docelowej trafiła tu przez
            // pomyłkę zamiast na rejestrację i czekała na wiadomość, która
            // nie miała przyjść.
            //
            // Jeden komunikat, zawsze ten sam — to zostaje, bo to jest
            // ochrona przed pytaniem „kto ma konto w Kuking" (D-056). Ale
            // zdanie jest teraz WARUNKOWE i prawdziwe w obu przypadkach,
            // a na końcu ma wyjście dla tego drugiego.
            // KOLOR NIE JEST DROGĄ DO PRZYCISKU (WCAG 2.2 AA, 1.4.1), ale
            // ETYKIETY TEŻ TU NIE WOLNO PODAĆ: konto pod tym adresem dostaje
            // list „Zaloguj mnie w Kuking", a adres bez konta — „Załóż konto
            // w Kuking". Nazwanie przycisku zdradziłoby dokładnie to, czego
            // D-056 zabrania zdradzać. Zostaje opis, który jest prawdziwy
            // w obu listach i nie zależy od koloru: JEDEN przycisk.
            'Jeśli na adres '.AdresEmail::maska($adres).' jest konto w Kuking, wysłaliśmy tam '
            .'wiadomość z jednym przyciskiem — możesz ją otworzyć także na innym telefonie albo komputerze. '
            .'Nie ma jej po kilku minutach? Sprawdź folder „Spam”. A jeśli nie masz jeszcze konta, '
            .'załóż je: '.route('register'),
        ));
    }

    /**
     * Ekran z linku z listu. NICZEGO NIE ZUŻYWA — patrz komentarz klasy.
     */
    public function confirmForm(Request $request, string $token): View
    {
        if (! self::wlaczone()) {
            return $this->ekranNiedostepny(
                'Logowanie linkiem jest teraz wyłączone',
                'Ten link przestał działać, bo chwilowo nie wpuszczamy linkiem. Zaloguj się hasłem — '
                .'Twoje konto działa normalnie.',
            );
        }

        $wiersz = LoginLinkToken::znajdzPoTokenie($token);

        if ($wiersz === null || ! $wiersz->jestWazny()) {
            return $this->ekranNieaktualnegoLinku();
        }

        $user = $wiersz->user;

        if ($user === null || in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
            return $this->ekranNieaktualnegoLinku();
        }

        return view('auth.login-link-confirm', [
            'token' => $token,
            'displayName' => $user->profile?->display_name,
            'adresSkrot' => AdresEmail::maska($user->email),
        ]);
    }

    /**
     * Wejście na konto. Dopiero tutaj token zostaje zużyty.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! self::wlaczone()) {
            return redirect()->route('login')->with(Komunikat::blad(
                'Logowanie linkiem jest teraz wyłączone. Zaloguj się hasłem — Twoje konto działa normalnie.',
            ));
        }

        // Tablica (`token[]=`) nie jest żadnym linkiem: pusty napis idzie
        // zwykłą drogą „link nieaktualny”, zamiast HTTP 500 (#2266).
        $token = Wejscie::tekst($request->input('token'));

        // ZUŻYCIE TOKENU IDZIE POD DWIEMA BLOKADAMI: NAJPIERW WIERSZ KONTA,
        // POTEM WIERSZ TOKENU (D-075, D-079). Kolejność, rewalidację i wpis
        // audytu w tej samej transakcji trzyma akcja `ZuzyjLinkDoLogowania`
        // (#970) — tu zostaje odpowiedź HTTP i sesja.
        try {
            $user = $this->zuzyjLink->handle($token, $request->ip());
        } catch (WejscieLinkiemWycofane $awaria) {
            // Operator dostaje nazwę brakującego wpisu (bez tokenu i adresu),
            // człowiek — prawdę i jedną rzecz do zrobienia.
            // Do monitoringu idzie zwykły `RuntimeException`, jak przed wyjęciem
            // akcji — to jego klasę i treść znają testy oraz alarmy.
            report(new RuntimeException(WejscieLinkiemWycofane::opis($awaria->kontoId), previous: $awaria->getPrevious()));

            return redirect()->route('login.link.confirm', ['token' => $token])->with(Komunikat::blad('Nie udało się Cię zalogować — to usterka po naszej stronie. Nic się nie zmieniło '
                .'i link nadal działa: kliknij „Zaloguj mnie” jeszcze raz.',
            ));
        }

        if ($user === null) {
            return $this->odeslijZNieaktualnymLinkiem();
        }

        // KONTO Z 2FA NIE WCHODZI TU DO KOŃCA. Dokładnie ta sama ścieżka co
        // po poprawnym haśle w `LoginController`: w sesji ląduje SAM
        // IDENTYFIKATOR konta, nie zalogowana sesja, a kod z aplikacji
        // sprawdza `TwoFactorChallengeController`. Link zastępuje hasło, nie
        // drugi składnik.
        if ($user->hasTwoFactorConfirmed()) {
            $request->session()->regenerate();
            $request->session()->put(TwoFactorAuthenticator::oczekujaceLogowanie($user));

            return redirect()->route('login.two_factor');
        }

        $request->session()->regenerate();

        // `remember: true` jak przy logowaniu hasłem — sesja 7 dni
        // i „zapamiętaj mnie" domyślnie to jedna z rzeczy, które dla tej
        // grupy już zrobiliśmy (issue #25, sekcja „co już zrobiliśmy”).
        // Kto wchodzi linkiem, tym bardziej nie chce robić tego co tydzień.
        Auth::login($user, remember: true);

        return redirect()->intended(route('home'));
    }

    /**
     * Czy wolno jeszcze pytać o link dla TEGO adresu.
     *
     * Klucz liczy się po SKRÓCIE adresu, nie po adresie — inaczej cudzy adres
     * e-mail leżałby w tabeli `cache` w postaci jawnej (`RateLimiter`
     * praktycznie nie rusza klucza). To dokładnie ta luka, którą naprawiał
     * `App\Support\KluczeLimitow` przy logowaniu.
     */
    private function wolnoPytacOAdres(string $adres): bool
    {
        $limit = (array) config('kuking.login_link.limit_na_adres');
        $proby = max(1, (int) ($limit['proby'] ?? 3));
        $minuty = max(1, (int) ($limit['minuty'] ?? 60));

        $klucz = self::KLUCZ_LIMITU.Skrot::hmac($adres);

        if (RateLimiter::tooManyAttempts($klucz, $proby)) {
            return false;
        }

        RateLimiter::hit($klucz, $minuty * 60);

        return true;
    }

    /**
     * Odesłanie po nieudanym wejściu: token zmyślony, wygasły, już zużyty
     * albo zużyty przez inne żądanie w chwili, gdy czekaliśmy na blokadę
     * wiersza konta.
     *
     * JEDEN KOMUNIKAT DLA WSZYSTKICH TYCH PRZYPADKÓW — z tego samego powodu,
     * dla którego jeden ekran obsługuje je w `ekranNieaktualnegoLinku()`:
     * różnica w treści byłaby wyrocznią „ten token istniał, tamten nie",
     * a człowiek i tak ma zrobić dokładnie to samo, czyli poprosić o nowy
     * link. Dlatego to zdanie stoi w jednym miejscu, a nie w dwóch gałęziach
     * `store()` osobno.
     */
    private function odeslijZNieaktualnymLinkiem(): RedirectResponse
    {
        return redirect()->route('login.link')->with(Komunikat::blad(
            'Ten link do logowania już nie działa — mógł wygasnąć albo zostać użyty. '
            .'Poproś o nowy: wpisz adres e-mail poniżej.',
        ));
    }

    private function ekranNieaktualnegoLinku(): View
    {
        /*
         * JEDEN EKRAN DLA TRZECH PRZYPADKÓW: token wygasły, token już zużyty
         * i token, którego nigdy nie było. To nie jest lenistwo redakcyjne —
         * gdyby te trzy sytuacje wyglądały różnie (choćby samym kodem
         * odpowiedzi), zgadywanie tokenów dostałoby wyrocznię „ten istniał,
         * tamten nie". Człowiekowi i tak nie zmienia to niczego: w każdym
         * z tych przypadków ma zrobić dokładnie to samo.
         */
        return $this->ekranNiedostepny(
            'Ten link już nie działa',
            'Link do logowania działa tylko raz i tylko przez chwilę — ten mógł wygasnąć albo zostać '
            .'już użyty. Nic się nie stało: poproś o nowy, a przyjdzie za moment.',
        );
    }

    private function ekranNiedostepny(string $naglowek, string $tresc): View
    {
        return view('auth.login-link-unavailable', [
            'naglowek' => $naglowek,
            'tresc' => $tresc,
            'mozeProsicONowy' => self::wlaczone(),
        ]);
    }

    private static function wlaczone(): bool
    {
        return (bool) config('kuking.login_link.wlaczone', false);
    }
}

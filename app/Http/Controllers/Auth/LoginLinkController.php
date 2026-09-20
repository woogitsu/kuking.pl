<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\DziennyBudzetListow;
use App\Domain\Security\WyslijLinkDoLogowania;
use App\Domain\Users\ZamekKonta;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\LoginLinkToken;
use App\Models\User;
use App\Rules\TurnstileJestPotwierdzony;
use App\Support\AdresEmail;
use App\Support\Poczta;
use App\Support\Skrot;
use App\Support\Turnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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

    public function send(Request $request, WyslijLinkDoLogowania $wyslij, DziennyBudzetListow $budzet): RedirectResponse
    {
        if (! self::wlaczone()) {
            return redirect()->route('login')->with('status',
                'Logowanie linkiem jest teraz wyłączone. Zaloguj się hasłem — Twoje konto działa normalnie.',
            );
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
            return back()->with('status', Poczta::komunikatBrakuPoczty('link do zalogowania'));
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
            return back()->withInput($request->only('email'))->with('status',
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
            );
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
        return back()->with('status',
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
        );
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
            return redirect()->route('login')->with('status',
                'Logowanie linkiem jest teraz wyłączone. Zaloguj się hasłem — Twoje konto działa normalnie.',
            );
        }

        $token = (string) $request->input('token', '');

        /*
         * ZUŻYCIE TOKENU IDZIE POD DWIEMA BLOKADAMI: NAJPIERW WIERSZ KONTA,
         * POTEM WIERSZ TOKENU. TA KOLEJNOŚĆ JEST OBOWIĄZKOWA (D-075, D-079).
         *
         * `lockForUpdate()` na wierszu tokenu nie jest ostrożnością na zapas.
         * Bez niego dwa równoległe żądania z tym samym tokenem (dwa
         * kliknięcia, podwójne wysłanie formularza, skaner i człowiek naraz)
         * mogłyby OBA odczytać wiersz, OBA uznać go za ważny i OBA zalogować
         * — czyli token „jednorazowy" wpuściłby dwa razy. Kasujemy w tej
         * samej transakcji, więc drugie żądanie zastaje albo blokadę, albo
         * pustkę.
         *
         * DLACZEGO JESZCZE BLOKADA KONTA, SKORO SAMO ZUŻYCIE JEJ NIE
         * POTRZEBUJE. Bo `WyslijLinkDoLogowania::wymienToken()` bierze te
         * same dwie tabele w kolejności `users` → `login_link_tokens`
         * (D-075: „KOLEJNOŚĆ BLOKAD ZOSTAJE JEDNA W CAŁYM REPOZYTORIUM:
         * KONTO NAJPIERW"). Ta metoda była jedynym miejscem w repozytorium,
         * które tę kolejność odwracało — i nie bolało to wyłącznie dlatego,
         * że w tej transakcji nie było ANI JEDNEJ linijki dotykającej
         * `users`. Bezpieczeństwo stało więc na NIEOBECNOŚCI jednej linijki:
         * pierwsze dopisane tu `$user->update(['last_seen_at' => now()])` —
         * rzecz naturalna i niewinnie wyglądająca — domyka cykl z tamtą
         * metodą i daje zakleszczenie na drodze, która dla osób 60+ jest
         * PODSTAWOWĄ drogą logowania, nie awaryjną (D-056). Czyli: 500 przy
         * kliknięciu w link z listu, u ludzi, którzy hasła nie użyją. Jedna
         * dodatkowa blokada na kliknięcie jest tańsza niż pilnowanie przez
         * lata, żeby nikt nigdy nie dopisał tu tej linijki.
         *
         * DLACZEGO TRZY ODCZYTY, A NIE JEDEN. Konta nie znamy, dopóki nie
         * przeczytamy tokenu — a tokenu nie wolno zablokować pierwszego.
         * Dlatego: (1) odczyt tokenu BEZ blokady, wyłącznie po to, żeby
         * wiedzieć, o czyje konto chodzi; (2) konto pod blokadą; (3) token
         * PONOWNIE, już pod blokadą. Krok (3) nie jest powtórzeniem kroku
         * (1): blokada serializuje, ale nie mówi żądaniu, że świat zmienił
         * się, kiedy ono czekało w kolejce (D-079 §3). Odczyt (1) nie
         * rozstrzyga więc NICZEGO poza tym, czyje konto zablokować —
         * wszystkie decyzje zapadają na wierszu z kroku (3).
         */
        $wstepny = LoginLinkToken::znajdzPoTokenie($token);
        $konto = $wstepny?->user;

        if ($konto === null) {
            return $this->odeslijZNieaktualnymLinkiem();
        }

        $user = ZamekKonta::zablokuj($konto, function (?User $swiezy) use ($token): ?User {
            // KONTA JUŻ NIE MA. Kaskada z `login_link_tokens.user_id`
            // zabrała razem z nim wiersz tokenu, więc nie ma tu czego
            // sprzątać ani kogo wpuszczać.
            if ($swiezy === null) {
                return null;
            }

            $wiersz = LoginLinkToken::query()
                ->where('token_hash', LoginLinkToken::skrot($token))
                ->lockForUpdate()
                ->first();

            // TOKEN ZNIKNĄŁ, KIEDY CZEKALIŚMY NA BLOKADĘ KONTA. Zniknąć
            // mógł na każdy z pięciu sposobów wypisanych w `LoginLinkToken`
            // — najczęściej przez drugie kliknięcie tego samego linku albo
            // przez nową prośbę o link z drugiego urządzenia. Człowiek
            // dostaje wtedy dokładnie ten sam komunikat co przy tokenie
            // zużytym i wygasłym: tamten ekran łączy te trzy przypadki
            // świadomie (patrz `ekranNieaktualnegoLinku()`) i nie ma
            // powodu, żeby rozjeżdżać się z nim tutaj. Przypadek wygaśnięcia
            // stoi niżej, za pytaniem o właściciela — powód tam.
            if ($wiersz === null) {
                return null;
            }

            // CZY TEN WIERSZ JEST NADAL NASZ — trzecie pytanie rewalidacji
            // z D-079 §3. Blokadę trzymamy na koncie wybranym w kroku (1);
            // gdyby wiersz tokenu należał w tej chwili do kogoś innego,
            // zużylibyśmy cudzy token BEZ blokady jego konta i wpuścili
            // konto, do którego ten token nie należy. Wtedy nie robimy nic
            // — także nie kasujemy, bo to nie nasz wiersz i nie nasza
            // blokada.
            //
            // DLACZEGO TO PYTANIE STOI PRZED PYTANIEM O WAŻNOŚĆ, a nie po
            // nim. Kasowanie wygasłego wiersza jest zapisem, więc podlega
            // tej samej regule co zapis niżej: wolno nam pisać tylko do
            // wiersza, którego konto trzymamy pod blokadą. Odwrotna
            // kolejność kasowałaby cudzy wygasły wiersz bez blokady jego
            // konta — czyli łamałaby regułę, którą ta metoda w ogóle
            // wprowadza, i to w komentarzu tuż obok. Żadne dziś osiągalne
            // żądanie tu nie trafia (wiersz odnajdujemy po skrócie tokenu,
            // a drugie konto musiałoby mieć ten sam token), więc zamiana
            // kolejności nie zmienia niczego, co widać z zewnątrz. Stoi tak
            // dlatego, że reguła bez wyjątku jest sprawdzalna, a reguła
            // z jednym nieosiągalnym wyjątkiem — już nie.
            if ((string) $wiersz->user_id !== (string) $swiezy->getKey()) {
                return null;
            }

            // TOKEN PRZEDAWNIŁ SIĘ, KIEDY CZEKALIŚMY NA BLOKADĘ KONTA.
            // Wygasły wiersz kasujemy przy okazji — nie jest już do
            // niczego, a zostawianie go zależnym od nocnego sprzątania
            // byłoby trzymaniem martwego klucza dłużej, niż trzeba. Tu
            // wolno: konto tego wiersza jest już zablokowane.
            if (! $wiersz->jestWazny()) {
                $wiersz->delete();

                return null;
            }

            // JEDNORAZOWOŚĆ. Kasujemy ZANIM cokolwiek zalogujemy i niezależnie
            // od tego, czy konto okaże się dalej wpuszczalne — link zużyty to
            // link zużyty, także wtedy, gdy trafił na konto zablokowane
            // w międzyczasie.
            $wiersz->delete();

            if (in_array($swiezy->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
                return null;
            }

            // Stan konta mógł się zmienić między prośbą a kliknięciem —
            // rola też. Konta obsługi serwisu tą drogą nie wchodzą (issue #25).
            if ($swiezy->isModerator()) {
                return null;
            }

            return $swiezy;
        });

        if ($user === null) {
            return $this->odeslijZNieaktualnymLinkiem();
        }

        AuditLogEntry::record('account.login_link_used', $user, $user, ip: $request->ip());

        // KONTO Z 2FA NIE WCHODZI TU DO KOŃCA. Dokładnie ta sama ścieżka co
        // po poprawnym haśle w `LoginController`: w sesji ląduje SAM
        // IDENTYFIKATOR konta, nie zalogowana sesja, a kod z aplikacji
        // sprawdza `TwoFactorChallengeController`. Link zastępuje hasło, nie
        // drugi składnik.
        if ($user->hasTwoFactorConfirmed()) {
            $request->session()->regenerate();
            $request->session()->put('logowanie.2fa.user_id', $user->getKey());

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
        return redirect()->route('login.link')->with('status',
            'Ten link do logowania już nie działa — mógł wygasnąć albo zostać użyty. '
            .'Poproś o nowy: wpisz adres e-mail poniżej.',
        );
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

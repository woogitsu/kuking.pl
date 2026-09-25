<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\KomunikatZamknietegoKonta;
use App\Domain\Security\WejsciePrzezDostawce\WejdzPrzezDostawce;
use App\Domain\Security\WejsciePrzezDostawce\WynikWejscia;
use App\Domain\Users\Actions\ZalozKonto;
use App\Google\DostawcaWejsciaGoogle;
use App\Google\KlientGoogle;
use App\Google\TozsamoscGoogle;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Google;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * WEJŚCIE KONTEM GOOGLE (issue #258, D-069).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TA DROGA ISTNIEJE — I CZYM NIE JEST
 * ────────────────────────────────────────────────────────────────────────
 *
 * Prośba właściciela po tym, jak jego 63-letnia mama nie założyła konta:
 * odbiła się o nazwę użytkownika, potem czekała na wiadomość, która nie
 * miała przyjść. Konto Google ma prawie każda osoba z Androidem, a hasło do
 * niego jest już w przeglądarce — jedno kliknięcie zamiast wymyślania hasła.
 *
 * To jest droga DODATKOWA, nigdy jedyna. Część naszych ludzi ma adresy
 * `@wp.pl`, `@o2.pl` i `@interia.pl`, gdzie konta Google nie ma. Hasło
 * i link e-mail zostają na ekranie logowania równorzędnie — tak samo jak
 * przy D-056.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZTERY EKRANY I DLACZEGO ŻADNEGO NIE DA SIĘ POMINĄĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 *   1. `start`      — GET, kliknięcie „Wejdź kontem Google". Zakłada
 *                     w sesji `state`, `nonce` i weryfikator PKCE
 *                     i odsyła człowieka do Google.
 *   2. `callback`   — GET, powrót z Google z kodem. Sprawdza `state`,
 *                     wymienia kod na token tożsamości i ROZSTRZYGA, co
 *                     dalej. Nikogo tu jeszcze nie logujemy, jeśli konto
 *                     nie było wcześniej powiązane.
 *   3. `finishForm`/`finish` — domknięcie konta dla NOWEJ osoby: imię,
 *                     nazwa w adresie profilu i DWA oświadczenia.
 *   4. `linkForm`/`link` — potwierdzenie połączenia z kontem, które na tym
 *                     adresie już u nas istnieje.
 *
 * Krok 3 istnieje, bo Google nie przekaże trzech rzeczy, których wymaga
 * nasza rejestracja: nazwy do adresu profilu, oświadczenia o wieku
 * i akceptacji regulaminu. **Nie wolno ich zaznaczyć za człowieka** — to
 * ciemny wzorzec, a przy oświadczeniu o wieku dodatkowo bez wartości: nikt
 * nie oświadczył niczego, jeśli haczyk postawił za niego serwer.
 *
 * Krok 4 jest w całości o BEZPIECZEŃSTWIE i jest opisany niżej.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ŁĄCZENIE KONT — TU MIESZKA CAŁE RYZYKO TEJ FUNKCJI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Pytanie brzmi: co robimy, gdy adres z Google należy do konta, które już
 * u nas jest. Zaufanie adresowi bez dowodu jest tu gotowym przejęciem
 * konta, dlatego obowiązują TRZY reguły i każda zamyka inny atak.
 *
 * **REGUŁA 1: `email_verified` od Google jest WARUNKIEM. Bez niego nie
 * robimy nic — ani logowania, ani rejestracji.**
 *
 * Konto Google nie musi być kontem Gmail. Da się je założyć na dowolny
 * cudzy adres (`ktos@wp.pl`) i — dopóki nie zostanie potwierdzone —
 * korzystać z niego normalnie. Token tożsamości niesie wtedy
 * `email_verified: false`. Gdybyśmy tego nie sprawdzali, napastnik
 * zakładałby konto Google na adres wybranej osoby i wchodził u nas na JEJ
 * konto (albo zakładał konto na jej adres, zabierając jej drogę wejścia,
 * zanim ona sama przyjdzie). To jest najkrótsza znana droga przejęcia konta
 * przy „zaloguj się przez…", i ona jest tu zamknięta na pierwszym warunku.
 *
 * **REGUŁA 2: konta z NIEPOTWIERDZONYM u nas adresem NIE ŁĄCZYMY. Nigdy,
 * nawet gdy Google adres potwierdziło.**
 *
 * To zamyka atak z wyprzedzeniem („pre-hijacking"). Nasza rejestracja
 * świadomie NIE wymaga potwierdzenia adresu przed pierwszą publikacją —
 * osoba, która z trudem założyła konto, nie może zostać odesłana do
 * skrzynki. Napastnik może więc DZIŚ założyć u nas konto na adres
 * `basia@wp.pl`, którego nie kontroluje, i czekać. Gdy prawdziwa Basia
 * przyjdzie kiedyś przez Google, automatyczne połączenie wpuściłoby ją do
 * konta NAPASTNIKA: on zna hasło, widzi wszystko, co ona napisze, i ma
 * wejście, o którym ona nie wie. Ona nie zauważy niczego — konto jest
 * puste, więc wygląda jak świeżo założone.
 *
 * Dlatego takie konto dostaje odmowę i zdanie mówiące, co zrobić: wejdź
 * hasłem albo linkiem e-mail i potwierdź adres, wtedy wejście kontem
 * Google zadziała. Napastnik nie potwierdzi adresu, bo nie ma skrzynki.
 *
 * **REGUŁA 3: konta z potwierdzonym adresem łączymy WYŁĄCZNIE po jawnym
 * potwierdzeniu przez człowieka na naszym ekranie.**
 *
 * Tu zostaje jedno kliknięcie więcej i zostaje świadomie. Sam atak jest już
 * zamknięty regułami 1 i 2 — kto przeszedł weryfikację Google dla tego
 * adresu, ten kontroluje skrzynkę, a kto kontroluje skrzynkę, mógł już
 * dzisiaj przejąć to konto przez „Nie pamiętam hasła". Chodzi o coś innego:
 * o to, żeby połączenie dwóch kont nie było NIESPODZIANKĄ. Człowiek widzi,
 * na jakie konto wchodzi („zalogujesz się jako Basia"), zanim wejdzie — ta
 * sama zasada co przy ekranie z linkiem e-mail (D-056). Z jednej skrzynki
 * korzysta czasem całe małżeństwo.
 *
 * **CZEGO TE REGUŁY NIE ZDRADZAJĄ, A CZEGO ZDRADZAJĄ**
 * Ekran odmowy z reguły 2 mówi wprost, że na tym adresie jest już konto —
 * czyli odpowiada na pytanie, na które formularz „Nie pamiętam hasła"
 * świadomie nie odpowiada. Nie jest to jednak nowa wyrocznia: aby tu dojść,
 * trzeba przejść weryfikację adresu po stronie Google, czyli mieć dostęp do
 * tej skrzynki — a kto ma do niej dostęp, ten dowie się tego samego,
 * prosząc o przypomnienie hasła i czytając własną pocztę. Cena milczenia
 * byłaby za to realna: człowiek dostałby odmowę bez powodu i nie miałby
 * pojęcia, co zrobić.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TA DROGA NIE OMIJA
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - **2FA.** Konto z potwierdzoną weryfikacją dwuetapową trafia tam, gdzie
 *    trafia po poprawnym haśle: na `/logowanie/kod`, tą samą sesyjną
 *    ścieżką (`logowanie.2fa.user_id`). Google zastępuje HASŁO, nie drugi
 *    składnik.
 *  - **Konta obsługi serwisu.** Moderator i administrator tą drogą nie
 *    wchodzą wcale — tam obowiązuje hasło i 2FA (ten sam zakres co przy
 *    D-056). Powiązanie zrobione przed awansem przestaje działać w chwili
 *    nadania roli, bo pytamy o rolę przy każdym wejściu.
 *  - **Blokadę i zawieszenie.** Konto zamknięte (`banned`,
 *    `pending_delete`, `erased`) nie wchodzi i czyta to samo uzasadnienie
 *    z DSA art. 17, co na ekranie logowania hasłem
 *    (`KomunikatZamknietegoKonta`). Konto ZAWIESZONE wchodzi — kara jest
 *    „tylko do odczytu" i odmowa logowania wywracałaby jej sens, dokładnie
 *    tak samo jak w `LoginController`.
 *  - **Zamkniętej rejestracji.** `registration_open` zamyka także tę drogę.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  GDY COKOLWIEK NIE ZAGRA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Wracamy na `/login` ze zdaniem po polsku mówiącym, co zrobić — a tam,
 * przed oczami, stoi hasło i „Wyślij mi link do zalogowania". Nigdy: pusty
 * ekran, nigdy angielski komunikat od dostawcy, nigdy przycisk, który
 * milczy (D-053). Bez kluczy przycisku nie ma w ogóle.
 */
class GoogleLoginController extends Controller
{
    /**
     * Klucze protokołu w sesji. Wszystkie jednorazowe, wszystkie kasowane po
     * odczycie. Tożsamość po powrocie trzyma `WejdzPrzezDostawce`.
     */
    private const KLUCZ_STATE = 'wejscie_google.state';

    private const KLUCZ_NONCE = 'wejscie_google.nonce';

    private const KLUCZ_PKCE = 'wejscie_google.pkce';

    public function __construct(private readonly DostawcaWejsciaGoogle $dostawca) {}

    /**
     * Kliknięcie „Wejdź kontem Google" — odsyłamy człowieka do Google.
     */
    public function start(Request $request, KlientGoogle $klient): RedirectResponse
    {
        if (! Google::dziala()) {
            return $this->drogaZamknieta();
        }

        /*
         * `state` I PKCE POWSTAJĄ TUTAJ I SĄ WIĄZANE Z SESJĄ.
         *
         * `state` jest jednorazowym sekretem sesji: przy powrocie
         * porównujemy to, co przyszło w adresie, z tym, co zostało tu
         * zapisane. Bez niego napastnik podrzuca w przekierowaniu SWÓJ kod
         * autoryzacyjny i loguje ofiarę na SWOJE konto Google (CSRF na
         * drodze OAuth — nie teoria, tylko podręcznikowy atak: od tej chwili
         * wszystko, co ofiara napisze, ląduje na koncie napastnika).
         *
         * Weryfikator PKCE nie opuszcza tej sesji NIGDY — do Google idzie
         * tylko jego skrót (`S256`). Kod przechwycony w drodze powrotnej
         * jest bez niego bezużyteczny.
         *
         * `regenerate()` PRZED zapisem: świeży identyfikator sesji zamyka
         * podrzucenie ofierze sesji z góry ustalonej (session fixation),
         * a jest tu darmowe, bo to jeszcze nie jest sesja zalogowana.
         */
        $request->session()->regenerate();

        $state = KlientGoogle::losowaWartosc();
        $nonce = KlientGoogle::losowaWartosc();
        $weryfikator = KlientGoogle::nowyWeryfikator();

        $request->session()->put(self::KLUCZ_STATE, $state);
        $request->session()->put(self::KLUCZ_NONCE, $nonce);
        $request->session()->put(self::KLUCZ_PKCE, $weryfikator);

        return redirect()->away($klient->adresZgody(
            state: $state,
            nonce: $nonce,
            wyzwaniePkce: KlientGoogle::wyzwanie($weryfikator),
            adresPowrotu: $this->adresPowrotu(),
        ));
    }

    /**
     * Powrót z Google. Rozstrzyga, co dalej — patrz komentarz klasy.
     */
    public function callback(Request $request, KlientGoogle $klient): RedirectResponse
    {
        if (! Google::dziala()) {
            return $this->drogaZamknieta();
        }

        // `pull`, nie `get`: te trzy wartości są jednorazowe. Powtórzone
        // wejście pod ten adres (odświeżenie, przycisk „wstecz", skaner
        // odnośników) nie ma prawa przejść drugi raz.
        $state = (string) $request->session()->pull(self::KLUCZ_STATE, '');
        $nonce = (string) $request->session()->pull(self::KLUCZ_NONCE, '');
        $weryfikator = (string) $request->session()->pull(self::KLUCZ_PKCE, '');

        /*
         * CZŁOWIEK ODMÓWIŁ ZGODY ALBO GOOGLE ODMÓWIŁO NAM.
         *
         * Google wraca wtedy z `error=access_denied` (najczęściej: ktoś
         * kliknął „Anuluj" na ekranie zgody) albo z innym kodem po
         * angielsku. Kodu NIE POKAZUJEMY — nikomu nic nie mówi. Zdanie na
         * ekranie logowania mówi za to, że nic się nie stało i co można
         * zrobić dalej.
         */
        if ($request->filled('error')) {
            Log::info('Wejście kontem Google przerwane po stronie Google.', [
                'blad' => (string) $request->query('error'),
            ]);

            return redirect()->route('login')->with('status',
                'Nie weszliśmy kontem Google — zgoda nie została udzielona. Nic się nie stało. '
                .'Możesz spróbować jeszcze raz albo zalogować się hasłem, a jeśli go nie pamiętasz, '
                .'poproś o wiadomość z przyciskiem do zalogowania.',
            );
        }

        $kod = (string) $request->query('code', '');

        /*
         * JEDEN KOMUNIKAT DLA WSZYSTKICH POWODÓW ODRZUCENIA, świadomie:
         * brak `state` w sesji, `state` niezgodny, brak weryfikatora, brak
         * kodu. Człowiek ma w każdym z tych przypadków zrobić dokładnie to
         * samo, a rozróżnienie („to Twój `state` nie pasował") powiedziałoby
         * napastnikowi, jak blisko był. To ta sama zasada, którą stosuje
         * ekran nieaktualnego linku w D-056.
         *
         * `hash_equals`, nie `===`: porównanie sekretu sesji ma nie mierzyć
         * się czasem.
         */
        if ($state === '' || $nonce === '' || $weryfikator === '' || $kod === ''
            || ! hash_equals($state, (string) $request->query('state', ''))) {
            Log::warning('Powrót z Google odrzucony: nie zgadza się `state` albo brakuje kodu.');

            return redirect()->route('login')->with('status',
                'Wejście kontem Google nie doszło do końca — to sprawdzenie mogło wygasnąć, '
                .'jeśli od kliknięcia minęła dłuższa chwila. Kliknij „Wejdź kontem Google" jeszcze raz. '
                .'Możesz też zalogować się hasłem albo poprosić o wiadomość z przyciskiem do zalogowania.',
            );
        }

        $tozsamosc = $klient->wymienKod($kod, $weryfikator, $nonce, $this->adresPowrotu());

        if ($tozsamosc === null) {
            return redirect()->route('login')->with('status',
                'Nie udało się dokończyć wejścia kontem Google — po stronie Google coś nie zagrało. '
                .'Spróbuj jeszcze raz za chwilę. Jeśli to się powtarza, zaloguj się hasłem albo poproś '
                .'o wiadomość z przyciskiem do zalogowania. Napisz też do nas na '
                .config('kuking.community.contact_email').' — odpisuje człowiek.',
            );
        }

        /*
         * REGUŁA 1 — WARUNEK, BEZ KTÓREGO NIE ROBIMY NIC. Patrz komentarz
         * klasy: to jest miejsce, w którym zamyka się najkrótszą drogę
         * przejęcia konta przy „zaloguj się przez…".
         */
        if (! $tozsamosc->emailPotwierdzony) {
            Log::warning('Wejście kontem Google odrzucone: Google nie potwierdziło adresu e-mail.');

            return redirect()->route('login')->with('status',
                'Google nie potwierdziło, że ten adres e-mail należy do Ciebie, dlatego tą drogą Cię nie '
                .'wpuścimy — inaczej ktoś mógłby wejść na cudze konto, podając cudzy adres. Potwierdź adres '
                .'w ustawieniach konta Google i wróć tutaj. Możesz też po prostu zalogować się hasłem albo '
                .'poprosić o wiadomość z przyciskiem do zalogowania.',
            );
        }

        // KONTO JUŻ POWIĄZANE — rozpoznajemy po `sub`, nigdy po adresie.
        $powiazane = User::findByGoogleSub($tozsamosc->sub);

        if ($powiazane !== null) {
            return $this->wpusc($request, $powiazane);
        }

        $istniejace = User::where('email', $tozsamosc->email)->first();

        if ($istniejace === null) {
            // NOWA OSOBA — nie zakładamy konta po cichu. Idzie na ekran
            // domknięcia, bo trzech rzeczy Google nam nie da (patrz klasa).
            $this->zapiszTozsamosc($request, $tozsamosc);

            return redirect()->route('google.finish');
        }

        return $this->propozycjaPolaczenia($request, $istniejace, $tozsamosc);
    }

    /**
     * Ekran domknięcia konta dla nowej osoby.
     */
    public function finishForm(Request $request): View|RedirectResponse
    {
        if (! Google::dziala()) {
            return $this->drogaZamknieta();
        }

        if (! config('kuking.account.registration_open')) {
            return redirect()->route('login')->with('status',
                'Zakładanie nowych kont jest chwilowo zamknięte. Jeśli masz już konto, zaloguj się hasłem '
                .'albo poproś o wiadomość z przyciskiem do zalogowania.',
            );
        }

        $tozsamosc = $this->wejscie()->tozsamoscZSesji($request);

        if ($tozsamosc === null) {
            return $this->trzebaZaczacOdNowa();
        }

        // PODPOWIEDŹ, NIE NADANIE. Nazwa stoi w polu, które człowiek widzi
        // i może zmienić — decyzja właściciela z 10 września (dwa pola przy
        // rejestracji, nazwa podpowiadana z imienia).
        return view('auth.google-finish', $this->wejscie()->ekranDomkniecia($request, $tozsamosc));
    }

    /**
     * Zakładamy konto — dopiero teraz i dopiero z dwoma oświadczeniami.
     */
    public function finish(Request $request, ZalozKonto $zalozKonto): RedirectResponse
    {
        if (! Google::dziala()) {
            return $this->drogaZamknieta();
        }

        // Ta sama bramka co w `RegisterController::store()`. Bez niej
        // zamknięcie rejestracji zamykałoby jedną z dwóch dróg do tego
        // samego skutku — czyli nie zamykałoby jej wcale.
        abort_unless(config('kuking.account.registration_open'), 503);

        $tozsamosc = $this->wejscie()->tozsamoscDoZalozenia($request);

        if ($tozsamosc === null) {
            return $this->trzebaZaczacOdNowa();
        }

        $dane = $this->wejscie()->daneDomkniecia($request);

        // GOOGLE POTWIERDZIŁO ADRES (reguła 1 wyżej), więc konto powstaje
        // z adresem potwierdzonym i listu z potwierdzeniem nie ma —
        // `listPotwierdzajacyNieWyszedl` jest zawsze `false`, nie ma o czym
        // mówić człowiekowi. Hasła też nie ma: konto ma dwie drogi wejścia,
        // Google i — bo adres jest potwierdzony — wiadomość z linkiem.
        $konto = $this->wejscie()->zalozKonto($request, $tozsamosc, $dane, $zalozKonto);

        if ($konto === null) {
            return redirect()->route('login')->with('status',
                'W tym czasie powstało już konto na ten adres. Zaloguj się — hasłem, kontem Google '
                .'albo poproś o wiadomość z przyciskiem do zalogowania.',
            );
        }

        return redirect()->route('onboarding.interests')
            ->with('status', 'Konto gotowe. Miło Cię widzieć w Kuking.');
    }

    /**
     * Ekran potwierdzenia połączenia z kontem, które już u nas jest.
     */
    public function linkForm(Request $request): View|RedirectResponse
    {
        if (! Google::dziala()) {
            return $this->drogaZamknieta();
        }

        $tozsamosc = $this->wejscie()->tozsamoscZSesji($request);
        $user = $this->wejscie()->kontoZSesji($request);

        if ($tozsamosc === null || $user === null || ! $this->wejscie()->wolnoPolaczyc($user, $tozsamosc)) {
            return $this->trzebaZaczacOdNowa();
        }

        return view('auth.google-link', [
            'email' => $tozsamosc->email,
            'displayName' => $user->profile?->display_name,
        ]);
    }

    /**
     * Połączenie kont — REGUŁA 3. Dopiero tutaj powstaje powiązanie.
     */
    public function link(Request $request): RedirectResponse
    {
        if (! Google::dziala()) {
            return $this->drogaZamknieta();
        }

        $tozsamosc = $this->wejscie()->tozsamoscZSesji($request);
        $user = $this->wejscie()->kontoZSesji($request);

        if ($tozsamosc === null || $user === null) {
            return $this->trzebaZaczacOdNowa();
        }

        /*
         * WSZYSTKIE WARUNKI SPRAWDZAMY PONOWNIE, przy zapisie i pod blokadą
         * wiersza konta — `WejdzPrzezDostawce::polacz()`. Ekran mógł stać
         * otwarty kilkanaście minut: konto mogło zostać zablokowane, dostać
         * rolę moderatora, zmienić adres albo stracić jego potwierdzenie.
         */
        $polaczone = $this->wejscie()->polacz($request, $user, $tozsamosc);

        if ($polaczone === null) {
            return $this->trzebaZaczacOdNowa();
        }

        $this->wejscie()->zapomnij($request);

        return $this->wpusc($request, $polaczone);
    }

    /**
     * Wejście na konto — reguły w `WejdzPrzezDostawce::wpusc()`, tu tylko
     * odpowiedź na ekranie.
     */
    private function wpusc(Request $request, User $user): RedirectResponse
    {
        return match ($this->wejscie()->wpusc($request, $user)) {
            WynikWejscia::KontoZamkniete => redirect()->route('login')->with('status', KomunikatZamknietegoKonta::dla($user)),
            WynikWejscia::KontoObslugi => redirect()->route('login')->with('status',
                'Konta obsługi serwisu wchodzą hasłem i kodem z aplikacji — nie kontem Google. '
                .'Zaloguj się poniżej.',
            ),
            WynikWejscia::DrugiSkladnik => redirect()->route('login.two_factor'),
            WynikWejscia::Wpuszczony => redirect()->intended(route('home')),
        };
    }

    /**
     * Adres z Google należy do konta, które już u nas jest — REGUŁA 2 i 3.
     */
    private function propozycjaPolaczenia(Request $request, User $user, TozsamoscGoogle $tozsamosc): RedirectResponse
    {
        // Konto zamknięte i obsługa serwisu — odpowiedź jak przy wejściu,
        // żeby te dwa przypadki miały JEDNO miejsce prawdy.
        if ($this->wejscie()->odmowaWejscia($user) !== null) {
            return $this->wpusc($request, $user);
        }

        /*
         * REGUŁA 2 — TU ZAMYKA SIĘ ATAK Z WYPRZEDZENIEM.
         *
         * Adres potwierdzony przez Google, ale NIEPOTWIERDZONY u nas, znaczy:
         * ktoś założył u nas konto na ten adres i nigdy nie dowiódł, że ma
         * do niego dostęp. Może to być ta sama osoba, która stoi teraz przed
         * ekranem — i wtedy zdanie niżej mówi jej dokładnie, co zrobić.
         * A może to być napastnik, który czekał właśnie na to połączenie.
         * Z naszej strony te dwa przypadki są nieodróżnialne, więc
         * rozstrzygamy je tak, jak wolno: nie łączymy.
         */
        if ($user->email_verified_at === null) {
            Log::warning('Odmowa połączenia konta Google z kontem o niepotwierdzonym u nas adresie.');

            return redirect()->route('login')->with('status',
                'Na ten adres e-mail jest już konto w Kuking, ale nikt jeszcze nie potwierdził, '
                .'że skrzynka do niego należy — dlatego nie połączymy go z kontem Google. To zabezpieczenie: '
                .'inaczej ktoś mógłby założyć konto na cudzy adres i przechwycić je w tym momencie. '
                .'Wejdź na to konto tak jak zwykle: hasłem albo prosząc o wiadomość z przyciskiem '
                .'do zalogowania. Potem potwierdź adres — wyślemy Ci wiadomość z przyciskiem — '
                .'i wejście kontem Google zacznie działać.',
            );
        }

        // REGUŁA 3 — połączenie po jawnym potwierdzeniu na naszym ekranie.
        $this->zapiszTozsamosc($request, $tozsamosc);
        $this->wejscie()->zapamietajKonto($request, $user);

        return redirect()->route('google.link');
    }

    /**
     * Adres powrotu MUSI być identyczny w obu żądaniach do Google (przy
     * zgodzie i przy wymianie kodu) — inaczej Google odpowiada
     * `redirect_uri_mismatch`. Dlatego liczy go jedna metoda, a nie dwa
     * miejsca.
     */
    private function adresPowrotu(): string
    {
        return route('google.callback');
    }

    /**
     * Do sesji trafia WYŁĄCZNIE tożsamość, która przeszła regułę 1
     * w `callback()` — `WejdzPrzezDostawce::zapamietaj()` odrzuca każdą inną.
     */
    private function zapiszTozsamosc(Request $request, TozsamoscGoogle $tozsamosc): void
    {
        $this->wejscie()->zapamietaj($request, $this->dostawca->tozsamosc($tozsamosc));
    }

    /**
     * Wspólne reguły konta i sesji (#1035) z różnicami Google.
     */
    private function wejscie(): WejdzPrzezDostawce
    {
        return new WejdzPrzezDostawce($this->dostawca);
    }

    private function trzebaZaczacOdNowa(): RedirectResponse
    {
        return redirect()->route('login')->with('status',
            'Wejście kontem Google wymaga ponownego potwierdzenia. '
            .'Kliknij „Wejdź kontem Google" jeszcze raz. Możesz też zalogować się hasłem albo poprosić '
            .'o wiadomość z przyciskiem do zalogowania.',
        );
    }

    /**
     * Funkcja wyłączona albo bez kluczy. Przycisku wtedy nie ma nigdzie na
     * ekranie, więc tu trafia tylko ktoś, kto wpisał adres wprost albo
     * wracał z Google w chwili, gdy właściciel wyłączył tę drogę. Nie
     * zostawiamy go z niczym i nie oddajemy 404 bez wyjaśnienia.
     */
    private function drogaZamknieta(): RedirectResponse
    {
        return redirect()->route('login')->with('status',
            'Wejście kontem Google jest teraz wyłączone. Zaloguj się hasłem — Twoje konto działa '
            .'normalnie — albo poproś o wiadomość z przyciskiem do zalogowania.',
        );
    }
}

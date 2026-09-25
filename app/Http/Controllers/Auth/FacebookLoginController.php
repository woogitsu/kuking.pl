<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\KomunikatZamknietegoKonta;
use App\Domain\Security\WejsciePrzezDostawce\WejdzPrzezDostawce;
use App\Domain\Security\WejsciePrzezDostawce\WynikWejscia;
use App\Domain\Users\Actions\ZalozKonto;
use App\Domain\Users\Actions\ZalozoneKonto;
use App\Facebook\DostawcaWejsciaFacebook;
use App\Facebook\KlientFacebook;
use App\Facebook\TozsamoscFacebook;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ProbaWejsciaKontemFacebooka;
use App\Support\Facebook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * WEJŚCIE KONTEM FACEBOOKA (issue #259, D-069, D-098).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PRZECZYTAJ TO, ZANIM PORÓWNASZ TEN PLIK Z `GoogleLoginController`
 * ────────────────────────────────────────────────────────────────────────
 *
 * Te dwa kontrolery wyglądają podobnie i to jest mylące, bo **różnią się
 * w jedynym miejscu, które naprawdę decyduje o bezpieczeństwie**: co robimy,
 * gdy adres e-mail od dostawcy należy do konta, które już u nas jest.
 *
 * Przy Google łączymy — po jawnym potwierdzeniu człowieka na naszym ekranie
 * (D-069, reguła 3). Wolno, bo Google mówi `email_verified: true`, czyli
 * dowodzi kontroli nad skrzynką; a kto kontroluje skrzynkę, mógł i tak dziś
 * przejąć to konto przez „Nie pamiętam hasła".
 *
 * **Facebook nie mówi nic.** Pełny opis pola `email` w Graph API to „The
 * User's primary email address listed on their profile. This field will not
 * be returned if no valid email address is available." — ani słowa
 * o potwierdzeniu. Warunek z reguły 1 D-069 nie jest tu „trudny": jest
 * NIESPEŁNIALNY, bo danych, na których stoi, nie ma.
 *
 * Gdybyśmy przy Facebooku powtórzyli regułę 3, powstałaby droga przejęcia
 * konta na życzenie: zakładam konto na Facebooku, wpisuję w nim cudzy adres,
 * klikam u nas „to moje konto" i wchodzę. Dlatego obowiązuje to, co
 * rozstrzygnęło D-098:
 *
 *   1. **Kolejne wejścia rozpoznajemy WYŁĄCZNIE po identyfikatorze konta
 *      Facebooka.** Nigdy po adresie e-mail.
 *   2. **Nowe konto powstaje z adresem NIEPOTWIERDZONYM** i przechodzi naszą
 *      zwykłą ścieżkę potwierdzenia adresu — dokładnie jak przy rejestracji
 *      hasłem. Adres z Facebooka nie ma prawa nigdy trafić do bazy jako
 *      potwierdzony.
 *   3. **Gdy adres z Facebooka należy do istniejącego konta — ODMAWIAMY**
 *      i mówimy, co zrobić. Nie łączymy, nie logujemy, nie zakładamy
 *      drugiego konta.
 *   4. **Powiązanie z istniejącym kontem powstaje tylko na życzenie osoby,
 *      która JUŻ JEST ZALOGOWANA** na to konto (hasłem albo linkiem
 *      e-mail) — czyli dowiodła, że jest jego właścicielem CZYNNOŚCIĄ,
 *      a nie twierdzeniem. To jest cała rola trasy `/wejdz/facebook/polacz`
 *      i przycisku „Połącz konto Facebooka" w Ustawieniach →
 *      Bezpieczeństwo.
 *
 * DLACZEGO TE TRASY NIE SĄ W GRUPIE `guest` (a trasy Google są). Bo punkt 4
 * wymaga człowieka ZALOGOWANEGO. Gdyby `/wejdz/facebook` było tylko dla
 * gościa, jedyna bezpieczna droga powiązania istniejącego konta byłaby
 * nieosiągalna — a odmowa z punktu 3 nie miałaby dokąd odesłać człowieka
 * i zamieniłaby się w ślepy zaułek. Rozstrzyga więc `Auth::check()`
 * w `callback()`, w jednym miejscu i jawnie.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZTERY EKRANY
 * ────────────────────────────────────────────────────────────────────────
 *
 *   1. `start`     — GET, kliknięcie „Wejdź kontem Facebooka". Zakłada
 *                    w sesji `state` i odsyła człowieka do Facebooka.
 *   2. `callback`  — GET, powrót z Facebooka z kodem. Sprawdza `state`,
 *                    wymienia kod na token, odczytuje tożsamość
 *                    i ROZSTRZYGA, co dalej.
 *   3. `finishForm`/`finish` — domknięcie konta dla NOWEJ osoby: imię,
 *                    nazwa w adresie profilu i DWA oświadczenia.
 *   4. `linkForm`/`link` — „połącz to konto z Facebookiem" dla osoby,
 *                    która jest już zalogowana.
 *
 * Krok 3 istnieje z tego samego powodu co przy Google: Facebook nie przekaże
 * trzech rzeczy, których wymaga nasza rejestracja — nazwy do adresu profilu,
 * oświadczenia o wieku i akceptacji regulaminu. Nie wolno ich zaznaczyć za
 * człowieka (ciemny wzorzec, a przy oświadczeniu o wieku dodatkowo bez
 * wartości: oświadczenie złożone przez serwer nie jest niczyim
 * oświadczeniem).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  GDY FACEBOOK NIE ODDA ADRESU E-MAIL — OSOBNY EKRAN, NIE BŁĄD
 * ────────────────────────────────────────────────────────────────────────
 *
 * Dwie przyczyny, obie realne: konto Facebooka założone na numer telefonu
 * nie ma adresu wcale, a człowiek może odznaczyć zgodę na adres na ekranie
 * Facebooka. Nasze konto bez adresu istnieć nie może — adres jest u nas
 * jedyną drogą odzyskania konta i jedyną drogą powiadomień.
 *
 * Człowiek widzi wtedy ekran, który mówi CO ZROBIĆ (założyć konto adresem
 * e-mail, a potem połączyć je z Facebookiem w ustawieniach), a nie „wystąpił
 * błąd" i nie pętlę dopraszania o zgodę. Meta ostrzega w tej sprawie sama:
 * „if someone is actively choosing not to grant a specific permission to an
 * app they are unlikely to change their mind, even in the face of continued
 * prompting".
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TA DROGA NIE OMIJA (identycznie jak przy Google)
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - **2FA.** Konto z potwierdzoną weryfikacją dwuetapową trafia na
 *    `/logowanie/kod`. Facebook zastępuje HASŁO, nie drugi składnik.
 *  - **Konta obsługi serwisu.** Moderator i administrator tą drogą nie
 *    wchodzą i nie łączą konta. Rolę sprawdzamy przy KAŻDYM wejściu.
 *  - **Blokadę.** Konto zamknięte (`banned`, `pending_delete`, `erased`)
 *    nie wchodzi i czyta to samo uzasadnienie z DSA art. 17 co przy haśle.
 *    Konto ZAWIESZONE wchodzi — kara jest „tylko do odczytu".
 *  - **Zamkniętą rejestrację.** `registration_open` zamyka także tę drogę.
 *
 * Bez kluczy przycisku nie ma w ogóle, a trasy odsyłają na `/login` ze
 * zdaniem po polsku (D-053: nigdzie martwego przycisku).
 */
class FacebookLoginController extends Controller
{
    /**
     * Klucz protokołu w sesji. Jednorazowy, kasowany po odczycie. Tożsamość
     * po powrocie trzyma `WejdzPrzezDostawce`.
     */
    private const KLUCZ_STATE = 'wejscie_facebook.state';

    public function __construct(private readonly DostawcaWejsciaFacebook $dostawca) {}

    /**
     * Kliknięcie „Wejdź kontem Facebooka" albo „Połącz konto Facebooka" —
     * odsyłamy człowieka do Facebooka.
     *
     * Jedna trasa dla obu przypadków, bo po stronie Facebooka dzieje się
     * dokładnie to samo. Co z tym zrobimy, rozstrzyga `callback()` po
     * `Auth::check()` — czyli po stanie, który da się sprawdzić WTEDY,
     * a nie po intencji zapamiętanej w sesji kilkadziesiąt sekund wcześniej.
     */
    public function start(Request $request, KlientFacebook $klient): RedirectResponse
    {
        if (! Facebook::dziala()) {
            return $this->drogaZamknieta();
        }

        /*
         * `state` POWSTAJE TUTAJ I JEST WIĄZANY Z SESJĄ.
         *
         * Przy powrocie porównujemy to, co przyszło w adresie, z tym, co
         * zostało tu zapisane. Bez tego napastnik podrzuca w przekierowaniu
         * SWÓJ kod autoryzacyjny i łączy ofiarę ze SWOIM kontem Facebooka
         * (CSRF na drodze OAuth — podręcznikowy atak, nie teoria).
         *
         * PKCE tu nie ma i jest to rozstrzygnięte w `KlientFacebook`:
         * dokumentacja Meta dla ręcznej drogi logowania go nie wymienia,
         * a parametr, o którym nie wiemy, czy dostawca go sprawdza, dałby
         * zabezpieczenie na papierze.
         *
         * `regenerate()` TYLKO DLA GOŚCIA. Świeży identyfikator sesji zamyka
         * podrzucenie ofierze sesji ustalonej z góry (session fixation)
         * i jest przy tym darmowy, dopóki sesja nie jest zalogowana.
         * Zalogowanej sesji nie ruszamy w tym miejscu: człowiek klika tu
         * „Połącz konto Facebooka" ze swojego konta i rotacja identyfikatora
         * sesji w połowie tej drogi nie kupuje niczego, a potrafi wyrzucić
         * z konta drugą kartę tej samej przeglądarki.
         */
        if (! Auth::check()) {
            $request->session()->regenerate();
        }

        $state = KlientFacebook::losowaWartosc();

        $request->session()->put(self::KLUCZ_STATE, $state);

        return redirect()->away($klient->adresZgody(
            state: $state,
            adresPowrotu: $this->adresPowrotu(),
        ));
    }

    /**
     * Powrót z Facebooka. Rozstrzyga, co dalej — patrz komentarz klasy.
     */
    public function callback(Request $request, KlientFacebook $klient): View|RedirectResponse
    {
        if (! Facebook::dziala()) {
            return $this->drogaZamknieta();
        }

        // `pull`, nie `get`: `state` jest jednorazowy. Powtórzone wejście pod
        // ten adres (odświeżenie, przycisk „wstecz", skaner odnośników) nie
        // ma prawa przejść drugi raz.
        $state = (string) $request->session()->pull(self::KLUCZ_STATE, '');

        /*
         * CZŁOWIEK ODMÓWIŁ ZGODY ALBO FACEBOOK ODMÓWIŁ NAM.
         *
         * Facebook wraca wtedy z `error=access_denied` (najczęściej: ktoś
         * kliknął „Anuluj" na ekranie zgody) albo z innym kodem po
         * angielsku. Kodu NIE POKAZUJEMY — nikomu nic nie mówi.
         */
        if ($request->filled('error')) {
            Log::info('Wejście kontem Facebooka przerwane po stronie Facebooka.', [
                'blad' => (string) $request->query('error'),
            ]);

            return redirect()->route('login')->with('status',
                'Nie weszliśmy kontem Facebooka — zgoda nie została udzielona. Nic się nie stało. '
                .'Możesz spróbować jeszcze raz albo zalogować się hasłem, a jeśli go nie pamiętasz, '
                .'poproś o wiadomość z przyciskiem do zalogowania.',
            );
        }

        $kod = (string) $request->query('code', '');

        /*
         * JEDEN KOMUNIKAT DLA WSZYSTKICH POWODÓW ODRZUCENIA, świadomie:
         * brak `state` w sesji, `state` niezgodny, brak kodu. Człowiek ma
         * w każdym z tych przypadków zrobić dokładnie to samo, a rozróżnienie
         * („to Twój `state` nie pasował") powiedziałoby napastnikowi, jak
         * blisko był.
         *
         * `hash_equals`, nie `===`: porównanie sekretu sesji ma nie mierzyć
         * się czasem.
         */
        if ($state === '' || $kod === ''
            || ! hash_equals($state, (string) $request->query('state', ''))) {
            Log::warning('Powrót z Facebooka odrzucony: nie zgadza się `state` albo brakuje kodu.');

            return redirect()->route('login')->with('status',
                'Wejście kontem Facebooka nie doszło do końca — to sprawdzenie mogło wygasnąć, '
                .'jeśli od kliknięcia minęła dłuższa chwila. Kliknij „Wejdź kontem Facebooka" jeszcze raz. '
                .'Możesz też zalogować się hasłem albo poprosić o wiadomość z przyciskiem do zalogowania.',
            );
        }

        $tozsamosc = $klient->wymienKod($kod, $this->adresPowrotu());

        if ($tozsamosc === null) {
            return redirect()->route('login')->with('status',
                'Nie udało się dokończyć wejścia kontem Facebooka — po stronie Facebooka coś nie zagrało. '
                .'Spróbuj jeszcze raz za chwilę. Jeśli to się powtarza, zaloguj się hasłem albo poproś '
                .'o wiadomość z przyciskiem do zalogowania. Napisz też do nas na '
                .config('kuking.community.contact_email').' — odpisuje człowiek.',
            );
        }

        // ROZPOZNANIE PO IDENTYFIKATORZE — jedyna droga rozpoznania konta
        // przy tym dostawcy (punkt 1 w komentarzu klasy).
        $powiazane = User::findByFacebookId($tozsamosc->identyfikator);

        if (Auth::check()) {
            return $this->dlaZalogowanego($request, $tozsamosc, $powiazane);
        }

        if ($powiazane !== null) {
            return $this->wpusc($request, $powiazane);
        }

        return $this->nowaOsoba($request, $tozsamosc);
    }

    /**
     * Powrót z Facebooka dla osoby, która JUŻ JEST ZALOGOWANA — czyli droga
     * „połącz moje konto z Facebookiem" (punkt 4 w komentarzu klasy).
     */
    private function dlaZalogowanego(
        Request $request,
        TozsamoscFacebook $tozsamosc,
        ?User $powiazane,
    ): RedirectResponse {
        /** @var User $user */
        $user = Auth::user();

        if ($powiazane !== null) {
            /*
             * TO SAMO KONTO — ALE ZGODA U FACEBOOKA WŁAŚNIE PADŁA NA NOWO.
             *
             * Tędy wraca przycisk „Połącz konto Facebooka jeszcze raz" z ekranu
             * „Ustawienia → Bezpieczeństwo" (issue #1025). Człowiek przeszedł
             * przez ekran zgody Facebooka, więc znacznik uśpienia gaśnie
             * i zapisuje się granica tej zgody — dokładnie tak samo jak przy
             * wejściu gościa w `wpusc()`. Bez tego przycisk byłby martwy
             * (D-053): uśpienie by zostało, a stare powiadomienie o odebraniu
             * dostępu dalej usypiałoby powiązanie.
             */
            if ($powiazane->getKey() === $user->getKey()) {
                $byloUspione = $user->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);

                $user->cofnijOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);

                if ($byloUspione) {
                    return redirect()->route('settings.security')->with('status',
                        'Połączenie z Facebookiem znów działa. Możesz logować się przyciskiem „Wejdź kontem Facebooka”.',
                    );
                }

                return redirect()->route('settings.security')->with('status',
                    'To konto jest już połączone z Twoim kontem Facebooka. Możesz logować się przyciskiem „Wejdź kontem Facebooka”.',
                );
            }

            /*
             * TO KONTO FACEBOOKA PROWADZI DO CZYJEGOŚ INNEGO KONTA W KUKING.
             *
             * Pilnuje tego `UNIQUE (dostawca, identyfikator)` w bazie, ale
             * człowiek ma się dowiedzieć, CO ZROBIĆ, a nie zobaczyć błąd
             * serwera. Nie mówimy przy tym, KTÓRE to konto — nie jest to
             * nasza sprawa i byłoby to wyrocznią „czy ta osoba jest
             * w Kuking".
             */
            Log::warning('Odmowa połączenia: to konto Facebooka jest już powiązane z innym kontem Kuking.');

            return redirect()->route('settings.security')->with('status',
                'Tego konta Facebooka nie połączymy — jest już połączone z innym kontem w Kuking. '
                .'Jeśli to Twoje drugie konto, wejdź na nie i tam rozłącz Facebooka (napisz do nas na '
                .config('kuking.community.contact_email').' — odpisuje człowiek), a potem wróć tutaj.',
            );
        }

        $this->zapiszTozsamosc($request, $tozsamosc);

        return redirect()->route('facebook.link');
    }

    /**
     * Powrót z Facebooka dla GOŚCIA, którego konta Facebooka jeszcze nie
     * znamy — czyli punkty 2 i 3 z komentarza klasy.
     */
    private function nowaOsoba(Request $request, TozsamoscFacebook $tozsamosc): View|RedirectResponse
    {
        /*
         * FACEBOOK NIE ODDAŁ ADRESU E-MAIL — OSOBNY EKRAN, NIE BŁĄD.
         *
         * Widok jest oddawany wprost z tego żądania (`GET`, więc nic nie
         * zmienia i nie ma czego zapisywać w sesji): tożsamość bez adresu
         * jest u nas bezużyteczna, więc nie ma po co jej trzymać.
         */
        if ($tozsamosc->email === null) {
            Log::info('Facebook nie oddał adresu e-mail — pokazujemy ekran z drogą dalej.');

            return view('auth.facebook-bez-adresu');
        }

        $istniejace = User::where('email', $tozsamosc->email)->first();

        if ($istniejace !== null) {
            /*
             * ODMOWA, KTÓRA JEST CAŁYM SENSEM TEJ FUNKCJI (D-098).
             *
             * Adres z Facebooka pasuje do konta, które u nas jest — i to
             * jest dokładnie moment, w którym przy Google proponujemy
             * połączenie. Tutaj NIE WOLNO, bo Facebook nie dowiódł, że ten
             * adres należy do osoby siedzącej przed ekranem. Wystarczyłoby
             * wpisać cudzy adres w swoim koncie na Facebooku.
             *
             * Zdanie na ekranie mówi, co zrobić, i ta droga JEST przechodnia:
             * kto ma dostęp do skrzynki, wejdzie na konto linkiem
             * z wiadomości (D-056) albo hasłem — a potem połączy konto
             * z Facebookiem w ustawieniach, jednym kliknięciem, już jako
             * jego dowiedziony właściciel.
             *
             * Czy to jest wyrocznia „na tym adresie jest konto"? Jest, tak
             * samo jak przy Google — i tak samo jest to cena, którą warto
             * zapłacić: odmowa bez powodu kończy się odejściem człowieka,
             * a napastnik dowie się tego samego, wpisując adres w „Nie
             * pamiętam hasła" (tam odpowiedź jest milcząca, ale wiadomość
             * i tak idzie na cudzą skrzynkę, nie do niego).
             */
            Log::warning('Odmowa: adres z Facebooka należy do istniejącego konta Kuking.');

            $this->powiadomOProbie($istniejace);

            return redirect()->route('login')->with('status',
                'Na adres e-mail z Twojego Facebooka jest już konto w Kuking, ale Facebook nie potwierdza nam, '
                .'że ta skrzynka naprawdę do Ciebie należy — dlatego tą drogą Cię nie wpuścimy. To zabezpieczenie: '
                .'inaczej ktoś mógłby wpisać cudzy adres w swoim koncie na Facebooku i wejść na cudze konto. '
                .'Wejdź na swoje konto tak jak zwykle: hasłem albo poproś o wiadomość z przyciskiem do zalogowania. '
                .'Potem w Ustawieniach → Bezpieczeństwo kliknij „Połącz konto Facebooka" — i od następnego razu '
                .'możesz logować się przyciskiem „Wejdź kontem Facebooka”.',
            );
        }

        // NOWA OSOBA — nie zakładamy konta po cichu. Idzie na ekran
        // domknięcia, bo trzech rzeczy Facebook nam nie da (patrz klasa).
        $this->zapiszTozsamosc($request, $tozsamosc);

        return redirect()->route('facebook.finish');
    }

    /**
     * Ekran domknięcia konta dla nowej osoby.
     */
    public function finishForm(Request $request): View|RedirectResponse
    {
        if (! Facebook::dziala()) {
            return $this->drogaZamknieta();
        }

        if (! config('kuking.account.registration_open')) {
            return redirect()->route('login')->with('status',
                'Zakładanie nowych kont jest chwilowo zamknięte. Jeśli masz już konto, zaloguj się hasłem '
                .'albo poproś o wiadomość z przyciskiem do zalogowania.',
            );
        }

        $tozsamosc = $this->wejscie()->tozsamoscZSesji($request);

        if ($tozsamosc === null || $tozsamosc->email === null) {
            return $this->trzebaZaczacOdNowa();
        }

        // PODPOWIEDŹ, NIE NADANIE — ten sam wywód co przy Google.
        return view('auth.facebook-finish', $this->wejscie()->ekranDomkniecia($request, $tozsamosc));
    }

    /**
     * Zakładamy konto — dopiero teraz i dopiero z dwoma oświadczeniami.
     */
    public function finish(Request $request, ZalozKonto $zalozKonto): RedirectResponse
    {
        if (! Facebook::dziala()) {
            return $this->drogaZamknieta();
        }

        // Ta sama bramka co w `RegisterController::store()`. Bez niej
        // zamknięcie rejestracji zamykałoby jedną z dróg do tego samego
        // skutku — czyli nie zamykałoby jej wcale.
        abort_unless(config('kuking.account.registration_open'), 503);

        $tozsamosc = $this->wejscie()->tozsamoscDoZalozenia($request);

        if ($tozsamosc === null) {
            return $this->trzebaZaczacOdNowa();
        }

        $dane = $this->wejscie()->daneDomkniecia($request);

        /*
         * ADRES JEST NIEPOTWIERDZONY I TO JEST NAJWAŻNIEJSZA WŁASNOŚĆ TEJ
         * DROGI.
         *
         * Facebook nie mówi, czy adres jest potwierdzony (patrz komentarz
         * klasy), więc `DostawcaWejsciaFacebook::tozsamosc()` zawsze daje
         * `emailPotwierdzony: false` i konto powstaje tak, jak przy
         * rejestracji hasłem: z adresem niepotwierdzonym i z naszą wiadomością
         * „potwierdź adres" (wychodzi z `event(new Registered)`
         * w `ZalozKonto`). Przyjęcie adresu jako potwierdzonego byłoby
         * przyjęciem na słowo czegoś, czego nikt nie powiedział — i zamknęłoby
         * drogę odwrotną: konto z „potwierdzonym" adresem, którego nikt nigdy
         * nie potwierdził, dostaje u nas link do zalogowania na tę skrzynkę.
         *
         * Konto ma więc jedną pewną drogę wejścia (Facebook) i drugą, która
         * otworzy się po kliknięciu w link z naszej wiadomości.
         */
        $konto = $this->wejscie()->zalozKonto($request, $tozsamosc, $dane, $zalozKonto);

        if ($konto === null) {
            return redirect()->route('login')->with('status',
                'W tym czasie powstało już konto na ten adres. Zaloguj się — hasłem albo poproś '
                .'o wiadomość z przyciskiem do zalogowania.',
            );
        }

        // „Wysłaliśmy Ci wiadomość" pada tylko wtedy, gdy to prawda (#1373).
        if ($konto->listPotwierdzajacyNieWyszedl) {
            return redirect()->route('onboarding.interests')->with('status', ZalozoneKonto::KOMUNIKAT_BEZ_LISTU);
        }

        return redirect()->route('onboarding.interests')->with('status',
            'Konto gotowe. Miło Cię widzieć w Kuking. Wysłaliśmy Ci jeszcze wiadomość na '
            .$konto->user->email.' — kliknij w niej przycisk, żeby potwierdzić adres. Dzięki temu '
            .'będziesz mieć drugą drogę wejścia na konto, gdyby Facebook kiedyś przestał działać.',
        );
    }

    /**
     * Ekran „połączyć to konto z Facebookiem?" dla osoby zalogowanej.
     */
    public function linkForm(Request $request): View|RedirectResponse
    {
        if (! Facebook::dziala()) {
            return $this->drogaZamknieta();
        }

        $user = Auth::user();
        $tozsamosc = $this->wejscie()->tozsamoscZSesji($request);

        if (! $user instanceof User || $tozsamosc === null || ! $this->wejscie()->wolnoPolaczyc($user, $tozsamosc)) {
            return $this->trzebaZaczacOdNowa();
        }

        return view('auth.facebook-link', [
            'displayName' => $user->profile?->display_name,
            'imieZFacebooka' => $tozsamosc->imie,
        ]);
    }

    /**
     * Połączenie konta z Facebookiem. Dopiero tutaj powstaje powiązanie.
     */
    public function link(Request $request): RedirectResponse
    {
        if (! Facebook::dziala()) {
            return $this->drogaZamknieta();
        }

        $user = Auth::user();
        $tozsamosc = $this->wejscie()->tozsamoscZSesji($request);

        if (! $user instanceof User || $tozsamosc === null) {
            return $this->trzebaZaczacOdNowa();
        }

        /*
         * REWALIDACJA POD BLOKADĄ WIERSZA KONTA (`ZamekKonta`, D-079, D-098)
         * — `WejdzPrzezDostawce::polacz()`. Ekran mógł stać otwarty
         * kilkanaście minut: konto mogło dostać rolę moderatora, zostać
         * zablokowane albo już zostać połączone z sąsiedniej karty.
         */
        $polaczone = $this->wejscie()->polacz($request, $user, $tozsamosc);

        $this->wejscie()->zapomnij($request);

        if ($polaczone === null) {
            return redirect()->route('settings.security')->with('status',
                'Nie połączyliśmy tego konta z Facebookiem — w trakcie coś się zmieniło. Spróbuj jeszcze raz. '
                .'Jeśli to się powtarza, napisz do nas na '.config('kuking.community.contact_email').'.',
            );
        }

        return redirect()->route('settings.security')->with('status',
            'Gotowe — możesz logować się na to konto przyciskiem „Wejdź kontem Facebooka". '
            .'Twoje hasło działa dalej tak samo jak wcześniej.',
        );
    }

    /**
     * Wejście na konto — reguły w `WejdzPrzezDostawce::wpusc()` (tam też
     * kolejność pytań: konto zamknięte, obsługa serwisu, drugi składnik),
     * a znacznik odebrania dostępu gaśnie w
     * `DostawcaWejsciaFacebook::przedWejsciem()`. Tu tylko odpowiedź.
     */
    private function wpusc(Request $request, User $user): RedirectResponse
    {
        return match ($this->wejscie()->wpusc($request, $user)) {
            WynikWejscia::KontoZamkniete => redirect()->route('login')->with('status', KomunikatZamknietegoKonta::dla($user)),
            WynikWejscia::KontoObslugi => redirect()->route('login')->with('status',
                'Konta obsługi serwisu wchodzą hasłem i kodem z aplikacji — nie kontem Facebooka. '
                .'Zaloguj się poniżej.',
            ),
            WynikWejscia::DrugiSkladnik => redirect()->route('login.two_factor'),
            WynikWejscia::Wpuszczony => redirect()->intended(route('home')),
        };
    }

    /**
     * List do właściciela konta: „ktoś próbował wejść Twoim adresem".
     *
     * ────────────────────────────────────────────────────────────────────
     *  DLACZEGO TEN LIST W OGÓLE IDZIE
     * ────────────────────────────────────────────────────────────────────
     *
     * Odmowę wyżej widzi WYŁĄCZNIE ten, kto ją wywołał. Właściciel konta nie
     * dowiadywał się o próbie w ogóle — ani wtedy, gdy sam kliknął nie ten
     * przycisk (i odbił się bez wiedzy, co dalej), ani wtedy, gdy zrobił to
     * ktoś obcy. Ten list jest jedynym sygnałem, jaki do niego dociera.
     *
     * JEST POWIADOMIENIEM, NIE KLUCZEM — nie niesie żadnego odnośnika, który
     * cokolwiek łączy albo loguje. Pełne uzasadnienie stoi w klasie
     * `ProbaWejsciaKontemFacebooka` i sprowadza się do tego, że odnośnik
     * „to ja, połącz konta" zamieniłby ten list w narzędzie przejęcia konta:
     * obcy wpisuje cudzy adres w swoim koncie na Facebooku, a MY wysyłamy
     * właścicielowi wiarygodną wiadomość, którą ten jednym kliknięciem oddaje
     * mu wejście.
     *
     * ────────────────────────────────────────────────────────────────────
     *  JEDEN LIST NA GODZINĘ NA KONTO
     * ────────────────────────────────────────────────────────────────────
     *
     * Bez tego ograniczenia ta funkcja jest zdalnym zalewaniem cudzej
     * skrzynki: wystarczy w kółko wracać na adres powrotu. Ogranicznik trasy
     * (`throttle:facebook_wejscie`) liczy żądania NAPASTNIKA i jego nie boli;
     * zalewana jest skrzynka OFIARY, więc licznik musi stać przy koncie
     * odbiorcy, nie przy adresie IP nadawcy.
     *
     * `Cache::add()` zapisuje tylko wtedy, gdy klucza jeszcze nie ma, i oddaje
     * `false`, gdy już był — czyli jest tu jednocześnie sprawdzeniem
     * i zajęciem miejsca, bez wyścigu między dwoma równoległymi żądaniami.
     * Ten sam wzorzec co w `DziennyBudzetListow`.
     *
     * D-056 TO PRZEŻYWA: odpowiedź jest taka sama niezależnie od tego, czy
     * list poszedł, czy został pominięty — nic tu nie wraca do przeglądarki.
     */
    private function powiadomOProbie(User $wlasciciel): void
    {
        $klucz = 'fb-proba-wejscia:'.$wlasciciel->getKey();

        if (Cache::add($klucz, 1, now()->addHour()) !== true) {
            Log::info('Powiadomienie o próbie wejścia kontem Facebooka pominięte — wysłane w ciągu ostatniej godziny.');

            return;
        }

        $wlasciciel->notify(new ProbaWejsciaKontemFacebooka);
    }

    /**
     * Adres powrotu MUSI być identyczny w obu żądaniach do Facebooka (przy
     * zgodzie i przy wymianie kodu) — inaczej Meta odpowiada błędem, a na
     * ekranie zgody człowiek widzi „URL Blocked". Dlatego liczy go jedna
     * metoda, a nie dwa miejsca.
     *
     * Ta wartość jest też wpisana W PANELU META, znak w znak
     * (`docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md` §4.2:
     * `https://kuking.pl/wejdz/facebook/wroc`). Zmiana tej trasy wymaga
     * zmiany także tam — i powiadomienia właściciela.
     */
    private function adresPowrotu(): string
    {
        return route('facebook.callback');
    }

    private function zapiszTozsamosc(Request $request, TozsamoscFacebook $tozsamosc): void
    {
        $this->wejscie()->zapamietaj($request, $this->dostawca->tozsamosc($tozsamosc));
    }

    /**
     * Wspólne reguły konta i sesji (#1035) z różnicami Facebooka.
     */
    private function wejscie(): WejdzPrzezDostawce
    {
        return new WejdzPrzezDostawce($this->dostawca);
    }

    private function trzebaZaczacOdNowa(): RedirectResponse
    {
        return redirect()->route('login')->with('status',
            'Wejście kontem Facebooka wymaga ponownego potwierdzenia. '
            .'Kliknij „Wejdź kontem Facebooka" jeszcze raz. Możesz też zalogować się hasłem albo poprosić '
            .'o wiadomość z przyciskiem do zalogowania.',
        );
    }

    /**
     * Funkcja wyłączona albo bez kluczy. Przycisku wtedy nie ma nigdzie na
     * ekranie, więc tu trafia tylko ktoś, kto wpisał adres wprost albo
     * wracał z Facebooka w chwili, gdy właściciel wyłączył tę drogę. Nie
     * zostawiamy go z niczym i nie oddajemy 404 bez wyjaśnienia.
     */
    private function drogaZamknieta(): RedirectResponse
    {
        return redirect()->route('login')->with('status',
            'Wejście kontem Facebooka jest teraz wyłączone. Zaloguj się hasłem — Twoje konto działa '
            .'normalnie. Jeśli nie pamiętasz hasła, poproś o wiadomość z przyciskiem do zalogowania.',
        );
    }
}

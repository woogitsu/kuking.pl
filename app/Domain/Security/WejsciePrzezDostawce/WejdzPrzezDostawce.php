<?php

declare(strict_types=1);

namespace App\Domain\Security\WejsciePrzezDostawce;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Users\Actions\ZalozKonto;
use App\Domain\Users\Actions\ZalozoneKonto;
use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Rules\ReservedUsername;
use App\Rules\UsernameNotTaken;
use App\Support\ExternalRegistrationDraft;
use App\Support\NazwaUzytkownika;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use LogicException;

/**
 * WEJŚCIE I ŁĄCZENIE KONTA PRZEZ DOSTAWCĘ — JEDNO ŹRÓDŁO REGUŁ KUKING
 * (issue #1035, D-069, D-098).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  GRANICA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Do tej klasy przychodzi tożsamość, którą adapter dostawcy JUŻ SPRAWDZIŁ.
 * Protokół — `state`, PKCE, nonce, token Google, Graph API
 * i `appsecret_proof` Facebooka — zostaje w `KlientGoogle`, `KlientFacebook`
 * i w kontrolerach, osobno dla każdego dostawcy. Tak samo zostają tam
 * decyzje, które są RÓŻNE z powodów bezpieczeństwa: reguła 1 i propozycja
 * połączenia po adresie przy Google, odmowa po adresie i list o próbie przy
 * Facebooku. Ta klasa NIGDY nie łączy kont po samym adresie.
 *
 * Tu mieszka to, co było skopiowane do obu kontrolerów:
 *
 *   - bramki wejścia (konto zamknięte, konto obsługi serwisu, 2FA)
 *     w kolejności z `LoginController`, dziennik i regeneracja sesji;
 *   - zapis, odczyt, wygaśnięcie i czyszczenie tożsamości w sesji;
 *   - warunki połączenia i zapis powiązania pod `ZamekKonta`;
 *   - walidacja ekranu domknięcia i założenie konta.
 *
 * Różnice dostawców przychodzą przez `DostawcaWejscia` — nie ma tu ani
 * jednego `if google`.
 *
 * GDZIE TRAFIAJĄ POPRAWKI: reguła wspólna obu dróg (np. zachowanie danych
 * przy wygaśnięciu tożsamości, #850) — tutaj. Zachowanie właściwe Meta (np.
 * kolejność odebrania dostępu i ponownej zgody, #1025) — w adapterze
 * Facebooka (`FacebookLoginController`, `DostawcaWejsciaFacebook`).
 *
 * Odpowiedzi HTTP i zdania na ekranie układa kontroler: mówią „kontem Google"
 * albo „kontem Facebooka" i mają zostać słowo w słowo takie, jak były.
 */
final readonly class WejdzPrzezDostawce
{
    public function __construct(private DostawcaWejscia $dostawca) {}

    public function dostawca(): DostawcaWejscia
    {
        return $this->dostawca;
    }

    // ─────────────────────────── bramki wejścia ───────────────────────────

    /**
     * Czy to konto tą drogą NIE wejdzie — i dlaczego. `null` znaczy: bramki
     * przepuszczają.
     *
     * WARUNKIEM NIE JEST `isActive()` — tak samo jak przy haśle. Zawieszenie
     * jest karą „tylko do odczytu": konto żyje, treści są widoczne, nie da
     * się nic opublikować. Odmowa wejścia wywracałaby ten projekt — człowiek
     * nie zobaczyłby ani wiadomości od moderacji, ani terminu końca kary.
     *
     * KONTA OBSŁUGI SERWISU TĄ DROGĄ NIE WCHODZĄ (ten sam zakres co D-056).
     * Rolę sprawdzamy przy KAŻDYM wejściu, więc powiązanie zrobione przed
     * awansem przestaje działać z chwilą nadania roli.
     */
    public function odmowaWejscia(User $user): ?WynikWejscia
    {
        if (in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
            return WynikWejscia::KontoZamkniete;
        }

        if ($user->hasStaffRole()) {
            return WynikWejscia::KontoObslugi;
        }

        return null;
    }

    /**
     * Wejście na konto — jedna droga dla obu dostawców i wszystkich
     * przypadków w ich kontrolerach.
     *
     * Kolejność pytań jest ta sama co w `LoginController` i nie jest
     * przypadkowa: najpierw konto zamknięte, potem obsługa serwisu, potem
     * drugi składnik.
     */
    public function wpusc(Request $request, User $user): WynikWejscia
    {
        $odmowa = $this->odmowaWejscia($user);

        if ($odmowa !== null) {
            return $odmowa;
        }

        $this->dostawca->przedWejsciem($user);

        AuditLogEntry::record($this->dostawca->akcjaWejscia(), $user, $user, ip: $request->ip());

        /*
         * KONTO Z 2FA NIE WCHODZI TU DO KOŃCA. Dokładnie ta sama ścieżka co
         * po poprawnym haśle: w sesji ląduje SAM IDENTYFIKATOR konta, nie
         * zalogowana sesja. Dostawca zastępuje hasło, nie drugi składnik.
         */
        if ($user->hasTwoFactorConfirmed()) {
            $request->session()->regenerate();
            $request->session()->put(TwoFactorAuthenticator::oczekujaceLogowanie($user));

            return WynikWejscia::DrugiSkladnik;
        }

        $request->session()->regenerate();

        // `remember: true` jak przy haśle i przy linku e-mail: kto wchodzi
        // jednym kliknięciem, tym bardziej nie chce robić tego co tydzień.
        Auth::login($user, remember: true);

        return WynikWejscia::Wpuszczony;
    }

    // ─────────────────────────── łączenie konta ───────────────────────────

    /**
     * Czy to konto wolno POŁĄCZYĆ z tą tożsamością — pytanie zadawane dwa
     * razy: przy pokazaniu ekranu i przy zapisie.
     *
     * Wspólne warunki stoją tutaj; to, czym człowiek dowiódł, że konto jest
     * jego, różni się między dostawcami i przychodzi z
     * `DostawcaWejscia::dowodPolaczenia()`.
     */
    public function wolnoPolaczyc(User $user, TozsamoscOdDostawcy $tozsamosc): bool
    {
        return $this->dostawca->dowodPolaczenia($user, $tozsamosc)
            && ! $this->dostawca->maPolaczenie($user)
            && ! $user->hasStaffRole()
            && ! in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)
            // To konto u dostawcy nie może być w międzyczasie powiązane
            // z KIMŚ INNYM — inaczej zapis wpadłby na unikalne ograniczenie bazy.
            && $this->dostawca->kontoPowiazane($tozsamosc->identyfikator) === null;
    }

    /**
     * Zapis powiązania. `null`, gdy warunki przestały być spełnione.
     *
     * REWALIDACJA POD BLOKADĄ WIERSZA KONTA (`ZamekKonta`, D-079).
     *
     * Ekran mógł stać otwarty kilkanaście minut. W tym czasie konto mogło
     * zostać zablokowane, dostać rolę moderatora, zmienić adres, stracić
     * potwierdzenie adresu albo zostać połączone z sąsiedniej karty.
     * Sprawdzenie warunków i zapis muszą widzieć TEN SAM stan konta —
     * `$swiezy` to wiersz wczytany POD blokadą, więc pytania zadajemy jemu,
     * nie obiektowi z sesji.
     */
    public function polacz(Request $request, User $user, TozsamoscOdDostawcy $tozsamosc): ?User
    {
        return ZamekKonta::zablokuj($user, function (?User $swiezy) use ($request, $tozsamosc): ?User {
            if ($swiezy === null || ! $this->wolnoPolaczyc($swiezy, $tozsamosc)) {
                return null;
            }

            $this->dostawca->polacz($swiezy, $tozsamosc->identyfikator);

            AuditLogEntry::record(
                action: $this->dostawca->akcjaPolaczenia(),
                actor: $swiezy,
                subject: $swiezy,
                ip: $request->ip(),
            );

            return $swiezy;
        });
    }

    // ────────────────────────── domknięcie konta ──────────────────────────

    /**
     * Dane ekranu domknięcia: adres i PODPOWIEDZI (nie nadania) imienia
     * i nazwy — ze szkicu, jeśli człowiek już raz je wpisał (#850).
     *
     * @return array{email: string, proponowanaNazwa: string, proponowaneImie: string}
     */
    public function ekranDomkniecia(Request $request, TozsamoscOdDostawcy $tozsamosc): array
    {
        $draft = ExternalRegistrationDraft::restore($request, $this->dostawca->nazwa(), $tozsamosc->identyfikator);

        return [
            'email' => (string) $tozsamosc->email,
            'proponowanaNazwa' => $draft['username'] ?? $this->proponowanaNazwa($tozsamosc),
            'proponowaneImie' => $draft['display_name'] ?? $tozsamosc->imie,
        ];
    }

    /**
     * Tożsamość do założenia konta. Gdy wygasła albo nie ma adresu —
     * `null`, a wpisane imię i nazwa zostają w szkicu (#850), żeby po
     * powrocie tym samym kontem u dostawcy nie trzeba było ich pisać drugi raz.
     */
    public function tozsamoscDoZalozenia(Request $request): ?TozsamoscOdDostawcy
    {
        $poprzedniIdentyfikator = $request->session()
            ->get($this->kluczTozsamosci().'.'.$this->dostawca->poleIdentyfikatoraWSesji());
        $tozsamosc = $this->tozsamoscZSesji($request);

        if ($tozsamosc === null || $tozsamosc->email === null) {
            ExternalRegistrationDraft::remember($request, $this->dostawca->nazwa(), $poprzedniIdentyfikator);

            return null;
        }

        return $tozsamosc;
    }

    /**
     * Walidacja ekranu domknięcia — te same reguły i te same zdania co przy
     * rejestracji hasłem.
     *
     * @return array{display_name: string, username: string}
     */
    public function daneDomkniecia(Request $request): array
    {
        $minAge = (int) config('kuking.account.min_age');

        // NAZWĘ UKŁADAMY PRZED WALIDACJĄ, tak samo jak w rejestracji hasłem
        // — i z tego samego powodu bezpieczeństwa: normalizacja stoi PRZED
        // `ReservedUsername`, więc „ądmin" jest sprawdzane jako `admin`.
        $request->merge([
            'username' => NazwaUzytkownika::znormalizuj((string) $request->input('username', '')),
        ]);

        /** @var array{display_name: string, username: string} $dane */
        $dane = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:'.config('kuking.profil.dlugosc_nazwy')],
            'username' => [
                'required', 'string', 'min:3', 'max:40',
                'regex:'.NazwaUzytkownika::WZORZEC,
                new ReservedUsername,
                new UsernameNotTaken,
            ],
            /*
             * OŚWIADCZENIA SĄ WYMAGANE I NIE SĄ ZAZNACZONE Z GÓRY.
             *
             * Dostawca ich nie przekaże i nie wolno ich postawić za
             * człowieka: to ciemny wzorzec, a przy oświadczeniu o wieku
             * dodatkowo bez wartości — oświadczenie złożone przez serwer nie
             * jest niczyim oświadczeniem. `accepted` odrzuca brak pola, więc
             * ekran bez haczyków nie przejdzie.
             */
            'age_confirmed' => ['accepted'],
            'terms_accepted' => ['accepted'],
            /*
             * TURNSTILE TU NIE STOI I JEST TO DECYZJA, NIE PRZEOCZENIE
             * (D-069, sekcja o captchy).
             *
             * D-050 stawia Turnstile tam, gdzie automat wysyła formularz
             * PUBLICZNY i coś nas to kosztuje. Ten formularz publiczny nie
             * jest: żeby na niego wejść, trzeba przejść ekran zgody dostawcy
             * — bramkę mocniejszą od captchy i wcześniejszą od niej. Zostaje
             * limit zapytań (`limits.google_domkniecie`,
             * `limits.facebook_domkniecie`).
             */
        ], [
            'display_name.required' => 'Podaj imię, którym mamy Cię nazywać.',
            'username.required' => 'Wpisz nazwę, która ma być w adresie Twojego profilu — na przykład imię i miejscowość: basia z podkarpacia.',
            // Zdanie BEZ RODZAJU i w tym samym brzmieniu co przy rejestracji
            // hasłem (`RegisterController`) — docs/brand/COPY_STYLE.md §2.
            'username.regex' => 'Z tej nazwy nie da się ułożyć adresu. Wpisz imię albo imię i miejscowość, na przykład: basia z podkarpacia.',
            'age_confirmed.accepted' => "Kuking jest dla osób od {$minAge} lat. Potwierdź, że masz tyle lat.",
            'terms_accepted.accepted' => 'Zaznacz, że znasz zasady Kuking.',
        ]);

        return $dane;
    }

    /**
     * Zakłada konto i wpuszcza na nie. `null`, gdy w międzyczasie powstało
     * konto na ten adres albo to konto u dostawcy zostało powiązane gdzie
     * indziej — wtedy stan domknięcia jest już wyczyszczony.
     *
     * SPRAWDZAMY PONOWNIE, CZY TO WCIĄŻ JEST NOWE KONTO. Między powrotem od
     * dostawcy a wysłaniem formularza mija tyle czasu, ile człowiek
     * potrzebuje na przeczytanie regulaminu. Bez tego sprawdzenia zapis
     * wpadłby na unikalne ograniczenie bazy i człowiek zobaczyłby błąd serwera.
     *
     * ADRES JEST POTWIERDZONY DOKŁADNIE WTEDY, GDY POTWIERDZIŁ GO DOSTAWCA
     * (`TozsamoscOdDostawcy::$emailPotwierdzony`): przy Google tak (reguła 1
     * D-069), przy Facebooku nigdy (D-098) — konto przechodzi wtedy naszą
     * zwykłą ścieżkę potwierdzenia adresu.
     *
     * BEZ HASŁA: w `password` ląduje skrót wartości losowej, której nie zna
     * nikt, także my. Hasło człowiek ustawi przez „Nie pamiętam hasła".
     *
     * @param  array{display_name: string, username: string}  $dane
     */
    public function zalozKonto(
        Request $request,
        TozsamoscOdDostawcy $tozsamosc,
        array $dane,
        ZalozKonto $zalozKonto,
    ): ?ZalozoneKonto {
        if ($this->dostawca->kontoPowiazane($tozsamosc->identyfikator) !== null
            || User::where('email', $tozsamosc->email)->exists()) {
            ExternalRegistrationDraft::forget($request, $this->dostawca->nazwa());
            $this->zapomnij($request);

            return null;
        }

        // Awarie po zatwierdzeniu konta idą do `report()` w `ZalozKonto`
        // i nie dają 500 (#1373).
        // Argumenty nazwane w jednej tablicy, bo powiązanie (`googleSub`
        // albo `facebookId`) wybiera dostawca — PHP nie przyjmuje
        // rozpakowania po argumentach nazwanych.
        $konto = $zalozKonto->handle(...[
            'email' => (string) $tozsamosc->email,
            'displayName' => $dane['display_name'],
            'username' => $dane['username'],
            'haslo' => null,
            'emailPotwierdzony' => $tozsamosc->emailPotwierdzony,
            'ip' => $request->ip(),
            'dziennik' => ['droga' => $this->dostawca->nazwa()],
            ...$this->dostawca->powiazanieNowegoKonta($tozsamosc->identyfikator),
        ]);

        ExternalRegistrationDraft::forget($request, $this->dostawca->nazwa());
        $this->zapomnij($request);

        Auth::login($konto->user, remember: true);
        $request->session()->regenerate();

        return $konto;
    }

    // ─────────────────────────── stan w sesji ───────────────────────────

    /**
     * W SESJI LEŻY MINIMUM I LEŻY KRÓTKO.
     *
     * Sesja jest po naszej stronie (sterownik bazy), więc nie jest to dana
     * wystawiona człowiekowi — ale jest to ROZPOZNANA TOŻSAMOŚĆ, na którą da
     * się założyć konto albo dołożyć drogę wejścia do istniejącego. Dlatego
     * zapisujemy chwilę zapisu i sprawdzamy ją przy odczycie: ekran
     * domknięcia porzucony na cudzym komputerze nie ma prawa być tam ważny
     * nazajutrz. Imię służy raz, jako podpowiedź na ekranie.
     *
     * Potwierdzenia adresu NIE zapisujemy — przy odczycie wynika ono
     * z dostawcy. Dlatego tożsamość, której potwierdzenie różni się od tego,
     * co dostawca gwarantuje, nie ma prawa tu trafić: przy Google do sesji
     * idzie wyłącznie tożsamość po regule 1, przy Facebooku nigdy
     * „potwierdzona".
     */
    public function zapamietaj(Request $request, TozsamoscOdDostawcy $tozsamosc): void
    {
        if ($tozsamosc->emailPotwierdzony !== $this->dostawca->potwierdzaAdres()) {
            throw new LogicException('Do sesji trafia tylko tożsamość o potwierdzeniu adresu gwarantowanym przez dostawcę.');
        }

        $request->session()->put($this->kluczTozsamosci(), [
            $this->dostawca->poleIdentyfikatoraWSesji() => $tozsamosc->identyfikator,
            'email' => $tozsamosc->email,
            'imie' => $tozsamosc->imie,
            'od' => now()->getTimestamp(),
        ]);
    }

    /** Tożsamość z sesji albo `null` — brak, wygaśnięcie, niepełne dane. */
    public function tozsamoscZSesji(Request $request): ?TozsamoscOdDostawcy
    {
        $dane = $request->session()->get($this->kluczTozsamosci());

        if (! is_array($dane)) {
            return null;
        }

        $od = (int) ($dane['od'] ?? 0);

        if ($od === 0 || Carbon::createFromTimestamp($od)
            ->addMinutes($this->dostawca->waznoscDomknieciaMinut())
            ->isPast()) {
            $this->zapomnij($request);

            return null;
        }

        $identyfikator = (string) ($dane[$this->dostawca->poleIdentyfikatoraWSesji()] ?? '');
        // Pusty adres wraca jako `null`, a nie jako pusty napis — ta sama
        // umowa co w `KlientFacebook`, żeby nie dało się przejść dalej
        // z „adresem", którego nie ma.
        $email = (string) ($dane['email'] ?? '');
        $email = $email === '' ? null : $email;

        // Dostawca, który potwierdza adres, bez adresu nie ma tu czego szukać.
        if ($identyfikator === '' || ($this->dostawca->potwierdzaAdres() && $email === null)) {
            return null;
        }

        return new TozsamoscOdDostawcy(
            identyfikator: $identyfikator,
            email: $email,
            emailPotwierdzony: $this->dostawca->potwierdzaAdres(),
            imie: (string) ($dane['imie'] ?? ''),
        );
    }

    /** Konto wskazane do połączenia na ekranie potwierdzenia (Google, reguła 3). */
    public function zapamietajKonto(Request $request, User $user): void
    {
        $request->session()->put($this->kluczKonta(), $user->getKey());
    }

    public function kontoZSesji(Request $request): ?User
    {
        $id = $request->session()->get($this->kluczKonta());

        return is_string($id) ? User::find($id) : null;
    }

    public function zapomnij(Request $request): void
    {
        $request->session()->forget([$this->kluczTozsamosci(), $this->kluczKonta()]);
    }

    // ─────────────────────────────── reszta ───────────────────────────────

    /**
     * Nazwa do PODPOWIEDZENIA w polu „nazwa w adresie profilu".
     *
     * Kolejność źródeł: imię od dostawcy, a gdy go nie ma — początek adresu
     * e-mail. Adres jest ostatni, bo nazwa profilu jest publiczna, a część
     * adresów to imię z nazwiskiem i rokiem urodzenia; imię jest lepszą
     * podpowiedzią i mniej zdradza. Puste, gdy nie da się ułożyć nic — wtedy
     * człowiek wpisuje swoje, tak jak przy rejestracji hasłem.
     */
    private function proponowanaNazwa(TozsamoscOdDostawcy $tozsamosc): string
    {
        foreach ([$tozsamosc->imie, Str::before((string) $tozsamosc->email, '@')] as $zrodlo) {
            $propozycja = NazwaUzytkownika::wolnaPropozycja((string) $zrodlo);

            if ($propozycja !== null) {
                return $propozycja;
            }
        }

        return '';
    }

    private function kluczTozsamosci(): string
    {
        return 'wejscie_'.$this->dostawca->nazwa().'.tozsamosc';
    }

    private function kluczKonta(): string
    {
        return 'wejscie_'.$this->dostawca->nazwa().'.konto';
    }
}

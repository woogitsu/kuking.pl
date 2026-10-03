<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Security\Actions\WlaczDwuetapowa;
use App\Domain\Security\Actions\WygenerujNoweKodyZapasowe;
use App\Domain\Security\Actions\WylaczDwuetapowa;
use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Security\WynikNowychKodowZapasowych;
use App\Domain\Security\WynikWlaczeniaDwuetapowej;
use App\Domain\Users\Actions\ZmianaHaslaWymagaPonownegoLogowania;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NoweKodyZapasoweRequest;
use App\Http\Requests\Settings\WlaczenieDwuetapowejRequest;
use App\Http\Requests\Settings\WylaczenieDwuetapowejRequest;
use App\Support\Komunikat;
use App\Support\Sesja\GeneracjaSesji;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use LogicException;

/**
 * Włączanie i wyłączanie weryfikacji dwuetapowej na koncie (issue #12).
 *
 * Dostępne dla KAŻDEGO zalogowanego — dla moderatora i admina jest
 * obowiązkowa (blokada `/admin/**` bez niej, patrz middleware
 * `EnsureModeratorHasTwoFactor`), dla zwykłego użytkownika zostaje
 * opcjonalna: wymuszanie jej na wszystkich zamknęłoby konto na zawsze
 * komuś, kto zgubi telefon i nigdy nie zapisał kodów zapasowych.
 */
class TwoFactorSettingsController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        $wlaczone = $user->hasTwoFactorConfirmed();

        return view('pages.settings.two_factor.index', [
            'wlaczone' => $wlaczone,
            // Tylko liczba, tylko własnego konta (trasa za `auth`); skróty
            // kodów nie opuszczają modelu (#2575).
            'pozostaleKody' => $wlaczone ? $user->pozostaleKodyZapasowe() : null,
            // Rozmiar nowego kompletu pochodzi z tej samej konfiguracji,
            // z której korzysta generator kodów — bez liczby w tekście (#2666).
            'liczbaKodow' => max(1, (int) config('kuking.two_factor.recovery_codes')),
        ]);
    }

    /**
     * Ekran włączenia: kod QR ORAZ sekret przepisany tekstem — nie każdy
     * zeskanuje kod, a część osób woli (albo musi) wpisać go ręcznie.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        // ŻĄDANIE GET NIE MOŻE ZDJĄĆ DZIAŁAJĄCEJ 2FA (audyt W4-02).
        //
        // Wcześniej stał tu warunek `|| $user->hasTwoFactorConfirmed()`, więc
        // samo WEJŚCIE na ten adres przy włączonej 2FA wołało
        // `beginTwoFactorSetup()`: podmieniało sekret i zerowało
        // `two_factor_confirmed_at`, kody zapasowe oraz znacznik ostatniego
        // użycia. Drugi składnik przestawał działać, zanim ktokolwiek
        // potwierdził nowy — bez hasła, bez POST-a, bez tokenu CSRF.
        //
        // Wystarczyło kliknąć link. Dla konta moderatora oznaczało to
        // natychmiastową utratę dostępu do `/admin`, bo `moderator.2fa` widzi
        // konto jako niepotwierdzone. Do tego żądania GET są przepuszczane
        // kontom zawieszonym, więc ta jedna ścieżka omijała także tryb
        // „tylko do odczytu".
        //
        // Zmiana drugiego składnika idzie teraz przez wyłączenie, które JEST
        // POST-em i prosi o hasło. To jedno kliknięcie więcej dla czynności
        // wykonywanej raz na kilka lat — i żadnej drogi, w której samo wejście
        // na stronę zdejmuje zabezpieczenie.
        if ($user->hasTwoFactorConfirmed()) {
            return $this->juzWlaczone();
        }

        // Sekret zapisujemy PRZY WEJŚCIU na ten ekran, nie dopiero po
        // potwierdzeniu kodem — inaczej odświeżenie strony (albo powrót do
        // niej za chwilę, żeby dokończyć skanowanie) pokazywałoby INNY sekret
        // i INNY kod QR niż ten, który człowiek już zeskanował.
        //
        // Tu jesteśmy wyłącznie wtedy, gdy 2FA NIE jest potwierdzone, więc nie
        // ma czego zepsuć: albo zaczynamy od zera, albo wracamy do przerwanego
        // ustawiania i pokazujemy ten sam sekret co poprzednio.
        //
        // „NULL" wyżej to jednak odczyt z POCZĄTKU żądania (#2061). Druga
        // karta mogła w tym czasie zapisać własny sekret, a właściciel już go
        // zeskanować i potwierdzić. Dlatego zapis rozstrzyga świeży wiersz pod
        // blokadą konta, a `$user` wraca z niego z tym, co jest w bazie: tym
        // samym sekretem co w drugiej karcie albo potwierdzonym 2FA.
        //
        // Świeży stan czytamy też wtedy, gdy na początku żądania sekret już
        // BYŁ, tylko niepotwierdzony: druga karta mogła go w tym czasie
        // potwierdzić, a wtedy ten sam kod QR pokazany jeszcze raz wyglądałby
        // na „dokończ włączanie" przy 2FA, która już chroni konto. Przy
        // istniejącym sekrecie metoda niczego nie zapisuje, tylko odświeża.
        $user->beginTwoFactorSetupIfNotStarted($this->totp->generateSecret());

        if ($user->hasTwoFactorConfirmed()) {
            return $this->juzWlaczone();
        }

        $otpAuthUri = $this->totp->otpAuthUri($user, $user->two_factor_secret);

        return view('pages.settings.two_factor.enable', [
            'sekret' => $user->two_factor_secret,
            'qr' => $this->totp->qrCodeSvg($otpAuthUri),
        ]);
    }

    private function juzWlaczone(): RedirectResponse
    {
        return redirect()->route('settings.two_factor.edit')->with(Komunikat::informacja('Weryfikacja dwuetapowa jest już włączona. Żeby ustawić ją na nowym telefonie, '
            .'najpierw ją wyłącz — poprosimy o hasło.',
        ));
    }

    /**
     * Potwierdzenie: dopiero POPRAWNY kod z aplikacji włącza 2FA naprawdę.
     * Bez tego kroku literówka przy przepisywaniu sekretu zablokowałaby
     * konto pierwszym prawdziwym logowaniem, zamiast na samym ekranie
     * włączenia, gdzie łatwo spróbować jeszcze raz.
     *
     * HASŁO JAK PRZY WYŁĄCZANIU (#1376, D-245). Kod z aplikacji dowodzi
     * tylko tego, że NOWY telefon jest dobrze ustawiony — nie tego, że sesję
     * obsługuje właściciel konta. Kto przejął otwartą sesję, podpinał więc
     * własny telefon, zabierał kody zapasowe, a właściciel przy następnym
     * logowaniu stawał przed kodem, którego nie ma. Włączenie przypisuje
     * kontu drugi składnik, więc waży tyle co jego zdjęcie i prosi o to samo.
     *
     * Hasło sprawdzamy PRZED kodem: przy złym haśle kod nie jest ani
     * sprawdzany, ani zużywany, a 2FA zostaje wyłączona.
     */
    public function confirm(WlaczenieDwuetapowejRequest $request, WlaczDwuetapowa $wlacz): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validated();

        try {
            $wynik = $wlacz->handle(
                $user, $data['password'], $data['code'], $request->ip(),
                (int) $request->session()->get(GeneracjaSesji::KLUCZ, 0),
                $request->session()->getId(),
            );
        } catch (ZmianaHaslaWymagaPonownegoLogowania $e) {
            return $this->odmowStarejSesji($request, $e);
        }

        switch ($wynik->status) {
            case WynikWlaczeniaDwuetapowej::BRAK_SEKRETU:
            case WynikWlaczeniaDwuetapowej::PRZEGRANA_Z_DRUGA_KARTA:
                return redirect()->route('settings.two_factor.enable');
            case WynikWlaczeniaDwuetapowej::JUZ_WLACZONE:
                return $this->juzWlaczone();
            case WynikWlaczeniaDwuetapowej::ZLE_HASLO:
                throw ValidationException::withMessages([
                    'password' => 'Wpisz ponownie hasło do Kuking. Jeśli go nie pamiętasz, skorzystaj z instrukcji przy formularzu.',
                ]);
            case WynikWlaczeniaDwuetapowej::ZLY_KOD:
                throw ValidationException::withMessages([
                    'code' => 'Kod jest nieprawidłowy. Sprawdź, czy godzina w telefonie jest ustawiona poprawnie, i spróbuj ponownie.',
                ]);
        }

        // STARE POŚWIADCZENIA JEDNOSKŁADNIKOWE GASNĄ (#930, D-245).
        //
        // Sesje i ciasteczka „zapamiętaj mnie" sprzed tej chwili powstały bez
        // drugiego składnika. Zostawione, dalej otwierałyby konto bez kodu —
        // a konto moderatora od tej sekundy także `/admin`, bo
        // `moderator.2fa` sprawdza stan konta, nie przebieg logowania.
        // Bieżąca sesja zostaje: to w niej właściciel właśnie podał hasło
        // i kod. Zły kod albo złe hasło kończą się wyjątkiem wyżej, więc
        // niczego nie odwołują.
        // Kod padł właśnie w tej sesji, więc moderator wchodzi do panelu bez
        // ponownego logowania (`moderator.2fa`, #930).
        $request->session()->put(TwoFactorAuthenticator::dowodSesji($user->refresh()));

        // Kody zapasowe idą do sesji TYLKO na ten jeden, następny widok
        // (`->with()` = flash na jedno żądanie) — to jest jedyny moment,
        // w którym serwis w ogóle zna ich jawną treść.
        return redirect()->route('settings.two_factor.codes')->with('kody_zapasowe', $wynik->kodyJawne);
    }

    /**
     * Kody zapasowe — pokazane RAZ. Odświeżenie tej strony ich już nie
     * pokaże (flash z poprzedniego żądania wygasł). Flash chroni ponowne
     * pobranie z serwera; no-store zabrania przechowywania odpowiedzi.
     * Historię mierzy scripts/fixtures/ustawienia-2fa-historia.mjs.
     */
    public function codes(Request $request): Response|RedirectResponse
    {
        $kody = $request->session()->get('kody_zapasowe');

        if (! is_array($kody)) {
            // Komunikat mówi, CO ZROBIĆ, a nie tylko co się nie udało —
            // i kieruje na przycisk „Wygeneruj nowe kody zapasowe", nie na
            // wyłączanie i włączanie 2FA od zera. Tamta droga zdejmowała
            // ochronę z konta na czas przeklikania i kazała przepisywać
            // sekret do telefonu jeszcze raz, choć z sekretem nic nie było
            // nie tak.
            return redirect()->route('settings.two_factor.edit')->with(Komunikat::informacja('Kody zapasowe pokazujemy tylko raz. Jeśli nie masz ich już pod ręką, '
                .'wygeneruj nowy komplet przyciskiem niżej — stare przestaną wtedy działać.',
            ));
        }

        return response()->view('pages.settings.two_factor.codes', ['kody' => $kody])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Nowy komplet kodów zapasowych, BEZ zdejmowania 2FA i bez ruszania
     * sekretu (czyli bez przepisywania go do telefonu jeszcze raz).
     *
     * Hasło jak przy wyłączaniu: kody zapasowe omijają aplikację w telefonie,
     * więc świeży komplet w rękach kogoś, kto akurat siedzi przy otwartej
     * sesji, jest wart dokładnie tyle co zdjęcie 2FA.
     *
     * SMTP w tym serwisie jeszcze nie działa, więc nie ma linku odzyskiwania
     * mailem. Utrata telefonu RAZEM z kodami zamyka konto do czasu wejścia
     * na serwer (`kuking:2fa-wylacz`) — dlatego droga do nowych kodów musi
     * być łatwa, dopóki człowiek ma jeszcze dostęp.
     */
    public function regenerateCodes(NoweKodyZapasoweRequest $request, WygenerujNoweKodyZapasowe $nowe): RedirectResponse
    {
        $data = $request->validated();

        $wynik = $nowe->handle($request->user(), $data['password']);

        // Flash z kodami dopiero PO zatwierdzeniu transakcji: nieudany commit
        // kończy się wyjątkiem, zanim jawne kody trafią do sesji.
        switch ($wynik->status) {
            case WynikNowychKodowZapasowych::ZLE_HASLO:
                throw ValidationException::withMessages([
                    'password' => 'Wpisz ponownie hasło do Kuking. Jeśli go nie pamiętasz, skorzystaj z instrukcji przy formularzu.',
                ])->errorBag('regenerate');
            case WynikNowychKodowZapasowych::ZAPISANE:
                return redirect()->route('settings.two_factor.codes')->with('kody_zapasowe', $wynik->kodyJawne);
            case WynikNowychKodowZapasowych::ZMIENIONE:
                return redirect()->route('settings.two_factor.edit')->with(Komunikat::blad('Nowe kody zapasowe powstały przed chwilą w innym oknie lub karcie. Zapisz kody z tamtego ekranu. '
                    .'Jeśli ich nie masz, kliknij „Wygeneruj nowe kody” jeszcze raz — poprzednie przestaną wtedy działać.',
                ));
            default:
                return redirect()->route('settings.two_factor.edit');
        }
    }

    /**
     * Wyłączenie wymaga hasła — 2FA chroni konto, więc jego zdjęcie nie
     * może być jednym kliknięciem kogoś, kto akurat siedzi przy otwartej
     * sesji w przeglądarce.
     */
    public function disable(WylaczenieDwuetapowejRequest $request, WylaczDwuetapowa $wylacz): RedirectResponse
    {
        $data = $request->validated();

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'Wpisz ponownie hasło do Kuking. Jeśli go nie pamiętasz, skorzystaj z instrukcji przy formularzu.',
            ])->errorBag('disable');
        }

        try {
            $wylacz->handle(
                $request->user(), $data['password'],
                (int) $request->session()->get(GeneracjaSesji::KLUCZ, 0),
                $request->session()->getId(), $request->ip(),
            );
        } catch (ZmianaHaslaWymagaPonownegoLogowania $e) {
            return $this->odmowStarejSesji($request, $e);
        }

        // WYŁĄCZENIE ZAMYKA INNE URZĄDZENIA JAK WŁĄCZENIE (#930).
        //
        // Zmiana drugiego składnika jest zmianą zabezpieczeń konta tej samej
        // wagi co zmiana hasła: inne przeglądarki i ciasteczka „zapamiętaj
        // mnie" muszą zalogować się od nowa, na nowych zasadach. Bieżąca sesja
        // zostaje — to w niej właściciel właśnie podał hasło. Jej dowód 2FA
        // traci sens, bo 2FA już nie ma.
        $request->session()->forget(TwoFactorAuthenticator::KLUCZ_DOWODU_SESJI);

        return redirect()->route('settings.two_factor.edit')
            ->with(Komunikat::sukces('Weryfikacja dwuetapowa jest wyłączona.'));
    }

    private function odmowStarejSesji(Request $request, ZmianaHaslaWymagaPonownegoLogowania $e): RedirectResponse
    {
        $guard = Auth::guard('web');
        if (! $guard instanceof SessionGuard) {
            throw new LogicException('Web guard musi obsługiwać wylogowanie bieżącego urządzenia.');
        }

        $guard->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['login' => $e->getMessage()]);
    }
}

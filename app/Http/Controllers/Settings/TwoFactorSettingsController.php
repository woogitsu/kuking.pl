<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Users\ZamekKonta;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

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
        return view('pages.settings.two_factor.index', [
            'wlaczone' => $request->user()->hasTwoFactorConfirmed(),
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
        if ($user->two_factor_secret === null) {
            $user->beginTwoFactorSetupIfNotStarted($this->totp->generateSecret());

            if ($user->hasTwoFactorConfirmed()) {
                return $this->juzWlaczone();
            }
        }

        $otpAuthUri = $this->totp->otpAuthUri($user, $user->two_factor_secret);

        return view('pages.settings.two_factor.enable', [
            'sekret' => $user->two_factor_secret,
            'qr' => $this->totp->qrCodeSvg($otpAuthUri),
        ]);
    }

    private function juzWlaczone(): RedirectResponse
    {
        return redirect()->route('settings.two_factor.edit')->with(
            'status',
            'Weryfikacja dwuetapowa jest już włączona. Żeby ustawić ją na nowym telefonie, '
            .'najpierw ją wyłącz — poprosimy o hasło.',
        );
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
    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'code' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], [
            'code.required' => 'Wpisz sześciocyfrowy kod z aplikacji.',
            'password.required' => 'Wpisz hasło do Kuking, żeby włączyć weryfikację dwuetapową.',
        ]);

        if ($user->two_factor_secret === null) {
            return redirect()->route('settings.two_factor.enable');
        }

        // Stary formularz włączenia przy JUŻ włączonej 2FA: bez sprawdzania
        // i zużywania kodu, bez nowego kompletu kodów zapasowych (#2061).
        if ($user->hasTwoFactorConfirmed()) {
            return $this->juzWlaczone();
        }

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Wpisz ponownie hasło do Kuking. Jeśli go nie pamiętasz, skorzystaj z instrukcji przy formularzu.',
            ]);
        }

        $sekret = $user->two_factor_secret;

        if (! $this->totp->verifyCode($user, $sekret, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Kod jest nieprawidłowy. Sprawdź, czy godzina w telefonie jest ustawiona poprawnie, i spróbuj ponownie.',
            ]);
        }

        // POTWIERDZENIE ROZSTRZYGA ŚWIEŻY WIERSZ POD BLOKADĄ KONTA (#2061).
        //
        // `$user` wczytano na początku żądania. Druga karta mogła w tym czasie
        // potwierdzić 2FA i pokazać kody zapasowe, które człowiek właśnie
        // przepisuje. Dotąd ten zapis wymieniał je na nowy komplet — kartka
        // przestawała działać, zanim ktokolwiek się o tym dowiedział. Pod
        // blokadą sprawdzamy więc jeszcze raz: sekret ten sam, który
        // zweryfikował kod, i 2FA wciąż niepotwierdzone. Inaczej nic nie
        // zapisujemy. Nowy komplet przy WŁĄCZONEJ 2FA daje tylko
        // „Wygeneruj nowe kody zapasowe" (`regenerateCodes()`).
        //
        // Skróty liczymy PRZED blokadą — bcrypt dziesięć razy nie ma czego
        // szukać w transakcji, która trzyma wiersz konta.
        $kodyJawne = $this->totp->generateBackupCodes();
        $skroty = $this->totp->hashBackupCodes($kodyJawne);

        $wlaczono = ZamekKonta::zablokuj($user, static function (?User $swiezy) use ($sekret, $skroty): bool {
            if ($swiezy === null || $swiezy->two_factor_secret !== $sekret || $swiezy->hasTwoFactorConfirmed()) {
                return false;
            }

            $swiezy->confirmTwoFactor($skroty);

            return true;
        });

        $user->refresh();

        if (! $wlaczono) {
            return $user->hasTwoFactorConfirmed()
                ? $this->juzWlaczone()
                : redirect()->route('settings.two_factor.enable');
        }

        AuditLogEntry::recordBezWywracania('account.two_factor_enabled', $user, $user, ip: $request->ip());

        // STARE POŚWIADCZENIA JEDNOSKŁADNIKOWE GASNĄ (#930, D-245).
        //
        // Sesje i ciasteczka „zapamiętaj mnie" sprzed tej chwili powstały bez
        // drugiego składnika. Zostawione, dalej otwierałyby konto bez kodu —
        // a konto moderatora od tej sekundy także `/admin`, bo
        // `moderator.2fa` sprawdza stan konta, nie przebieg logowania.
        // Bieżąca sesja zostaje: to w niej właściciel właśnie podał hasło
        // i kod. Zły kod albo złe hasło kończą się wyjątkiem wyżej, więc
        // niczego nie odwołują.
        $user->invalidateSessions($request->session()->getId());

        // Kod padł właśnie w tej sesji, więc moderator wchodzi do panelu bez
        // ponownego logowania (`moderator.2fa`, #930).
        $request->session()->put(TwoFactorAuthenticator::dowodSesji($user->refresh()));

        // Kody zapasowe idą do sesji TYLKO na ten jeden, następny widok
        // (`->with()` = flash na jedno żądanie) — to jest jedyny moment,
        // w którym serwis w ogóle zna ich jawną treść.
        return redirect()->route('settings.two_factor.codes')->with('kody_zapasowe', $kodyJawne);
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
            return redirect()->route('settings.two_factor.edit')->with(
                'status',
                'Kody zapasowe pokazujemy tylko raz. Jeśli nie masz ich już pod ręką, '
                .'wygeneruj nowy komplet przyciskiem niżej — stare przestaną wtedy działać.',
            );
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
    public function regenerateCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validateWithBag('regenerate', [
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Wpisz hasło do Kuking, żeby dostać nowe kody zapasowe.',
        ]);

        if (! $user->hasTwoFactorConfirmed()) {
            return redirect()->route('settings.two_factor.edit');
        }

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Wpisz ponownie hasło do Kuking. Jeśli go nie pamiętasz, skorzystaj z instrukcji przy formularzu.',
            ])->errorBag('regenerate');
        }

        // DWIE KARTY, DWA KOMPLETY (#2057).
        //
        // Dwa nakładające się żądania pokazywały dwa różne komplety, a działał
        // tylko ten zapisany jako ostatni. Kto przepisał kody z pierwszej
        // karty, miał kartkę, która nie otwiera konta — i dowiadywał się o tym
        // dopiero po zgubieniu telefonu.
        //
        // Migawka to zaszyfrowany komplet z chwili, w której żądanie wczytało
        // konto. Pod blokadą wiersza porównujemy ją ze stanem bieżącym: inny
        // komplet znaczy, że w trakcie tego żądania ktoś (druga karta) już
        // zapisał nowe kody. Wtedy niczego nie nadpisujemy i niczego nie
        // pokazujemy — komplet z tamtej karty zostaje ważny. Sama blokada
        // by nie wystarczyła: kolejkuje, ale drugie żądanie po wejściu i tak
        // nadpisałoby komplet, który pierwsza karta właśnie pokazała.
        $migawka = $user->getRawOriginal('two_factor_backup_codes');

        // Skróty liczymy PRZED blokadą — bcrypt kilka razy nie ma czego
        // szukać w transakcji, która trzyma wiersz konta.
        $kodyJawne = $this->totp->generateBackupCodes();
        $skroty = $this->totp->hashBackupCodes($kodyJawne);

        $wynik = ZamekKonta::zablokuj($user, static function (?User $swiezy) use ($migawka, $skroty): string {
            if ($swiezy === null || ! $swiezy->hasTwoFactorConfirmed()) {
                return 'wylaczone';
            }

            if ($swiezy->getRawOriginal('two_factor_backup_codes') !== $migawka) {
                return 'zmienione';
            }

            $swiezy->replaceTwoFactorBackupCodes($skroty);

            return 'zapisane';
        });

        $user->refresh();

        // Flash z kodami dopiero PO zatwierdzeniu transakcji: nieudany commit
        // kończy się wyjątkiem, zanim jawne kody trafią do sesji.
        return match ($wynik) {
            'zapisane' => redirect()->route('settings.two_factor.codes')->with('kody_zapasowe', $kodyJawne),
            'zmienione' => redirect()->route('settings.two_factor.edit')->with(
                'status',
                'Nowe kody zapasowe powstały przed chwilą w innym oknie lub karcie. Zapisz kody z tamtego ekranu. '
                .'Jeśli ich nie masz, kliknij „Wygeneruj nowe kody” jeszcze raz — poprzednie przestaną wtedy działać.',
            ),
            default => redirect()->route('settings.two_factor.edit'),
        };
    }

    /**
     * Wyłączenie wymaga hasła — 2FA chroni konto, więc jego zdjęcie nie
     * może być jednym kliknięciem kogoś, kto akurat siedzi przy otwartej
     * sesji w przeglądarce.
     */
    public function disable(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('disable', [
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Wpisz hasło do Kuking, żeby wyłączyć weryfikację dwuetapową.',
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'Wpisz ponownie hasło do Kuking. Jeśli go nie pamiętasz, skorzystaj z instrukcji przy formularzu.',
            ])->errorBag('disable');
        }

        $user = $request->user();
        $byloWlaczone = $user->hasTwoFactorConfirmed();
        $user->disableTwoFactor();
        if ($byloWlaczone) {
            AuditLogEntry::recordBezWywracania('account.two_factor_disabled', $user, $user, ip: $request->ip());
        }

        // WYŁĄCZENIE ZAMYKA INNE URZĄDZENIA JAK WŁĄCZENIE (#930).
        //
        // Zmiana drugiego składnika jest zmianą zabezpieczeń konta tej samej
        // wagi co zmiana hasła: inne przeglądarki i ciasteczka „zapamiętaj
        // mnie" muszą zalogować się od nowa, na nowych zasadach. Bieżąca sesja
        // zostaje — to w niej właściciel właśnie podał hasło. Jej dowód 2FA
        // traci sens, bo 2FA już nie ma.
        $request->user()->invalidateSessions($request->session()->getId());
        $request->session()->forget(TwoFactorAuthenticator::KLUCZ_DOWODU_SESJI);

        return redirect()->route('settings.two_factor.edit')
            ->with('status', 'Weryfikacja dwuetapowa jest wyłączona.');
    }
}

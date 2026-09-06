<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function create(Request $request): View
    {
        $user = $request->user();

        // Sekret zapisujemy PRZY WEJŚCIU na ten ekran, nie dopiero po
        // potwierdzeniu kodem — inaczej odświeżenie strony (albo powrót do
        // niej za chwilę, żeby dokończyć skanowanie) pokazywałoby INNY sekret
        // i INNY kod QR niż ten, który człowiek już zeskanował.
        //
        // Jeśli 2FA jest już włączone, a ktoś mimo to wraca na ten ekran
        // (np. cofnięciem w przeglądarce), zaczynamy od nowa świeżym
        // sekretem — nie pokazujemy ponownie sekretu KONTA JUŻ AKTYWNEGO.
        if ($user->two_factor_secret === null || $user->hasTwoFactorConfirmed()) {
            $user->beginTwoFactorSetup($this->totp->generateSecret());
            $user->refresh();
        }

        $otpAuthUri = $this->totp->otpAuthUri($user, $user->two_factor_secret);

        return view('pages.settings.two_factor.enable', [
            'sekret' => $user->two_factor_secret,
            'qr' => $this->totp->qrCodeSvg($otpAuthUri),
        ]);
    }

    /**
     * Potwierdzenie: dopiero POPRAWNY kod z aplikacji włącza 2FA naprawdę.
     * Bez tego kroku literówka przy przepisywaniu sekretu zablokowałaby
     * konto pierwszym prawdziwym logowaniem, zamiast na samym ekranie
     * włączenia, gdzie łatwo spróbować jeszcze raz.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'code' => ['required', 'string'],
        ], [
            'code.required' => 'Wpisz sześciocyfrowy kod z aplikacji.',
        ]);

        if ($user->two_factor_secret === null) {
            return redirect()->route('settings.two_factor.enable');
        }

        if (! $this->totp->verifyCode($user, $user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Kod jest nieprawidłowy. Sprawdź, czy godzina w telefonie jest ustawiona poprawnie, i spróbuj ponownie.',
            ]);
        }

        $kodyJawne = $this->totp->generateBackupCodes();
        $user->confirmTwoFactor($this->totp->hashBackupCodes($kodyJawne));

        // Kody zapasowe idą do sesji TYLKO na ten jeden, następny widok
        // (`->with()` = flash na jedno żądanie) — to jest jedyny moment,
        // w którym serwis w ogóle zna ich jawną treść.
        return redirect()->route('settings.two_factor.codes')->with('kody_zapasowe', $kodyJawne);
    }

    /**
     * Kody zapasowe — pokazane RAZ. Odświeżenie tej strony ich już nie
     * pokaże (flash z poprzedniego żądania wygasł), co jest zamierzone:
     * mają trafić na kartkę teraz, nie zostawać w historii przeglądarki.
     */
    public function codes(Request $request): View|RedirectResponse
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

        return view('pages.settings.two_factor.codes', ['kody' => $kody]);
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

        $data = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Wpisz swoje hasło, żeby dostać nowe kody zapasowe.',
        ]);

        if (! $user->hasTwoFactorConfirmed()) {
            return redirect()->route('settings.two_factor.edit');
        }

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Hasło jest nieprawidłowe.',
            ]);
        }

        $kodyJawne = $this->totp->generateBackupCodes();
        $user->replaceTwoFactorBackupCodes($this->totp->hashBackupCodes($kodyJawne));

        return redirect()->route('settings.two_factor.codes')->with('kody_zapasowe', $kodyJawne);
    }

    /**
     * Wyłączenie wymaga hasła — 2FA chroni konto, więc jego zdjęcie nie
     * może być jednym kliknięciem kogoś, kto akurat siedzi przy otwartej
     * sesji w przeglądarce.
     */
    public function disable(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'Wpisz swoje hasło, żeby wyłączyć weryfikację dwuetapową.',
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'Hasło jest nieprawidłowe.',
            ]);
        }

        $request->user()->disableTwoFactor();

        return redirect()->route('settings.two_factor.edit')
            ->with('status', 'Weryfikacja dwuetapowa jest wyłączona.');
    }
}

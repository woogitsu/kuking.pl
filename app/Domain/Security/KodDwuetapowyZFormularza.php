<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Drugi składnik na publicznym formularzu z hasłem, który NIE jest
 * logowaniem: cofnięcie usunięcia konta (#1314) i odwołanie od decyzji
 * osoby, która nie może się zalogować (#2272, audyt S-04).
 *
 * Konto z włączonym kodem z aplikacji nie loguje się samym hasłem
 * (`LoginController` → `TwoFactorChallengeController`), więc samym hasłem
 * nie wolno mu też niczego zrobić obok logowania. Do 30.09.2026 formularz
 * odwołania przyjmował samo hasło — kto znał hasło z wycieku, składał
 * odwołanie w imieniu zbanowanego konta z 2FA i dowiadywał się, że hasło
 * jest dobre. Wydzielone z `AccountDeletionController` bez zmiany zasad:
 *
 *  - jedno pole: same cyfry idą do `verifyCode()`, reszta do kodów
 *    zapasowych (`XXXXX-XXXXX`);
 *  - próba liczona PRZED sprawdzeniem, atomowo (#2043), w TYM SAMYM koszyku
 *    konta co przy logowaniu (`TwoFactorAuthenticator::kluczLimituProb`);
 *    puste pole się nie liczy;
 *  - `$zuzyjKodZapasowy = false` sprawdza kod zapasowy bez skreślania go —
 *    gdy formularz i tak niczego nie zrobi (nie ma czego cofać, nie ma
 *    decyzji do odwołania), pomyłka nie kosztuje kodu ratunkowego.
 */
final class KodDwuetapowyZFormularza
{
    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    /**
     * @param  string  $przycisk  napis na przycisku wysyłki — komunikat mówi,
     *                            co kliknąć po wpisaniu kodu
     * @return string|null komunikat dla pola `code` albo `null`, gdy kod jest poprawny
     */
    public function sprawdz(User $osoba, string $kod, bool $zuzyjKodZapasowy, string $przycisk): ?string
    {
        if ($kod === '') {
            return 'To konto ma włączoną weryfikację dwuetapową. Otwórz aplikację uwierzytelniającą '
                .'w telefonie i wpisz sześciocyfrowy kod (albo jeden z kodów zapasowych), '
                ."a potem wpisz jeszcze raz hasło i kliknij „{$przycisk}”.";
        }

        $minuty = $this->totp->zarezerwujProbe($osoba);

        if ($minuty !== null) {
            return "Za dużo prób kodu. Spróbuj ponownie za {$minuty} min.";
        }

        $cyfry = (string) preg_replace('/\s+/', '', $kod);

        $poprawny = ctype_digit($cyfry)
            ? $this->totp->verifyCode($osoba, (string) $osoba->two_factor_secret, $cyfry)
            : ($zuzyjKodZapasowy
                ? $this->totp->consumeBackupCode($osoba, $kod)
                : $this->totp->backupCodeMatches($osoba, $kod));

        if (! $poprawny) {
            return 'Kod jest nieprawidłowy albo już wykorzystany. Sprawdź godzinę w telefonie, '
                .'wpisz nowy kod z aplikacji (albo niewykorzystany kod zapasowy) i jeszcze raz hasło.';
        }

        RateLimiter::clear(TwoFactorAuthenticator::kluczLimituProb($osoba));

        return null;
    }
}

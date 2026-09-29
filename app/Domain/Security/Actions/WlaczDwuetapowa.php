<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Security\WynikWlaczeniaDwuetapowej;
use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Potwierdzenie i włączenie weryfikacji dwuetapowej — sprawdzenia, blokada
 * konta i wpis audytu jako nazwany przypadek użycia.
 *
 * Wyjęte z `Settings\TwoFactorSettingsController::confirm()` bez zmiany
 * zachowania (issue #970). Kolejność zostaje ta sama: brak sekretu, 2FA już
 * włączona, HASŁO, dopiero potem KOD (przy złym haśle kod nie jest ani
 * sprawdzany, ani zużywany — #1376, D-245), skróty kodów zapasowych PRZED
 * blokadą, zapis pod `ZamekKonta` na świeżym wierszu (#2061), wpis audytu
 * po zatwierdzeniu. Kontroler zostaje przy HTTP: walidacja pól, komunikaty
 * błędów, unieważnienie innych sesji, dowód 2FA w sesji i flash z kodami.
 *
 * `$user` po wywołaniu jest odświeżony ze stanu w bazie (jeśli doszło do
 * zapisu).
 */
final class WlaczDwuetapowa
{
    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    public function handle(User $user, string $haslo, string $kod, ?string $ip): WynikWlaczeniaDwuetapowej
    {
        if ($user->two_factor_secret === null) {
            return new WynikWlaczeniaDwuetapowej(WynikWlaczeniaDwuetapowej::BRAK_SEKRETU);
        }

        // Stary formularz włączenia przy JUŻ włączonej 2FA: bez sprawdzania
        // i zużywania kodu, bez nowego kompletu kodów zapasowych (#2061).
        if ($user->hasTwoFactorConfirmed()) {
            return new WynikWlaczeniaDwuetapowej(WynikWlaczeniaDwuetapowej::JUZ_WLACZONE);
        }

        if (! Hash::check($haslo, $user->password)) {
            return new WynikWlaczeniaDwuetapowej(WynikWlaczeniaDwuetapowej::ZLE_HASLO);
        }

        $sekret = $user->two_factor_secret;

        if (! $this->totp->verifyCode($user, $sekret, $kod)) {
            return new WynikWlaczeniaDwuetapowej(WynikWlaczeniaDwuetapowej::ZLY_KOD);
        }

        // POTWIERDZENIE ROZSTRZYGA ŚWIEŻY WIERSZ POD BLOKADĄ KONTA (#2061).
        //
        // `$user` wczytano na początku żądania. Druga karta mogła w tym czasie
        // potwierdzić 2FA i pokazać kody zapasowe, które człowiek właśnie
        // przepisuje. Pod blokadą sprawdzamy więc jeszcze raz: sekret ten sam,
        // który zweryfikował kod, i 2FA wciąż niepotwierdzone. Inaczej nic nie
        // zapisujemy. Nowy komplet przy WŁĄCZONEJ 2FA daje tylko
        // `WygenerujNoweKodyZapasowe`.
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
            return new WynikWlaczeniaDwuetapowej($user->hasTwoFactorConfirmed()
                ? WynikWlaczeniaDwuetapowej::JUZ_WLACZONE
                : WynikWlaczeniaDwuetapowej::PRZEGRANA_Z_DRUGA_KARTA);
        }

        AuditLogEntry::recordBezWywracania('account.two_factor_enabled', $user, $user, ip: $ip);

        return new WynikWlaczeniaDwuetapowej(WynikWlaczeniaDwuetapowej::WLACZONO, $kodyJawne);
    }
}

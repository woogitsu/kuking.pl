<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Wyzwanie drugiego kroku logowania w API — konto z potwierdzonym 2FA (D-270).
 *
 * NA WWW pierwszy krok zostawia w SESJI identyfikator konta i odcisk jego
 * stanu (`TwoFactorAuthenticator::oczekujaceLogowanie`). API nie ma sesji,
 * więc ten sam stan jedzie do aplikacji w postaci ZASZYFROWANEJ kluczem
 * aplikacji i wraca razem z kodem. Nie ma tabeli, nie ma cache'u.
 *
 * DLACZEGO TO JEST BEZPIECZNE — trzy warstwy, każda z osobna wystarcza
 * na inny atak:
 *
 *  1. Szyfrowanie z uwierzytelnieniem (`Crypt`, AES-256 z MAC): wyzwania nie
 *     da się ani odczytać, ani podrobić, ani przerobić na cudze konto.
 *  2. Odcisk stanu konta — ten sam, którego używa WWW (#931). Zmiana hasła,
 *     „wyloguj inne urządzenia", zmiana statusu albo wyłączenie 2FA
 *     unieważniają wyzwanie od razu, zanim minie jego termin.
 *  3. Termin (`config('kuking.api.wyzwanie_minut')`).
 *
 * WYZWANIE NIE JEST TOKENEM: bez kodu z aplikacji niczego nie otwiera. Kod
 * sprawdza `SprawdzKodDrugiegoSkladnika` z tym samym limitem prób po koncie
 * co WWW — przechwycone wyzwanie nie daje więc nowego budżetu na zgadywanie.
 */
final class WyzwanieDwuetapowe
{
    /** Stała w treści: wyzwanie wystawione do czegoś innego nie przejdzie tutaj. */
    private const CEL = 'api-2fa-v1';

    /**
     * @return array{wyzwanie: string, wazne_do: Carbon}
     */
    public static function wystaw(User $user, string $urzadzenie): array
    {
        $waznoDo = now()->addMinutes(max(1, (int) config('kuking.api.wyzwanie_minut')));

        $tresc = json_encode([
            'cel' => self::CEL,
            'konto' => (string) $user->getKey(),
            'odcisk' => TwoFactorAuthenticator::oczekujaceLogowanie($user)['logowanie.2fa.odcisk'],
            'urzadzenie' => $urzadzenie,
            'do' => $waznoDo->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        return ['wyzwanie' => Crypt::encryptString($tresc), 'wazne_do' => $waznoDo];
    }

    /**
     * Konto i nazwa urządzenia z wyzwania — albo `null`, gdy wyzwanie jest
     * podrobione, przeterminowane, wystawione do innego celu albo nie pasuje
     * już do stanu konta. Jedno `null` na wszystkie przypadki: wołający mówi
     * człowiekowi jedno — „zaloguj się jeszcze raz".
     *
     * @return array{0: User, 1: string}|null
     */
    public static function odczytaj(string $wyzwanie): ?array
    {
        try {
            $dane = json_decode(Crypt::decryptString($wyzwanie), true, 4, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($dane)
            || ($dane['cel'] ?? null) !== self::CEL
            || ! is_string($dane['konto'] ?? null)
            || ! is_string($dane['urzadzenie'] ?? null)
            || ! is_int($dane['do'] ?? null)
            || $dane['do'] < now()->getTimestamp()) {
            return null;
        }

        $user = User::query()->find($dane['konto']);

        if (! $user instanceof User
            || ! $user->hasTwoFactorConfirmed()
            || ! TwoFactorAuthenticator::oczekujaceLogowanieAktualne($user, $dane['odcisk'] ?? null)) {
            return null;
        }

        return [$user, $dane['urzadzenie']];
    }
}

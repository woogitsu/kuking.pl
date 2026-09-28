<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Drugi składnik logowania: kod z aplikacji albo kod zapasowy (issue #12).
 *
 * WSPÓLNE DLA WWW I API (D-270). Limit prób, kolejność „najpierw kod
 * z aplikacji, potem zapasowy" i zużycie kodu zapasowego były wpisane
 * w `TwoFactorChallengeController::store()`. API ma dokładnie ten sam drugi
 * krok, więc sprawdzenie przeszło tutaj — z JEDNYM koszykiem prób na konto
 * (`TwoFactorAuthenticator::kluczLimituProb`) dla obu dróg. Dwa koszyki
 * dawałyby zgadującemu podwójny budżet na sześć cyfr jednego konta.
 *
 * Limit liczony PO KONCIE, nie po adresie IP — kod ma sześć cyfr, więc bez
 * limitu prób jest do odgadnięcia, a rozproszony atak z wielu adresów miałby
 * ominąć zwykły throttle po IP.
 *
 * DZIENNIK AUDYTU (#2042, #2199): każdy rzeczywiście sprawdzony i błędny kod
 * zostawia wpis `account.two_factor_login_failed` — zapis jest TU, a nie
 * w kontrolerach, żeby żaden kanał (WWW, API) nie mógł go pominąć. Kanał
 * (`kanal` w metadanych) mówi, którędy przyszła próba. Odpowiedź
 * `ZA_DUZO_PROB` nie dopisuje nic, więc limit ogranicza też wolumen dziennika.
 * Wpisanego kodu ani sekretu 2FA do dziennika nie przekazujemy — tylko rodzaj
 * sprawdzonego kodu i skrót adresu IP (liczy go `AuditLogEntry`).
 *
 * Zwraca liczbę minut blokady albo wynik sprawdzenia — komunikat dla
 * człowieka pisze wołający, bo formularz i aplikacja mówią o polach inaczej.
 */
final class SprawdzKodDrugiegoSkladnika
{
    /** Kod poprawny: licznik prób wyzerowany, kod zapasowy (jeśli był) zużyty. */
    public const POPRAWNY = 'poprawny';

    /** Kod niepoprawny albo już użyty: próba policzona (zarezerwowana przed sprawdzeniem). */
    public const BLEDNY = 'bledny';

    /** Limit prób wyczerpany: kodu nawet nie sprawdzono. */
    public const ZA_DUZO_PROB = 'za_duzo_prob';

    /** Kanał próby w metadanych wpisu audytu: formularz w przeglądarce. */
    public const KANAL_WWW = 'www';

    /** Kanał próby w metadanych wpisu audytu: API aplikacji mobilnej. */
    public const KANAL_API = 'api';

    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    /**
     * @param  self::KANAL_WWW|self::KANAL_API  $kanal  którędy przyszła próba (tylko do dziennika audytu)
     * @param  string|null  $ip  adres żądania; do dziennika trafia wyłącznie jego skrót
     * @return array{0: self::POPRAWNY|self::BLEDNY|self::ZA_DUZO_PROB, 1: int|null} wynik i minuty blokady
     */
    public function handle(User $user, string $kod, string $kodZapasowy, string $kanal, ?string $ip): array
    {
        // Próba liczona PRZED sprawdzeniem kodu, atomowo (#2043) — patrz
        // `TwoFactorAuthenticator::zarezerwujProbe()`.
        $minuty = $this->totp->zarezerwujProbe($user);

        if ($minuty !== null) {
            return [self::ZA_DUZO_PROB, $minuty];
        }

        $poprawny = false;

        if ($kod !== '') {
            $poprawny = $this->totp->verifyCode($user, (string) $user->two_factor_secret, $kod);
        }

        if (! $poprawny && $kodZapasowy !== '') {
            $poprawny = $this->totp->consumeBackupCode($user, $kodZapasowy);
        }

        if (! $poprawny) {
            $rodzaj = $kod !== '' && $kodZapasowy !== '' ? 'oba' : ($kod !== '' ? 'totp' : 'zapasowy');
            AuditLogEntry::recordBezWywracania(
                'account.two_factor_login_failed',
                subject: $user,
                metadata: ['rodzaj' => $rodzaj, 'kanal' => $kanal],
                ip: $ip,
            );

            return [self::BLEDNY, null];
        }

        RateLimiter::clear(TwoFactorAuthenticator::kluczLimituProb($user));

        return [self::POPRAWNY, null];
    }
}

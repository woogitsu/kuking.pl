<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\TwoFactorAuthenticator;
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
 * Zwraca liczbę minut blokady albo wynik sprawdzenia — komunikat dla
 * człowieka pisze wołający, bo formularz i aplikacja mówią o polach inaczej.
 */
final class SprawdzKodDrugiegoSkladnika
{
    /** Kod poprawny: licznik prób wyzerowany, kod zapasowy (jeśli był) zużyty. */
    public const POPRAWNY = 'poprawny';

    /** Kod niepoprawny albo już użyty: próba policzona. */
    public const BLEDNY = 'bledny';

    /** Limit prób wyczerpany: kodu nawet nie sprawdzono. */
    public const ZA_DUZO_PROB = 'za_duzo_prob';

    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    /**
     * @return array{0: self::POPRAWNY|self::BLEDNY|self::ZA_DUZO_PROB, 1: int|null} wynik i minuty blokady
     */
    public function handle(User $user, string $kod, string $kodZapasowy): array
    {
        [$maxProb, $decayMinuty] = TwoFactorAuthenticator::limitProb();
        $klucz = TwoFactorAuthenticator::kluczLimituProb($user);

        if (RateLimiter::tooManyAttempts($klucz, $maxProb)) {
            return [self::ZA_DUZO_PROB, max(1, (int) ceil(RateLimiter::availableIn($klucz) / 60))];
        }

        $poprawny = false;

        if ($kod !== '') {
            $poprawny = $this->totp->verifyCode($user, (string) $user->two_factor_secret, $kod);
        }

        if (! $poprawny && $kodZapasowy !== '') {
            $poprawny = $this->totp->consumeBackupCode($user, $kodZapasowy);
        }

        if (! $poprawny) {
            RateLimiter::hit($klucz, $decayMinuty * 60);

            return [self::BLEDNY, null];
        }

        RateLimiter::clear($klucz);

        return [self::POPRAWNY, null];
    }
}

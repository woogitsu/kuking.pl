<?php

declare(strict_types=1);

namespace App\Google;

use App\Domain\Security\WejsciePrzezDostawce\DostawcaWejscia;
use App\Domain\Security\WejsciePrzezDostawce\TozsamoscOdDostawcy;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Support\Google;

/**
 * Google w regułach Kuking — tylko to, czym różni się od Facebooka (#1035).
 * Wspólne reguły: `WejdzPrzezDostawce`. Protokół: `KlientGoogle`
 * i `GoogleLoginController`.
 */
final class DostawcaWejsciaGoogle implements DostawcaWejscia
{
    /**
     * Mapowanie tożsamości z tokenu Google. Woła się je PO regule 1
     * (`email_verified`) w `GoogleLoginController::callback()`.
     */
    public function tozsamosc(TozsamoscGoogle $tozsamosc): TozsamoscOdDostawcy
    {
        return new TozsamoscOdDostawcy(
            identyfikator: $tozsamosc->sub,
            email: $tozsamosc->email,
            emailPotwierdzony: $tozsamosc->emailPotwierdzony,
            imie: $tozsamosc->imie,
        );
    }

    public function nazwa(): string
    {
        return TozsamoscZewnetrzna::DOSTAWCA_GOOGLE;
    }

    public function poleIdentyfikatoraWSesji(): string
    {
        return 'sub';
    }

    public function waznoscDomknieciaMinut(): int
    {
        return Google::waznoscDomknieciaMinut();
    }

    public function potwierdzaAdres(): bool
    {
        return true;
    }

    public function kontoPowiazane(string $identyfikator): ?User
    {
        return User::findByGoogleSub($identyfikator);
    }

    public function maPolaczenie(User $user): bool
    {
        return $user->hasGoogleConnected();
    }

    public function polacz(User $user, string $identyfikator): void
    {
        $user->connectGoogle($identyfikator);
    }

    /**
     * REGUŁY 2 I 3 D-069: adres od Google jest dowodem, więc musi być
     * adresem TEGO konta — i to konto musi mieć adres potwierdzony u nas
     * (inaczej atak z wyprzedzeniem, patrz `GoogleLoginController`).
     */
    public function dowodPolaczenia(User $user, TozsamoscOdDostawcy $tozsamosc): bool
    {
        return $user->email === $tozsamosc->email
            && $user->email_verified_at !== null;
    }

    public function przedWejsciem(User $user): void {}

    public function akcjaWejscia(): string
    {
        return 'account.login_google';
    }

    public function akcjaPolaczenia(): string
    {
        return 'account.google_connected';
    }

    public function powiazanieNowegoKonta(string $identyfikator): array
    {
        return ['googleSub' => $identyfikator];
    }
}

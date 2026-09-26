<?php

declare(strict_types=1);

namespace App\Facebook;

use App\Domain\Security\WejsciePrzezDostawce\DostawcaWejscia;
use App\Domain\Security\WejsciePrzezDostawce\TozsamoscOdDostawcy;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Support\Facebook;

/**
 * Facebook w regułach Kuking — tylko to, czym różni się od Google (#1035).
 * Wspólne reguły: `WejdzPrzezDostawce`. Protokół: `KlientFacebook`
 * i `FacebookLoginController`.
 */
final class DostawcaWejsciaFacebook implements DostawcaWejscia
{
    /**
     * Mapowanie tożsamości z Graph API. Adres NIGDY nie jest potwierdzony:
     * Facebook o tym nie mówi (D-098).
     */
    public function tozsamosc(TozsamoscFacebook $tozsamosc): TozsamoscOdDostawcy
    {
        return new TozsamoscOdDostawcy(
            identyfikator: $tozsamosc->identyfikator,
            email: $tozsamosc->email,
            emailPotwierdzony: false,
            imie: $tozsamosc->imie,
        );
    }

    public function nazwa(): string
    {
        return TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK;
    }

    public function poleIdentyfikatoraWSesji(): string
    {
        return 'identyfikator';
    }

    public function waznoscDomknieciaMinut(): int
    {
        return Facebook::waznoscDomknieciaMinut();
    }

    public function potwierdzaAdres(): bool
    {
        return false;
    }

    public function kontoPowiazane(string $identyfikator): ?User
    {
        return User::findByFacebookId($identyfikator);
    }

    public function maPolaczenie(User $user): bool
    {
        return $user->hasFacebookConnected();
    }

    public function polacz(User $user, string $identyfikator): void
    {
        $user->connectFacebook($identyfikator);
    }

    /**
     * ADRESU E-MAIL NIE MA W TYCH WARUNKACH I TO JEST CAŁA RÓŻNICA WOBEC
     * GOOGLE. Tam adres od dostawcy musi się zgadzać z adresem konta, bo to on
     * jest dowodem. Tutaj dowodem jest to, że człowiek JEST ZALOGOWANY na to
     * konto (`linkForm`/`link` wymagają `Auth::user()`) — a adres z Facebooka
     * nie dowodzi niczego i porównywanie go dawałoby złudzenie warunku. Konto
     * może więc mieć zupełnie inny adres niż Facebook: ludzie mają kilka adresów.
     */
    public function dowodPolaczenia(User $user, TozsamoscOdDostawcy $tozsamosc): bool
    {
        return true;
    }

    /**
     * POWRÓT PO ODEBRANIU DOSTĘPU — znacznik gaśnie TUTAJ.
     *
     * Człowiek, który odebrał nam dostęp w ustawieniach Facebooka, a teraz
     * znów przeszedł przez ekran zgody, właśnie tę zgodę oddał na nowo.
     * Zostawienie znacznika kazałoby ekranowi „Ustawienia → Bezpieczeństwo"
     * pokazywać mu „dostęp odebrany" w chwili, w której właśnie wszedł tą
     * drogą — czyli karałoby go za skorzystanie z własnych ustawień (#259).
     */
    public function przedWejsciem(User $user): void
    {
        $user->cofnijOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);
    }

    public function akcjaWejscia(): string
    {
        return 'account.login_facebook';
    }

    public function akcjaPolaczenia(): string
    {
        return 'account.facebook_connected';
    }

    public function powiazanieNowegoKonta(string $identyfikator): array
    {
        return ['facebookId' => $identyfikator];
    }
}

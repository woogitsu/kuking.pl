<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * „Paczka gotowa, ale najpierw cofnij usunięcie konta” — list dla konta
 * w karencji (`pending_delete`).
 *
 * Decyzja właściciela z 23 września 2026. Konto w karencji jest wylogowane
 * i nie zaloguje się (`EnsureAccountIsActive`, `LoginController`), a pobranie
 * paczki wymaga zalogowania (`DataSettingsController::download`). Zwykły
 * `DataExportReady` dawał tu przycisk „Pobierz swoje dane”, który prowadził
 * na odmowę logowania — martwy przycisk w e-mailu.
 *
 * Dlatego ten list NIE niesie linku do paczki, tylko do strony cofnięcia
 * usunięcia (publiczna, sprawdza hasło). Po cofnięciu paczka czeka
 * w ustawieniach jak każda inna.
 *
 * TERMIN = WCZEŚNIEJSZA z dwóch dat: końca karencji (potem konto znika
 * razem z paczką) i wygaśnięcia paczki (potem paczki już nie ma, choć konto
 * jeszcze da się odzyskać). Podanie późniejszej byłoby obietnicą, której
 * nie dotrzymamy.
 */
class DataExportReadyInGracePeriod extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DataExport $export) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Twoja paczka danych jest gotowa',
        );
    }

    public function content(): Content
    {
        $graceEndsAt = $this->export->user?->deletionGraceEndsAt();

        return new Content(
            view: 'mail.data-export-ready-grace',
            with: [
                'cancelUrl' => route('account.delete.cancel'),
                'deadline' => $this->deadline(),
                'packageExpiresFirst' => $this->packageExpiresFirst($graceEndsAt),
                'graceEndsAt' => $graceEndsAt,
                'displayName' => $this->export->user?->profile?->display_name,
            ],
        );
    }

    /** Do kiedy trzeba cofnąć usunięcie, żeby paczkę jeszcze pobrać. */
    public function deadline(): ?Carbon
    {
        $graceEndsAt = $this->export->user?->deletionGraceEndsAt();
        $expiresAt = $this->export->expires_at;

        if ($graceEndsAt === null || $expiresAt === null) {
            return $graceEndsAt ?? $expiresAt;
        }

        return $expiresAt->lt($graceEndsAt) ? $expiresAt : $graceEndsAt;
    }

    private function packageExpiresFirst(?Carbon $graceEndsAt): bool
    {
        $expiresAt = $this->export->expires_at;

        return $expiresAt !== null && ($graceEndsAt === null || $expiresAt->lt($graceEndsAt));
    }
}

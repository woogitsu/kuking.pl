<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * „Twoje dane są gotowe” — jedyny e-mail w całym procesie eksportu.
 *
 * Link w środku jest PODPISANY i WYGASAJĄCY (URL::temporarySignedRoute).
 * Nieodgadywalny adres nie jest autoryzacją (AGENTS.md, sekcja 7): podpis
 * potwierdza, że link wystawił Kuking, a data wygaśnięcia zamyka go razem
 * z paczką. Sam link nie wystarczy — trasa pobrania dodatkowo sprawdza,
 * czy pobiera właściciel paczki.
 */
class DataExportReady extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public DataExport $export) {}

    public function envelope(): Envelope
    {
        // Temat i pierwsze zdanie treści są gotowymi napisami
        // z docs/brand/COPY_STYLE.md §6 („E-mail”) — nie parafrazujemy ich.
        return new Envelope(
            subject: 'Twoje dane są gotowe do pobrania',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.data-export-ready',
            with: [
                'downloadUrl' => URL::temporarySignedRoute(
                    'settings.data.download',
                    $this->export->expires_at ?? now()->addDays((int) config('kuking.exports.ttl_days')),
                    ['export' => $this->export->getKey()],
                ),
                'expiresAt' => $this->export->expires_at,
                'sizeText' => $this->sizeText(),
                'displayName' => $this->export->user?->profile?->display_name,
            ],
        );
    }

    /** Rozmiar po ludzku: „około 45 MB”, nie „47185920”. */
    private function sizeText(): ?string
    {
        $bytes = $this->export->bytes;

        if ($bytes === null || $bytes <= 0) {
            return null;
        }

        if ($bytes < 1024 * 1024) {
            return 'mniej niż 1 MB';
        }

        return 'około '.(int) round($bytes / (1024 * 1024)).' MB';
    }
}

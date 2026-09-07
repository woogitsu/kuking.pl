<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_TRIAGE = 'triage';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Powody zgłoszenia w języku, który rozumie zgłaszający.
     * Klucz idzie do bazy, wartość na ekran.
     */
    public const REASONS = [
        'spam' => 'Spam albo reklama',
        'scam' => 'Oszustwo lub podejrzany link',
        'impersonation' => 'Ktoś podaje się za inną osobę',
        'harassment' => 'Obraża lub nęka kogoś',
        'hate' => 'Mowa nienawiści',
        'sexual' => 'Treść nieprzyzwoita',
        'personal_data' => 'Ujawnia czyjeś dane osobowe',
        'copyright' => 'To nie jest treść tej osoby',
        'dangerous_advice' => 'Niebezpieczna porada kulinarna',
        'minor' => 'Dotyczy dziecka',
        'other' => 'Coś innego',
    ];

    /**
     * Zgłoszenie społecznościowe: „to jest spam", „to jest chamskie".
     * Nasze zasady, nasza kolejka, może wymagać zalogowania.
     */
    public const SOURCE_COMMUNITY = 'community';

    /**
     * Zgłoszenie nielegalnej treści w rozumieniu DSA art. 16.
     *
     * Inna rzecz niż wyżej i dlatego ma osobną nazwę. Ten mechanizm MUSI być
     * dostępny dla każdej osoby i każdego podmiotu, także bez konta — nie
     * wolno kazać komuś zakładać konta w serwisie kulinarnym po to, żeby mógł
     * zgłosić przestępstwo. Niesie też własne obowiązki: potwierdzenie odbioru
     * i powiadomienie o decyzji z pouczeniem o środkach odwoławczych.
     */
    public const SOURCE_LEGAL_NOTICE = 'legal_notice';

    protected $fillable = [
        'reporter_id',
        // Tożsamość jednego wysłania formularza zgłoszenia BEZ KONTA (DSA
        // art. 16 ust. 2 lit. c). Częściowy indeks UNIQUE
        // `reports_one_per_klucz_wyslania` sprawia, że podwójne kliknięcie
        // nie zakłada drugiej sprawy z własnym terminem odpowiedzi.
        'klucz_wyslania',
        'source',
        'notifier_name',
        'notifier_email',
        'target_type',
        'target_id',
        'target_url',
        'reason',
        'details',
        'illegality_explanation',
        'good_faith_at',
        'status',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'good_faith_at' => 'datetime',
            'receipt_sent_at' => 'datetime',
            'decision_sent_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_TRIAGE, self::STATUS_REVIEWING], true);
    }

    public function jestZgloszeniemPrawnym(): bool
    {
        return $this->source === self::SOURCE_LEGAL_NOTICE;
    }

    /**
     * Czy mamy komu odpowiedzieć.
     *
     * Art. 16 ust. 2 lit. c przewiduje wyjątek: przy zgłoszeniach dotyczących
     * przestępstw z art. 3-7 dyrektywy 2011/93/UE dane zgłaszającego nie są
     * wymagane. Wtedy nie ma adresu i to jest zgodne z przepisem, a nie brak
     * w naszych danych.
     */
    public function maAdresDoOdpowiedzi(): bool
    {
        return $this->jestZgloszeniemPrawnym() && $this->notifier_email !== null;
    }
}

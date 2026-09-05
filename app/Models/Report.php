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

    protected $fillable = [
        'reporter_id',
        'target_type',
        'target_id',
        'reason',
        'details',
        'status',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
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
}

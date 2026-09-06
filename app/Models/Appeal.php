<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Odwołanie od decyzji moderacyjnej (issue #10, DSA art. 17 i 20).
 *
 * Odwołanie zawsze dotyczy JEDNEJ decyzji z `moderation_actions` — nie „konta"
 * ani „treści" w ogóle. Dzięki temu odpowiedź da się przypiąć do konkretnego
 * uzasadnienia, które ta osoba dostała, i widać, czy pisze o tym samym.
 */
class Appeal extends Model
{
    use HasUuids;

    /** Czeka na moderatora. */
    public const STATUS_OPEN = 'open';

    /** Decyzja podtrzymana. */
    public const STATUS_UPHELD = 'upheld';

    /** Decyzja cofnięta — treść wraca, konto wraca. */
    public const STATUS_OVERTURNED = 'overturned';

    public const ETYKIETY = [
        self::STATUS_OPEN => 'Czeka na rozpatrzenie',
        self::STATUS_UPHELD => 'Decyzja podtrzymana',
        self::STATUS_OVERTURNED => 'Decyzja cofnięta',
    ];

    protected $fillable = [
        'moderation_action_id',
        'user_id',
        'body',
        'status',
        'decided_by',
        'decision_note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function moderationAction(): BelongsTo
    {
        return $this->belongsTo(ModerationAction::class);
    }

    /** Osoba, która się odwołała. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function statusLabel(): string
    {
        return self::ETYKIETY[$this->status] ?? $this->status;
    }

    /**
     * Do kiedy obiecaliśmy odpowiedzieć.
     *
     * Siedem DNI ROBOCZYCH z `docs/legal/MODERATION_PLAYBOOK.md` §3 punkt 4.
     * `addWeekdays()` pomija soboty i niedziele; świąt nie zna i nie udajemy,
     * że zna — to jest cel operacyjny pokazywany moderatorowi w kolejce,
     * a użytkownikowi mówimy „w ciągu 7 dni roboczych", bez daty co do godziny.
     */
    public function responseDeadline(): CarbonInterface
    {
        return $this->created_at->copy()->addWeekdays(
            (int) config('kuking.moderation.appeal_response_working_days'),
        );
    }

    /** Czy termin odpowiedzi już minął — kolejka ma to pokazywać wprost. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->responseDeadline()->isPast();
    }
}

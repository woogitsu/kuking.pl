<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Odwołanie od decyzji moderacyjnej (issue #10 i #23, DSA art. 17 i 20).
 *
 * Odwołanie zawsze dotyczy JEDNEJ decyzji z `moderation_actions` — nie „konta"
 * ani „treści" w ogóle. Dzięki temu odpowiedź da się przypiąć do konkretnego
 * uzasadnienia, które ta osoba dostała, i widać, czy pisze o tym samym.
 *
 * DWIE ROLE, JEDNA TABELA (issue #23)
 * `appellant` mówi, KTO się odwołuje — autor treści (`user_id`, ma konto)
 * albo zgłaszający (`report_id`, może nie mieć konta wcale — zgłoszenie
 * prawne wolno złożyć bez danych, migracja `allow_anonymous_legal_notices`).
 * Dokładnie jedno z tych dwóch pól jest wypełnione — pilnuje tego
 * `appeals_appellant_identity_check` w bazie, nie tylko ten model.
 * Uzasadnienie wyboru „jedna tabela, nie dwie" jest w komentarzu migracji
 * `2026_09_07_800000_appeals_open_to_reporters.php`.
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

    /** Odwołuje się autor treści, ukaranej decyzją. Ma konto: `user_id`. */
    public const APPELLANT_AUTHOR = 'author';

    /** Odwołuje się osoba, która złożyła zgłoszenie. Może nie mieć konta: `report_id`. */
    public const APPELLANT_REPORTER = 'reporter';

    public const ETYKIETY = [
        self::STATUS_OPEN => 'Czeka na rozpatrzenie',
        self::STATUS_UPHELD => 'Decyzja podtrzymana',
        self::STATUS_OVERTURNED => 'Decyzja cofnięta',
    ];

    protected $fillable = [
        'moderation_action_id',
        'user_id',
        'report_id',
        'appellant',
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

    /** Osoba, która się odwołała. NULL przy odwołaniu zgłaszającego bez konta. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Zgłoszenie, którego dotyczy odwołanie ZGŁASZAJĄCEGO. NULL dla autora. */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isFromReporter(): bool
    {
        return $this->appellant === self::APPELLANT_REPORTER;
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

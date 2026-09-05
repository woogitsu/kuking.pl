<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Decyzja moderatora wraz z uzasadnieniem.
 *
 * `user_message` to treść, którą realnie zobaczył użytkownik. Trzymamy ją,
 * bo przy odwołaniu musimy wiedzieć, co mu powiedzieliśmy (DSA art. 17).
 */
class ModerationAction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const ACTION_NONE = 'no_action';

    public const ACTION_HIDE = 'hide';

    public const ACTION_REMOVE = 'remove';

    public const ACTION_WARN = 'warn';

    public const ACTION_SUSPEND = 'suspend';

    public const ACTION_BAN = 'ban';

    protected $fillable = [
        'moderator_id',
        'report_id',
        'target_type',
        'target_id',
        'action',
        'reason_code',
        'note',
        'user_message',
    ];

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}

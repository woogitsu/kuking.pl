<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Obiekt zabezpieczony jako dowód — nie wolno go skasować żadną drogą.
 *
 * Wiersze powstają WYŁĄCZNIE w `ZabezpieczDowodCsam` (przez `forceCreate()`,
 * bez `$fillable`) i nie ma w serwisie drogi, która by je zdejmowała. Patrz
 * migracja `create_zabezpieczenia_dowodow_table` i `ZabezpieczoneDowody`.
 *
 * @property string $id
 * @property string $target_type
 * @property string $target_id
 * @property string|null $subject_user_id
 * @property string|null $report_id
 * @property string|null $moderation_action_id
 * @property string|null $secured_by
 * @property string|null $previous_media_status
 * @property string|null $note
 */
class ZabezpieczenieDowodu extends Model
{
    use HasUuids;

    protected $table = 'zabezpieczenia_dowodow';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    protected function casts(): array
    {
        return ['secured_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function securedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secured_by');
    }
}

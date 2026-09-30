<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * „Dziękuję" autora treści pod jednym komentarzem (issue #2355, F11).
 *
 * `$fillable` PUSTE: komentarz i osobę ustawia wyłącznie
 * `App\Domain\Comments\Actions\ThankForComment`, po sprawdzeniu Policy.
 * Nie ma liczników ani sortowania po tej tabeli — patrz migracja.
 *
 * @property string $id
 * @property string $comment_id
 * @property string $thanker_id
 * @property Carbon $created_at
 */
class CommentThank extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'comment_thanks';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'comment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function thanker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'thanker_id');
    }
}

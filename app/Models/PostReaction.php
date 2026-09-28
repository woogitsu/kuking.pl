<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * „Smakowicie wygląda" jednej osoby pod jednym wpisem (issue #1813, D-280).
 *
 * `$fillable` PUSTE: wpis i osobę ustawia `App\Domain\Reakcje\Smakowicie`,
 * a `notified_at` — wyłącznie zbiorcze powiadomienie.
 *
 * @property string $id
 * @property string $post_id
 * @property string $user_id
 * @property Carbon|null $notified_at
 * @property Carbon $created_at
 */
class PostReaction extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'post_reactions';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blokada. Tabela ma klucz złożony, więc model jest celowo "cichy":
 * bez auto-inkrementu, bez updated_at.
 */
class Block extends Model
{
    protected $table = 'blocks';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'blocker_id',
        'blocked_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}

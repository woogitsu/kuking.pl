<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wiersz sygnału produktowego (issue #115): dziś `photo_upload_failed`
 * i `search_performed`. Zapisuj wyłącznie przez `App\Domain\Analytics\ZapiszSygnal`
 * — patrz komentarz tamtej klasy, dlaczego nie wprost przez ten model.
 *
 * `occurred_at` ZASTĘPUJE `created_at` (tabela nie ma `updated_at` — sygnał
 * jest niezmienny po zapisaniu), stąd `CREATED_AT` przestawione tutaj zamiast
 * na standardową nazwę kolumny.
 */
class ProductSignal extends Model
{
    protected $table = 'product_signals';

    public const CREATED_AT = 'occurred_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'signal_name',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

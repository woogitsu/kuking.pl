<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cisza nocna i dzienny limit powiadomień POZA serwisem, wybrane przez
 * człowieka (D-303). Brak wiersza = wartości domyślne z konfiguracji.
 *
 * Powiadomień w serwisie (dzwonek) te ustawienia nie dotyczą i nie mają
 * dotyczyć — AGENTS.md §1: „Ugotowałem" zawsze powiadamia autora.
 *
 * @property string $user_id
 * @property int $cisza_od
 * @property int $cisza_do
 * @property int $dzienny_limit
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class UstawieniaPowiadomienZewnetrznych extends Model
{
    protected $table = 'ustawienia_powiadomien_zewnetrznych';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['cisza_od', 'cisza_do', 'dzienny_limit'];

    protected function casts(): array
    {
        return [
            'cisza_od' => 'integer',
            'cisza_do' => 'integer',
            'dzienny_limit' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

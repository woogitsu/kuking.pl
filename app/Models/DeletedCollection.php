<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kopia odzyskania prywatnego zeszytu, który jego właściciel usunął (#2567).
 *
 * To nie jest zeszyt: nie ma go na liście, nie ma adresu, nikt poza
 * właścicielem go nie widzi. Wiersz żyje najwyżej
 * `kuking.usuniete_tresci.retention_days` dni, a potem zabiera go
 * `PrzedawnioneUsunieteZeszyty`. `$fillable` jest pusty — kopię tworzy
 * wyłącznie `UsunZeszyt`, a zdejmuje `OdzyskajUsunietyZeszyt`.
 *
 * @property string $id
 * @property string $owner_id
 * @property string $collection_id dawny identyfikator zeszytu
 * @property string $name
 * @property string|null $description
 * @property CarbonImmutable $collection_created_at
 * @property list<array{recipe_id: string|null, post_id: string|null, note: string|null, created_at: string, position?: int|null}> $items
 * @property int $items_count
 * @property CarbonImmutable $deleted_at
 */
class DeletedCollection extends Model
{
    use HasUuids;

    protected $table = 'deleted_collections';

    protected $fillable = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'items_count' => 'integer',
            'collection_created_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}

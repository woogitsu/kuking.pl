<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wariant nazwy tagu, który prowadzi do tagu kanonicznego (SPEC §1.3).
 *
 * `bigserial`, NIE `uuid` — ten wiersz nigdy nie jest adresowany z zewnątrz
 * ani pokazywany osobno: alias NIE MA własnej publicznej strony (wejście na
 * alias prowadzi na stronę tagu kanonicznego). Dokładnie ten sam wybór co
 * `product_signals`/`audit_log` — uzasadnienie w migracji
 * `2026_09_07_100000_create_tags_tables`.
 */
class TagAlias extends Model
{
    public const SOURCE_SEED = 'seed';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_AI_SUGGESTION = 'ai_suggestion';

    /** Tylko `created_at` w bazie (patrz migracja) — tak jak `Ingredient`. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'tag_id',
        'alias',
        'normalized_alias',
        'source',
    ];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}

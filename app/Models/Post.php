<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wpis: zdjęcie + kilka słów. Główna jednostka treści w Kuking.
 */
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_HIDDEN = 'hidden';

    public const STATUS_REMOVED = 'removed';

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_FOLLOWERS = 'followers';

    public const VISIBILITY_PRIVATE = 'private';

    protected $fillable = [
        'author_id',
        'body',
        'visibility',
        'status',
        'recipe_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->withPivot('position')
            ->orderBy('post_media.position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)
            ->whereNull('parent_id')
            ->where('status', Comment::STATUS_PUBLISHED)
            ->oldest();
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    // ---------------------------------------------------------------------
    // Zakresy
    // ---------------------------------------------------------------------

    /** @param  Builder<Post>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at');
    }

    /** @param  Builder<Post>  $query */
    public function scopePubliclyVisible(Builder $query): void
    {
        $query->published()->where('visibility', self::VISIBILITY_PUBLIC);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at !== null;
    }

    public function url(): string
    {
        return route('posts.show', ['post' => $this->getKey()]);
    }
}

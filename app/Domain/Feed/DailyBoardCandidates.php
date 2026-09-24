<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Publiczne pozycje, które gospodarz może wyróżnić; bez miar popularności. */
final class DailyBoardCandidates
{
    /** @return Builder<Post> */
    public function posts(User $moderator): Builder
    {
        return Post::query()->publiclyVisible()->tylkoOdAktywnychAutorow()
            ->zWidocznymPrzepisem(null)->widoczneDla($moderator);
    }

    /** Istniejący wybór osoby pozostaje edytowalny także bez świeżego wpisu.
     * @return Builder<User>
     */
    public function people(User $moderator): Builder
    {
        return User::query()->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('id', [...$moderator->blocking()->pluck('users.id'), ...$moderator->blockedBy()->pluck('users.id')]);
    }

    /** @return Builder<User> */
    public function searchPeople(User $moderator, string $search): Builder
    {
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        return $this->people($moderator)
            ->whereHas('posts', fn (Builder $query) => $query->publiclyVisible()->zWidocznymPrzepisem(null))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('profile', fn (Builder $profile) => $profile
                ->where(fn (Builder $names) => $names->where('display_name', 'ilike', $pattern)->orWhere('username', 'ilike', $pattern))))
            ->withMax(['posts as latest_publication' => fn (Builder $query) => $query->publiclyVisible()->zWidocznymPrzepisem(null)], 'published_at')
            ->orderByDesc('latest_publication')->orderBy('id');
    }
}

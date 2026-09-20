<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Jobs\PrzeanalizujZdjecieWpisu;
use App\Models\Media;
use App\Models\Post;
use App\Moderacja\KlientOpenAI;
use Illuminate\Database\Eloquent\Collection;

final class PostImageAnalysis
{
    public function enabled(): bool
    {
        return config('kuking.moderation.sygnaly.wlaczone')
            && config('kuking.moderation.model.ocenia_zdjecia') && KlientOpenAI::oceniamy();
    }

    /** @return Collection<int, Media> */
    public function selected(Post $post): Collection
    {
        $limit = max(0, min(6, (int) config('kuking.moderation.model.zdjec_na_wpis')));

        return $post->media()->limit($limit)->get();
    }

    public function dispatchReady(Post $post): void
    {
        if (! $this->enabled() || ! app(AutomaticAnalysisAccess::class)->allows($post)) {
            return;
        }
        foreach ($this->selected($post) as $media) {
            if ($media->status === Media::STATUS_READY) {
                PrzeanalizujZdjecieWpisu::dispatch((string) $post->id, (string) $media->id)->afterCommit();
            }
        }
    }

    public function imageReady(Media $media): void
    {
        if (! $this->enabled()) {
            return;
        }
        Post::whereHas('media', fn ($query) => $query->where('media.id', $media->id))
            ->each(function (Post $post) use ($media): void {
                if ($this->selected($post)->contains('id', $media->id)
                    && app(AutomaticAnalysisAccess::class)->allows($post)) {
                    PrzeanalizujZdjecieWpisu::dispatch((string) $post->id, (string) $media->id)->afterCommit();
                }
            });
    }
}

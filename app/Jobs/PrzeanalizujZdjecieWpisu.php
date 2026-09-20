<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\AutomaticAnalysisAccess;
use App\Domain\Moderation\PostImageAnalysis;
use App\Models\Media;
use App\Models\Post;
use App\Moderacja\OcenaModelem;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Jeden wariant, jedno HTTP; tekst i inne zdjęcia mają własne zadania. */
class PrzeanalizujZdjecieWpisu implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public string $postId, public string $mediaId)
    {
        $this->onQueue('low');
    }

    public function uniqueId(): string
    {
        return $this->postId.':'.$this->mediaId;
    }

    public function handle(PostImageAnalysis $images, AutomaticAnalysisAccess $access, OcenaModelem $model,
        OznaczDoPrzegladu $mark, AlarmujModeratora $alarm): void
    {
        try {
            $post = Post::find($this->postId);
            if (! $images->enabled() || $post === null || ! $access->allows($post)) {
                return;
            }
            $media = $images->selected($post)->firstWhere('id', $this->mediaId);
            if (! $media instanceof Media || $media->status !== Media::STATUS_READY) {
                return;
            }
            $signals = $model->dlaZdjecia($media);
            if (! $access->allows($post) || ! $images->selected($post)->contains('id', $this->mediaId)) {
                return;
            }
            $report = $mark->handle($post, $signals, uzupelnij: true);
            if ($report !== null) {
                $alarm->handle($report, $signals);
            }
        } catch (Throwable $error) {
            Log::warning('Nie udało się ocenić zdjęcia wpisu.', ['blad' => $error::class]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tags\Actions;

use App\Domain\Tags\InlineTagTokens;
use App\Domain\Tags\TagMutationLock;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use App\Support\LimityTagow;
use Illuminate\Support\Facades\DB;

final class ResolvePostTags
{
    public function __construct(private readonly ResolveTagsForPost $resolver, private readonly InlineTagTokens $tokens) {}

    /**
     * Wywołujący zapisuje wynik i body w tej samej transakcji.
     *
     * @param  list<string>  $manualNames
     * @return array<string, array{position: int, dodany_recznie: bool}>
     */
    public function handle(?string $body, array $manualNames, ?Post $post = null): array
    {
        return DB::transaction(function () use ($body, $manualNames, $post): array {
            TagMutationLock::forPost();
            $preserved = $post === null ? [] : $post->tags()->pluck('tags.id')->all();
            $manual = $this->resolver->handle($manualNames, $preserved);
            $inline = $this->resolver->handleTokens($this->tokens->fromBody($body), $preserved);
            $map = [];
            foreach ($manual as $tag) {
                $map[$tag->getKey()] = ['position' => count($map), 'dodany_recznie' => true];
            }
            foreach ($inline as $tag) {
                if (! isset($map[$tag->getKey()])) {
                    $map[$tag->getKey()] = ['position' => count($map), 'dodany_recznie' => false];
                }
            }
            if (count($map) > LimityTagow::maksTagowNaWpis()) {
                throw new BladDlaCzlowieka(LimityTagow::komunikatZaDuzoTagow());
            }

            return $map;
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Feed\FollowingFeed;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PostResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /api/v1/feed` — wpisy obserwowanych, CHRONOLOGICZNIE (AGENTS.md §8).
 *
 * To samo zapytanie co strona główna na WWW (`FollowingFeed`): te same
 * filtry widoczności, blokad, aktywnych autorów i widocznego przepisu.
 * Kolejna strona: `?cursor=` z `meta.next_cursor`.
 */
class FeedController extends Controller
{
    public function __invoke(Request $request, FollowingFeed $feed): AnonymousResourceCollection
    {
        return PostResource::collection($feed->paginate($request->user()));
    }
}

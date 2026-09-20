<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tags\PodpowiedziTagow;
use App\Support\LimityTagow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TagSuggestionController extends Controller
{
    public function __invoke(Request $request, PodpowiedziTagow $suggestions): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer !== null, 401);
        $data = $request->validate([
            'q' => ['required', 'string', 'min:'.LimityTagow::minZnakow(), 'max:'.config('kuking.tags.suggestions_query_max_length')],
        ]);

        return response()->json($suggestions->dla($data['q'], $viewer))
            ->header('Cache-Control', 'private, no-store');
    }
}

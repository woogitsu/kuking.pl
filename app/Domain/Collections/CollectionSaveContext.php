<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Cel należy do konkretnego formularza, nie do wspólnej sesji dwóch kart. */
final class CollectionSaveContext
{
    /** @return array{save_type: string, save_id: string}|array{} */
    public function parameters(Request $request): array
    {
        $type = $request->input('save_type');
        $id = $request->input('save_id');

        if (! in_array($type, ['recipe', 'post'], true) || ! is_string($id) || ! Str::isUuid($id)) {
            return [];
        }

        return ['save_type' => $type, 'save_id' => $id];
    }

    public function content(Request $request): Recipe|Post|null
    {
        $parameters = $this->parameters($request);
        if ($parameters === []) {
            return null;
        }

        $content = $parameters['save_type'] === 'recipe'
            ? Recipe::find($parameters['save_id'])
            : Post::find($parameters['save_id']);

        return $content !== null && Gate::forUser($request->user())->allows('view', $content) ? $content : null;
    }
}

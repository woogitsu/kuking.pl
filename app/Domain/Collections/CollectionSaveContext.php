<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Cel należy do konkretnego formularza, nie do wspólnej sesji dwóch kart. */
final class CollectionSaveContext
{
    /**
     * Dostaje surowe wartości pól `save_type` i `save_id` (zwykłe wartości,
     * nie żądanie — #970); niepoprawne dają pustą tablicę.
     *
     * @return array{save_type: string, save_id: string}|array{}
     */
    public function parameters(mixed $type, mixed $id): array
    {
        if (! in_array($type, ['recipe', 'post'], true) || ! is_string($id) || ! Str::isUuid($id)) {
            return [];
        }

        return ['save_type' => $type, 'save_id' => $id];
    }

    public function content(mixed $type, mixed $id, ?User $user): Recipe|Post|null
    {
        $parameters = $this->parameters($type, $id);
        if ($parameters === []) {
            return null;
        }

        $content = $parameters['save_type'] === 'recipe'
            ? Recipe::find($parameters['save_id'])
            : Post::find($parameters['save_id']);

        return $content !== null && Gate::forUser($user)->allows('view', $content) ? $content : null;
    }
}

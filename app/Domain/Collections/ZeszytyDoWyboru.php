<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;

final class ZeszytyDoWyboru
{
    /** @return EloquentCollection<int, Collection> */
    public function dla(Request $request): EloquentCollection
    {
        $user = $request->user();
        if ($user === null) {
            return new EloquentCollection;
        }

        // Karty dzielą jedno pobranie, wyłącznie w bieżącym żądaniu.
        $key = self::class.'.'.$user->getKey();
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, $user->collections()
                ->when($user->isSuspended(), fn ($query) => $query->where('visibility', 'private'))
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'visibility', 'is_default']));
        }

        /** @var EloquentCollection<int, Collection> $zeszyty */
        $zeszyty = $request->attributes->get($key);

        return $zeszyty;
    }
}

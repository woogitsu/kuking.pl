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
            // Własne i wspólne (#1743) — ten sam zakres co walidacja zapisu
            // (`CollectionController::regulyWlasnegoZeszytu()`). Własne
            // najpierw, potem cudze, żeby „Zapisane" zostało na górze.
            $request->attributes->set($key, Collection::query()
                ->dostepneDoZapisuDla($user)
                ->when($user->isSuspended(), fn ($query) => $query->where('visibility', 'private'))
                ->with('owner.profile')
                ->orderByRaw('(collections.owner_id = ?) DESC', [$user->getKey()])
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'owner_id', 'name', 'visibility', 'is_default']));
        }

        /** @var EloquentCollection<int, Collection> $zeszyty */
        $zeszyty = $request->attributes->get($key);

        return $zeszyty;
    }
}

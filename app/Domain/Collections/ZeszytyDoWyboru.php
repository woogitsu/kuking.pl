<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class ZeszytyDoWyboru
{
    /**
     * Zeszyty, do których osoba może zapisać treść. Domena nie zna żądania:
     * dostaje osobę albo null, a jedno pobranie na żądanie (wspólne dla kart)
     * zapewnia adapter HTTP `App\Http\Support\ZeszytyZZadania` (#970).
     *
     * @return EloquentCollection<int, Collection>
     */
    public function dla(?User $user): EloquentCollection
    {
        if ($user === null) {
            return new EloquentCollection;
        }

        // Własne i wspólne (#1743) — ten sam zakres co walidacja zapisu
        // (`CollectionController::regulyWlasnegoZeszytu()`). Własne
        // najpierw, potem cudze, żeby „Zapisane" zostało na górze.
        return Collection::query()
            ->dostepneDoZapisuDla($user)
            ->when($user->isSuspended(), fn ($query) => $query->where('visibility', 'private'))
            ->with('owner.profile')
            ->orderByRaw('(collections.owner_id = ?) DESC', [$user->getKey()])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'owner_id', 'name', 'visibility', 'is_default']);
    }

    /**
     * Domyślny zeszyt, gdy jest publiczny — cel szybkiego „Zapisuję” bez
     * wyboru zeszytu (issue #1400). Przy prywatnym zwraca null, bo wtedy
     * przycisk nie potrzebuje żadnej wskazówki. Wybiera z już pobranej listy
     * (`dla()`), więc karty nie dokładają zapytań.
     *
     * @param  EloquentCollection<int, Collection>  $zeszyty
     */
    public function publicznyDomyslny(EloquentCollection $zeszyty): ?Collection
    {
        $domyslny = $zeszyty->firstWhere('is_default', true);

        return $domyslny?->isPublic() ? $domyslny : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Przepisy z zeszytu, które wolno pokazać oglądającemu — kontrakt dla
 * „Wybrane przepisy z zeszytu na jeden dzień” (#2483).
 *
 * Kontrakt po stronie Planera, implementacja w `Collections`
 * (`WidocznaZawartoscZeszytu`), wiązanie w `AppServiceProvider`. Bez niego
 * import `Collections` w Planerze zamykał cykl `Users → Planer → Collections →
 * Users` (`GrafModulowDomenyBezCykliTest`).
 */
interface PrzepisyZeszytuDlaPlanu
{
    /** @return BelongsToMany<Recipe, Collection> */
    public function przepisy(Collection $collection, ?User $viewer): BelongsToMany;
}

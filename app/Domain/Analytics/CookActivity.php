<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Trzy źródła aktywności „cook", ujednolicone do `(user_id, activity_at)` —
 * dokładnie `weekly_cook_activity` z `docs/seo/ANALYTICS.md` §2.2/§3.2.
 *
 * WSPÓLNE DLA `WeeklyActiveCooks` I `CookRetentionCohorts`
 * Obie klasy liczą z tego samego zbioru zdarzeń („opublikowany post LUB
 * przepis LUB Ugotowałem"), różni je tylko sposób grupowania w czasie.
 * Jedna kopia tego zapytania — poprawka `deleted_at`/statusu w jednym miejscu
 * naprawia obie metryki naraz, zamiast wymagać pamiętania o drugiej.
 *
 * Soft-delete wykluczony przez global scope `SoftDeletes` na `Post`/`Recipe`
 * (uruchamiany automatycznie przy `->toBase()`); `cooked_events` nie ma
 * soft delete w MVP, więc liczone są wszystkie wiersze.
 */
final class CookActivity
{
    /**
     * `$liczeni` — gdy podane, odcina konta, które nie liczą się do metryk
     * (`CookEligibility::tylkoLiczeni()`), w każdej gałęzi UNION osobno.
     */
    public function unionQuery(?CookEligibility $liczeni = null): QueryBuilder
    {
        $posty = Post::query()
            ->select('author_id as user_id', 'published_at as activity_at')
            ->published();

        $przepisy = Recipe::query()
            ->select('author_id as user_id', 'published_at as activity_at')
            ->published();

        $ugotowania = CookedEvent::query()
            ->select('user_id', 'cooked_at as activity_at');

        if ($liczeni !== null) {
            $liczeni->tylkoLiczeni($posty, 'posts.author_id');
            $liczeni->tylkoLiczeni($przepisy, 'recipes.author_id');
            $liczeni->tylkoLiczeni($ugotowania, 'cooked_events.user_id');
        }

        return $posty->toBase()->unionAll($przepisy->toBase())->unionAll($ugotowania->toBase());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Support\Czas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Weekly Active Cooks — North Star z `docs/seo/ANALYTICS.md` §2.2.
 *
 * Definicja NIE zmienia się w tej klasie (issue #114 tego nie prosi):
 * tydzień kalendarzowy poniedziałek-niedziela wg `date_trunc('week', …)`
 * Postgresa, kwalifikuje `CookActivity` (post/przepis/„Ugotowałem").
 *
 * Jedyna zmiana wobec §2.2: `CookEligibility::tylkoLiczeni()` odcina
 * konto gospodarza, konta zbanowane/`pending_delete` i konta testowe, ZANIM
 * cokolwiek trafi do agregacji — patrz ta klasa po uzasadnienie.
 */
final class WeeklyActiveCooks
{
    public function __construct(
        private readonly CookEligibility $eligibility = new CookEligibility,
        private readonly CookActivity $activity = new CookActivity,
    ) {}

    /**
     * Tygodnie od najstarszego do najnowszego, z liczbą WAC każdego.
     *
     * `$ileTygodni` ogranicza wynik do tylu NAJNOWSZYCH tygodni z aktywnością
     * (null = wszystkie). Ograniczenie działa PO agregacji, więc nie zmienia
     * ani definicji tygodnia, ani tego, kto się liczy — tylko to, ile wierszy
     * wynikowych pokazujemy.
     *
     * @return Collection<int, object{week_start: string, week_end: string, weekly_active_cooks: int}>
     */
    public function weekly(?int $ileTygodni = null): Collection
    {
        // Tydzień POLSKI, nie tydzień sesji bazy. Bez `at time zone`
        // aktywność z poniedziałku 00:30 czasu polskiego wpadała do tygodnia
        // poprzedniego, a cała granica tygodnia zależała od domyślnej strefy
        // serwera Postgresa — ustawienia, którego to repozytorium nie
        // kontroluje. Patrz `Czas::wStrefieCzlowieka()`.
        $tydzien = "date_trunc('week', ".Czas::wStrefieCzlowieka('activity_at').')';

        $zapytanie = DB::query()
            ->fromSub($this->activity->unionQuery($this->eligibility), 'weekly_cook_activity')
            ->selectRaw("{$tydzien}::date as week_start")
            ->selectRaw("({$tydzien}::date + interval '6 days')::date as week_end")
            ->selectRaw('count(distinct user_id) as weekly_active_cooks')
            ->groupByRaw($tydzien)
            ->orderByRaw("{$tydzien} desc");

        if ($ileTygodni !== null) {
            $zapytanie->limit($ileTygodni);
        }

        return $zapytanie->get()->sortBy('week_start')->values();
    }
}

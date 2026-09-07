<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\User;
use App\Support\Czas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kohorta retencji tygodniowej „cook activity" — `docs/seo/ANALYTICS.md` §3.2.
 *
 * Ta sama poprawka co `WeeklyActiveCooks` (issue #114): `CookEligibility`
 * odcina konto gospodarza, konta zbanowane/`pending_delete` i konta testowe —
 * zarówno z tygodnia rejestracji (`user_weeks`), jak i z aktywności
 * (przez `CookActivity`, patrz tam). Bez wykluczenia z `user_weeks` gospodarz
 * i tak nie pojawiłby się w wyniku (złączenie z pustą aktywnością nic nie da),
 * ale dwa miejsca wykluczenia to jaśniejszy dowód, że to jest TA SAMA reguła,
 * a nie efekt uboczny czego innego.
 *
 * `week_offset = 0` to tydzień rejestracji, `1` to D7-ish retencja, `4` to
 * D30-ish — dzielenie `active_users` z danego `week_offset` przez
 * `active_users` przy `week_offset = 0` tej samej kohorty daje % retencji
 * (patrz doc).
 */
final class CookRetentionCohorts
{
    public function __construct(
        private readonly CookEligibility $eligibility = new CookEligibility,
        private readonly CookActivity $activity = new CookActivity,
    ) {}

    /**
     * @return Collection<int, object{signup_week: string, week_offset: int, active_users: int}>
     */
    public function weekly(): Collection
    {
        $wykluczeni = $this->eligibility->excludedUserIds();

        // Ta sama strefa co w `WeeklyActiveCooks` i z tego samego powodu —
        // patrz `Czas::wStrefieCzlowieka()`. Tydzień rejestracji i tydzień
        // aktywności MUSZĄ być liczone tak samo, bo `week_offset` to różnica
        // między nimi: rozjazd o jeden dzień przesuwałby całe kohorty.
        $tydzienRejestracji = "date_trunc('week', ".Czas::wStrefieCzlowieka('created_at').')';
        $tydzienAktywnosci = "date_trunc('week', ".Czas::wStrefieCzlowieka('activity_at').')';

        $userWeeks = User::query()
            ->select('id as user_id')
            ->selectRaw("{$tydzienRejestracji}::date as signup_week")
            ->when($wykluczeni !== [], fn ($q) => $q->whereNotIn('id', $wykluczeni))
            ->toBase();

        $activityWeeks = DB::query()
            ->fromSub($this->activity->unionQuery($wykluczeni), 'activity')
            ->select('user_id')
            ->selectRaw("{$tydzienAktywnosci}::date as activity_week")
            ->groupBy('user_id')
            ->groupByRaw("{$tydzienAktywnosci}::date");

        return DB::query()
            ->fromSub($userWeeks, 'uw')
            ->joinSub($activityWeeks, 'aw', function ($join): void {
                $join->on('aw.user_id', '=', 'uw.user_id')
                    ->whereColumn('aw.activity_week', '>=', 'uw.signup_week');
            })
            ->select('uw.signup_week')
            ->selectRaw('((aw.activity_week - uw.signup_week) / 7)::int as week_offset')
            ->selectRaw('count(distinct uw.user_id) as active_users')
            ->groupBy('uw.signup_week')
            ->groupByRaw('((aw.activity_week - uw.signup_week) / 7)::int')
            ->orderBy('uw.signup_week')
            ->orderBy('week_offset')
            ->get();
    }
}

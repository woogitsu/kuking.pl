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
 * D30-ish.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PRZEZ CO DZIELIĆ — I DLACZEGO NIE PRZEZ `week_offset = 0`
 *  (audyt zewnętrzny, G07)
 * ────────────────────────────────────────────────────────────────────────
 *
 * Stało tu: „dzielenie `active_users` z danego `week_offset` przez
 * `active_users` przy `week_offset = 0` tej samej kohorty daje % retencji".
 * To jest NIEPRAWDA i nie jest to niedokładność — ta formuła potrafi dać
 * ponad 100%.
 *
 * ZMIERZONE, przez `kuking:raport`: troje ludzi rejestruje się w tym samym
 * tygodniu, jedna osoba publikuje w tygodniu rejestracji, DWIE INNE dopiero
 * w tygodniu czwartym. Raport wypisał:
 *
 *     tydzień 2026-07-27: 1 na starcie · po tygodniu 0 (0,0%) · po miesiącu 2 (200,0%)
 *
 * Powód: zapytanie NIE wymaga, żeby to byli ci sami ludzie. Liczy, ilu
 * członków kohorty było aktywnych w danym tygodniu — a zbiór aktywnych
 * w tygodniu 4 nie zawiera się w zbiorze aktywnych w tygodniu 0. Ktoś, kto
 * zarejestrował się i odezwał dopiero po miesiącu, jest w liczniku, ale nie
 * w mianowniku.
 *
 * MIANOWNIKIEM JEST ROZMIAR KOHORTY: wszyscy liczeni ludzie, którzy
 * zarejestrowali się w danym tygodniu, niezależnie od tego, czy cokolwiek
 * zrobili. Daje to `rozmiaryKohort()`. Wtedy `week_offset = 0` przestaje być
 * mianownikiem, a staje się pierwszą wartością krzywej („ilu z nich w ogóle
 * ruszyło w pierwszym tygodniu") — i żaden procent nie przekracza 100.
 *
 * `weekly()` zwraca WYŁĄCZNIE tygodnie, w których ktoś coś zrobił. Kohorta,
 * w której nie odezwał się nikt, nie ma tu ANI JEDNEGO wiersza — dlatego
 * rozmiary są osobną metodą, a nie kolumną w tych wierszach. To nie jest
 * szczegół techniczny: kohorta całkowicie cicha jest najważniejszym
 * sygnałem dla bramki V1, a przy złączeniu po aktywności znikała z raportu
 * zamiast pokazać zero.
 */
final class CookRetentionCohorts
{
    public function __construct(
        private readonly CookEligibility $eligibility = new CookEligibility,
        private readonly CookActivity $activity = new CookActivity,
    ) {}

    /**
     * @return Collection<int, \stdClass> wiersze `DB::query()`: signup_week, week_offset, active_users
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

    /**
     * Ilu LICZONYCH ludzi zarejestrowało się w każdym tygodniu — mianownik
     * retencji.
     *
     * Bez złączenia z aktywnością, więc widać także kohorty, w których nie
     * odezwał się nikt. To jest cała różnica względem `weekly()` i cały
     * powód istnienia tej metody.
     *
     * To samo wykluczenie (`CookEligibility`) i ta sama strefa czasowa co
     * w `weekly()` — mianownik liczony inną regułą niż licznik dawałby
     * procent, którego nie da się obronić.
     *
     * @return Collection<string, int> klucz: `signup_week` w formacie `Y-m-d`
     */
    public function rozmiaryKohort(): Collection
    {
        $wykluczeni = $this->eligibility->excludedUserIds();

        $tydzienRejestracji = "date_trunc('week', ".Czas::wStrefieCzlowieka('created_at').')';

        return User::query()
            ->selectRaw("{$tydzienRejestracji}::date as signup_week")
            ->selectRaw('count(*) as ilu')
            ->when($wykluczeni !== [], fn ($q) => $q->whereNotIn('id', $wykluczeni))
            ->groupByRaw("{$tydzienRejestracji}::date")
            ->orderByRaw("{$tydzienRejestracji}::date")
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $w): array => [(string) $w->signup_week => (int) $w->ilu]);
    }
}

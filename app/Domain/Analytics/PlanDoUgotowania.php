<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pętla „Planuję → Ugotowałem" — pomiar planera tygodnia (#27, D-310).
 *
 * D-310 wdrożyło sam planer, a listę zakupów odłożyło do chwili, gdy wiadomo,
 * czy ktokolwiek z planera korzysta. Ta klasa odpowiada na jedno pytanie:
 * czy przepis wpisany w dzień planu kończy się „Ugotowałem" w tym dniu albo
 * tuż po nim. Niczego nie wysyła i niczego nie zmienia w planie.
 *
 * JEDNOSTKA: POZYCJA PLANU Z PRZEPISEM
 * Wiersz `meal_plan_entries` z `recipe_id` (osoba + dzień + przepis; indeks
 * unikalny gwarantuje, że ten sam przepis nie stoi dwa razy w jednym dniu).
 * Własne wpisy tekstowe („obiad u mamy") i pozycje po twardo usuniętym
 * przepisie (`recipe_id` = NULL) nie mają czego „ugotować" w sensie
 * `cooked_events` i nie wchodzą do pomiaru. Własne przepisy autora liczą się
 * tak samo jak cudze: zaplanować i ugotować własny przepis to prawdziwe użycie
 * planera (inaczej niż zapis własnego przepisu w Zeszycie).
 *
 * LICZNIK
 * Pozycja jest konwersją, gdy ta sama osoba ma `cooked_events` tego przepisu
 * z `cooked_at` NIE WCZEŚNIEJSZYM niż chwila dodania pozycji do planu
 * (`created_at`) i z DATĄ (w strefie `kuking.strefa`, tej samej, w której
 * człowiek wybiera dzień w planerze) od dnia planu do dnia planu + 3 dni
 * włącznie. Ugotowanie sprzed dodania do planu nie jest skutkiem planu.
 * Ugotowanie dzień przed planem też nie — miernik jest ostrożny i może
 * zaniżać, nie zawyża. Kilka ugotowań w oknie to jedna konwersja.
 * Porównanie robi `AT TIME ZONE` na `timestamptz`, więc strefa sesji
 * PostgreSQL nie przesuwa granic.
 *
 * KOHORTA: PEŁNA I ZAMKNIĘTA
 * Pozycje z dniem planu od (dziś − 33) do (dziś − 4) włącznie, gdzie „dziś" to
 * data w strefie człowieka. Każda miała już pełne okno (dzień planu + 3),
 * więc zero w liczniku znaczy „nie ugotowali", nie „jeszcze nie zdążyli".
 * Pozycje z dniem późniejszym (dzisiejsze, świeże i przyszłe) są liczone
 * osobno (`w_oknie_obserwacji`) i NIGDY nie wchodzą do mianownika.
 *
 * MAŁA PRÓBA
 * Poniżej `MINIMUM_POZYCJI` pozycji w kohorcie procent jest `null`, a raport
 * pisze „za mało danych" — jak w `ZapisDoUgotowania`. Celu procentowego nie
 * ma: dopiero pierwszy pomiar da punkt odniesienia.
 *
 * KTO SIĘ NIE LICZY
 * Osoby wykluczone przez `CookEligibility` (gospodarz, konta testowe,
 * zalążkowe, zamknięte), jak w pozostałych sekcjach `kuking:raport`.
 *
 * ZNANE OGRANICZENIA
 * Usunięcie pozycji z planu kasuje wiersz, więc taka pozycja znika z pomiaru
 * (zaniża mianownik, gdy ktoś sprząta plan). „Skopiuj poprzedni tydzień"
 * tworzy pozycje bez świadomego wyboru — ich konwersja jest zwykle niższa;
 * rozróżnienia nie ma i nie dodajemy dla niego kolumny ani zdarzenia.
 *
 * Wynik jest wyłącznie zbiorczy: liczby i procent. Bez identyfikatorów osób,
 * tytułów przepisów, etykiet planu i bez rankingu.
 */
final class PlanDoUgotowania
{
    /** Ile dni PO dniu planu nadal zalicza ugotowanie do planu. */
    public const DNI_PO_PLANIE = 3;

    /** Szerokość kohorty w dniach planu. */
    public const SZEROKOSC_KOHORTY = 30;

    public const MINIMUM_POZYCJI = 20;

    public function __construct(private readonly CookEligibility $eligibility = new CookEligibility) {}

    /**
     * @return array{
     *     dzien_od: string,
     *     dzien_do: string,
     *     w_kohorcie: int,
     *     ugotowane: int,
     *     procent: float|null,
     *     w_oknie_obserwacji: int,
     * }
     */
    public function policz(?CarbonImmutable $teraz = null): array
    {
        $teraz ??= CarbonImmutable::now();
        $strefa = (string) config('kuking.strefa', 'Europe/Warsaw');
        $dzisiaj = $teraz->setTimezone($strefa)->startOfDay();

        $dzienDo = $dzisiaj->subDays(self::DNI_PO_PLANIE + 1)->toDateString();
        $dzienOd = $dzisiaj->subDays(self::DNI_PO_PLANIE + self::SZEROKOSC_KOHORTY)->toDateString();

        $pozycje = DB::table('meal_plan_entries as p')
            ->whereNotNull('p.recipe_id')
            ->tap(fn ($q) => $this->eligibility->tylkoLiczeni($q, 'p.user_id'))
            ->select('p.user_id', 'p.recipe_id', 'p.day', 'p.created_at');

        $wKohorcie = 'z.day >= ? AND z.day <= ?';
        $po = self::DNI_PO_PLANIE;

        $wiersz = DB::query()
            ->fromSub($pozycje, 'z')
            ->selectRaw("count(*) FILTER (WHERE {$wKohorcie}) AS w_kohorcie", [$dzienOd, $dzienDo])
            ->selectRaw(
                "count(*) FILTER (WHERE {$wKohorcie} AND EXISTS ("
                .'SELECT 1 FROM cooked_events ce'
                .' WHERE ce.user_id = z.user_id AND ce.recipe_id = z.recipe_id'
                .' AND ce.cooked_at >= z.created_at'
                .' AND (ce.cooked_at AT TIME ZONE ?)::date >= z.day'
                ." AND (ce.cooked_at AT TIME ZONE ?)::date <= z.day + {$po}"
                .')) AS ugotowane',
                [$dzienOd, $dzienDo, $strefa, $strefa],
            )
            ->selectRaw('count(*) FILTER (WHERE z.day > ?) AS w_oknie_obserwacji', [$dzienDo])
            ->first();

        $pozycji = (int) ($wiersz->w_kohorcie ?? 0);
        $ugotowane = (int) ($wiersz->ugotowane ?? 0);

        return [
            'dzien_od' => $dzienOd,
            'dzien_do' => $dzienDo,
            'w_kohorcie' => $pozycji,
            'ugotowane' => $ugotowane,
            'procent' => $pozycji < self::MINIMUM_POZYCJI ? null : round($ugotowane / $pozycji * 100, 1),
            'w_oknie_obserwacji' => (int) ($wiersz->w_oknie_obserwacji ?? 0),
        ];
    }
}

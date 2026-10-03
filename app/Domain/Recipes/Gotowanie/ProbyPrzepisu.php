<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Domain\Recipes\Historia\HistoriaWersji;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * „Moje próby tego przepisu" — prywatna historia własnych wykonań jednego
 * przepisu (V2, #2412).
 *
 * To WIDOK, nie nowe dane: wykonania, ich notatki, czasy i zdjęcia już leżą
 * w `cooked_events` i `cooked_event_media`. Nic się tu nie zapisuje, więc
 * eksport danych i usunięcie konta obejmują je tak jak dotąd (tabela jest ta
 * sama), a cudzych treści nie ma skąd skopiować.
 *
 * GRANICE
 *  - zapytanie jest wiązane z `user_id` OSOBY, KTÓRA PATRZY: wykonania innych
 *    osób (w tym autora przepisu) nie trafiają ani do SQL, ani do HTML;
 *  - kto wywołuje, musi najpierw sprawdzić `RecipePolicy::view` — przepis
 *    usunięty, ukryty, prywatny albo za blokadą nie ma tu historii ani nawet
 *    tytułu (kontroler odpowiada 404);
 *  - numery wersji pokazujemy wyłącznie, gdy `HistoriaWersji::wolnoOgladac`
 *    pozwala i wersja nie jest ukryta — ta sama reguła co `WersjaWykonania`,
 *    ale jednym zapytaniem na stronę, nie jednym na próbę;
 *  - kolejność jest chronologiczna i deterministyczna: `cooked_at`, potem `id`.
 *
 * RÓŻNICE to metadane (wersja, rzeczywisty czas) liczone względem poprzedniej
 * próby tej samej osoby, także poprzez granicę strony. Tekstów notatek nie
 * porównujemy i nie streszczamy — stoją obok siebie, a podstawowa historia
 * działa bez AI.
 */
final class ProbyPrzepisu
{
    public const NA_STRONE = 12;

    /**
     * @return array{
     *     proby: LengthAwarePaginator<int, CookedEvent>,
     *     wersje: Collection<string, RecipeVersion>,
     *     roznice: array<string, list<string>>,
     *     numeracja: array<string, int>,
     *     razem: int
     * }
     */
    public static function dla(User $user, Recipe $przepis): array
    {
        $proby = CookedEvent::query()
            ->where('user_id', $user->getKey())
            ->where('recipe_id', $przepis->getKey())
            ->orderBy('cooked_at')
            ->orderBy('id')
            ->with('media')
            ->paginate(self::NA_STRONE);

        /** @var Collection<int, CookedEvent> $strona */
        $strona = collect($proby->items());
        $pierwsza = $strona->first();
        $poprzednia = $pierwsza === null ? null : self::poprzedniaPrzed($user, $przepis, $pierwsza);

        $idWersji = $strona->pluck('recipe_version_id')
            ->push($poprzednia?->recipe_version_id)
            ->filter()
            ->unique()
            ->values();

        $wersje = $idWersji->isEmpty() || ! HistoriaWersji::wolnoOgladac($user, $przepis)
            ? collect()
            : HistoriaWersji::zapytanie($przepis, HistoriaWersji::widziUkryte($user, $przepis))
                ->select(['id', 'recipe_id', 'version_number'])
                ->whereIn('id', $idWersji->all())
                ->get()
                ->keyBy(fn (RecipeVersion $wersja): string => (string) $wersja->getKey());

        $roznice = [];
        $numeracja = [];
        $numer = ($proby->currentPage() - 1) * self::NA_STRONE;

        foreach ($strona as $proba) {
            $numeracja[(string) $proba->getKey()] = ++$numer;
            $roznice[(string) $proba->getKey()] = self::roznice($proba, $poprzednia, $wersje);
            $poprzednia = $proba;
        }

        return [
            'proby' => $proby,
            'wersje' => $wersje,
            'roznice' => $roznice,
            'numeracja' => $numeracja,
            'razem' => $proby->total(),
        ];
    }

    /** Ostatnia próba PRZED tą w kolejności `cooked_at`, `id` — albo `null`, gdy to pierwsza. */
    private static function poprzedniaPrzed(User $user, Recipe $przepis, CookedEvent $proba): ?CookedEvent
    {
        return CookedEvent::query()
            ->where('user_id', $user->getKey())
            ->where('recipe_id', $przepis->getKey())
            ->where(function ($q) use ($proba): void {
                $q->where('cooked_at', '<', $proba->cooked_at)
                    ->orWhere(function ($r) use ($proba): void {
                        $r->where('cooked_at', $proba->cooked_at)->where('id', '<', $proba->getKey());
                    });
            })
            ->orderByDesc('cooked_at')
            ->orderByDesc('id')
            ->select(['id', 'cooked_at', 'actual_minutes', 'recipe_version_id'])
            ->first();
    }

    /**
     * Wersja i rzeczywisty czas oceniane osobno: zmiana, zgodność albo brak danych.
     *
     * @param  Collection<string, RecipeVersion>  $wersje
     * @return list<string>
     */
    private static function roznice(CookedEvent $proba, ?CookedEvent $poprzednia, Collection $wersje): array
    {
        if ($poprzednia === null) {
            return ['To pierwsza zapisana próba.'];
        }

        $wersjePorownywalne = $proba->recipe_version_id !== null && $poprzednia->recipe_version_id !== null;
        $czasPorownywalny = $proba->actual_minutes !== null && $poprzednia->actual_minutes !== null;
        $wersjaZmieniona = $wersjePorownywalne && $proba->recipe_version_id !== $poprzednia->recipe_version_id;
        $czasZmieniony = $czasPorownywalny && (int) $proba->actual_minutes !== (int) $poprzednia->actual_minutes;

        if (($wersjePorownywalne && $czasPorownywalny) && ! $wersjaZmieniona && ! $czasZmieniony) {
            return ['Wersja i czas bez zmian względem poprzedniej próby.'];
        }

        if (! $wersjePorownywalne && ! $czasPorownywalny) {
            return ['Brak danych do porównania wersji i czasu z poprzednią próbą.'];
        }

        $roznice = [];
        if ($wersjaZmieniona) {
            $nowa = $wersje->get((string) $proba->recipe_version_id)?->version_number;
            $stara = $wersje->get((string) $poprzednia->recipe_version_id)?->version_number;

            $roznice[] = $nowa !== null && $stara !== null
                ? 'Wersja przepisu: '.$nowa.' (poprzednio '.$stara.').'
                : 'Wersja przepisu inna niż przy poprzedniej próbie.';
        } else {
            $roznice[] = $wersjePorownywalne
                ? 'Wersja przepisu bez zmian względem poprzedniej próby.'
                : 'Brak danych do porównania wersji przepisu z poprzednią próbą.';
        }

        if ($czasZmieniony) {
            $roznice[] = 'Rzeczywisty czas: '.Czas::czasPrzepisu((int) $proba->actual_minutes)
                .' (poprzednio '.Czas::czasPrzepisu((int) $poprzednia->actual_minutes).').';
        } else {
            $roznice[] = $czasPorownywalny
                ? 'Rzeczywisty czas bez zmian względem poprzedniej próby.'
                : 'Brak danych do porównania rzeczywistego czasu z poprzednią próbą.';
        }

        return $roznice;
    }
}

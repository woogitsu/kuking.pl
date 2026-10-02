<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Domain\Media\WgladZUrzedu;
use App\Models\RecentRecipeView;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

/**
 * Opcjonalna, prywatna lista ostatnio oglądanych przepisów (#2553, V2).
 *
 * TO SĄ DANE O ZACHOWANIU CZŁOWIEKA, więc reguły są wąskie i wszystkie tutaj:
 *
 * - DOMYŚLNIE WYŁĄCZONA. Zapis wizyty zaczyna się dopiero po jawnym
 *   `wlacz()` (przycisk w ustawieniach). Nie rekonstruujemy dawnych wizyt.
 * - Zapamiętujemy minimum: identyfikator przepisu i czas OSTATNIEJ wizyty.
 *   Ten sam przepis to jedna pozycja (`UNIQUE (user_id, recipe_id)`).
 * - LIMIT i CZAS ŻYCIA (`kuking.ostatnio_ogladane.limit` / `.dni`) działają
 *   trzy razy: przy zapisie (przycina do limitu), przy ODCZYCIE (starsze
 *   i nadliczbowe pozycje są niewidoczne, zanim posprząta je zadanie) i w
 *   nocnym `kuking:sprzataj-ostatnio-ogladane`.
 * - `wylacz()` i `wyczysc()` kasują zapisane wizyty OD RAZU, w jednej transakcji.
 * - Lista nie służy do niczego poza powrotem do przepisu: nie trafia do
 *   rankingów, rekomendacji, feedu, statystyk, powiadomień, zeszytu, planera,
 *   postępu gotowania ani `cooked_events`. Nikt poza właścicielem jej nie widzi.
 * - KAŻDE pokazanie listy pyta ponownie o widoczność (`widoczneDla()` i Policy
 *   `view`, jak zwykły widz BEZ roli obsługi). Przepis prywatny, usunięty,
 *   ukryty albo od zablokowanej osoby znika, a jego tytuł, autor i zdjęcie
 *   nie opuszczają bazy. Wglądy moderacyjne nie zostawiają pozycji.
 *
 * ZAPIS NIE SPOWALNIA STRONY PRZEPISU. `zaplanujZapis()` odkłada całą robotę
 * (wczytanie, Policy, upsert) na czas PO wysłaniu odpowiedzi (`defer()`), a w
 * ścieżce żądania nie robi żadnego zapytania: stan włączenia jest już w
 * zalogowanym modelu `User`. Gość i osoba z wyłączoną funkcją nie zapisują nic
 * i ich odpowiedź jest bajt w bajt taka jak przed tą funkcją.
 */
class OstatnioOgladane
{
    /** Nazwa odroczonego zapisu — drugie wywołanie w tym samym żądaniu zastępuje pierwsze. */
    private const ODROCZONY = 'kuking.ostatnio-ogladane';

    public function wlacz(User $osoba): void
    {
        DB::table('users')
            ->where('id', $osoba->getKey())
            ->whereNull('ostatnio_ogladane_wlaczone_at')
            ->update(['ostatnio_ogladane_wlaczone_at' => now()]);

        $osoba->refresh();
    }

    /** Wyłączenie kasuje historię od razu — flaga i wiersze w jednej transakcji. */
    public function wylacz(User $osoba): int
    {
        $ile = DB::transaction(function () use ($osoba): int {
            DB::table('users')
                ->where('id', $osoba->getKey())
                ->update(['ostatnio_ogladane_wlaczone_at' => null]);

            return DB::table('recent_recipe_views')->where('user_id', $osoba->getKey())->delete();
        });

        $osoba->refresh();

        return $ile;
    }

    /** „Wyczyść”: kasuje zapisane wizyty, funkcja zostaje włączona. */
    public function wyczysc(User $osoba): int
    {
        return DB::table('recent_recipe_views')->where('user_id', $osoba->getKey())->delete();
    }

    /**
     * Odkłada zapis wizyty na czas po odpowiedzi. Nic nie robi dla gościa,
     * dla autora własnego przepisu i dla osoby z wyłączoną funkcją — i nie
     * wykonuje w tym żądaniu ani jednego zapytania.
     */
    public function zaplanujZapis(?User $osoba, Recipe $recipe): void
    {
        if ($osoba === null
            || ! $osoba->maWlaczoneOstatnioOgladane()
            || $osoba->getKey() === $recipe->author_id) {
            return;
        }

        $osobaId = (string) $osoba->getKey();
        $przepisId = (string) $recipe->getKey();
        $chwila = now();

        defer(fn () => $this->zapiszPoOdpowiedzi($osobaId, $przepisId, $chwila), self::ODROCZONY);
    }

    /** Zapis teraz — dla testów domeny i wywołań spoza HTTP. */
    public function zapisz(User $osoba, Recipe $recipe): void
    {
        $this->zapiszPoOdpowiedzi((string) $osoba->getKey(), (string) $recipe->getKey(), now());
    }

    /**
     * Pozycje do pokazania: tylko niewygasłe, w limicie, w kolejności od
     * najnowszej, i tylko przepisy, które osoba widzi DZIŚ jak zwykły widz.
     * Wyłączona funkcja zawsze daje pustą listę.
     *
     * @return Collection<int, array{recipe: Recipe, viewed_at: CarbonImmutable}>
     */
    public function lista(User $osoba): Collection
    {
        if (! $osoba->maWlaczoneOstatnioOgladane()) {
            return collect();
        }

        $pozycje = RecentRecipeView::query()
            ->where('user_id', $osoba->getKey())
            ->where('viewed_at', '>', $this->granicaCzasu())
            ->orderByDesc('viewed_at')
            ->orderByDesc('id')
            ->limit($this->limit())
            ->get();

        if ($pozycje->isEmpty()) {
            return collect();
        }

        $jakZwykleKonto = WgladZUrzedu::jakZwykleKonto($osoba);

        $przepisy = Recipe::query()
            ->widoczneDla($jakZwykleKonto)
            ->whereKey($pozycje->pluck('recipe_id')->all())
            ->with(['author.profile.avatar', 'heroMedia'])
            ->get()
            ->keyBy(fn (Recipe $przepis): string => (string) $przepis->getKey());

        $gate = Gate::forUser($jakZwykleKonto);

        return $pozycje
            ->map(function (RecentRecipeView $pozycja) use ($przepisy, $gate): ?array {
                $przepis = $przepisy->get($pozycja->recipe_id);

                // Druga bramka po zakresie: konto autora zbanowane albo kasowane
                // i inne reguły Policy, których samo zapytanie nie zna.
                if ($przepis === null || ! $gate->allows('view', $przepis)) {
                    return null;
                }

                return ['recipe' => $przepis, 'viewed_at' => $pozycja->viewed_at];
            })
            ->filter()
            ->values();
    }

    /**
     * Nocne sprzątanie: wizyty po terminie, nadliczbowe ponad limit i
     * osierocone (osoba z wyłączoną funkcją). `$wszystkie` kasuje też
     * niewygasłe (tylko do wycofania migracji).
     */
    public function posprzataj(bool $naSucho = false, bool $wszystkie = false): int
    {
        $zapytanie = DB::table('recent_recipe_views');

        if (! $wszystkie) {
            $granica = $this->granicaCzasu();
            $limit = $this->limit();

            $zapytanie->where(function ($warunek) use ($granica, $limit): void {
                $warunek->where('viewed_at', '<=', $granica)
                    ->orWhereIn('user_id', function ($sub): void {
                        $sub->select('id')->from('users')->whereNull('ostatnio_ogladane_wlaczone_at');
                    })
                    ->orWhereIn('id', function ($sub) use ($limit): void {
                        $sub->select('id')->fromSub(
                            DB::table('recent_recipe_views')->selectRaw(
                                'id, row_number() over (partition by user_id order by viewed_at desc, id desc) as miejsce',
                            ),
                            'ponumerowane',
                        )->where('miejsce', '>', $limit);
                    });
            });
        }

        return $naSucho ? $zapytanie->count() : $zapytanie->delete();
    }

    /**
     * Właściwy zapis, uruchamiany PO odpowiedzi. Dostaje tylko identyfikatory
     * i chwilę żądania — nie modele (po odpowiedzi mogły się zmienić).
     */
    private function zapiszPoOdpowiedzi(string $osobaId, string $przepisId, CarbonInterface $chwila): void
    {
        try {
            $osoba = User::query()->find($osobaId);
            $przepis = Recipe::query()->with('author')->find($przepisId);

            if ($osoba === null || $przepis === null || $osoba->getKey() === $przepis->author_id) {
                return;
            }

            // WGLĄD MODERACYJNY NIE JEST WIZYTĄ: pytamy o widoczność jak o konto
            // BEZ roli obsługi. Przepis ukryty, zdjęty albo od zablokowanej osoby
            // nie zostawia pozycji nawet wtedy, gdy moderator miał prawo go otworzyć.
            if (! Gate::forUser(WgladZUrzedu::jakZwykleKonto($osoba))->allows('view', $przepis)) {
                return;
            }

            DB::transaction(function () use ($osobaId, $przepisId, $chwila): void {
                // `FOR SHARE` na wierszu konta: wyłączenie funkcji (UPDATE tego
                // wiersza) czeka na ten zapis albo ten zapis czeka na nie i po
                // wyłączeniu nie wstawia już nic. Warunki stanu są w samym
                // zapytaniu, bo funkcję mogło wyłączyć drugie okno w trakcie żądania.
                $zamkniete = User::STATUSY_ZAMKNIETEGO_KONTA;
                DB::statement(
                    'INSERT INTO recent_recipe_views (id, user_id, recipe_id, viewed_at) '
                    .'SELECT ?::uuid, u.id, ?::uuid, ?::timestamptz FROM users u '
                    .'WHERE u.id = ?::uuid AND u.ostatnio_ogladane_wlaczone_at IS NOT NULL '
                    .'AND u.status NOT IN ('.implode(',', array_fill(0, count($zamkniete), '?')).') '
                    .'FOR SHARE OF u '
                    .'ON CONFLICT (user_id, recipe_id) DO UPDATE SET viewed_at = EXCLUDED.viewed_at',
                    [(string) Str::uuid7(), $przepisId, $chwila->toIso8601String(), $osobaId, ...$zamkniete],
                );

                $this->przytnijDoLimitu($osobaId);
            });
        } catch (Throwable $e) {
            // Zapis wizyty jest efektem ubocznym oglądania, nie jego warunkiem —
            // strona już się wyświetliła. Logujemy WYŁĄCZNIE klasę błędu, bez
            // identyfikatorów osoby i przepisu (to dane o zachowaniu).
            report(new \RuntimeException('Nie zapisano ostatnio oglądanego przepisu: '.$e::class));
        }
    }

    private function przytnijDoLimitu(string $osobaId): void
    {
        DB::table('recent_recipe_views')
            ->where('user_id', $osobaId)
            ->where(function ($warunek) use ($osobaId): void {
                $warunek->where('viewed_at', '<=', $this->granicaCzasu())
                    ->orWhereNotIn('id', function ($sub) use ($osobaId): void {
                        $sub->select('id')
                            ->from('recent_recipe_views')
                            ->where('user_id', $osobaId)
                            ->orderByDesc('viewed_at')
                            ->orderByDesc('id')
                            ->limit($this->limit());
                    });
            })
            ->delete();
    }

    private function granicaCzasu(): CarbonInterface
    {
        return now()->subDays($this->dni());
    }

    public function limit(): int
    {
        return max(1, (int) config('kuking.ostatnio_ogladane.limit', 10));
    }

    public function dni(): int
    {
        return max(1, (int) config('kuking.ostatnio_ogladane.dni', 7));
    }
}

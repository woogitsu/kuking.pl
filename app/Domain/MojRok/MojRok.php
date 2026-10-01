<?php

declare(strict_types=1);

namespace App\Domain\MojRok;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * „Mój rok w kuchni” — prywatne archiwum jednej osoby (issue #2353, F14
 * z researchu z 30 września 2026, D-333).
 *
 * CO TO JEST, A CZEGO TU NIE MA
 * Ekran pokazuje WYŁĄCZNIE właścicielowi konta: ile dań opublikował w danym
 * roku, ile razy sam coś ugotował („Ugotowałem”) i które przepisy gotował
 * najczęściej. Nic więcej. Nie ma tu:
 *  - cudzych wykonań, imion i kont innych osób — nie liczymy, kto ugotował
 *    z Twoich przepisów (to zdarzenia innych ludzi, z blokadami w tle);
 *  - sum publicznych, porównań, rankingów, serii, odznak i procentów;
 *  - udostępniania i adresu, który ktokolwiek poza właścicielem otworzy;
 *  - wpływu na feed, wyszukiwanie, rekomendacje i jakąkolwiek kolejność
 *    treści (AGENTS.md §8, D-275): wynik nigdzie nie wraca.
 * Żadnej nowej kolumny i żadnej nowej tabeli: wszystko wynika z istniejących
 * rekordów, więc wymazanie konta i eksport RODO pokrywają to bez dodatków.
 *
 * ZAKRES = TO SAMO CO WSPOMNIENIA
 * Te same reguły co `Wspomnienia`: wyłącznik `users.memories_enabled` gasi
 * całość (człowiek w żałobie nie ma odklikiwać), wpis i wykonanie schowane
 * jako wspomnienie (`hide_as_memory`) nie liczą się i nie wracają, a
 * wykonanie przepisu, którego ta osoba już nie widzi (usunięty, prywatny,
 * ukryty przez moderację, blokada z autorem, konto autora zamknięte),
 * znika z podsumowania od razu — nie ma tu żadnej kopii liczb.
 *
 * Wpisy własne liczymy łącznie z prywatnymi: to archiwum właściciela,
 * a on widzi je też na profilu i w „Moje wpisy”.
 *
 * PUSTY ROK NIE JEST OCENĄ. Gdy liczba wynosi zero, widok nie pokazuje
 * zera, tylko pomija wiersz; gdy nie ma nic — jedno spokojne zdanie.
 */
final class MojRok
{
    /** Ile przepisów w „Gotowane najczęściej”. */
    public const MAKS_PRZEPISOW = 3;

    /** Przepis wchodzi do „najczęściej”, dopiero gdy wracał co najmniej tyle razy. */
    public const MIN_POWTORZEN = 2;

    /** Tyle lat wstecz oferuje przełącznik roku (jak `Wspomnienia`). */
    public const MAKS_LAT_WSTECZ = 10;

    public static function biezacyRok(): int
    {
        return Czas::lokalnie(Carbon::now())->year;
    }

    /**
     * @return array{dania: int, wykonania: int, najczesciej: list<array{przepis: Recipe, razy: int}>, pusty: bool}
     */
    public function podsumowanie(User $user, int $rok): array
    {
        $od = Carbon::create($rok, 1, 1, 0, 0, 0, Czas::strefa())->utc();
        $do = Carbon::create($rok + 1, 1, 1, 0, 0, 0, Czas::strefa())->utc();

        $dania = Post::query()
            ->where('author_id', $user->getKey())
            ->published()
            ->enabledKinds()
            ->where('hide_as_memory', false)
            ->where('published_at', '>=', $od)
            ->where('published_at', '<', $do)
            ->count();

        $wykonania = $this->wykonania($user)
            ->where('cooked_at', '>=', $od)
            ->where('cooked_at', '<', $do);

        $liczbaWykonan = (clone $wykonania)->count();

        $najczesciej = [];

        if ($liczbaWykonan >= self::MIN_POWTORZEN) {
            $grupy = (clone $wykonania)
                ->select('recipe_id', DB::raw('count(*) as razy'), DB::raw('max(cooked_at) as ostatnio'))
                ->groupBy('recipe_id')
                ->havingRaw('count(*) >= ?', [self::MIN_POWTORZEN])
                ->orderByDesc('razy')
                ->orderByDesc('ostatnio')
                ->orderBy('recipe_id')
                ->limit(self::MAKS_PRZEPISOW)
                ->get();

            $przepisy = Recipe::query()
                ->whereIn('id', $grupy->pluck('recipe_id'))
                ->get(['id', 'title', 'slug'])
                ->keyBy('id');

            foreach ($grupy as $grupa) {
                $przepis = $przepisy->get($grupa->recipe_id);

                if ($przepis !== null) {
                    $najczesciej[] = ['przepis' => $przepis, 'razy' => (int) $grupa->getAttribute('razy')];
                }
            }
        }

        return [
            'dania' => $dania,
            'wykonania' => $liczbaWykonan,
            'najczesciej' => $najczesciej,
            'pusty' => $dania === 0 && $liczbaWykonan === 0,
        ];
    }

    /**
     * Lata (malejąco), w których jest co pokazać — do przełącznika roku.
     * Bieżący rok jest zawsze pierwszy: to tam człowiek trafia z „Moje”.
     *
     * @return list<int>
     */
    public function lata(User $user): array
    {
        $biezacy = self::biezacyRok();
        $najstarszy = $biezacy - self::MAKS_LAT_WSTECZ;
        $strefa = Czas::strefa();

        $zWpisow = Post::query()
            ->where('author_id', $user->getKey())
            ->published()
            ->enabledKinds()
            ->where('hide_as_memory', false)
            ->selectRaw('distinct extract(year from published_at at time zone ?)::int as rok', [$strefa])
            ->pluck('rok');

        $zWykonan = $this->wykonania($user)
            ->selectRaw('distinct extract(year from cooked_at at time zone ?)::int as rok', [$strefa])
            ->pluck('rok');

        return $zWpisow->merge($zWykonan)
            ->map(fn ($rok): int => (int) $rok)
            ->push($biezacy)
            ->filter(fn (int $rok): bool => $rok >= $najstarszy && $rok <= $biezacy)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * WŁASNE wykonania widocznego przepisu — to samo sito co we wspomnieniach.
     * Zapytanie jest przywiązane do `user_id` osoby oglądającej; nie ma
     * parametru, którym dałoby się podać cudzy identyfikator.
     *
     * @return Builder<CookedEvent>
     */
    private function wykonania(User $user): Builder
    {
        return CookedEvent::query()
            ->where('user_id', $user->getKey())
            ->where('hide_as_memory', false)
            ->whereHas('recipe', fn ($przepis) => $przepis->widoczneDla($user)
                ->whereHas('author', fn ($a) => $a->dostepnyJakoAutor()));
    }
}

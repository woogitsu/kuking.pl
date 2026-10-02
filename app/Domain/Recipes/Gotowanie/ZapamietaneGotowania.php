<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Domain\Media\WgladZUrzedu;
use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Prywatna lista „Gotowanie zapamiętane na koncie” (#2439).
 *
 * To lista WŁASNYCH, niewygasłych rekordów `cooking_progress`, czyli stanów,
 * które osoba świadomie kazała zapamiętać na koncie. NIE jest to lista
 * „niedokończonych gotowań”: rekord powstaje także z pustymi odhaczeniami,
 * a wszystkie kroki mogą już być zaznaczone — model nie ma statusu
 * ukończenia. Nigdy nie tworzy „Ugotowałem” i niczego z odhaczeń nie
 * wnioskuje.
 *
 * ZASADY
 * - TYLKO ODCZYT. Ta klasa nie zmienia `expires_at` ani żadnego innego pola:
 *   samo czytanie listy i otwarcie linku nie przedłuża retencji.
 * - Wygasły rekord (`expires_at <= now()`) jest niewidoczny niezależnie od
 *   tego, czy nocne sprzątanie już go skasowało.
 * - Przed pokazaniem tytułu, zdjęcia, adresu czy liczby kroków przepis jest
 *   sprawdzany jak dla zwykłego konta (`widoczneDla()` i `RecipePolicy::view`,
 *   bez wglądu z urzędu). `CookingProgress::recipe()` używa `withTrashed()`,
 *   więc relacja NIE jest zabezpieczeniem — przepisy pobiera się osobnym
 *   zapytaniem, które usunięte (soft-delete) pomija.
 * - Niedostępne rekordy odpadają PRZED wycięciem strony i przed ustaleniem
 *   pustego stanu, żeby nie wypierały dostępnych ani nie zdradzały, że są.
 * - Kroki liczone wyłącznie po AKTUALNYCH id kroków przepisu: skasowanego
 *   kroku nie liczymy, a zmiana przepisu nie jest świadectwem wykonania.
 * - Kolejność: ostatnia zmiana (`updated_at`) malejąco, remis po `id`.
 */
class ZapamietaneGotowania
{
    public const NA_STRONE = 10;

    /** Ogranicza pamięć i liczbę ID przepisów sprawdzanych w jednym zapytaniu. */
    private const PORCJA_REKORDOW = 100;

    /**
     * @return array{pozycje: Collection<int, array{recipe: Recipe, postep: CookingProgress, zrobione: int, wszystkich: int, stan: string}>, jest_wiecej: bool}
     */
    public function strona(User $osoba, int $offset = 0, int $limit = self::NA_STRONE): array
    {
        $offset = max(0, $offset);

        $teraz = now();
        $jakZwykleKonto = WgladZUrzedu::jakZwykleKonto($osoba);
        $gate = Gate::forUser($jakZwykleKonto);
        /** @var Collection<int, array{recipe: Recipe, postep: CookingProgress}> $strona */
        $strona = collect();
        $doPominiecia = $offset;
        $jestWiecej = false;
        /** @var CookingProgress|null $ostatni */
        $ostatni = null;

        // Filtr dostępności musi poprzedzać offset. Czytamy stałe porcje po
        // (updated_at, id), aż znajdziemy stronę i jeden następny dostępny
        // wiersz. Limit całej listy ukryłby dostępny przepis za ukrytymi.
        do {
            $zapytanie = CookingProgress::query()
                ->where('user_id', $osoba->getKey())
                ->where('expires_at', '>', $teraz)
                ->orderByDesc('updated_at')
                ->orderByDesc('id');

            if ($ostatni !== null) {
                $zapytanie->where(function (Builder $q) use ($ostatni): void {
                    // Surowa wartość zachowuje mikrosekundy z PostgreSQL;
                    // konwersja Carbon na format połączenia mogłaby je zgubić.
                    $czas = $ostatni->getRawOriginal('updated_at');
                    $q->where('updated_at', '<', $czas)
                        ->orWhere(function (Builder $tenSamCzas) use ($ostatni): void {
                            $tenSamCzas->where('updated_at', $ostatni->getRawOriginal('updated_at'))
                                ->where('id', '<', $ostatni->getKey());
                        });
                });
            }

            $rekordy = $zapytanie->limit(self::PORCJA_REKORDOW)->get();
            if ($rekordy->isEmpty()) {
                break;
            }

            $przepisy = Recipe::query()
                ->widoczneDla($jakZwykleKonto)
                ->whereKey($rekordy->pluck('recipe_id')->all())
                ->with(['author.profile.avatar', 'heroMedia'])
                ->get()
                ->keyBy(fn (Recipe $przepis): string => (string) $przepis->getKey());

            foreach ($rekordy as $postep) {
                $przepis = $przepisy->get($postep->recipe_id);

                if ($przepis === null || ! $gate->allows('view', $przepis)) {
                    continue;
                }

                if ($doPominiecia > 0) {
                    $doPominiecia--;

                    continue;
                }

                if ($strona->count() === $limit) {
                    $jestWiecej = true;

                    break 2;
                }

                $strona->push(['recipe' => $przepis, 'postep' => $postep]);
            }

            $ostatni = $rekordy->last();
        } while ($rekordy->count() === self::PORCJA_REKORDOW);

        if ($strona->isEmpty()) {
            return ['pozycje' => collect(), 'jest_wiecej' => false];
        }

        // Jedno zapytanie o kroki wszystkich przepisów strony, nie jedno na kartę.
        /** @var array<string, list<string>> $krokiPrzepisow */
        $krokiPrzepisow = [];
        RecipeStep::query()
            ->whereIn('recipe_id', $strona->map(fn (array $p): string => (string) $p['recipe']->getKey())->all())
            ->get(['id', 'recipe_id'])
            ->each(function (RecipeStep $krok) use (&$krokiPrzepisow): void {
                $krokiPrzepisow[(string) $krok->recipe_id][] = (string) $krok->id;
            });

        $pozycje = $strona->map(function (array $p) use ($krokiPrzepisow): array {
            $aktualne = $krokiPrzepisow[(string) $p['recipe']->getKey()] ?? [];
            $zrobione = count(array_intersect($p['postep']->done_step_ids, $aktualne));
            $wszystkich = count($aktualne);

            return [
                'recipe' => $p['recipe'],
                'postep' => $p['postep'],
                'zrobione' => $zrobione,
                'wszystkich' => $wszystkich,
                'stan' => $this->stan($zrobione, $wszystkich),
            ];
        });

        return ['pozycje' => $pozycje, 'jest_wiecej' => $jestWiecej];
    }

    /** Słowny opis odhaczeń: bez odhaczeń / X z Y / wszystkie kroki odhaczone. */
    private function stan(int $zrobione, int $wszystkich): string
    {
        if ($wszystkich > 0 && $zrobione >= $wszystkich) {
            return 'Wszystkie kroki odhaczone';
        }

        if ($zrobione === 0) {
            return 'Bez odhaczeń';
        }

        return "Odhaczone: {$zrobione} z {$wszystkich}";
    }
}

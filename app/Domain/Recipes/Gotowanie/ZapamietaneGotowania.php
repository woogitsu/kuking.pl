<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie;

use App\Domain\Media\WgladZUrzedu;
use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
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

    /** Sufit liczby rekordów czytanych naraz. Retencja jest krótka (domyślnie 24 h), więc to zapas. */
    private const MAKS_REKORDOW = 500;

    /**
     * @return array{pozycje: Collection<int, array{recipe: Recipe, postep: CookingProgress, zrobione: int, wszystkich: int, stan: string}>, jest_wiecej: bool}
     */
    public function strona(User $osoba, int $offset = 0, int $limit = self::NA_STRONE): array
    {
        $offset = max(0, $offset);

        $rekordy = CookingProgress::query()
            ->where('user_id', $osoba->getKey())
            ->where('expires_at', '>', now())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAKS_REKORDOW)
            ->get();

        if ($rekordy->isEmpty()) {
            return ['pozycje' => collect(), 'jest_wiecej' => false];
        }

        $jakZwykleKonto = WgladZUrzedu::jakZwykleKonto($osoba);

        $przepisy = Recipe::query()
            ->widoczneDla($jakZwykleKonto)
            ->whereKey($rekordy->pluck('recipe_id')->all())
            ->with(['author.profile.avatar', 'heroMedia'])
            ->get()
            ->keyBy(fn (Recipe $przepis): string => (string) $przepis->getKey());

        $gate = Gate::forUser($jakZwykleKonto);

        $widoczne = $rekordy
            ->map(function (CookingProgress $postep) use ($przepisy, $gate): ?array {
                $przepis = $przepisy->get($postep->recipe_id);

                if ($przepis === null || ! $gate->allows('view', $przepis)) {
                    return null;
                }

                return ['recipe' => $przepis, 'postep' => $postep];
            })
            ->filter()
            ->values();

        $strona = $widoczne->slice($offset, $limit)->values();

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

        return ['pozycje' => $pozycje, 'jest_wiecej' => $widoczne->count() > $offset + $limit];
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

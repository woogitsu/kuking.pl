<?php

declare(strict_types=1);

namespace App\Domain\UgotujmyRazem;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WeeklyRecipePick;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

/**
 * Odczyt „Ugotujmy razem” (F3): przepis tygodnia, jego wykonania z tego
 * tygodnia i archiwum poprzednich tygodni.
 *
 * DOBÓR TREŚCI (AGENTS.md §8, D-275) — tylko dozwolone reguły:
 *  - przepis to WYBÓR GOSPODARZA, oznaczony tak na stronie;
 *  - wykonania wyłącznie PO CZASIE (`cooked_at`, potem `id`), od najnowszego;
 *  - bramki widoczności i blokady (`CookedEvent::widoczneDla`, ta sama
 *    reguła co galeria „Komu wyszło” pod przepisem).
 * Żadnego sortowania po reakcjach, żadnej liczby wykonań na stronie
 * („bez liczników presji”).
 */
final class UgotujmyRazem
{
    /** Ile wykonań na jedną porcję listy — tyle, ile pod przepisem. */
    public const WYKONAN_NA_STRONE = 12;

    public const TYGODNI_W_ARCHIWUM_NA_STRONE = 10;

    /** Przepis tygodnia, który WOLNO pokazać temu widzowi — albo nic. */
    public function dlaTygodnia(TydzienGotowania $tydzien, ?User $widz): ?WeeklyRecipePick
    {
        $pick = WeeklyRecipePick::query()
            ->whereDate('week_starts_on', $tydzien->dzienStartu())
            ->with('recipe.author.profile', 'recipe.heroMedia')
            ->first();

        if ($pick === null || ! Gate::forUser($widz)->allows('view', $pick)) {
            return null;
        }

        return $pick;
    }

    public function biezacy(?User $widz): ?WeeklyRecipePick
    {
        return $this->dlaTygodnia(TydzienGotowania::biezacy(), $widz);
    }

    /**
     * Wykonania przepisu z okna tygodnia [poniedziałek 00:00, następny
     * poniedziałek 00:00) czasu polskiego, chronologicznie od najnowszego.
     *
     * @return LengthAwarePaginator<int, CookedEvent>
     */
    public function wykonania(WeeklyRecipePick $pick, ?User $widz): LengthAwarePaginator
    {
        $tydzien = $pick->tydzien();

        return CookedEvent::query()
            ->where('recipe_id', $pick->recipe_id)
            ->where('cooked_at', '>=', $tydzien->poczatek())
            ->where('cooked_at', '<', $tydzien->koniec())
            ->widoczneDla($widz)
            ->with(['user.profile.avatar', 'media', 'recipe'])
            ->orderByDesc('cooked_at')
            ->orderByDesc('id')
            ->paginate(self::WYKONAN_NA_STRONE, ['*'], 'wykonania');
    }

    /**
     * Poprzednie tygodnie, od najnowszego. Przepis musi być dziś publiczny,
     * opublikowany i widoczny dla widza — ta sama granica co
     * `WeeklyRecipePickPolicy::view`, tylko w zapytaniu (inaczej lista
     * pokazywałaby tydzień, którego strona daje 404).
     *
     * @return LengthAwarePaginator<int, WeeklyRecipePick>
     */
    public function archiwum(?User $widz): LengthAwarePaginator
    {
        return WeeklyRecipePick::query()
            ->whereDate('week_starts_on', '<', TydzienGotowania::biezacy()->dzienStartu())
            ->whereHas('recipe', function ($przepis) use ($widz): void {
                $przepis->widoczneDla($widz)
                    ->where('recipes.status', Recipe::STATUS_PUBLISHED)
                    ->whereNotNull('recipes.published_at')
                    ->where('recipes.visibility', 'public')
                    ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor());
            })
            ->with('recipe')
            ->orderByDesc('week_starts_on')
            ->paginate(self::TYGODNI_W_ARCHIWUM_NA_STRONE, ['*'], 'archiwum');
    }
}

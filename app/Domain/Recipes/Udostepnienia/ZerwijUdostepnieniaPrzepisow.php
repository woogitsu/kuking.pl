<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Udostepnienia;

use App\Domain\Users\KoniecUdostepnienPrzepisow;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Koniec udostępnień przepisów przy blokadzie i wymazaniu konta (#2650).
 *
 * BLOKADA (`miedzy()`, z `BlockUser` pod zamkiem pary) kasuje udostępnienia
 * w obie strony. `RecipePolicy::readShared()` i tak odmawia przy blokadzie,
 * ale bez skasowania odblokowanie przywróciłoby dostęp, którego autor po
 * blokadzie nie chce — tak jak po blokadzie trzeba od nowa zacząć
 * obserwować.
 *
 * WYMAZANIE KONTA (`przyWymazaniu()`, z `EraseAccountData`, każdy zakres):
 * znikają udostępnienia DLA tej osoby i udostępnienia JEJ przepisów —
 * wiersz `users` zostaje jako `erased`, więc `ON DELETE CASCADE` tu nie
 * pomoże.
 *
 * Kolejność usuwanych wierszy ustalona (`ORDER BY id … FOR UPDATE`), jak
 * w `ZerwijWspoldzielenie` (D-093): dwie równoległe egzekucje kont nie
 * zakleszczą się na tych samych wierszach.
 */
final class ZerwijUdostepnieniaPrzepisow implements KoniecUdostepnienPrzepisow
{
    public function miedzy(User $a, User $b): void
    {
        $idA = (string) $a->getKey();
        $idB = (string) $b->getKey();

        $this->skasuj(DB::table('recipe_shares')
            ->join('recipes', 'recipes.id', '=', 'recipe_shares.recipe_id')
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('recipes.author_id', $idA)->where('recipe_shares.recipient_id', $idB))
                ->orWhere(fn ($x) => $x->where('recipes.author_id', $idB)->where('recipe_shares.recipient_id', $idA))));
    }

    public function przyWymazaniu(User $user): void
    {
        $id = (string) $user->getKey();

        $this->skasuj(DB::table('recipe_shares')
            ->where('recipe_shares.recipient_id', $id)
            ->orWhereIn('recipe_shares.recipe_id', DB::table('recipes')->where('author_id', $id)->select('id')));
    }

    private function skasuj(Builder $zapytanie): void
    {
        $id = $zapytanie
            ->orderBy('recipe_shares.id')
            ->lock('FOR UPDATE OF recipe_shares')
            ->pluck('recipe_shares.id')
            ->all();

        if ($id !== []) {
            DB::table('recipe_shares')->whereIn('id', $id)->delete();
        }
    }
}

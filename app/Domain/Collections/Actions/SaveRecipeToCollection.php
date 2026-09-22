<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * "Zapisuję" — dodanie przepisu do zeszytu.
 *
 * Kolekcja "Zapisane" tworzy się sama przy pierwszym zapisie. Nikt nie musi
 * wymyślać nazwy folderu, żeby zachować przepis na potem — to jest dokładnie
 * ten moment, w którym połowa ludzi rezygnuje.
 */
final class SaveRecipeToCollection
{
    public function __construct(private readonly NotifyUser $notify) {}

    public function handle(User $user, Recipe $recipe, ?Collection $collection = null, ?string $note = null): Collection
    {
        // Oba skutki są zapisami tej samej bazy (#907). Istniejące powiązanie
        // jest znacznikiem zakończenia: zatwierdzamy je razem z powiadomieniem.
        // Nie sprawdzamy istnienia wiadomości, którą mogła usunąć retencja.
        return DB::transaction(fn (): Collection => $this->saveWithNotification($user, $recipe, $collection, $note));
    }

    private function saveWithNotification(User $user, Recipe $recipe, ?Collection $collection, ?string $note): Collection
    {
        $collection ??= $user->defaultCollection();
        Gate::forUser($user)->authorize('update', $collection);

        // DRUGIE KLIKNIĘCIE „ZAPISUJĘ” NIE JEST NOWYM ZAPISEM (issue #43).
        //
        // Klucz główny `collection_items (collection_id, recipe_id)` pilnował
        // bazy przed drugim wierszem, więc na pierwszy rzut oka wszystko było
        // w porządku. Ale `syncWithoutDetaching` na istniejącej parze robi
        // UPDATE, a nie nic — i to miało dwa widoczne skutki:
        //
        //  1. `created_at` w zeszycie było nadpisywane, więc przepis skakał
        //     na górę listy (zeszyt jest ułożony od najnowszego zapisu).
        //     Człowiek nie zrobił nic poza powtórzeniem tej samej akcji,
        //     a kolejność jego zeszytu się zmieniała;
        //  2. autor przepisu dostawał DRUGIE powiadomienie „ktoś zapisał
        //     Twój przepis" — od jednej osoby, za jedno zapisanie.
        //
        // Podwójne kliknięcie w grupie 50+ to norma, nie pomyłka, więc
        // sprawdzamy stan przed zapisem: dwa kliknięcia mają dać dokładnie
        // ten sam skutek co jedno.
        if ($collection->recipes()->whereKey($recipe->getKey())->exists()) {
            // Jedyne, co wolno tu zmienić, to notatka — jeśli ktoś ją
            // faktycznie podał. `created_at` zostaje takie, jak było.
            if ($note !== null) {
                $collection->recipes()->updateExistingPivot($recipe->getKey(), ['note' => $note]);
            }

            return $collection;
        }

        try {
            DB::transaction(fn () => $collection->recipes()->attach($recipe->getKey(), [
                'note' => $note,
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Dwa kliknięcia potrafią wejść RÓWNOCZEŚNIE — wtedy oba przechodzą
            // sprawdzenie wyżej i drugie odbija się o klucz główny. Dla
            // człowieka to nadal jest jedno zapisanie, więc kończymy cicho,
            // bez błędu 500 i bez drugiego powiadomienia dla autora.
            return $collection;
        }

        // Autor dowiaduje się, że ktoś odłożył jego przepis "na potem".
        // To jedno z najprzyjemniejszych powiadomień w serwisie.
        if ($user->isActive()) {
            $this->notify->handle(
                recipient: $recipe->author,
                type: Notification::TYPE_SAVED,
                actor: $user,
                data: [
                    'recipe_id' => $recipe->getKey(),
                    'recipe_title' => $recipe->title,
                    'recipe_slug' => $recipe->slug,
                ],
            );
        }

        return $collection;
    }

    public function remove(User $user, Recipe $recipe): void
    {
        $user->collections()->each(
            fn (Collection $collection) => $collection->recipes()->detach($recipe->getKey()),
        );
    }
}

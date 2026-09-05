<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;

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
        $collection ??= $user->defaultCollection();

        $collection->recipes()->syncWithoutDetaching([
            $recipe->getKey() => ['note' => $note, 'created_at' => now()],
        ]);

        // Autor dowiaduje się, że ktoś odłożył jego przepis "na potem".
        // To jedno z najprzyjemniejszych powiadomień w serwisie.
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

        return $collection;
    }

    public function remove(User $user, Recipe $recipe): void
    {
        $user->collections()->each(
            fn (Collection $collection) => $collection->recipes()->detach($recipe->getKey()),
        );
    }
}

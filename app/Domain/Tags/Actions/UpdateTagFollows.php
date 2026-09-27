<?php

declare(strict_types=1);

namespace App\Domain\Tags\Actions;

use App\Domain\Tags\TagMutationLock;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateTagFollows
{
    /** Dodaje tylko nowe relacje; ponowienie nie przepisuje daty początku. */
    public function follow(User $user, array $ids, bool $promotedOnly = false): void
    {
        DB::transaction(function () use ($user, $ids, $promotedOnly): void {
            TagMutationLock::forPost();
            $freshUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $this->assertActive($freshUser);
            if ($promotedOnly) {
                $ids = Tag::promowane()->whereIn('tags.id', $ids)->pluck('tags.id')->all();
            }
            $this->insert($freshUser, $ids);
        });
    }

    public function unfollow(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            TagMutationLock::forPost();
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $user->followedTags()->detach($id);
        });
    }

    /** Różnica względem otwarcia formularza, nigdy względem całego obecnego zbioru. */
    public function save(User $user, array $selected, array $scope): void
    {
        DB::transaction(function () use ($user, $selected, $scope): void {
            TagMutationLock::forPost();
            $freshUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $initial = array_keys($scope['followed']);
            $add = array_values(array_diff($selected, $initial));
            $remove = array_values(array_diff($initial, $selected));

            // Samo cofnięcie obserwowania pozostaje możliwe także po sankcji.
            // Formularz, który dodaje choć jeden tag, odmawia całego zapisu.
            if ($add !== []) {
                $this->assertActive($freshUser);
            }

            if (array_diff($selected, $scope['shown']) !== []) {
                throw ValidationException::withMessages(['tags' => 'Wybierz tagi z tej listy i zapisz ponownie.']);
            }
            $this->insert($freshUser, $add);
            foreach ($remove as $id) {
                // Nie usuwamy relacji, której data zmieniła się od otwarcia.
                // Kolumna ma dokładność sekundy; nie jest wersją relacji.
                DB::table('tag_follows')->where('user_id', $freshUser->getKey())
                    ->where('tag_id', $id)->where('created_at', $scope['followed'][$id])->delete();
            }
        });
    }

    private function assertActive(User $user): void
    {
        if (! $user->isActive()) {
            throw new BladDlaCzlowieka('To konto jest niedostępne.');
        }
    }

    private function insert(User $user, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }
        // Blokada wierszy chroni też przed zmianą statusu poza scalaniem.
        $active = Tag::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($active->count() !== count($ids) || $active->contains(fn (Tag $tag): bool => $tag->status !== Tag::STATUS_ACTIVE)) {
            throw ValidationException::withMessages([
                'tags' => 'Jeden z wybranych tagów nie jest już dostępny. Sprawdź pozostałe zaznaczenia i zapisz ponownie.',
            ]);
        }
        DB::table('tag_follows')->insertOrIgnore(array_map(fn (string $id): array => [
            'user_id' => $user->getKey(), 'tag_id' => $id, 'created_at' => now(),
        ], $ids));
    }
}

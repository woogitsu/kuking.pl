<?php

declare(strict_types=1);

namespace App\Domain\Tags\Actions;

use App\Domain\Social\ListyWidza;
use App\Domain\Tags\LimitObserwowanychTagow;
use App\Domain\Tags\TagMutationLock;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Tag;
use App\Models\User;
use App\Support\LimityTagow;
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
            ListyWidza::uniewaznij();
        });
    }

    public function unfollow(User $user, string $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            TagMutationLock::forPost();
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $user->followedTags()->detach($id);
            ListyWidza::uniewaznij();
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
            // Najpierw zdjęcia, potem dodania: limit (#2326) liczy stan PO
            // zapisie, więc zamiana jednego tagu na inny przy pełnej liście
            // przechodzi. Odmowa niżej cofa całą transakcję, także zdjęcia.
            foreach ($remove as $id) {
                // Nie usuwamy relacji, której data zmieniła się od otwarcia.
                // Kolumna ma dokładność sekundy; nie jest wersją relacji.
                DB::table('tag_follows')->where('user_id', $freshUser->getKey())
                    ->where('tag_id', $id)->where('created_at', $scope['followed'][$id])->delete();
            }
            $this->insert($freshUser, $add);
            ListyWidza::uniewaznij();
        });
    }

    private function assertActive(User $user): void
    {
        if (! $user->isActive()) {
            throw new BladDlaCzlowieka('To konto jest niedostępne.');
        }
    }

    /**
     * Limit liczby obserwowanych tagów (#2326), liczony pod blokadą wiersza
     * konta, którą biorą wszystkie wejścia tej klasy — dwa równoległe
     * dodania czekają na siebie i nie przeskoczą granicy razem.
     *
     * Liczą się tylko NOWE relacje: ponowne „Obserwuj” tagu już
     * obserwowanego nie dokłada wiersza, więc nie ma czego odmawiać. Konto
     * sprzed limitu, które obserwuje więcej, niczego nie traci — nie doda
     * tylko nowego, dopóki nie zejdzie poniżej.
     *
     * Bez wczytywania relacji: jedno `whereIn` ograniczone do wybranych
     * i jedno `exists()` z przesunięciem, czyli „czy jest ich już co
     * najmniej tyle” — koszt nie rośnie z liczbą obserwowanych.
     *
     * @param  list<string>  $ids
     */
    private function assertMiesciSieWLimicie(User $user, array $ids): void
    {
        $juz = DB::table('tag_follows')->where('user_id', $user->getKey())
            ->whereIn('tag_id', $ids)->count();
        $nowych = count($ids) - $juz;
        if ($nowych === 0) {
            return;
        }

        $wolnych = LimityTagow::maksObserwowanych() - $nowych;
        $pelno = $wolnych < 0 || DB::table('tag_follows')->where('user_id', $user->getKey())
            ->offset($wolnych)->limit(1)->exists();
        if ($pelno) {
            throw LimitObserwowanychTagow::withMessages([
                'tags' => LimityTagow::komunikatLimituObserwowanych(),
            ]);
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
        $this->assertMiesciSieWLimicie($user, $ids);
        DB::table('tag_follows')->insertOrIgnore(array_map(fn (string $id): array => [
            'user_id' => $user->getKey(), 'tag_id' => $id, 'created_at' => now(),
        ], $ids));
    }
}

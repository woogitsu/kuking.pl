<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\RemoveUnavailableFromCollection;
use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\WidocznaZawartoscZeszytu;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Regresja #2094: cofnięcie wyjęcia jest jednym zapisem na świeżym stanie. */
final class PowrotDoZeszytuSwiezyStanTest extends TestCase
{
    use RefreshDatabase;

    public function test_powrot_wpisu_zachowuje_notatki_i_daty_oraz_jest_idempotentny(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $post = Post::factory()->for($author, 'author')->create();
        $a = $this->zeszyt($owner, 'A');
        $b = $this->zeszyt($owner, 'B');
        $date = now()->subDays(12)->startOfSecond()->toDateTimeString();
        $removed = [
            ['collection_id' => $b->id, 'note' => 'bez cukru', 'created_at' => $date],
            ['collection_id' => $a->id, 'note' => 'na święta', 'created_at' => $date],
        ];

        $action = app(SavePostToCollection::class);
        $this->assertSame(2, $action->restore($owner, $post, $removed));
        $this->assertSame(0, $action->restore($owner, $post, $removed));
        $this->assertSame('bez cukru', $b->posts()->whereKey($post->id)->firstOrFail()->pivot->note);
        $this->assertSame('na święta', $a->posts()->whereKey($post->id)->firstOrFail()->pivot->note);
        $this->assertSame($date, (string) $a->posts()->whereKey($post->id)->firstOrFail()->pivot->created_at);
        $this->assertSame($date, (string) $b->posts()->whereKey($post->id)->firstOrFail()->pivot->created_at);

        $b->delete();
        $this->assertSame(0, $action->restore($owner, $post, $removed));
    }

    public function test_blad_drugiego_wiersza_cofa_calosc_powrotu_wpisu(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->create();
        $a = $this->zeszyt($owner, 'A');
        $b = $this->zeszyt($owner, 'B');
        if (strcmp($a->id, $b->id) > 0) {
            [$a, $b] = [$b, $a];
        }
        $removed = [
            ['collection_id' => $a->id, 'note' => 'pierwszy', 'created_at' => now()->subDay()->toDateTimeString()],
            ['collection_id' => $b->id, 'note' => 'drugi', 'created_at' => 'nie-data'],
        ];

        try {
            app(SavePostToCollection::class)->restore($owner, $post, $removed);
            $this->fail('Nieprawidłowa data drugiego wiersza powinna przerwać przywracanie.');
        } catch (QueryException) {
            $this->assertFalse($a->posts()->whereKey($post->id)->exists());
            $this->assertFalse($b->posts()->whereKey($post->id)->exists());
        }
    }

    public function test_powrot_przepisu_zachowuje_date_notatke_i_jedno_powiadomienie(): void
    {
        $owner = User::factory()->create();
        $author = User::factory()->create();
        $recipe = Recipe::factory()->for($author, 'author')->create();
        $a = $this->zeszyt($owner, 'A');
        $b = $this->zeszyt($owner, 'B');
        $date = now()->subDays(8)->startOfSecond()->toDateTimeString();
        $removed = [
            ['collection_id' => $a->id, 'note' => 'mniej soli', 'created_at' => $date],
            ['collection_id' => $b->id, 'note' => 'dla Ani', 'created_at' => $date],
        ];

        $action = app(SaveRecipeToCollection::class);
        $this->assertSame(2, $action->restore($owner, $recipe, $removed));
        $this->assertSame(0, $action->restore($owner, $recipe, $removed));
        $this->assertSame('mniej soli', $a->recipes()->whereKey($recipe->id)->firstOrFail()->pivot->note);
        $this->assertSame('dla Ani', $b->recipes()->whereKey($recipe->id)->firstOrFail()->pivot->note);
        $this->assertSame($date, (string) $a->recipes()->whereKey($recipe->id)->firstOrFail()->pivot->created_at);
        $this->assertSame($date, (string) $b->recipes()->whereKey($recipe->id)->firstOrFail()->pivot->created_at);
        $this->assertSame(1, Notification::query()->where('user_id', $author->id)->where('type', Notification::TYPE_SAVED)->count());
    }

    public function test_zawieszenie_zapobiega_powrotowi_do_publicznego_zeszytu_i_mutacji_niedostepnych(): void
    {
        $owner = User::factory()->create();
        $post = Post::factory()->create();
        $recipe = Recipe::factory()->create();
        $deleted = Recipe::factory()->create();
        $collection = $this->zeszyt($owner, 'Publiczny', 'public');
        $deleted->delete();
        $collection->recipes()->attach($deleted->id);
        $contents = app(WidocznaZawartoscZeszytu::class);
        $fingerprint = $contents->odcisk($collection, $contents->niedostepne($collection, $owner));
        $staleOwner = User::findOrFail($owner->id);
        $staleCollection = Collection::findOrFail($collection->id);
        $owner->suspend();

        foreach ([
            fn () => app(SavePostToCollection::class)->restore($staleOwner, $post, [['collection_id' => $collection->id, 'note' => null, 'created_at' => null]]),
            fn () => app(SaveRecipeToCollection::class)->restore($staleOwner, $recipe, [['collection_id' => $collection->id, 'note' => null, 'created_at' => null]]),
            fn () => app(RemoveUnavailableFromCollection::class)->handle($staleOwner, $staleCollection, $fingerprint),
        ] as $mutation) {
            try {
                $mutation();
                $this->fail('Publiczny zeszyt zawieszonej osoby nie może być zmieniony.');
            } catch (AuthorizationException) {
                // Sprawdzenie musi objąć świeżego aktora pod zamkiem.
            }
        }

        $this->assertFalse($collection->posts()->whereKey($post->id)->exists());
        $this->assertTrue($collection->recipes()->withTrashed()->whereKey($deleted->id)->exists());
    }

    private function zeszyt(User $owner, string $name, string $visibility = 'private'): Collection
    {
        return Collection::create(['owner_id' => $owner->id, 'name' => $name, 'visibility' => $visibility]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\ZamekZapisuDoZeszytu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regresja #1022 przez HTTP: stan zmienia się W TRAKCIE żądania zapisu.
 *
 * Zmianę wstrzykujemy tuż po pierwszym odczycie celu w akcji — czyli po
 * `authorize('view')` kontrolera, a przed zamkami. Pełny przeplot na dwóch
 * połączeniach mierzy `tests/Dwa/ZapisDoZeszytuBiezacyStanTest`; tu chodzi
 * o to, co zobaczy człowiek: zdanie po polsku zamiast 500 i brak mutacji.
 */
class ZapisDoZeszytuPoUtracieDostepuTest extends TestCase
{
    use RefreshDatabase;

    public static function zmiany(): iterable
    {
        foreach (['recipe', 'post'] as $type) {
            foreach (['private', 'hidden', 'soft_delete', 'block', 'delete_collection'] as $change) {
                yield "$type/$change" => [$type, $change];
            }
        }
    }

    #[DataProvider('zmiany')]
    public function test_zmiana_w_trakcie_zapisu_daje_zdanie_i_nie_zapisuje(string $type, string $change): void
    {
        $saver = $this->user('zapisujaca');
        $author = $this->user('autorka');
        $subject = $type === 'recipe'
            ? Recipe::factory()->for($author, 'author')->create(['visibility' => 'public'])
            : Post::factory()->for($author, 'author')->create(['visibility' => 'public']);
        $collection = $saver->collections()->create(['name' => 'Na niedzielę', 'visibility' => 'private']);
        $url = $type === 'recipe' ? route('collections.save', $subject->slug) : route('collections.save-post', $subject);

        $this->poPierwszymOdczycie($subject->getTable(), function () use ($change, $subject, $saver, $author, $collection): void {
            match ($change) {
                'private' => $subject->newQuery()->whereKey($subject->getKey())->update(['visibility' => 'private']),
                'hidden' => $subject->newQuery()->whereKey($subject->getKey())->update(['status' => 'hidden']),
                'soft_delete' => $subject->newQuery()->whereKey($subject->getKey())->update(['deleted_at' => now()]),
                'block' => DB::table('blocks')->insert(['blocker_id' => $author->id, 'blocked_id' => $saver->id, 'created_at' => now()]),
                'delete_collection' => $collection->delete(),
            };
        });

        $this->actingAs($saver)->from('/home')->post($url, ['collection_id' => $collection->id])
            ->assertRedirect('/home')
            ->assertSessionHasErrors([
                'collection_id' => $change === 'delete_collection' ? ZamekZapisuDoZeszytu::BRAK_ZESZYTU : ZamekZapisuDoZeszytu::NIEDOSTEPNE,
            ]);

        $this->assertDatabaseMissing('collection_items', [$type === 'recipe' ? 'recipe_id' : 'post_id' => $subject->id]);
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_SAVED)->count());
        // Usunięty zeszyt nie zamienia się po cichu w domyślny.
        $this->assertDatabaseMissing('collections', ['owner_id' => $saver->id, 'is_default' => true]);
    }

    public function test_zeszyt_usuniety_przed_akcja_nie_daje_500_ani_zapisu_do_domyslnego(): void
    {
        $saver = $this->user('zapisujaca');
        $recipe = Recipe::factory()->create(['visibility' => 'public']);
        $collection = $saver->collections()->create(['name' => 'Stary', 'visibility' => 'private']);
        $stale = Collection::query()->findOrFail($collection->id);
        $collection->delete();

        try {
            app(SaveRecipeToCollection::class)->handle($saver, $recipe, $stale);
            $this->fail('Zapis do usuniętego zeszytu przeszedł.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(ZamekZapisuDoZeszytu::BRAK_ZESZYTU, $e->getMessage());
        }

        $this->assertSame(0, DB::table('collection_items')->count());
        $this->assertDatabaseMissing('collections', ['owner_id' => $saver->id]);
    }

    public function test_kontrola_dodatnia_podwojny_zapis_zachowuje_pierwsza_date_i_wlasny_zeszyt_dziala(): void
    {
        $saver = $this->user('zapisujaca');
        $recipe = Recipe::factory()->create(['visibility' => 'public']);
        $post = Post::factory()->create(['visibility' => 'public']);
        $own = $saver->collections()->create(['name' => 'Moje', 'visibility' => 'private']);

        $this->travelTo(now()->subHour());
        $this->actingAs($saver)->post(route('collections.save', $recipe->slug), ['collection_id' => $own->id])->assertSessionHasNoErrors();
        $this->actingAs($saver)->post(route('collections.save-post', $post))->assertSessionHasNoErrors();
        $first = DB::table('collection_items')->where('recipe_id', $recipe->id)->value('created_at');
        $this->travelBack();

        $this->actingAs($saver)->post(route('collections.save', $recipe->slug), ['collection_id' => $own->id])->assertSessionHasNoErrors();
        $this->actingAs($saver)->post(route('collections.save-post', $post))->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('collection_items')->where('recipe_id', $recipe->id)->where('collection_id', $own->id)->count());
        $this->assertSame($first, DB::table('collection_items')->where('recipe_id', $recipe->id)->value('created_at'));
        $this->assertSame(1, DB::table('collection_items')->where('post_id', $post->id)->count());
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_SAVED)->count());
    }

    public function test_istniejacy_zapis_zostaje_po_utracie_dostepu_a_ponowny_nie_przechodzi(): void
    {
        $saver = $this->user('zapisujaca');
        $post = Post::factory()->create(['visibility' => 'public']);
        $collection = app(SavePostToCollection::class)->handle($saver, $post);
        $post->update(['visibility' => 'private']);

        $this->expectException(BladDlaCzlowieka::class);

        try {
            app(SavePostToCollection::class)->handle($saver, $post->fresh(), null, 'nowa notatka');
        } finally {
            // #773: niedostępny zapis zostaje, notatka nie jest nadpisana.
            $this->assertDatabaseHas('collection_items', ['collection_id' => $collection->id, 'post_id' => $post->id, 'note' => null]);
        }
    }

    /** Jedno wstrzyknięcie zmiany po pierwszym odczycie celu w akcji. */
    private function poPierwszymOdczycie(string $table, callable $zmiana): void
    {
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $table, $zmiana): void {
            if ($armed && str_starts_with($query->sql, 'select "author_id" from "'.$table.'"')) {
                $armed = false;
                $zmiana();
            }
        });
    }
}

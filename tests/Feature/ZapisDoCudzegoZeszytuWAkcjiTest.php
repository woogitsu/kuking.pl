<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Akcja zapisu sama sprawdza prawo do zeszytu (issue #942).
 *
 * CO BYŁO ŹLE
 * `SaveRecipeToCollection::handle()` i `SavePostToCollection::handle()`
 * przyjmowały dowolny `Collection` i dopisywały do niego pozycję. Kontroler
 * wybierał zeszyt przez `$user->collections()`, więc przez HTTP cudzy zeszyt
 * nie przechodził — ale akcja jest wołana także spoza kontrolera (zadania,
 * skrypty, kolejne ekrany), a UUID w ręku to nie autoryzacja (AGENTS.md §7).
 *
 * CO JEST TERAZ
 * Obie akcje wołają `Gate::forUser($user)->authorize('update', $collection)`
 * (commit 2c561ce0). Te testy wołają akcję BEZPOŚREDNIO, z pominięciem
 * kontrolera — inaczej pilnowałyby walidacji kontrolera, a nie akcji.
 */
final class ZapisDoCudzegoZeszytuWAkcjiTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function pozycje(): array
    {
        return DB::table('collection_items')
            ->orderBy('collection_id')->orderBy('recipe_id')->orderBy('post_id')
            ->get()->map(fn ($r) => (array) $r)->all();
    }

    private function zapisanych(): int
    {
        return Notification::query()->where('type', Notification::TYPE_SAVED)->count();
    }

    public function test_przepis_do_cudzego_zeszytu_jest_odrzucony_i_nic_nie_zmienia(): void
    {
        $a = $this->user('ala');
        $b = $this->user('bogdan');
        $zeszytB = Collection::create(['owner_id' => $b->getKey(), 'name' => 'Zeszyt Bogdana', 'visibility' => 'private']);
        $nowy = Recipe::factory()->create();
        $juzJest = Recipe::factory()->create();
        $zeszytB->recipes()->attach($juzJest->getKey(), [
            'note' => 'Babcina wersja, mniej soli',
            'created_at' => now()->subDays(3)->startOfSecond(),
        ]);
        $przed = $this->pozycje();
        $powiadomien = $this->zapisanych();
        $akcja = app(SaveRecipeToCollection::class);

        foreach ([[$nowy, null], [$juzJest, null], [$juzJest, 'Podmieniona notatka']] as [$przepis, $notatka]) {
            try {
                $akcja->handle($a, $przepis, $zeszytB, $notatka);
                $this->fail('Akcja zapisała przepis do cudzego zeszytu.');
            } catch (AuthorizationException) {
                // oczekiwane
            }
            $this->assertSame($przed, $this->pozycje(), 'Odmowa zmieniła collection_items.');
            $this->assertSame($powiadomien, $this->zapisanych(), 'Odmowa wysłała powiadomienie „zapisano”.');
        }

        $pivot = $zeszytB->recipes()->whereKey($juzJest->getKey())->first()->pivot;
        $this->assertSame('Babcina wersja, mniej soli', $pivot->note);
    }

    public function test_wpis_do_cudzego_zeszytu_jest_odrzucony_i_nic_nie_zmienia(): void
    {
        $a = $this->user('ala');
        $b = $this->user('bogdan');
        $zeszytB = Collection::create(['owner_id' => $b->getKey(), 'name' => 'Zeszyt Bogdana', 'visibility' => 'private']);
        $nowy = Post::factory()->create();
        $juzJest = Post::factory()->create();
        $zeszytB->posts()->attach($juzJest->getKey(), [
            'note' => 'Zrobić na imieniny',
            'created_at' => now()->subDays(3)->startOfSecond(),
        ]);
        $przed = $this->pozycje();
        $powiadomien = $this->zapisanych();
        $akcja = app(SavePostToCollection::class);

        foreach ([[$nowy, null], [$juzJest, null], [$juzJest, 'Podmieniona notatka']] as [$wpis, $notatka]) {
            try {
                $akcja->handle($a, $wpis, $zeszytB, $notatka);
                $this->fail('Akcja zapisała wpis do cudzego zeszytu.');
            } catch (AuthorizationException) {
                // oczekiwane
            }
            $this->assertSame($przed, $this->pozycje(), 'Odmowa zmieniła collection_items.');
            $this->assertSame($powiadomien, $this->zapisanych());
        }

        $pivot = $zeszytB->posts()->whereKey($juzJest->getKey())->first()->pivot;
        $this->assertSame('Zrobić na imieniny', $pivot->note);
    }

    public function test_kontrola_dodatnia_wlasny_zeszyt_i_domyslny_nadal_dzialaja(): void
    {
        $a = $this->user('ala');
        $wlasny = Collection::create(['owner_id' => $a->getKey(), 'name' => 'Zeszyt Ali', 'visibility' => 'private']);
        $przepisy = Recipe::factory()->count(2)->create();
        $wpisy = Post::factory()->count(2)->create();
        $powiadomien = $this->zapisanych();

        $zwrocony = app(SaveRecipeToCollection::class)->handle($a, $przepisy[0], $wlasny, 'Na niedzielę');
        $this->assertTrue($zwrocony->is($wlasny));
        $this->assertSame('Na niedzielę', $wlasny->recipes()->whereKey($przepisy[0]->getKey())->first()->pivot->note);

        $domyslny = app(SaveRecipeToCollection::class)->handle($a, $przepisy[1], null);
        $this->assertSame((string) $a->getKey(), (string) $domyslny->owner_id);
        $this->assertTrue($domyslny->recipes()->whereKey($przepisy[1]->getKey())->exists());
        $this->assertSame($powiadomien + 2, $this->zapisanych(), 'Zapis do własnego zeszytu przestał powiadamiać autora.');

        app(SavePostToCollection::class)->handle($a, $wpisy[0], $wlasny);
        $this->assertTrue($wlasny->posts()->whereKey($wpisy[0]->getKey())->exists());
        $domyslnyWpisu = app(SavePostToCollection::class)->handle($a, $wpisy[1]);
        $this->assertTrue($domyslnyWpisu->is($domyslny));
        $this->assertTrue($domyslny->posts()->whereKey($wpisy[1]->getKey())->exists());
    }
}

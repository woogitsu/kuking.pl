<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Żądania HTTP, nie odczyt reguł z kontrolera — regresja issue #473. */
class WyborZeszytuMaWalidacjeTest extends TestCase
{
    use RefreshDatabase;

    public static function invalidSelections(): array
    {
        $cases = [];

        foreach (['recipe', 'post'] as $kind) {
            foreach (['text', 'array', 'missing', 'foreign'] as $selection) {
                $cases[$kind.' '.$selection] = [$kind, $selection];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidSelections')]
    public function test_nieprawidlowy_wybor_nie_zapisuje_i_daje_zdanie_po_polsku(string $kind, string $selection): void
    {
        $user = $this->user('zapisujaca');
        $other = $this->user('obca');
        $foreign = $other->collections()->create(['name' => 'Prywatne obiady', 'visibility' => 'private']);
        $target = $kind === 'recipe' ? Recipe::factory()->create() : Post::factory()->create();
        $url = $kind === 'recipe'
            ? route('collections.save', $target->slug)
            : route('collections.save-post', $target);
        $id = match ($selection) {
            'text' => 'to-nie-jest-uuid',
            'array' => [$foreign->getKey()],
            'missing' => (string) Str::uuid(),
            'foreign' => $foreign->getKey(),
        };

        $this->actingAs($user)->from('/home')->post($url, ['collection_id' => $id])
            ->assertRedirect('/home')
            ->assertSessionHasErrors([
                'collection_id' => 'Odśwież stronę i ponownie wybierz zeszyt do zapisania.',
            ]);

        $this->assertDatabaseCount('collection_items', 0);
        $this->assertSame(0, $user->collections()->count(), 'Odmowa nie może tworzyć zeszytu domyślnego.');
    }

    public static function validSelections(): array
    {
        return [
            'recipe own' => ['recipe', 'own'],
            'post own' => ['post', 'own'],
            'recipe omitted' => ['recipe', 'omitted'],
            'post omitted' => ['post', 'omitted'],
            'recipe empty' => ['recipe', 'empty'],
            'post empty' => ['post', 'empty'],
        ];
    }

    #[DataProvider('validSelections')]
    public function test_wlasny_zeszyt_i_brak_wyboru_nadal_zapisuja(string $kind, string $selection): void
    {
        $user = $this->user('zapisujaca');
        $collection = $user->collections()->create(['name' => 'Na niedzielę', 'visibility' => 'private']);
        $target = $kind === 'recipe' ? Recipe::factory()->create() : Post::factory()->create();
        $url = $kind === 'recipe'
            ? route('collections.save', $target->slug)
            : route('collections.save-post', $target);
        $data = match ($selection) {
            'own' => ['collection_id' => $collection->getKey()],
            'empty' => ['collection_id' => ''],
            'omitted' => [],
        };

        $this->actingAs($user)->from('/home')->post($url, $data)
            ->assertRedirect('/home')
            ->assertSessionHasNoErrors();

        $expected = $selection === 'own' ? $collection : $user->defaultCollection();
        $this->assertDatabaseHas('collection_items', [
            'collection_id' => $expected->getKey(),
            $kind === 'recipe' ? 'recipe_id' : 'post_id' => $target->getKey(),
        ]);
        $this->assertDatabaseCount('collection_items', 1);
    }
}

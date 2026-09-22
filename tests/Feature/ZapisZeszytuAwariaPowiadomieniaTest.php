<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ZapisZeszytuAwariaPowiadomieniaTest extends TestCase
{
    use DatabaseMigrations;

    public function test_odmowa_powiadomienia_wycofuje_zapis_a_ponowienie_dokancza_obie_rzeczy(): void
    {
        $reader = $this->user();
        $author = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $book = $reader->defaultCollection();
        // Wartość typu pochodzi z aplikacji, nie z założenia próbnika.
        $type = Notification::TYPE_SAVED;
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT proba_907 CHECK (type <> '$type') NOT VALID");
        try {
            $this->actingAs($reader)->post(route('collections.save', $recipe->slug), ['collection_id' => $book->id])->assertStatus(500);
        } finally {
            DB::statement('ALTER TABLE notifications DROP CONSTRAINT proba_907');
        }
        $afterFailure = DB::table('collection_items')->where('collection_id', $book->id)->count();
        $this->assertSame(0, Notification::where('type', $type)->count());
        $this->post(route('collections.save', $recipe->slug), ['collection_id' => $book->id])->assertRedirect();
        $afterRetry = Notification::where('type', $type)->count();
        $this->assertSame([0, 1], [$afterFailure, $afterRetry], 'Awaria nie może zostawić zapisu bez powiadomienia, którego retry już nie tworzy.');
        $this->assertSame(1, DB::table('collection_items')->where('collection_id', $book->id)->count());
        $this->post(route('collections.save', $recipe->slug), ['collection_id' => $book->id])->assertRedirect();
        $this->assertSame(1, Notification::where('type', $type)->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1395: po usunięciu przepisu własne archiwum autora pokazywało pustą
 * kartę zapowiedzi z odnośnikiem do 403. Zapowiedź i jej komentarze zostają
 * w bazie — znikają tylko z listy, lat i licznika.
 */
class ZapowiedzUsunietegoPrzepisuNaWlasnymProfiluTest extends TestCase
{
    use RefreshDatabase;

    public static function komentarze(): array
    {
        return ['bez komentarzy' => [0], 'z komentarzami' => [2]];
    }

    #[DataProvider('komentarze')]
    public function test_wlasne_archiwum_nie_pokazuje_zapowiedzi_usunietego_przepisu(int $komentarzy): void
    {
        $autor = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'title' => 'Znikający bigos']);
        $zapowiedz = WpisWskazujacyPrzepis::dopisz($przepis);
        $this->assertNotNull($zapowiedz);
        $zapowiedz->forceFill(['published_at' => now()->subYears(2)])->save();
        Comment::factory()->count($komentarzy)->create(['post_id' => $zapowiedz->id]);

        // Kontrola dodatnia: zwykły wpis i własny dostępny (także prywatny)
        // przepis zostają widoczne właścicielowi.
        $zwykly = Post::factory()->create(['author_id' => $autor->id, 'body' => 'Dziś zupa ogórkowa']);
        $prywatny = Recipe::factory()->create(['author_id' => $autor->id, 'title' => 'Mój prywatny pasztet', 'visibility' => 'private']);
        WpisWskazujacyPrzepis::dopisz($prywatny);
        $zTrescia = Post::factory()->create(['author_id' => $autor->id, 'recipe_id' => $przepis->id, 'body' => 'Mój opis zostaje']);

        $profil = route('profile.show', $autor->profile->username);
        $przed = $this->actingAs($autor)->get($profil)->assertOk();
        $this->assertSame(4, $przed->viewData('posts')->total());

        $this->actingAs($autor)->delete(route('recipes.destroy', $przepis->slug))->assertRedirect();
        $this->assertSoftDeleted($przepis);

        $po = $this->actingAs($autor)->get($profil)->assertOk();
        $html = $po->getContent();
        $this->assertStringNotContainsString(route('posts.show', $zapowiedz), $html, 'Martwa karta z linkiem do 403.');
        $this->assertSame(3, $po->viewData('posts')->total());
        $this->assertSame(3, $po->viewData('stats')['posts']);
        $this->assertSame([(int) now()->year], $po->viewData('lata')->all());
        $po->assertSee($zwykly->body)->assertSee($prywatny->title)->assertSee($zTrescia->body);

        // Adres zapowiedzi dalej odmawia, a dane nie zginęły.
        $this->actingAs($autor)->get(route('posts.show', $zapowiedz))->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $zapowiedz->id, 'deleted_at' => null]);
        $this->assertSame($komentarzy, Comment::where('post_id', $zapowiedz->id)->count());
    }
}

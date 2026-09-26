<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #766, druga połowa: karta wykonania nie może linkować do przepisu,
 * którego patrzący nie ma prawa zobaczyć.
 *
 * Własne wykonanie jest celowo dostępne także wtedy, gdy autor zrobił
 * przepis prywatnym albo moderacja go ukryła (`CookedEventPolicy::view`,
 * `UgotowalemWlasneWykonanieNieZnikaTest`). Karta pokazywała jednak zawsze
 * „z przepisu <a href=recipes.show>” — kucharz klikał i dostawał 403.
 * Teraz odnośnik stoi tylko przy `RecipePolicy::view`, a w pozostałych
 * przypadkach tytuł jest zwykłym tekstem ze zdaniem o braku dostępu.
 */
class KartaWykonaniaBezLinkuDoNiedostepnegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const ZDANIE = 'Ten przepis nie jest dla Ciebie dostępny.';

    public static function niedostepne(): array
    {
        return [
            'autor zmienił na prywatny' => [['visibility' => 'private']],
            'moderacja ukryła' => [['status' => Recipe::STATUS_HIDDEN]],
        ];
    }

    #[DataProvider('niedostepne')]
    public function test_wlasne_wykonanie_nie_linkuje_do_niedostepnego_przepisu(array $zmiana): void
    {
        [$kucharz, $przepis, $wykonanie] = $this->scena();

        // KONTROLA DODATNIA: przepis publiczny — odnośnik jest i działa.
        $this->assertKartaLinkuje($this->actingAs($kucharz)->get(route('cooked.show', $wykonanie)), $przepis);
        $this->actingAs($kucharz)->get(route('recipes.show', $przepis->slug))->assertOk();

        $przepis->forceFill($zmiana)->save();

        // Ten odnośnik prowadziłby do odmowy — to jest błąd z issue.
        $this->actingAs($kucharz)->get(route('recipes.show', $przepis->slug))->assertForbidden();

        $odpowiedz = $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))->assertOk();
        $this->assertKartaBezLinku($odpowiedz, $przepis);
        $odpowiedz->assertSee('Twoje zdjęcie i notatka zostają.');
        $odpowiedz->assertSee('Notatka kucharza');
        // Przycisk usunięcia nadal jest — własne dane nie znikają.
        $odpowiedz->assertSee('Usuń to wykonanie');
    }

    #[DataProvider('niedostepne')]
    public function test_zakladka_ugotowane_na_wlasnym_profilu_nie_linkuje_do_niedostepnego_przepisu(array $zmiana): void
    {
        [$kucharz, $przepis] = $this->scena();
        $adres = route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']);

        $this->assertKartaLinkuje($this->actingAs($kucharz)->get($adres), $przepis);

        $przepis->forceFill($zmiana)->save();

        $odpowiedz = $this->actingAs($kucharz)->get($adres)->assertOk();
        $this->assertKartaBezLinku($odpowiedz, $przepis);
        $odpowiedz->assertSee('Notatka kucharza');
    }

    public function test_na_wlasnym_profilu_dostepny_przepis_obok_niedostepnego_dalej_linkuje(): void
    {
        [$kucharz, $prywatny] = $this->scena();
        $prywatny->forceFill(['visibility' => 'private'])->save();

        $publiczny = Recipe::factory()->create([
            'author_id' => $prywatny->author_id,
            'slug' => 'bigos-publiczny',
            'title' => 'Bigos publiczny',
        ]);
        CookedEvent::factory()->create(['recipe_id' => $publiczny->getKey(), 'user_id' => $kucharz->getKey()]);

        $odpowiedz = $this->actingAs($kucharz)
            ->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']))
            ->assertOk();

        $this->assertKartaLinkuje($odpowiedz, $publiczny);
        $this->assertKartaBezLinku($odpowiedz, $prywatny);
    }

    public function test_moderator_na_cudzym_wykonaniu_nie_dostaje_zdania_o_swoich_zdjeciach(): void
    {
        [, $przepis, $wykonanie] = $this->scena();
        $przepis->forceFill(['visibility' => 'private'])->save();

        $moderator = $this->user('moderatorka');
        $moderator->forceFill(['role' => User::ROLE_MODERATOR])->save();

        $odpowiedz = $this->actingAs($moderator->fresh())->get(route('cooked.show', $wykonanie))->assertOk();
        $this->assertKartaBezLinku($odpowiedz, $przepis);
        $odpowiedz->assertDontSee('Twoje zdjęcie i notatka zostają.');
    }

    public function test_usuniety_przepis_ma_dalej_wlasne_zdanie(): void
    {
        [$kucharz, $przepis, $wykonanie] = $this->scena();
        $przepis->delete();

        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Przepisu, z którego to powstało, już nie ma.')
            ->assertDontSee(self::ZDANIE);
    }

    /**
     * @return array{User, Recipe, CookedEvent}
     */
    private function scena(): array
    {
        $autorka = $this->user('autorkaprzepisu');
        $kucharz = $this->user('kucharz');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => 'public',
            'slug' => 'rosol-babci',
            'title' => 'Rosół babci Heleny',
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => 'Notatka kucharza z gotowania.',
        ]);

        return [$kucharz, $przepis, $wykonanie];
    }

    private function assertKartaLinkuje(TestResponse $odpowiedz, Recipe $przepis): void
    {
        $odpowiedz->assertOk();
        $odpowiedz->assertSee('href="'.route('recipes.show', $przepis->slug).'"', false);
        $odpowiedz->assertSee($przepis->title);
    }

    private function assertKartaBezLinku(TestResponse $odpowiedz, Recipe $przepis): void
    {
        $odpowiedz->assertDontSee(route('recipes.show', $przepis->slug), false);
        $odpowiedz->assertSee('z przepisu „'.$przepis->title.'”. '.self::ZDANIE, false);
    }
}

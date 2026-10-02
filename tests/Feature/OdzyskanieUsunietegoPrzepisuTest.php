<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneUsunieteTresci;
use App\Domain\Recipes\Actions\GenerateRecipeSlug;
use App\Domain\Recipes\OdzyskajUsunietyPrzepis;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\Report;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Autor odzyskuje własny, omyłkowo usunięty przepis przed końcem retencji
 * (issue #2620, D-333).
 *
 * Mierzymy obie strony granicy: przed terminem przepis wraca jako PRYWATNY
 * szkic z całą treścią, a po terminie, jako nagrobek, po decyzji moderacji,
 * na cudzym albo wymazanym koncie — nie wraca i nic się nie zmienia.
 */
class OdzyskanieUsunietegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.usuniete_tresci.retention_days' => 30]);
    }

    private function usunietyPrzepis(User $autor, int $dniTemu = 1, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Sernik babci', ...$atrybuty]);

        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => 'twaróg', 'position' => 0]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Zmiksuj.']);

        $this->travelTo(now()->subDays($dniTemu), fn () => $przepis->delete());

        return $przepis->refresh();
    }

    private function odzyskaj(User $kto, Recipe $przepis): TestResponse
    {
        return $this->actingAs($kto)->post(route('collections.deleted-recipes.recover', $przepis->getKey()));
    }

    private function sprawaModeracyjna(Recipe $przepis, string $akcja = 'remove'): void
    {
        DB::table('moderation_actions')->insert([
            'id' => (string) Str::uuid(),
            'moderator_id' => $this->user()->getKey(),
            'target_type' => 'recipe',
            'target_id' => $przepis->getKey(),
            'action' => $akcja,
            'reason_code' => 'spam',
            'created_at' => now(),
        ]);
    }

    public function test_autor_odzyskuje_przepis_przed_terminem_jako_prywatny_szkic_z_cala_trescia(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor, 5);
        $slug = $przepis->slug;
        $wykonanie = CookedEvent::factory()->create(['recipe_id' => $przepis->getKey()]);

        $odpowiedz = $this->odzyskaj($autor, $przepis);

        $odpowiedz->assertRedirect(route('recipes.create', ['szkic' => $przepis->getKey()]));
        $odpowiedz->assertSessionHas('status', fn (string $t): bool => str_contains($t, 'szkic widoczny tylko dla Ciebie'));

        $odzyskany = Recipe::query()->findOrFail($przepis->getKey());
        $this->assertNull($odzyskany->deleted_at);
        $this->assertSame(Recipe::STATUS_DRAFT, $odzyskany->status);
        $this->assertNull($odzyskany->published_at);
        $this->assertSame('private', $odzyskany->visibility);
        $this->assertSame($slug, $odzyskany->slug);
        $this->assertSame('Sernik babci', $odzyskany->title);
        $this->assertSame(['twaróg'], $odzyskany->ingredients()->pluck('ingredient_text')->all());
        $this->assertSame(['Zmiksuj.'], $odzyskany->steps()->pluck('instruction')->all());

        // „Ugotowałem” innych osób nigdy nie znikało i nie znika.
        $this->assertDatabaseHas('cooked_events', ['id' => $wykonanie->getKey()]);
    }

    public function test_odzyskany_przepis_nie_jest_publiczny_dopoki_autor_go_nie_opublikuje(): void
    {
        $autor = $this->user();
        $obcy = $this->user();
        $przepis = $this->usunietyPrzepis($autor);
        $zeszyt = Collection::create(['owner_id' => $obcy->getKey(), 'name' => 'Cudzy zeszyt', 'visibility' => 'private']);
        $zeszyt->recipes()->attach($przepis->getKey());

        $this->odzyskaj($autor, $przepis);

        auth()->logout();
        $this->get(route('recipes.show', $przepis->slug))->assertForbidden();
        $this->actingAs($obcy)->get(route('recipes.show', $przepis->slug))->assertForbidden();
        $this->actingAs($obcy)->get(route('collections.show', $zeszyt))->assertDontSee('Sernik babci');
        $this->assertDatabaseHas('collection_items', ['collection_id' => $zeszyt->getKey(), 'recipe_id' => $przepis->getKey()]);
        $this->actingAs($autor)->get(route('recipes.show', $przepis->slug))->assertOk();
    }

    public function test_lista_pokazuje_termin_z_konfiguracji_i_tylko_przepisy_do_odzyskania(): void
    {
        config(['kuking.usuniete_tresci.retention_days' => 7]);
        $autor = $this->user();
        $obcy = $this->user();

        $swiezy = $this->usunietyPrzepis($autor, 2, ['title' => 'Pierogi ruskie']);
        $this->usunietyPrzepis($autor, 9, ['title' => 'Za stary rosół']);
        $this->usunietyPrzepis($obcy, 1, ['title' => 'Cudzy bigos']);
        $zModeracja = $this->usunietyPrzepis($autor, 1, ['title' => 'Zdjęty przez moderację']);
        $this->sprawaModeracyjna($zModeracja);
        $this->usunietyPrzepis($autor, 1, ['title' => 'Aktualnie ukryty', 'status' => Recipe::STATUS_HIDDEN]);

        $odpowiedz = $this->actingAs($autor)->get(route('collections.deleted-recipes'));

        $odpowiedz->assertOk()
            ->assertSee('Pierogi ruskie')
            ->assertSee('przez 7 dni')
            ->assertSee('Odzyskaj przepis')
            ->assertDontSee('Za stary rosół')
            ->assertDontSee('Cudzy bigos')
            ->assertDontSee('Zdjęty przez moderację')
            ->assertDontSee('Aktualnie ukryty');

        $this->assertStringContainsString(
            Czas::data(OdzyskajUsunietyPrzepis::termin($swiezy), 'j F Y, H:i'),
            $odpowiedz->getContent(),
        );
    }

    public function test_po_terminie_przepis_nie_wraca(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor, 31);

        $this->odzyskaj($autor, $przepis)
            ->assertRedirect(route('collections.deleted-recipes'))
            ->assertSessionHas('status', fn (string $t): bool => str_contains($t, 'nie da się już odzyskać'));

        $this->assertSoftDeleted($przepis);
    }

    public function test_nagrobek_nie_jest_odtwarzany(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor, 31);
        CookedEvent::factory()->create(['recipe_id' => $przepis->getKey()]);

        $wynik = app(PrzedawnioneUsunieteTresci::class)->posprzataj(30);
        $this->assertSame(1, $wynik['nagrobki']);

        // Nagrobek jest „nowy" dla okna tylko wtedy, gdy ktoś cofnie zegar usunięcia —
        // tu sprawdzamy sam warunek: oknem nie jest, ale i tak odmowa po treści.
        DB::table('recipes')->where('id', $przepis->getKey())->update(['deleted_at' => now()->subDay()]);

        $this->odzyskaj($autor, $przepis)->assertRedirect(route('collections.deleted-recipes'));

        $nagrobek = Recipe::withTrashed()->findOrFail($przepis->getKey());
        $this->assertTrue($nagrobek->trashed());
        $this->assertSame(PrzedawnioneUsunieteTresci::TYTUL_NAGROBKA, $nagrobek->title);
        $this->assertSame([], $this->actingAs($autor)->get(route('collections.deleted-recipes'))->viewData('przepisy')->all());
    }

    public function test_cudze_konto_moderator_i_gosc_nie_odzyskuja(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor);

        $this->odzyskaj($this->user(), $przepis)->assertForbidden();
        $this->odzyskaj($this->moderator(), $przepis)->assertForbidden();

        auth()->logout();
        $this->post(route('collections.deleted-recipes.recover', $przepis->getKey()))->assertRedirect(route('login'));

        $this->assertSoftDeleted($przepis);
    }

    public function test_nieistniejacy_identyfikator_to_404(): void
    {
        $this->actingAs($this->user())
            ->post(route('collections.deleted-recipes.recover', (string) Str::uuid()))
            ->assertNotFound();
    }

    public function test_przepis_zdjety_przez_moderacje_nie_wraca_przez_autora(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor);
        $this->sprawaModeracyjna($przepis);

        $this->odzyskaj($autor, $przepis)
            ->assertRedirect(route('collections.deleted-recipes'))
            ->assertSessionHas('status', fn (string $t): bool => str_contains($t, 'nie da się już odzyskać'));

        $this->assertSoftDeleted($przepis);
    }

    #[DataProvider('statusyZdjeteDecyzja')]
    public function test_przepis_ukryty_albo_zdjety_statusem_nie_wraca(string $status): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor, 1, ['status' => $status]);

        $this->odzyskaj($autor, $przepis)->assertRedirect(route('collections.deleted-recipes'));

        $this->assertSoftDeleted($przepis);
    }

    /** @return array<string, array{string}> */
    public static function statusyZdjeteDecyzja(): array
    {
        return [
            'ukryty przez moderację' => [Recipe::STATUS_HIDDEN],
            'zdjęty przez moderację' => [Recipe::STATUS_REMOVED],
        ];
    }

    public function test_sprawa_przy_komentarzu_pod_przepisem_tez_blokuje_odzyskanie(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor);
        $komentarz = Comment::factory()->create(['recipe_id' => $przepis->getKey(), 'post_id' => null]);
        Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'comment',
            'target_id' => $komentarz->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->odzyskaj($autor, $przepis)->assertRedirect(route('collections.deleted-recipes'));

        $this->assertSoftDeleted($przepis);
    }

    #[DataProvider('kontaBezPrawaDoOdzyskania')]
    public function test_konto_nieaktywne_nie_odzyskuje(string $stan): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor);

        match ($stan) {
            'zawieszone' => $autor->suspend(),
            'zbanowane' => $autor->ban(),
            'do usuniecia' => $autor->markForDeletion(User::DELETE_SCOPE_EVERYTHING),
        };

        // Konto bez prawa pisania odbija już middleware (przekierowanie z
        // komunikatem) albo Policy (403) — w każdym razie przepis zostaje.
        $this->assertContains($this->odzyskaj($autor->fresh(), $przepis)->getStatusCode(), [302, 403]);
        $this->assertContains($this->actingAs($autor->fresh())->get(route('collections.deleted-recipes'))->getStatusCode(), [302, 403]);

        $this->assertSoftDeleted($przepis);
    }

    /** @return array<string, array{string}> */
    public static function kontaBezPrawaDoOdzyskania(): array
    {
        return [
            'zawieszone' => ['zawieszone'],
            'zbanowane' => ['zbanowane'],
            'w trakcie usuwania' => ['do usuniecia'],
        ];
    }

    public function test_konto_zmienione_w_trakcie_jest_sprawdzane_pod_blokada(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor);

        // Model z żądania wciąż mówi „aktywne”; baza już nie.
        DB::table('users')->where('id', $autor->getKey())->update(['status' => User::STATUS_BANNED]);

        try {
            app(OdzyskajUsunietyPrzepis::class)->handle($autor, (string) $przepis->getKey());
            $this->fail('Konto zbanowane w międzyczasie odzyskało przepis.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('Stan Twojego konta zmienił się', $e->getMessage());
        }

        $this->assertSoftDeleted($przepis);
    }

    public function test_drugie_wyslanie_nie_dubluje_i_mowi_to_wprost(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor);

        $this->odzyskaj($autor, $przepis)->assertSessionHas('status_rodzaj', 'sukces');
        $drugie = $this->odzyskaj($autor, $przepis);

        $drugie->assertRedirect(route('recipes.create', ['szkic' => $przepis->getKey()]))
            ->assertSessionHas('status_rodzaj', 'informacja')
            ->assertSessionHas('status', fn (string $t): bool => str_contains($t, 'już odzyskany'));

        $this->assertSame(1, Recipe::query()->where('author_id', $autor->getKey())->count());
        $this->assertSame(1, $przepis->ingredients()->count());
    }

    public function test_ekran_mowi_wprost_ktorych_zdjec_nie_odzyskano(): void
    {
        $autor = $this->user();
        $dobre = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $odrzucone = Media::factory()->create(['owner_id' => $autor->getKey(), 'status' => Media::STATUS_REJECTED]);
        $cudze = Media::factory()->create(['owner_id' => $this->user()->getKey()]);

        $przepis = $this->usunietyPrzepis($autor, 1, ['hero_media_id' => $odrzucone->getKey()]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Piecz.', 'media_id' => $cudze->getKey()]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 2, 'instruction' => 'Ostudź.', 'media_id' => $dobre->getKey()]);

        $this->odzyskaj($autor, $przepis)
            ->assertSessionHas('status', fn (string $t): bool => str_contains($t, 'Nie wróciło 2 zdjęcia'));

        $odzyskany = $przepis->fresh();
        $this->assertNull($odzyskany->hero_media_id);
        $this->assertSame(
            [null, $dobre->getKey()],
            $odzyskany->steps()->whereIn('position', [1, 2])->pluck('media_id')->all(),
        );
    }

    public function test_przepis_bez_problemow_ze_zdjeciami_mowi_ze_sa_na_miejscu(): void
    {
        $autor = $this->user();
        $dobre = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $przepis = $this->usunietyPrzepis($autor, 1, ['hero_media_id' => $dobre->getKey()]);

        $this->odzyskaj($autor, $przepis)
            ->assertSessionHas('status', fn (string $t): bool => str_contains($t, 'Zdjęcia, które były przy przepisie, są na miejscu'));

        $this->assertSame($dobre->getKey(), $przepis->fresh()->hero_media_id);
    }

    public function test_adres_przepisu_jest_zarezerwowany_nowy_przepis_o_tym_samym_tytule_nie_koliduje(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor, 3, ['slug' => 'sernik-babci']);

        $nowy = (new GenerateRecipeSlug)->handle('Sernik babci');
        $this->assertSame('sernik-babci-2', $nowy);
        Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Sernik babci', 'slug' => $nowy]);

        $this->odzyskaj($autor, $przepis)->assertRedirect();

        $this->assertSame('sernik-babci', $przepis->fresh()->slug);
        $this->assertSame(2, Recipe::query()->where('author_id', $autor->getKey())->count());
    }

    public function test_sprzatanie_nie_rusza_odzyskanego_przepisu_i_zabiera_nieodzyskany(): void
    {
        $autor = $this->user();
        $odzyskany = $this->usunietyPrzepis($autor, 20);
        $porzucony = $this->usunietyPrzepis($autor, 20);
        $this->odzyskaj($autor, $odzyskany);

        $this->travel(15)->days();
        app(PrzedawnioneUsunieteTresci::class)->posprzataj(30);

        $this->assertDatabaseHas('recipes', ['id' => $odzyskany->getKey(), 'deleted_at' => null]);
        $this->assertDatabaseMissing('recipes', ['id' => $porzucony->getKey()]);
    }

    public function test_komunikat_po_usunieciu_podaje_termin_z_konfiguracji(): void
    {
        config(['kuking.usuniete_tresci.retention_days' => 14]);
        $autor = $this->user();
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($autor)->delete(route('recipes.destroy', $przepis->slug))
            ->assertSessionHas('status', fn (string $t): bool => str_contains($t, 'przez 14 dni')
                && str_contains($t, 'Usunięte przepisy'));
    }

    public function test_przycisk_odzyskania_ma_napis_i_nie_wymaga_skryptu(): void
    {
        $autor = $this->user();
        $przepis = $this->usunietyPrzepis($autor);

        $html = $this->actingAs($autor)->get(route('collections.deleted-recipes'))->getContent();

        $this->assertStringContainsString('action="'.route('collections.deleted-recipes.recover', $przepis->getKey()).'"', $html);
        $this->assertMatchesRegularExpression('/<button class="btn btn-primary" type="submit"[^>]*>Odzyskaj przepis<\/button>/u', $html);
        $this->assertStringContainsString('aria-label="Odzyskaj przepis: Sernik babci"', $html);
    }
}

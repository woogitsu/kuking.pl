<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * „Dopisz przepis” do własnego wpisu ze zdjęciem (issue #1334) —
 * wersja najprostsza, bez migracji danych.
 *
 * Pilnujemy trzech rzeczy: (1) akcja jest tylko u autora kwalifikującego się
 * wpisu i serwer pyta Policy także przy zapisie, (2) przepis dostaje zdjęcie
 * wpisu bez ponownego wgrywania, (3) wpis zostaje nietknięty — treść,
 * widoczność, komentarze, zdjęcie — i jego zdjęcie nie ginie z żadnej strony.
 */
class DopiszPrzepisDoWpisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_autor_widzi_formularz_ze_zdjeciem_wpisu(): void
    {
        [$autor, $wpis, $zdjecie] = $this->wpisZeZdjeciem(Post::VISIBILITY_FOLLOWERS);

        $this->actingAs($autor)
            ->get(route('recipes.create.from-post', $wpis))
            ->assertOk()
            ->assertSee('Dopisz przepis do swojego dania')
            ->assertSee('<input type="hidden" name="z_wpisu" value="'.$wpis->getKey().'">', false)
            ->assertSee('data-zdjecie-z-wpisu', false)
            ->assertSee('Zdjęcie z wpisu zobaczy każdy, kto zobaczy ten przepis')
            ->assertSee('Pierogi jak u mamy, wyszły wreszcie.')
            // Wpis nie jest publiczny, więc domyślnie „Tylko ja” — nic nie
            // staje się publiczne bez świadomego wyboru.
            ->assertSee('value="private" checked', false)
            ->assertDontSee('value="public" checked', false);
    }

    public function test_akcja_jest_w_menu_karty_tylko_u_autora(): void
    {
        [$autor, $wpis] = $this->wpisZeZdjeciem(Post::VISIBILITY_PUBLIC);
        $obca = $this->user('obca');
        $link = 'href="'.e(route('recipes.create.from-post', $wpis)).'"';

        $this->actingAs($autor)->get($wpis->url())->assertOk()->assertSee($link, false);
        $this->actingAs($obca)->get($wpis->url())->assertOk()->assertDontSee($link, false);
    }

    public function test_obcy_i_wpisy_niekwalifikujace_sie_dostaja_odmowe(): void
    {
        [$autor, $wpis] = $this->wpisZeZdjeciem(Post::VISIBILITY_PUBLIC);
        $obca = $this->user('obca');

        $this->actingAs($obca)->get(route('recipes.create.from-post', $wpis))->assertForbidden();

        // Wpis bez zdjęcia.
        $bezZdjecia = Post::factory()->create(['author_id' => $autor->getKey()]);
        $this->actingAs($autor)->get(route('recipes.create.from-post', $bezZdjecia))->assertForbidden();

        // Zapowiedź przepisu — przepis już jest.
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $zapowiedz = $this->wpisZeZdjeciem(Post::VISIBILITY_PUBLIC, $autor)[1];
        $zapowiedz->forceFill(['recipe_id' => $przepis->getKey()])->save();
        $this->actingAs($autor)->get(route('recipes.create.from-post', $zapowiedz))->assertForbidden();

        // Pytanie.
        config(['kuking.questions.enabled' => true]);
        $pytanie = Post::factory()->question()->create(['author_id' => $autor->getKey()]);
        $pytanie->media()->attach(Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(), ['position' => 0]);
        $this->actingAs($autor)->get(route('recipes.create.from-post', $pytanie))->assertForbidden();

        // Kontrola dodatnia: ten sam autor z kwalifikującym się wpisem wchodzi.
        $this->actingAs($autor)->get(route('recipes.create.from-post', $wpis))->assertOk();
    }

    public function test_zapis_uzywa_zdjecia_wpisu_i_nie_rusza_wpisu(): void
    {
        [$autor, $wpis, $zdjecie] = $this->wpisZeZdjeciem(Post::VISIBILITY_FOLLOWERS);
        $komentarz = Comment::factory()->create(['post_id' => $wpis->getKey()]);
        $przed = $this->stanWpisu($wpis);

        $this->actingAs($autor)
            ->post(route('recipes.store'), $this->formularz(['z_wpisu' => $wpis->getKey()]))
            ->assertRedirect();

        $przepis = Recipe::query()->where('author_id', $autor->getKey())->sole();
        $this->assertSame($zdjecie->getKey(), $przepis->hero_media_id, 'Przepis nie dostał zdjęcia z wpisu.');
        $this->assertSame('private', $przepis->visibility);

        $wpis->refresh();
        $this->assertSame($przed, $this->stanWpisu($wpis), 'Wpis zmienił się przy dopisaniu przepisu.');
        $this->assertTrue($wpis->media()->whereKey($zdjecie->getKey())->exists(), 'Zdjęcie zniknęło z wpisu.');
        $this->assertTrue(Comment::query()->whereKey($komentarz->getKey())->where('post_id', $wpis->getKey())->exists());

        // Zdjęcie ma teraz dwóch rodziców — sprzątacz nie skasuje go ani po
        // odpięciu od wpisu, ani po usunięciu przepisu.
        $wpis->media()->detach($zdjecie->getKey());
        $this->assertFalse(app(KasujZdjecie::class)->jesliNieuzywane($zdjecie->fresh()), 'Zdjęcie przepisu skasowane po odpięciu od wpisu.');
        $this->assertNotNull(Media::query()->find($zdjecie->getKey()));
    }

    public function test_zapis_z_cudzym_wpisem_jest_odrzucony_i_nic_nie_powstaje(): void
    {
        [, $cudzyWpis] = $this->wpisZeZdjeciem(Post::VISIBILITY_PUBLIC);
        $intruz = $this->user('intruz');

        $this->actingAs($intruz)
            ->post(route('recipes.store'), $this->formularz(['z_wpisu' => $cudzyWpis->getKey()]))
            ->assertForbidden();

        $this->assertSame(0, Recipe::query()->where('author_id', $intruz->getKey())->count());

        // Kontrola dodatnia: bez `z_wpisu` ten sam formularz przechodzi.
        $this->actingAs($intruz)->post(route('recipes.store'), $this->formularz())->assertRedirect();
        $this->assertNull(Recipe::query()->where('author_id', $intruz->getKey())->sole()->hero_media_id);
    }

    /** @return array{0: User, 1: Post, 2: Media} */
    private function wpisZeZdjeciem(string $widocznosc, ?User $autor = null): array
    {
        Storage::fake('public');
        $autor ??= $this->user('autorka');

        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $wariant) {
            Storage::disk('public')->put($wariant['key'], 'bajty-zdjecia');
        }

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => $widocznosc,
            'body' => 'Pierogi jak u mamy, wyszły wreszcie.',
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return [$autor, $wpis, $zdjecie];
    }

    /** @return array<string, mixed> */
    private function stanWpisu(Post $wpis): array
    {
        return [...$wpis->only(['body', 'visibility', 'status', 'recipe_id', 'kind']), 'published_at' => (string) $wpis->published_at];
    }

    /** @return array<string, mixed> */
    private function formularz(array $dodatki = []): array
    {
        return [
            'title' => 'Pierogi ruskie mamy',
            'skladniki_tekst' => "mąka\nziemniaki\ntwaróg",
            'przygotowanie_tekst' => 'Zagnieć ciasto.'."\n\n".'Ulep pierogi i ugotuj.',
            'visibility' => 'private',
            'action' => 'publish',
            ...$dodatki,
        ];
    }
}

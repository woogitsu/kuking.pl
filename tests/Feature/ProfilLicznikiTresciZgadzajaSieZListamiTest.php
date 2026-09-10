<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Liczniki wpisów/przepisów/„Ugotowałem" na profilu a to, co widz NAPRAWDĘ
 * widzi na listach pod nimi (audyt ZESZYTY/PROFIL, punkt 1) —
 * ZMIERZONE I W PORZĄDKU, zapisane testem, żeby zostało w porządku.
 *
 * `ProfileController::stats` liczy te trzy liczniki DOKŁADNIE tym samym
 * `tap(fn ($query) => $this->tylkoWidoczne(...))` / `tylkoZWidocznychPrzepisow`,
 * którego używają zakładki „Wszystko", „Przepisy" i „Ugotowane" — to nie jest
 * przypadek, tylko jedna metoda prywatna wywołana w dwóch miejscach. Ten test
 * nie naprawia niczego: zamyka drzwi przed przyszłym rozjazdem (np. gdyby
 * ktoś policzył licznik osobnym zapytaniem "dla wydajności").
 */
class ProfilLicznikiTresciZgadzajaSieZListamiTest extends TestCase
{
    use RefreshDatabase;

    public function test_licznik_przepisow_zgadza_sie_z_lista_gdy_przepis_jest_tylko_dla_obserwujacych(): void
    {
        $wlasciciel = $this->user('wlascicielka7');
        $obcy = $this->user('obca7');

        Recipe::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Zupa dla wszystkich',
            'slug' => 'zupa-dla-wszystkich-'.Str::lower(Str::random(6)),
        ]);

        Recipe::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => 'followers',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Zupa dla swoich',
            'slug' => 'zupa-dla-swoich-'.Str::lower(Str::random(6)),
        ]);

        $odpowiedz = $this->actingAs($obcy)
            ->get(route('profile.show', ['username' => 'wlascicielka7', 'zakladka' => 'przepisy']))
            ->assertOk();

        // KONTROLA LICZBOWA: dokładnie 1 (sam publiczny), nie „mniej niż 2".
        // Licznik siedzi na zakładce „Wszystko", więc sprawdzamy ją osobno —
        // to ta sama odpowiedź, co widz dostałby po kliknięciu w liczbę.
        $odpowiedzWszystko = $this->actingAs($obcy)
            ->get(route('profile.show', ['username' => 'wlascicielka7']))
            ->assertOk();

        $odpowiedzWszystko->assertSee(
            '<span class="stat-value">1</span> <span class="stat-label">przepis</span>',
            false,
        );
        $odpowiedz->assertSee('Zupa dla wszystkich');
        $odpowiedz->assertDontSee('Zupa dla swoich');
    }

    public function test_licznik_wpisow_zgadza_sie_z_lista_gdy_wpis_jest_tylko_dla_obserwujacych(): void
    {
        $wlasciciel = $this->user('wlascicielka8');
        $obcy = $this->user('obca8');

        Post::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => 'published',
            'published_at' => now(),
            'body' => 'Publiczny wpis widoczny dla wszystkich',
        ]);

        Post::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => Post::VISIBILITY_FOLLOWERS,
            'status' => 'published',
            'published_at' => now(),
            'body' => 'Wpis tylko dla obserwujących',
        ]);

        $odpowiedz = $this->actingAs($obcy)
            ->get(route('profile.show', ['username' => 'wlascicielka8']))
            ->assertOk();

        // KONTROLA LICZBOWA: dokładnie 1 (sam publiczny), nie „mniej niż 2".
        $odpowiedz->assertSee(
            '<span class="stat-value">1</span> <span class="stat-label">wpis</span>',
            false,
        );
        $odpowiedz->assertSee('Publiczny wpis widoczny dla wszystkich');
        $odpowiedz->assertDontSee('Wpis tylko dla obserwujących');
    }

    public function test_licznik_ugotowanych_zgadza_sie_z_lista_gdy_przepis_jest_prywatny(): void
    {
        $wlasciciel = $this->user('wlascicielka9');
        $obcy = $this->user('obca9');

        $publiczny = Recipe::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Zupa jawna',
            'slug' => 'zupa-jawna-'.Str::lower(Str::random(6)),
        ]);

        $prywatny = Recipe::factory()->create([
            'author_id' => $wlasciciel->getKey(),
            'visibility' => 'private',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => 'Zupa tajna',
            'slug' => 'zupa-tajna-'.Str::lower(Str::random(6)),
        ]);

        CookedEvent::factory()->create(['recipe_id' => $publiczny->getKey(), 'user_id' => $wlasciciel->getKey()]);
        CookedEvent::factory()->create(['recipe_id' => $prywatny->getKey(), 'user_id' => $wlasciciel->getKey()]);

        $odpowiedz = $this->actingAs($obcy)
            ->get(route('profile.show', ['username' => 'wlascicielka9', 'zakladka' => 'ugotowane']))
            ->assertOk();

        // KONTROLA LICZBOWA na stronie „Wszystko" (licznik jest tam, nie na
        // samej zakładce „Ugotowane"): dokładnie 1, nie „mniej niż 2".
        $odpowiedzWszystko = $this->actingAs($obcy)
            ->get(route('profile.show', ['username' => 'wlascicielka9']))
            ->assertOk();

        $odpowiedzWszystko->assertSee(
            '<span class="stat-value">1</span> <span class="stat-label">raz Ugotowałem</span>',
            false,
        );

        // Tytuł tajnego przepisu nie ma prawa wyjść przez samo wykonanie.
        $odpowiedz->assertDontSee('Zupa tajna');
        $odpowiedz->assertSee('Zupa jawna');
    }
}

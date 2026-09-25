<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\MapaStrony;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zapamiętana mapa strony nadąża za widocznością treści (issue #1006).
 *
 * Mapa siedzi w cache przez sześć godzin. Do 24 września 2026 nic tego
 * klucza nie kasowało, a jedyny test granicy robił `Cache::flush()` przed
 * drugim odczytem — sprawdzał więc świeże zapytanie, nie to, co widzi robot.
 * Tu NIKT nie czyści cache ręcznie: pierwszy odczyt mapę zapamiętuje,
 * zmiana idzie zwykłym zapisem modelu, drugi odczyt musi już widzieć skutek.
 */
class MapaStronyNadazaZaWidocznosciaTest extends TestCase
{
    use RefreshDatabase;

    private function autor(string $nazwa = 'kucharz'): User
    {
        $autor = User::factory()->create()->load('profile');
        $autor->profile->forceFill(['username' => $nazwa])->save();

        return $autor;
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'body' => 'Rosół wyszedł złoty.',
        ]);
    }

    private function mapa(): string
    {
        return (string) $this->get(route('sitemap'))->assertOk()->getContent();
    }

    public function test_przepis_przestawiony_na_prywatny_znika_z_zapamietanej_mapy(): void
    {
        $przepis = $this->przepis($this->autor());
        $this->assertStringContainsString(route('recipes.show', $przepis->slug), $this->mapa());

        $przepis->forceFill(['visibility' => 'private'])->save();

        $this->assertStringNotContainsString(route('recipes.show', $przepis->slug), $this->mapa());
    }

    public function test_ukryty_i_usuniety_wpis_znika_z_zapamietanej_mapy(): void
    {
        $autor = $this->autor();
        $ukryty = $this->wpis($autor);
        $usuniety = $this->wpis($autor);
        $mapa = $this->mapa();
        $this->assertStringContainsString(route('posts.show', $ukryty->getKey()), $mapa);
        $this->assertStringContainsString(route('posts.show', $usuniety->getKey()), $mapa);

        // Osobno, z odczytem po każdej zmianie — inaczej skasowanie klucza
        // przez pierwszą zmianę przykryłoby brak haka przy drugiej.
        $ukryty->forceFill(['status' => Post::STATUS_HIDDEN])->save();
        $mapa = $this->mapa();
        $this->assertStringNotContainsString(route('posts.show', $ukryty->getKey()), $mapa);
        $this->assertStringContainsString(route('posts.show', $usuniety->getKey()), $mapa);

        $usuniety->delete();
        $this->assertStringNotContainsString(route('posts.show', $usuniety->getKey()), $this->mapa());
    }

    public function test_ban_autora_zdejmuje_z_mapy_jego_przepisy_wpisy_i_profil(): void
    {
        $autor = $this->autor('zbanowany');
        $przepis = $this->przepis($autor);
        $wpis = $this->wpis($autor);

        // Kontrola dodatnia: publiczna treść INNEJ osoby zostaje w mapie.
        $inny = $this->autor('sasiadka');
        $przepisInnego = $this->przepis($inny);

        $mapa = $this->mapa();
        $this->assertStringContainsString(route('profile.show', 'zbanowany'), $mapa);

        $autor->forceFill(['status' => User::STATUS_BANNED])->save();

        $mapa = $this->mapa();
        $this->assertStringNotContainsString(route('recipes.show', $przepis->slug), $mapa);
        $this->assertStringNotContainsString(route('posts.show', $wpis->getKey()), $mapa);
        $this->assertStringNotContainsString(route('profile.show', 'zbanowany'), $mapa);
        $this->assertStringContainsString(route('recipes.show', $przepisInnego->slug), $mapa);
        $this->assertStringContainsString(route('profile.show', 'sasiadka'), $mapa);
    }

    public function test_zmiana_nazwy_profilu_zmienia_adres_w_mapie(): void
    {
        $autor = $this->autor('staranazwa');
        $this->przepis($autor);
        $this->assertStringContainsString(route('profile.show', 'staranazwa'), $this->mapa());

        $autor->profile->forceFill(['username' => 'nowanazwa'])->save();

        $mapa = $this->mapa();
        $this->assertStringNotContainsString(route('profile.show', 'staranazwa'), $mapa);
        $this->assertStringContainsString(route('profile.show', 'nowanazwa'), $mapa);
    }

    public function test_nowa_publikacja_trafia_do_mapy_po_zatwierdzeniu_transakcji(): void
    {
        $autor = $this->autor();
        $this->mapa();

        DB::transaction(function () use ($autor, &$przepis): void {
            $przepis = $this->przepis($autor);

            // Przed COMMIT klucz stoi — inaczej równoległe żądanie odbudowałoby
            // mapę bez tej publikacji i zapamiętało ją na sześć godzin.
            $this->assertTrue(Cache::has(MapaStrony::KLUCZ));
        });

        $this->assertStringContainsString(route('recipes.show', $przepis->slug), $this->mapa());
    }

    public function test_wycofana_transakcja_nie_kasuje_mapy_i_nie_rusza_reszty_cache(): void
    {
        $przepis = $this->przepis($this->autor());
        $this->mapa();
        Cache::put('inny.klucz', 'zostaje');

        try {
            DB::transaction(function () use ($przepis): void {
                $przepis->forceFill(['visibility' => 'private'])->save();

                throw new \RuntimeException('wycofaj');
            });
        } catch (\RuntimeException) {
        }

        $this->assertTrue(Cache::has(MapaStrony::KLUCZ), 'Wycofany zapis skasował mapę.');

        $przepis->refresh()->forceFill(['visibility' => 'private'])->save();

        $this->assertFalse(Cache::has(MapaStrony::KLUCZ));
        $this->assertSame('zostaje', Cache::get('inny.klucz'), 'Unieważnienie mapy wyczyściło cały cache.');
    }

    public function test_zapis_bez_wplywu_na_widocznosc_nie_kasuje_mapy(): void
    {
        $przepis = $this->przepis($this->autor());
        $this->mapa();

        $przepis->forceFill(['title' => 'Inny tytuł tego samego przepisu'])->save();

        $this->assertTrue(Cache::has(MapaStrony::KLUCZ));
    }
}

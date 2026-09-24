<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\TagFeed;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Własne wpisy osoby, która nikogo nie obserwuje, stoją na jej Starcie
 * (issue #1318, decyzja właściciela z 24.09).
 *
 * `FollowingFeed::paginate()` od zawsze dokłada autora do listy, żeby po
 * publikacji nie było wrażenia, że nic się nie zapisało. Ale bez
 * obserwowanych osób Start wybiera feed tagów albo odkrywanie — a odkrywanie
 * bierze wyłącznie `visibility = public`. Własny wpis „tylko dla
 * obserwujących" nie stał więc na Starcie autora wcale; to normalna ścieżka
 * pierwszego dnia (onboarding pozwala pominąć tagi i osoby).
 *
 * Decyzja: własne wpisy (publiczne i „tylko dla obserwujących", tylko
 * opublikowane) są WMIESZANE w feed zastępczy — chronologicznie, bez
 * duplikatów — a nagłówek nie obiecuje samych cudzych wpisów.
 */
class WlasneWpisyWFeedzieZastepczymTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $atrybuty */
    private function wpis(User $autor, string $tresc, array $atrybuty = []): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            ...$atrybuty,
        ]);
    }

    /** @return list<string> */
    private function idy(CursorPaginator $strona): array
    {
        return collect($strona->items())->map(fn (Post $p) => (string) $p->getKey())->all();
    }

    public function test_bez_obserwacji_start_pokazuje_wlasny_wpis_dla_obserwujacych_obok_swiezo_z_kuking(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia Nowa']);
        $halina = $this->user('halina', ['display_name' => 'Halina z Kaszub']);

        $cudzy = $this->wpis($halina, 'Pomidorowa od Haliny', ['published_at' => now()->subHour()]);
        $moj = $this->wpis($basia, 'Moj pierwszy rosol dla obserwujacych', [
            'visibility' => Post::VISIBILITY_FOLLOWERS,
            'published_at' => now(),
        ]);

        $odpowiedz = $this->actingAs($basia)->get(route('home'))->assertOk();

        // Sedno błędu: przed poprawką tego wpisu tu nie było.
        $odpowiedz->assertSee('Moj pierwszy rosol dla obserwujacych');
        // KONTROLA DODATNIA: odkrywanie i propozycje osób zostają.
        $odpowiedz->assertSee('Pomidorowa od Haliny');
        $odpowiedz->assertSee('Co dobrego u innych?');
        $odpowiedz->assertSee('Twoje wpisy i najnowsze z innych kuchni');
        $odpowiedz->assertDontSee('Twoja strona główna jest jeszcze pusta.');
        $this->assertStringContainsString(
            route('profile.show', 'halina'),
            (string) $odpowiedz->getContent(),
            'Zniknęły propozycje osób albo wpis innej osoby.',
        );

        // Chronologicznie: własny nowszy nad cudzym starszym.
        $this->assertSame(
            [(string) $moj->getKey(), (string) $cudzy->getKey()],
            $this->idy(app(DiscoverFeed::class)->paginate($basia, zWlasnymi: true)),
        );
    }

    public function test_wlasny_wpis_publiczny_nie_pojawia_sie_dwa_razy_w_odkrywaniu(): void
    {
        $basia = $this->user('basia');
        $moj = $this->wpis($basia, 'Moj publiczny bigos');

        $idy = $this->idy(app(DiscoverFeed::class)->paginate($basia, zWlasnymi: true));

        $this->assertSame([(string) $moj->getKey()], $idy);
    }

    public function test_feed_tagow_dokleja_wlasne_bez_tagu_i_nie_dubluje_wlasnego_z_tagiem(): void
    {
        $zupy = Tag::create(['slug' => 'zupy', 'name' => 'Zupy', 'normalized_name' => 'zupy']);
        $basia = $this->user('basia');
        $ola = $this->user('ola');
        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $cudzyZTagiem = $this->wpis($ola, 'Zupa Oli', ['published_at' => now()->subHours(2)]);
        $cudzyZTagiem->tags()->attach($zupy->getKey(), ['position' => 0]);

        $mojZTagiem = $this->wpis($basia, 'Moja zupa publiczna', ['published_at' => now()->subHour()]);
        $mojZTagiem->tags()->attach($zupy->getKey(), ['position' => 0]);

        $mojBezTagu = $this->wpis($basia, 'Moje pierogi dla obserwujacych', [
            'visibility' => Post::VISIBILITY_FOLLOWERS,
            'published_at' => now(),
        ]);

        $this->assertSame(
            [(string) $mojBezTagu->getKey(), (string) $mojZTagiem->getKey(), (string) $cudzyZTagiem->getKey()],
            $this->idy(app(TagFeed::class)->paginate($basia, zWlasnymi: true)),
        );

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Moje pierogi dla obserwujacych')
            ->assertSee('Najnowsze z Twoich tagów i Twoje wpisy')
            ->assertSee('To wpisy z tagów, które obserwujesz, i Twoje własne.');
    }

    public function test_w_feedzie_zastepczym_tylko_opublikowane_i_bez_prywatnych(): void
    {
        $basia = $this->user('basia');
        $widoczny = $this->wpis($basia, 'Moj widoczny');
        $this->wpis($basia, 'Moj prywatny', ['visibility' => Post::VISIBILITY_PRIVATE]);
        $this->wpis($basia, 'Moj ukryty', ['status' => Post::STATUS_HIDDEN, 'visibility' => Post::VISIBILITY_FOLLOWERS]);
        $this->wpis($basia, 'Moj szkic', ['status' => Post::STATUS_DRAFT, 'published_at' => null, 'visibility' => Post::VISIBILITY_FOLLOWERS]);

        $this->assertSame(
            [(string) $widoczny->getKey()],
            $this->idy(app(DiscoverFeed::class)->paginate($basia, zWlasnymi: true)),
        );
    }

    public function test_inna_osoba_nie_widzi_cudzego_wpisu_dla_obserwujacych(): void
    {
        $basia = $this->user('basia');
        $ola = $this->user('ola');
        $publiczny = $this->wpis($basia, 'Publiczny od Basi');
        $this->wpis($basia, 'Tylko dla obserwujacych Basi', ['visibility' => Post::VISIBILITY_FOLLOWERS]);

        $this->actingAs($ola)->get(route('home'))
            ->assertOk()
            // KONTROLA DODATNIA: odkrywanie Oli działa i pokazuje Basię.
            ->assertSee('Publiczny od Basi')
            ->assertDontSee('Tylko dla obserwujacych Basi')
            ->assertSee('Twoja strona główna jest jeszcze pusta.');

        $this->assertSame(
            [(string) $publiczny->getKey()],
            $this->idy(app(DiscoverFeed::class)->paginate($ola, zWlasnymi: true)),
        );
    }

    public function test_strona_swiezo_z_kuking_nie_miesza_wpisow_dla_obserwujacych(): void
    {
        $basia = $this->user('basia');
        $this->wpis($basia, 'Publiczny od Basi');
        $this->wpis($basia, 'Tylko dla obserwujacych Basi', ['visibility' => Post::VISIBILITY_FOLLOWERS]);

        // `/discover` to wspólne „Świeżo z Kuking" — bez własnych domieszek.
        $this->actingAs($basia)->get(route('discover'))
            ->assertOk()
            ->assertSee('Publiczny od Basi')
            ->assertDontSee('Tylko dla obserwujacych Basi');
    }
}

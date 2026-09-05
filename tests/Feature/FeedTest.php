<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\FollowingFeed;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_feed_pokazuje_wpisy_obserwowanych_chronologicznie(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');
        $basia->following()->attach($marek->getKey(), ['created_at' => now()]);

        $starszy = Post::factory()->create(['author_id' => $marek->getKey(), 'published_at' => now()->subDays(2)]);
        $nowszy = Post::factory()->create(['author_id' => $marek->getKey(), 'published_at' => now()]);

        $feed = app(FollowingFeed::class)->paginate($basia->fresh());

        $this->assertSame($nowszy->getKey(), $feed->items()[0]->getKey());
        $this->assertSame($starszy->getKey(), $feed->items()[1]->getKey());
    }

    public function test_feed_zawiera_wlasne_wpisy(): void
    {
        $basia = $this->user('basia');
        $wlasny = Post::factory()->create(['author_id' => $basia->getKey()]);

        // Bez tego po pierwszej publikacji użytkownik widzi pustkę
        // i myśli, że nic się nie zapisało.
        $feed = app(FollowingFeed::class)->paginate($basia);

        $this->assertSame($wlasny->getKey(), $feed->items()[0]->getKey());
    }

    public function test_pusty_feed_pokazuje_swiezo_z_kuking_i_propozycje_ludzi(): void
    {
        $nowy = $this->user('nowy');
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey()]);

        $this->actingAs($nowy)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Świeżo z Kuking')
            ->assertSee('Osoby, które tu gotują');
    }

    public function test_wpisy_zablokowanej_osoby_nie_pojawiaja_sie_w_odkrywaniu(): void
    {
        $basia = $this->user('basia');
        $spam = $this->user('spam');
        Post::factory()->create(['author_id' => $spam->getKey(), 'body' => 'Zarabiaj z domu 5000 zl']);

        app(BlockUser::class)->handle($basia, $spam);

        $this->actingAs($basia->fresh())
            ->get(route('discover'))
            ->assertOk()
            ->assertDontSee('Zarabiaj z domu');
    }

    public function test_strona_glowna_dla_gosci_pokazuje_publiczne_wpisy(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->create(['author_id' => $ktos->getKey(), 'body' => 'Rosol na niedziele']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Pokaż, co dziś ugotowałeś')
            ->assertSee('Rosol na niedziele');
    }

    public function test_wpisy_prywatne_nie_wychodza_w_odkrywaniu(): void
    {
        $ktos = $this->user('ktos');
        Post::factory()->private()->create(['author_id' => $ktos->getKey(), 'body' => 'Tylko dla mnie']);

        $this->get(route('discover'))->assertOk()->assertDontSee('Tylko dla mnie');
    }
}

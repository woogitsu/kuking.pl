<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Niedostępna treść nie jest pustym zeszytem (#567). */
final class ZeszytNiedostepneZapisyTest extends TestCase
{
    use RefreshDatabase;

    public function test_prywatny_przepis_nie_udaje_pustego_zeszytu_i_wraca_po_upublicznieniu(): void
    {
        [$owner, $author, $book] = $this->scene();
        $recipe = $this->recipe($book, $author);
        $this->assertStringContainsString($recipe->title, $this->page($owner, $book));
        $recipe->update(['visibility' => 'private']);
        $text = $this->page($owner, $book);
        $this->assertHidden($text, 1, [$recipe->title]);
        $this->assertDatabaseHas('collection_items', ['collection_id' => $book->id, 'recipe_id' => $recipe->id]);
        $recipe->update(['visibility' => 'public']);
        $text = $this->page($owner, $book);
        $this->assertStringContainsString($recipe->title, $text);
        $this->assertStringNotContainsString('1 zapis nie jest dla Ciebie dostępny', $text);
    }

    public function test_blokada_w_obu_kierunkach_ukrywa_zapis_a_cofniecie_go_przywraca(): void
    {
        [$owner, $author, $book] = $this->scene();
        $recipe = $this->recipe($book, $author);
        foreach ([[$owner, $author], [$author, $owner]] as [$blocker, $target]) {
            app(BlockUser::class)->handle($blocker, $target);
            $this->assertHidden($this->page($owner, $book), 1, [$recipe->title]);
            $this->assertSame(1, $book->recipes()->count());
            app(UnblockUser::class)->handle($blocker, $target);
            $text = $this->page($owner, $book);
            $this->assertStringContainsString($recipe->title, $text);
            $this->assertStringNotContainsString('1 zapis nie jest dla Ciebie dostępny', $text);
        }
    }

    public function test_banned_i_pending_delete_ukrywaja_zapis_a_przywrocenie_konta_go_odslania(): void
    {
        [$owner, $author, $book] = $this->scene();
        $recipe = $this->recipe($book, $author);
        foreach ([User::STATUS_BANNED, User::STATUS_PENDING_DELETE] as $status) {
            $author->forceFill(['status' => $status])->save();
            $this->assertHidden($this->page($owner, $book), 1, [$recipe->title]);
            $this->assertSame(1, $book->recipes()->count());
            $author->forceFill(['status' => User::STATUS_ACTIVE])->save();
            $text = $this->page($owner, $book);
            $this->assertStringContainsString($recipe->title, $text);
            $this->assertStringNotContainsString('1 zapis nie jest dla Ciebie dostępny', $text);
        }
    }

    public function test_mieszane_zapisy_maja_jeden_prawdziwy_licznik_i_odmiane(): void
    {
        [$owner, $author, $book] = $this->scene();
        $visibleRecipe = $this->recipe($book, $author);
        $visiblePost = $this->postItem($book, $author);
        $hiddenRecipe = $this->recipe($book, $author, 'private');
        $hiddenPost = $this->postItem($book, $author, 'private');
        $secrets = [$hiddenRecipe->title, $hiddenPost->body];
        $text = $this->page($owner, $book);
        $this->assertHidden($text, 2, $secrets);
        $this->assertStringContainsString($visibleRecipe->title, $text);
        $this->assertStringContainsString($visiblePost->body, $text);
        for ($i = 0; $i < 3; $i++) {
            $secrets[] = $this->recipe($book, $author, 'private')->title;
        }
        $this->assertHidden($this->page($owner, $book), 5, $secrets);
        $this->assertSame(5, $book->recipes()->count());
        $this->assertSame(2, $book->posts()->count());
    }

    public function test_obcy_widz_publicznego_zeszytu_nie_dostaje_tekstu_o_swoim_zeszycie(): void
    {
        [$owner, $author, $book] = $this->scene();
        $book->update(['visibility' => 'public']);
        $visitor = $this->user();
        $private = $this->recipe($book, $owner, 'private');
        $public = $this->recipe($book, $author);
        $this->assertStringContainsString($private->title, $this->page($owner, $book));
        $text = $this->page($visitor, $book);
        $this->assertHidden($text, 1, [$private->title]);
        $this->assertStringContainsString($public->title, $text);
        $this->assertStringNotContainsString('Twojego zeszytu', $text);
        $this->assertStringNotContainsString('już dla Ciebie', $text);
    }

    public function test_licznik_nie_uznaje_dalszych_stron_za_niedostepne_zapisy(): void
    {
        [$owner, $author, $book] = $this->scene();
        $postSize = (int) config('kuking.collections.saved_posts_page_size');
        for ($i = 0; $i < 13; $i++) {
            $this->recipe($book, $author);
        }
        for ($i = 0; $i < $postSize + 1; $i++) {
            $this->postItem($book, $author);
        }
        $secretRecipe = $this->recipe($book, $author, 'private');
        $secretPost = $this->postItem($book, $author, 'private');
        foreach ([[1, 1, 12, $postSize], [2, 1, 1, $postSize], [1, 2, 12, 1], [2, 2, 1, 1]] as [$rp, $pp, $rc, $pc]) {
            $response = $this->actingAs($owner)->get(route('collections.show', ['collection' => $book, 'page' => $rp, 'wpisy' => $pp]))->assertOk();
            $this->assertHidden($this->section($response->getContent()), 2, [$secretRecipe->title, $secretPost->body]);
            $this->assertCount($rc, $response->viewData('recipes'));
            $this->assertCount($pc, $response->viewData('posts'));
            $this->assertSame(13, $response->viewData('recipes')->total());
            $this->assertSame($postSize + 1, $response->viewData('posts')->total());
        }
    }

    public function test_pusty_zeszyt_nadal_ma_pusty_stan(): void
    {
        [$owner, , $book] = $this->scene();
        $text = $this->page($owner, $book);
        $this->assertStringContainsString('W tym zeszycie nic jeszcze nie ma', $text);
        $this->assertStringNotContainsString('dla Ciebie dostępn', $text);
    }

    public function test_usuniete_miekko_tresci_zostawiaja_zapisy_i_wraca_ich_widocznosc_po_przywroceniu(): void
    {
        [$owner, $author, $book] = $this->scene();
        $recipe = $this->recipe($book, $author);
        $post = $this->postItem($book, $author);
        $recipe->delete();
        $post->delete();
        $this->assertDatabaseHas('collection_items', ['collection_id' => $book->id, 'recipe_id' => $recipe->id]);
        $this->assertDatabaseHas('collection_items', ['collection_id' => $book->id, 'post_id' => $post->id]);
        $this->assertHidden($this->page($owner, $book), 2, [$recipe->title, $post->body]);
        $recipe->restore();
        $post->restore();
        $text = $this->page($owner, $book);
        $this->assertStringContainsString($recipe->title, $text);
        $this->assertStringContainsString($post->body, $text);
        $this->assertStringNotContainsString('dla Ciebie dostępn', $text);
    }

    private function scene(): array
    {
        $owner = $this->user();
        $author = $this->user();
        $book = Collection::create(['owner_id' => $owner->id, 'name' => 'Zeszyt kontrolny', 'visibility' => 'private']);

        return [$owner, $author, $book];
    }

    private function recipe(Collection $book, User $author, string $visibility = 'public'): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'visibility' => $visibility, 'title' => 'Przepis kontrolny '.Str::uuid()]);
        $book->recipes()->attach($recipe->id);

        return $recipe;
    }

    private function postItem(Collection $book, User $author, string $visibility = 'public'): Post
    {
        $post = Post::factory()->create(['author_id' => $author->id, 'visibility' => $visibility, 'body' => 'Wpis kontrolny '.Str::uuid()]);
        $book->posts()->attach($post->id);

        return $post;
    }

    private function page(User $viewer, Collection $book): string
    {
        return $this->section($this->actingAs($viewer)->get(route('collections.show', $book))->assertOk()->getContent());
    }

    private function assertHidden(string $text, int $count, array $secrets): void
    {
        $expected = match ($count) {
            1 => '1 zapis nie jest dla Ciebie dostępny',
            2 => '2 zapisy nie są dla Ciebie dostępne',
            5 => '5 zapisów nie jest dla Ciebie dostępnych',
        };
        $this->assertStringContainsString($expected, $text);
        $this->assertStringNotContainsString('W tym zeszycie nic jeszcze nie ma', $text);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    private function section(string $html): string
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $nodes = (new \DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " marka-zeszyt ")]');
        $this->assertSame(1, $nodes->length);

        return trim(preg_replace('/\s+/u', ' ', $nodes->item(0)->textContent));
    }
}

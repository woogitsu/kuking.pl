<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Posts\ZapisWpisuRequest;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #2633: formularze dodawania i edycji wpisu pokazują limit opisu
 * (jak komentarze, #762) PRZED wysłaniem. Limit pochodzi z jednej stałej.
 */
class LimitZnakowOpisuWpisuWidocznyPrzedWyslaniemTest extends TestCase
{
    use RefreshDatabase;

    private function sprawdz(string $html): void
    {
        $limit = ZapisWpisuRequest::LIMIT_ZNAKOW_TRESCI;
        $this->assertSame(4000, $limit);
        $this->assertStringContainsString("Najwyżej {$limit} znaków.", $html);
        $this->assertSame(1, preg_match('/<textarea[^>]*name="body"[^>]*>/', $html, $m));
        $this->assertStringContainsString('data-licznik="'.$limit.'"', $m[0]);
        $this->assertStringContainsString('data-licznik-cel="f-body-licznik"', $m[0]);
        $this->assertMatchesRegularExpression('/aria-describedby="[^"]*\bf-body-licznik\b[^"]*"/', $m[0]);
        $this->assertStringContainsString('id="f-body-licznik"', $html);
        $this->assertStringNotContainsString('maxlength', $m[0]);
    }

    public function test_formularz_dodawania_wpisu_pokazuje_limit(): void
    {
        $r = $this->actingAs($this->user('limitdodaj2633'))->get(route('posts.create'));

        $r->assertOk();
        $this->sprawdz($r->getContent());
    }

    public function test_formularz_edycji_wpisu_pokazuje_limit(): void
    {
        $autor = $this->user('limitedytuj2633');
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Rosół.']);

        $r = $this->actingAs($autor)->get(route('posts.edit', $post));

        $r->assertOk();
        $this->sprawdz($r->getContent());
    }
}

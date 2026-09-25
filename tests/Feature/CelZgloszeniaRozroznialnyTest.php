<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kryteria odbioru #794, których nie obejmował `CelZgloszeniaWFormularzuTest`:
 *  2. dwa różne obiekty tej samej kategorii dają rozróżnialny kontekst,
 *  3. HTML z treści jest escapowany, a bardzo długi wyraz nie daje pustego „…”,
 *  4. po błędzie walidacji cel nadal widać, a powód i szczegóły zostają.
 */
class CelZgloszeniaRozroznialnyTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwa_komentarze_tej_samej_kategorii_sa_rozroznialne(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $marek = $this->user('marek', ['display_name' => 'Marek']);
        $widz = $this->user('widz');

        $rosol = Recipe::factory()->create(['title' => 'Rosół babci', 'visibility' => 'public']);
        $sernik = Recipe::factory()->create(['title' => 'Sernik z rodzynkami', 'visibility' => 'public']);

        $pierwszy = $this->komentarz($rosol, $basia, 'Dodałam lubczyk.');
        $drugi = $this->komentarz($sernik, $marek, 'Piekłem godzinę dłużej.');

        $html1 = $this->formularz($widz, 'comment', $pierwszy->getKey());
        $html2 = $this->formularz($widz, 'comment', $drugi->getKey());

        $this->assertStringContainsString('komentarz Basi pod przepisem «Rosół babci»', $html1);
        $this->assertStringContainsString('„Dodałam lubczyk.”', $html1);
        $this->assertStringNotContainsString('Piekłem godzinę dłużej.', $html1);

        $this->assertStringContainsString('komentarz Marka pod przepisem «Sernik z rodzynkami»', $html2);
        $this->assertStringContainsString('„Piekłem godzinę dłużej.”', $html2);
        $this->assertStringNotContainsString('Dodałam lubczyk.', $html2);
    }

    public function test_dwa_wpisy_tej_samej_osoby_rozroznia_fragment_tresci(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');

        $pierwszy = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Pierogi ruskie na obiad.']);
        $drugi = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Zupa ogórkowa po babci.']);

        $html1 = $this->formularz($widz, 'post', $pierwszy->getKey());
        $html2 = $this->formularz($widz, 'post', $drugi->getKey());

        // Ta sama nazwa „wpis Basi" — rozróżnia je dopiero fragment.
        $this->assertStringContainsString('wpis Basi', $html1);
        $this->assertStringContainsString('wpis Basi', $html2);
        $this->assertStringContainsString('„Pierogi ruskie na obiad.”', $html1);
        $this->assertStringContainsString('„Zupa ogórkowa po babci.”', $html2);
        $this->assertStringNotContainsString('ogórkowa', $html1);
        $this->assertStringNotContainsString('ruskie', $html2);
    }

    public function test_html_z_tresci_jest_escapowany(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');
        $rosol = Recipe::factory()->create(['title' => 'Rosół <b>babci</b>', 'visibility' => 'public']);
        $komentarz = $this->komentarz($rosol, $basia, 'Super <script>alert(1)</script> <img src=x onerror=alert(2)>');

        $html = $this->formularz($widz, 'comment', $komentarz->getKey());

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>babci</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Rosół &lt;b&gt;babci&lt;/b&gt;', $html);
    }

    public function test_bardzo_dlugi_wyraz_nie_daje_pustego_cudzyslowu(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');
        $wpis = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'https://przyklad.example/'.str_repeat('x', 400),
        ]);

        $html = $this->formularz($widz, 'post', $wpis->getKey());

        $this->assertStringContainsString('wpis Basi', $html);
        $this->assertStringNotContainsString('„…”', $html);
        $this->assertStringNotContainsString(str_repeat('x', 201), $html);
    }

    public function test_po_bledzie_walidacji_cel_widoczny_a_szczegoly_zachowane(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $widz = $this->user('widz');
        $rosol = Recipe::factory()->create(['title' => 'Rosół babci', 'visibility' => 'public']);
        $komentarz = $this->komentarz($rosol, $basia, 'Dodałam lubczyk.');
        $formularz = route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]);

        // Brak powodu — szczegóły mają zostać.
        $html = (string) $this->actingAs($widz)->from($formularz)->followingRedirects()
            ->post(route('reports.store', ['type' => 'comment', 'id' => $komentarz->getKey()]), [
                'details' => 'Ten komentarz podaje zły czas gotowania.',
            ])->assertOk()->getContent();

        $this->assertStringContainsString('Wybierz, co jest nie tak z tą treścią.', $html);
        $this->assertStringContainsString('komentarz Basi pod przepisem «Rosół babci»', $html);
        $this->assertStringContainsString('„Dodałam lubczyk.”', $html);
        $this->assertStringContainsString('Ten komentarz podaje zły czas gotowania.', $html);

        // Za długie szczegóły — wybrany powód ma zostać zaznaczony.
        $html = (string) $this->actingAs($widz)->from($formularz)->followingRedirects()
            ->post(route('reports.store', ['type' => 'comment', 'id' => $komentarz->getKey()]), [
                'reason' => 'spam',
                'details' => str_repeat('a', 2001),
            ])->assertOk()->getContent();

        $this->assertStringContainsString('komentarz Basi pod przepisem «Rosół babci»', $html);
        $this->assertMatchesRegularExpression('/value="spam"\s+checked/', $html);
        $this->assertDatabaseCount('reports', 0);
    }

    private function komentarz(Recipe $przepis, User $autor, string $tresc): Comment
    {
        return Comment::factory()->create([
            'post_id' => null,
            'recipe_id' => $przepis->getKey(),
            'author_id' => $autor->getKey(),
            'body' => $tresc,
        ]);
    }

    private function formularz(User $kto, string $typ, string|int $id): string
    {
        return (string) $this->actingAs($kto)
            ->get(route('reports.create', ['type' => $typ, 'id' => $id]))
            ->assertOk()
            ->getContent();
    }
}

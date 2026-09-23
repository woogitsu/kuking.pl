<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\KonfliktEdycjiWpisu;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #981: dwie karty edycji tego samego wpisu. Druga nie może po cichu
 * nadpisać tekstu, widoczności i tagów zapisanych w pierwszej — zapis jest
 * odrzucany, a tekst z drugiej karty wraca do formularza (AGENTS.md §5:
 * poprawne dane nigdy nie znikają).
 */
class EdycjaWpisuDwieKartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_druga_karta_z_ta_sama_wersja_startowa_jest_odrzucona_i_nie_gubi_tekstu(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Wersja pierwsza.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $wersjaStartowa = app(EditPost::class)->wersja($post);

        // Karta A zapisuje.
        $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => 'Poprawka z karty A.',
            'visibility' => 'private',
            'tag_names' => ['sernik'],
            'wersja_edycji' => $wersjaStartowa,
        ])->assertRedirect(route('posts.show', $post));

        // Karta B, otwarta wcześniej, zapisuje na tej samej wersji startowej.
        $response = $this->actingAs($basia)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), [
                'body' => 'Poprawka z karty B.',
                'visibility' => 'public',
                'tag_names' => ['zupy'],
                'wersja_edycji' => $wersjaStartowa,
            ]);

        $response->assertRedirect(route('posts.edit', $post));
        $response->assertSessionHasErrors('body');
        $response->assertSessionHasInput('body', 'Poprawka z karty B.');
        $response->assertSessionHas('konflikt_edycji', true);

        $post->refresh();
        $this->assertSame('Poprawka z karty A.', $post->body);
        $this->assertSame('private', $post->visibility);
        $this->assertSame(['sernik'], $post->tags->pluck('name')->all());

        // Ekran po odrzuceniu pokazuje obie wersje i niesie już bieżącą
        // wersję, więc świadomy drugi zapis przechodzi.
        $ekran = $this->actingAs($basia)->get(route('posts.edit', $post));
        $ekran->assertOk();
        $ekran->assertSee('Tak ten wpis jest zapisany teraz');
        $ekran->assertSee('Poprawka z karty A.');
        $ekran->assertSee('Poprawka z karty B.');
        $ekran->assertSee('value="'.app(EditPost::class)->wersja($post->fresh()).'"', false);
    }

    public function test_edycja_z_aktualna_wersja_przechodzi(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Wersja pierwsza.',
        ]);

        $ekran = $this->actingAs($basia)->get(route('posts.edit', $post));
        $ekran->assertSee('name="wersja_edycji" value="'.app(EditPost::class)->wersja($post).'"', false);

        $this->actingAs($basia)->put(route('posts.update', $post), [
            'body' => 'Wersja druga.',
            'visibility' => 'public',
            'wersja_edycji' => app(EditPost::class)->wersja($post),
        ])->assertRedirect(route('posts.show', $post))->assertSessionHasNoErrors();

        $this->assertSame('Wersja druga.', $post->fresh()->body);
    }

    public function test_akcja_domenowa_odrzuca_nieaktualna_wersje(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Stara treść.']);
        $akcja = app(EditPost::class);
        $wersja = $akcja->wersja($post);

        $akcja->handle($basia, $post, 'Nowa treść.', 'public', wersjaFormularza: $wersja);

        $this->expectException(KonfliktEdycjiWpisu::class);
        try {
            $akcja->handle($basia, $post->fresh(), 'Inna treść.', 'public', wersjaFormularza: $wersja);
        } finally {
            $this->assertSame('Nowa treść.', $post->fresh()->body);
        }
    }
}

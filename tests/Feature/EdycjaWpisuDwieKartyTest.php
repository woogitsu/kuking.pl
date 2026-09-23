<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\KonfliktEdycjiWpisu;
use App\Domain\Tags\Actions\MergeTags;
use App\Models\Post;
use App\Models\Tag;
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
        // Konflikt pod własnym kluczem: tekst jest poprawny, pole `body`
        // nie może dostać `aria-invalid`.
        $response->assertSessionHasErrors('wersja');
        $response->assertSessionDoesntHaveErrors('body');
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

    /**
     * Konflikt nie jest błędem pola tekstu (przegląd #981): podsumowanie
     * błędów prowadzi do sekcji z zapisaną wersją, a `body` nie dostaje
     * `aria-invalid`, bo tekst osoby jest poprawny.
     */
    public function test_konflikt_prowadzi_do_zapisanej_wersji_i_nie_oznacza_tekstu_jako_blednego(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Wersja pierwsza.']);
        $wersjaStartowa = app(EditPost::class)->wersja($post);
        app(EditPost::class)->handle($basia, $post, 'Poprawka z karty A.', 'public');

        $ekran = $this->followingRedirects()->actingAs($basia)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), [
                'body' => 'Poprawka z karty B.',
                'visibility' => 'public',
                'wersja_edycji' => $wersjaStartowa,
            ]);
        $this->followRedirects = false;

        $ekran->assertOk();
        $ekran->assertSee('Sprawdź formularz');
        $ekran->assertSee('href="#wersja-zapisana"', false);
        $ekran->assertSee('id="wersja-zapisana"', false);
        $ekran->assertDontSee('aria-invalid="true"', false);
        $ekran->assertSee('Poprawka z karty B.');
        $this->assertSame('Poprawka z karty A.', $post->fresh()->body);
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

    /**
     * Regresja z przeglądu #981: podwójne kliknięcie „Zapisz zmiany" wysyła
     * dwa identyczne żądania z tą samą wersją startową. Przeglądarka pokazuje
     * odpowiedź na drugie — nie może ono udawać konfliktu z „inną kartą".
     */
    public function test_podwojne_klikniecie_zapisz_konczy_sie_sukcesem_obu_zadan(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Wersja pierwsza.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
        $formularz = [
            'body' => 'Poprawka po podwójnym kliknięciu.',
            'visibility' => 'followers',
            'tag_names' => ['sernik'],
            'wersja_edycji' => app(EditPost::class)->wersja($post),
        ];

        $this->actingAs($basia)->put(route('posts.update', $post), $formularz)
            ->assertRedirect(route('posts.show', $post))->assertSessionHasNoErrors();
        $zapisPierwszy = $post->fresh()->updated_at;
        $this->travel(5)->seconds();

        $this->actingAs($basia)
            ->from(route('posts.edit', $post))
            ->put(route('posts.update', $post), $formularz)
            ->assertRedirect(route('posts.show', $post))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('konflikt_edycji')
            ->assertSessionHas('status', 'Wpis zapisany.');

        $post->refresh();
        $this->assertSame('Poprawka po podwójnym kliknięciu.', $post->body);
        $this->assertSame('followers', $post->visibility);
        $this->assertSame(['sernik'], $post->tags->pluck('name')->all());
        // Jeden zapis: drugie żądanie niczego nie dotknęło.
        $this->assertTrue($zapisPierwszy->equalTo($post->updated_at));
    }

    public function test_akcja_domenowa_przyjmuje_nieaktualna_wersje_gdy_tresc_jest_juz_zapisana(): void
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Stara treść.']);
        $akcja = app(EditPost::class);
        $wersja = $akcja->wersja($post);

        $akcja->handle($basia, $post, 'Nowa treść.', 'public', ['zupy'], wersjaFormularza: $wersja);
        $akcja->handle($basia, $post->fresh(), '  Nowa treść.  ', 'public', ['zupy'], wersjaFormularza: $wersja);

        $this->assertSame('Nowa treść.', $post->fresh()->body);
        $this->assertSame(['zupy'], $post->fresh()->tags->pluck('name')->all());
    }

    /**
     * Znana granica z komentarza `EditPost`: scalenie tagów zmienia wersję
     * wpisu, ale formularz otwarty przed scaleniem i zapisany BEZ zmian
     * przechodzi — nazwa źródła jest aliasem celu, więc stan docelowy
     * równa się zapisanemu.
     */
    public function test_zapis_bez_zmian_po_scaleniu_tagow_nie_udaje_konfliktu(): void
    {
        $basia = $this->user('basia');
        foreach (['szarlotka', 'jablecznik'] as $nazwa) {
            Tag::create([
                'name' => $nazwa,
                'normalized_name' => Tag::znormalizujNazwe($nazwa),
                'slug' => Tag::slugDlaNazwy($nazwa),
                'is_seeded' => true,
            ]);
        }
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Ciasto z jabłkami.']);
        $akcja = app(EditPost::class);
        $akcja->handle($basia, $post, 'Ciasto z jabłkami.', 'public', ['jablecznik']);
        $wersjaPrzedScaleniem = $akcja->wersja($post->fresh());

        app(MergeTags::class)->handle(Tag::where('name', 'jablecznik')->firstOrFail(), Tag::where('name', 'szarlotka')->firstOrFail());
        $this->assertNotSame($wersjaPrzedScaleniem, $akcja->wersja($post->fresh()));

        $akcja->handle($basia, $post->fresh(), 'Ciasto z jabłkami.', 'public', ['jablecznik'], wersjaFormularza: $wersjaPrzedScaleniem);
        $this->assertSame(['szarlotka'], $post->fresh()->tags->pluck('name')->all());

        $this->expectException(KonfliktEdycjiWpisu::class);
        $akcja->handle($basia, $post->fresh(), 'Ciasto z jabłkami i cynamonem.', 'public', ['jablecznik'], wersjaFormularza: $wersjaPrzedScaleniem);
    }
}

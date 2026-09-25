<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\EditPost;
use App\Domain\Posts\Actions\PublishPost;
use App\Domain\Tags\LiczbyTagowWCache;
use App\Domain\Tags\TagCollage;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cache stron tagów dla gościa (audyt B4 W2) czyści się po zmianie wpisu —
 * decyzja właściciela: nowe danie ma być na stronie tagu od razu, a nie po
 * `CACHE_SEKUND` (`UniewaznijCacheTagow`).
 *
 * KONTROLA DODATNIA w każdym teście: wpis dopisany do bazy z pominięciem
 * modelu (sam `attach` tagu, bez zapisu wpisu) NIE zmienia wyniku —
 * dowód, że cache naprawdę działa, więc zmiana widoczna po akcji domeny
 * pochodzi z unieważnienia, a nie z braku cache.
 */
class CacheTagowCzysciSiePoZmianieWpisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_gosc_widzi_nowe_danie_na_stronie_tagu_od_razu_po_publikacji(): void
    {
        $autor = $this->user('kucharka');
        $pierwszy = $this->opublikuj($autor, 'zupy');
        $tag = Tag::query()->where('slug', 'zupy')->firstOrFail();

        // Gość ogląda stronę tagu — wynik trafia do cache.
        $this->get(route('tags.show', $tag))->assertOk();
        $this->assertSame([$pierwszy->id], $this->wpisyKolazu($tag));
        $this->assertSame(1, $this->liczba($tag));

        // Kontrola dodatnia: zmiana z pominięciem modelu zostaje niewidoczna.
        $obok = $this->wpisZPominieciemModelu($tag);
        $this->assertSame([$pierwszy->id], $this->wpisyKolazu($tag), 'Kolaż gościa nie jest z cache — test nic nie mierzy.');
        $this->assertSame(1, $this->liczba($tag), 'Liczby tagu nie są z cache — test nic nie mierzy.');

        // Inna osoba: kolaż bierze najwyżej jedno zdjęcie od osoby na tag.
        $nowy = $this->opublikuj($this->user('sasiadka'), 'zupy');

        $this->assertContains($nowy->id, $this->wpisyKolazu($tag), 'Nowe danie nie pojawiło się w kolażu gościa od razu po publikacji.');
        $this->assertSame(3, $this->liczba($tag));
        $this->assertEqualsCanonicalizing([$pierwszy->id, $obok->id, $nowy->id], $this->wpisyKolazu($tag));
    }

    public function test_edycja_tagow_przenosi_wpis_miedzy_stronami_tagow_od_razu(): void
    {
        $autor = $this->user('kucharz');
        $wpis = $this->opublikuj($autor, 'obiady');
        $stary = Tag::query()->where('slug', 'obiady')->firstOrFail();
        $this->opublikuj($this->user('ktos'), 'kolacje');
        $nowyTag = Tag::query()->where('slug', 'kolacje')->firstOrFail();

        $this->assertSame(1, $this->liczba($stary));
        $this->assertSame(1, $this->liczba($nowyTag));
        $this->wpisZPominieciemModelu($stary);
        $this->assertSame(1, $this->liczba($stary), 'Liczby tagu nie są z cache — test nic nie mierzy.');

        app(EditPost::class)->handle($autor, $wpis, $wpis->body, Post::VISIBILITY_PUBLIC, ['kolacje']);

        // Stary tag traci wpis (zostaje ten dopisany obok), nowy go zyskuje.
        $this->assertSame(1, $this->liczba($stary));
        $this->assertNotContains($wpis->id, $this->wpisyKolazu($stary));
        $this->assertSame(2, $this->liczba($nowyTag));
        $this->assertContains($wpis->id, $this->wpisyKolazu($nowyTag));
    }

    public function test_ukrycie_i_usuniecie_zdejmuja_wpis_ze_strony_tagu_od_razu(): void
    {
        $autor = $this->user('gospodyni');
        $ukryty = $this->opublikuj($autor, 'ciasta');
        $usuniety = $this->opublikuj($this->user('drugi'), 'ciasta');
        $tag = Tag::query()->where('slug', 'ciasta')->firstOrFail();

        $this->assertSame(2, $this->liczba($tag));
        $this->wpisZPominieciemModelu($tag);
        $this->assertSame(2, $this->liczba($tag), 'Liczby tagu nie są z cache — test nic nie mierzy.');

        $ukryty->forceFill(['status' => Post::STATUS_HIDDEN])->save();
        $this->assertSame(2, $this->liczba($tag)); // 3 w bazie − ukryty
        $this->assertNotContains($ukryty->id, $this->wpisyKolazu($tag));

        $usuniety->delete();
        $this->assertSame(1, $this->liczba($tag));
        $this->assertNotContains($usuniety->id, $this->wpisyKolazu($tag));
    }

    private function opublikuj(User $autor, string $tag): Post
    {
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);

        return app(PublishPost::class)->handle(
            $autor,
            'Dzisiejsze danie',
            mediaIds: [$zdjecie->getKey()],
            visibility: Post::VISIBILITY_PUBLIC,
            tagNames: [$tag],
        );
    }

    /**
     * Publiczny wpis ze zdjęciem przypięty do tagu SAMYM `attach` — zapis
     * wpisu następuje przed przypięciem tagu i poza transakcją, więc hak
     * modelu nie widzi jeszcze tagu i niczego nie czyści.
     */
    private function wpisZPominieciemModelu(Tag $tag): Post
    {
        $wpis = Post::factory()->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
        $wpis->tags()->attach($tag);
        $wpis->media()->attach(Media::factory()->create(['owner_id' => $wpis->author_id]));

        return $wpis;
    }

    /** @return list<string> */
    private function wpisyKolazu(Tag $tag): array
    {
        return (new TagCollage)->forTagsWCache([$tag->id])[$tag->id]
            ->map(fn (Media $m): string => (string) $m->posts->first()->id)
            ->values()->all();
    }

    private function liczba(Tag $tag): int
    {
        return (new LiczbyTagowWCache)->forTags([$tag->id])[$tag->id]['postsCount'];
    }
}

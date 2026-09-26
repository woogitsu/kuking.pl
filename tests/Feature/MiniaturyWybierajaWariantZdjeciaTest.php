<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyPick;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Support\Czas;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Miniatury tablicy i katalogu tagów dają przeglądarce wybór wariantu
 * (issues #1310 i #1326).
 *
 * Przed poprawką obie miniatury miały gołe `<img src=…feed>`: na powitalnej
 * trzy zdjęcia dań, w katalogu tagów do stu kart na stronę — zawsze plik
 * 960 px, także na telefonie o zwykłej gęstości. Teraz `srcset` pochodzi
 * z `Media::srcset()` (tych samych prawdziwych szerokości co w `<x-photo>`),
 * a `sizes` opisuje pole, w którym zdjęcie naprawdę stoi.
 *
 * Każdy test najpierw sprawdza, że obrazek w ogóle jest w HTML-u (kontrola
 * dodatnia) — bez tego brak `srcset` przechodziłby też wtedy, gdy zdjęcia
 * nie ma wcale.
 */
class MiniaturyWybierajaWariantZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_powitalna_daje_srcset_z_wariantow_i_rozmiar_karty(): void
    {
        $media = Media::factory()->create();
        $this->danieNaDzis($media);

        $img = $this->obrazekTablicy(route('landing'));

        $this->assertSame(
            implode(', ', [
                $media->url('thumb').' 320w',
                $media->url('podglad').' 640w',
                $media->url('feed').' 960w',
                $media->url('large').' 1600w',
            ]),
            $img->getAttribute('srcset'),
            'Zdjęcie dania na powitalnej nie oferuje mniejszych wariantów — przeglądarka znów bierze sztywny `feed` 960 px.',
        );
        $this->assertSame('(min-width: 64rem) 33vw, 100vw', $img->getAttribute('sizes'));
        $this->assertSame($media->url('feed'), $img->getAttribute('src'));
    }

    public function test_powitalna_z_samym_podgladem_pokazuje_podglad_a_nie_oryginal(): void
    {
        $media = $this->zdjecieTylkoZPodgladem();
        $this->danieNaDzis($media);

        $img = $this->obrazekTablicy(route('landing'));

        $this->assertSame($media->url('podglad').' 640w', $img->getAttribute('srcset'));
        $this->assertSame($media->url('podglad'), $img->getAttribute('src'));
        $this->assertStringNotContainsString($media->object_key, $img->getAttribute('src').$img->getAttribute('srcset'));
    }

    public function test_tablica_w_odkryj_opisuje_pole_120_px(): void
    {
        $media = Media::factory()->create();
        $this->danieNaDzis($media);

        $img = $this->obrazekTablicy(route('discover'));

        $this->assertSame('120px', $img->getAttribute('sizes'));
        $this->assertStringContainsString($media->url('thumb').' 320w', $img->getAttribute('srcset'));
        $this->assertSame($media->url('thumb'), $img->getAttribute('src'));
    }

    public function test_karta_katalogu_tagow_daje_srcset_i_rozmiar_karty(): void
    {
        $media = Media::factory()->create();
        $tag = $this->tagZeZdjeciem($media);

        $img = $this->obrazekKartyTagu($tag);

        $this->assertSame(
            implode(', ', [
                $media->url('thumb').' 320w',
                $media->url('podglad').' 640w',
                $media->url('feed').' 960w',
                $media->url('large').' 1600w',
            ]),
            $img->getAttribute('srcset'),
            'Karta katalogu tagów znów podaje sam `src=feed` — przy stu kartach to sto plików 960 px.',
        );
        $this->assertSame('(min-width: 48rem) 24rem, 100vw', $img->getAttribute('sizes'));
        $this->assertSame('', $img->getAttribute('alt'), 'Zdjęcie karty tagu jest ozdobnikiem — nazwa tagu stoi obok.');
    }

    public function test_karta_tagu_z_samym_podgladem_pokazuje_podglad_a_nie_oryginal(): void
    {
        $media = $this->zdjecieTylkoZPodgladem();
        $tag = $this->tagZeZdjeciem($media);

        $img = $this->obrazekKartyTagu($tag);

        $this->assertSame($media->url('podglad').' 640w', $img->getAttribute('srcset'));
        $this->assertSame($media->url('podglad'), $img->getAttribute('src'));
        $this->assertStringNotContainsString($media->object_key, $img->getAttribute('src').$img->getAttribute('srcset'));
    }

    /** Gotowe zdjęcie, dla którego istnieje wyłącznie wariant `podglad` (np. przed zadaniem w tle). */
    private function zdjecieTylkoZPodgladem(): Media
    {
        $media = Media::factory()->create();
        $media->update(['metadata' => ['variants' => ['podglad' => $media->wariant('podglad')]]]);

        return $media->fresh();
    }

    /** Wybór redakcyjny: jedno danie ze zdjęciem, bez zależności od automatu `DailyBoard`. */
    private function danieNaDzis(Media $media): void
    {
        $post = Post::factory()->create(['author_id' => $media->owner_id, 'body' => 'Pierogi ruskie od babci']);
        $post->media()->attach($media->getKey(), ['position' => 0]);

        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $post->getKey(),
            'position' => 0,
            'curator_id' => $this->moderator()->getKey(),
        ]);
    }

    private function tagZeZdjeciem(Media $media): Tag
    {
        $tag = Tag::factory()->create();
        $post = Post::factory()->create(['author_id' => $media->owner_id]);
        $post->tags()->attach($tag);
        $post->media()->attach($media->getKey());

        return $tag;
    }

    private function obrazekTablicy(string $adres): DOMElement
    {
        $img = $this->xpath($this->get($adres)->assertOk()->getContent())
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' kuking-board-post-photo ')]//img")
            ->item(0);

        $this->assertInstanceOf(DOMElement::class, $img, "Na {$adres} nie ma zdjęcia dania w tablicy — test nie sprawdzałby niczego.");

        return $img;
    }

    private function obrazekKartyTagu(Tag $tag): DOMElement
    {
        $img = $this->xpath($this->get(route('tags.index'))->assertOk()->getContent())
            ->query('//nav[@aria-label="Wszystkie tagi, alfabetycznie"]/a[@href="'.route('tags.show', $tag).'"]//img')
            ->item(0);

        $this->assertInstanceOf(DOMElement::class, $img, 'Karta tagu nie ma zdjęcia — test nie sprawdzałby niczego.');

        return $img;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }
}

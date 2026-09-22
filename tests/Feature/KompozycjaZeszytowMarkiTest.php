<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KompozycjaZeszytowMarkiTest extends TestCase
{
    use RefreshDatabase;

    private function dokument(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($dom);
    }

    public function test_rzeczywiste_zeszyty_maja_pelne_nazwy_opisy_liczby_i_linki(): void
    {
        $owner = $this->user('zeszytymarki');
        $name = 'Rodzinne przepisy <na niedzielę> '.str_repeat('i uroczystości ', 5).'KONIEC';
        $collection = Collection::create(['owner_id' => $owner->id, 'name' => $name, 'description' => 'Opis <bez HTML> & wspomnienia', 'visibility' => 'private']);
        $empty = Collection::create(['owner_id' => $owner->id, 'name' => 'Pusty publiczny', 'visibility' => 'public']);
        $recipe = Recipe::factory()->for($owner, 'author')->create();
        $collection->recipes()->attach($recipe->id, ['created_at' => now()]);
        $html = $this->actingAs($owner)->get(route('collections.index'))->assertOk()->getContent();
        $xpath = $this->dokument($html);
        $main = '//main//*[contains(concat(" ",normalize-space(@class)," ")," marka-zeszyt ")]';
        $this->assertSame(1, $xpath->query($main)->length);
        $cards = $main.'//div[contains(concat(" ",normalize-space(@class)," ")," marka-zeszyty ")]/article';
        $this->assertSame(2, $xpath->query($cards)->length);
        foreach ([$collection, $empty] as $item) {
            $card = $cards.'[.//a[@href="'.route('collections.show', $item).'"]]';
            $this->assertSame(1, $xpath->query($card.'[contains(concat(" ",@class," ")," blok-ciemny ") and contains(concat(" ",@class," ")," marka-zeszyt-karta ")]')->length);
            $this->assertSame($item->name, trim($xpath->evaluate('string('.$card.'//h2/a)')));
        }
        $this->assertStringContainsString('Opis &lt;bez HTML&gt; &amp; wspomnienia', $html);
        $this->assertStringContainsString('1 przepis', $html);
        $this->assertStringContainsString('0 przepisów', $html);
        $this->assertStringContainsString('Tylko dla Ciebie', $html);
        $this->assertStringContainsString('Widoczny dla wszystkich', $html);
        $recent = $main.'//section[contains(@class,"marka-zeszyt-ostatnie")]';
        $this->assertSame(1, $xpath->query($recent)->length);
        $row = $recent.'//li[contains(@class,"marka-zeszyt-zapis")]';
        $this->assertSame(1, $xpath->query($row)->length);
        $this->assertSame(1, $xpath->query($row.'//a')->length);
        $this->assertSame(route('recipes.show', $recipe->slug), $xpath->evaluate('string('.$row.'//a[contains(@class,"marka-zeszyt-zapis-link")]/@href)'));
        $this->assertSame(0, $xpath->query($row.'//a//img')->length);
        $this->assertSame($recipe->title, trim($xpath->evaluate('string('.$row.'//h3)')));
        $this->assertSame(0, $xpath->query($row.'//h3/a')->length);
        $this->assertSame('Zobacz', trim($xpath->evaluate('string('.$row.'//a)')));
        $this->assertSame('Zobacz: '.$recipe->title, $xpath->evaluate('string('.$row.'//a/@aria-label)'));
    }

    public function test_pusty_zeszyt_nie_obiecuje_trwalego_dostepu_i_nie_tworzy_danych(): void
    {
        $owner = $this->user('pustymarki');
        $html = $this->actingAs($owner)->get(route('collections.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('zawsze do niego wrócisz', $html);
        $this->assertSame(0, Collection::where('owner_id', $owner->id)->count());
        $this->assertSame(0, $this->dokument($html)->query('//main//li[contains(@class,"marka-zeszyt-zapis")]')->length);
    }

    public function test_kafle_przepisow_zachowuja_pelny_tytul_i_tylko_rzeczywiste_zdjecie(): void
    {
        $owner = $this->user('kaflezeszytu');
        $collection = Collection::create(['owner_id' => $owner->id, 'name' => 'Przepisy', 'visibility' => 'private']);
        $media = Media::factory()->create(['owner_id' => $owner->id, 'alt_text' => 'Prawdziwe pierogi']);
        $title = str_repeat('Rodzinne pierogi ', 8).'KOŃCOWE SŁOWO';
        $withPhoto = Recipe::factory()->for($owner, 'author')->create(['title' => $title, 'hero_media_id' => $media->id]);
        $withoutPhoto = Recipe::factory()->for($owner, 'author')->create(['title' => 'Bez zdjęcia', 'hero_media_id' => null]);
        $collection->recipes()->attach([$withPhoto->id, $withoutPhoto->id]);
        $xpath = $this->dokument($this->actingAs($owner)->get(route('collections.show', $collection))->assertOk()->getContent());
        $grid = '//main//*[contains(concat(" ",@class," ")," marka-zeszyt-przepisy ")]';
        $this->assertSame(1, $xpath->query($grid)->length);
        $this->assertSame(2, $xpath->query($grid.'//article[contains(concat(" ",@class," ")," recipe-card-kafel ")]')->length);
        foreach ([$withPhoto, $withoutPhoto] as $recipe) {
            $card = $grid.'//article[.//a[contains(concat(" ",@class," ")," recipe-card-otworz ") and @href="'.route('recipes.show', $recipe->slug).'"]]';
            $this->assertSame(1, $xpath->query($card)->length);
            $this->assertSame($recipe->title, trim($xpath->evaluate('string('.$card.'//h3)')));
            $this->assertSame($recipe->hero_media_id ? 1 : 0, $xpath->query($card.'//img')->length);
            $this->assertSame(1, $xpath->query($card.'//a')->length);
            $this->assertSame(0, $xpath->query($card.'//h3/a|'.$card.'//a//img')->length);
            $this->assertSame('Zobacz przepis', trim($xpath->evaluate('string('.$card.'//a)')));
            $this->assertSame('Zobacz przepis: '.$recipe->title, $xpath->evaluate('string('.$card.'//a/@aria-label)'));
            $this->assertSame(0, $xpath->query($card.'//a//a')->length);
        }
        $this->assertSame(1, $xpath->query($grid.'//img[@alt="Prawdziwe pierogi"]')->length);
    }

    public function test_zeszyty_w_szynie_profilu_i_zeszytu_maja_krotka_akcje_oraz_pelna_nazwe_i_opis(): void
    {
        $owner = $this->user('szynazeszytumarki');
        $name = str_repeat('Rodzinny zeszyt ', 6).'KONIEC';
        $description = str_repeat('Opis wspólnych obiadów i rodzinnych spotkań. ', 6);
        $target = Collection::create(['owner_id' => $owner->id, 'name' => $name, 'description' => $description, 'visibility' => 'public']);
        $opened = Collection::create(['owner_id' => $owner->id, 'name' => 'Otwarty teraz', 'visibility' => 'public']);
        $recipe = Recipe::factory()->for($owner, 'author')->create(['visibility' => 'private']);
        $target->recipes()->attach($recipe->id);
        $viewer = $this->user('czytelnikszyny');

        foreach ([
            [route('profile.show', 'szynazeszytumarki'), 'szyna-zeszyty-profilu'],
            [route('collections.show', $opened), 'szyna-inne-zeszyty'],
        ] as [$url, $heading]) {
            $xpath = $this->dokument($this->actingAs($viewer)->get($url)->assertOk()->getContent());
            $row = '//aside//*[@aria-labelledby="'.$heading.'"]//li[.//a[@href="'.route('collections.show', $target).'"]]';
            $this->assertSame(1, $xpath->query($row)->length);
            $this->assertSame(1, $xpath->query($row.'//a')->length);
            $this->assertSame('Otwórz zeszyt', trim($xpath->evaluate('string('.$row.'/a[contains(@class,"szyna-otworz")])')));
            $this->assertSame('Otwórz zeszyt: '.$name, $xpath->evaluate('string('.$row.'/a/@aria-label)'));
            $this->assertSame($name, trim($xpath->evaluate('string('.$row.'/span[contains(@class,"szyna-nazwa")])')));
            $this->assertSame(trim($description), trim($xpath->evaluate('string('.$row.'/span[contains(@class,"szyna-podpis")])')));
            $this->assertSame(0, $xpath->query($row.'//a/span')->length);
            $this->assertStringNotContainsString('1 przepis', $xpath->query($row)->item(0)->textContent);
            $this->assertStringNotContainsString($recipe->title, $xpath->query($row)->item(0)->textContent);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KolejkaGotowaniaZdjecieKrokuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Recipe, Media, Media} */
    private function przepis(User $autor, string $tytul): array
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => $tytul, 'visibility' => 'public']);
        $przepis->forceFill(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()])->save();

        $pierwsze = Media::factory()->for($autor, 'owner')->create(['alt_text' => $tytul.' na początku']);
        $drugie = Media::factory()->for($autor, 'owner')->create(['alt_text' => $tytul.' po zmianie kroku']);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Spójrz na zdjęcie.', 'media_id' => $pierwsze->getKey(), 'timer_seconds' => 600]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Zmień krok.', 'media_id' => $drugie->getKey()]);

        return [$przepis, $pierwsze, $drugie];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dom);
    }

    /** @return list<string> */
    private function zrodlaZdjecKroku(string $html): array
    {
        $zrodla = [];

        foreach ($this->xpath($html)->query('//*[@data-kolejka-zdjecie-kroku]//img') as $wezel) {
            if ($wezel instanceof DOMElement) {
                $zrodla[] = $wezel->getAttribute('src');
            }
        }

        return $zrodla;
    }

    public function test_http_w_kolejce_pokazuje_tylko_zdjecie_biezacego_kroku_aktywnej_potrawy(): void
    {
        $autor = $this->user('autor2828');
        [$zupa, $zupaPierwsze, $zupaDrugie] = $this->przepis($autor, 'Zupa');
        [$ciasto, $ciastoPierwsze] = $this->przepis($autor, 'Ciasto');
        $lista = $zupa->slug.':1,'.$ciasto->slug.':1';

        $this->get(route('cooking.show', ['recipe' => $zupa->slug, 'krok' => 1]))
            ->assertOk()->assertSee($zupaPierwsze->url('feed'), false);

        $html = $this->get(route('kolejka-gotowania', ['p' => $lista, 'a' => $zupa->slug]))
            ->assertOk()->getContent();
        $this->assertSame([$zupaPierwsze->url('feed')], $this->zrodlaZdjecKroku($html), 'ZDJECIE_2828_BIEZACY_KROK');

        $nastepny = $this->get(route('kolejka-gotowania', ['p' => $zupa->slug.':2,'.$ciasto->slug.':1', 'a' => $zupa->slug]))
            ->assertOk()->getContent();
        $this->assertSame([$zupaDrugie->url('feed')], $this->zrodlaZdjecKroku($nastepny), 'Zmiana kroku ma usunąć stare zdjęcie.');

        $drugaPotrawa = $this->get(route('kolejka-gotowania', ['p' => $lista, 'a' => $ciasto->slug]))
            ->assertOk()->getContent();
        $this->assertSame([$ciastoPierwsze->url('feed')], $this->zrodlaZdjecKroku($drugaPotrawa), 'Zmiana potrawy ma usunąć stare zdjęcie.');

        $xpath = $this->xpath($html);
        $link = $xpath->query('//*[@data-kolejka-zdjecie-kroku]//a[@data-powieksz]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $link);
        $this->assertSame($zupaPierwsze->url('large'), $link->getAttribute('href'));
        $this->assertSame('Powiększ zdjęcie: Zupa na początku', $link->getAttribute('aria-label'));
        $stanKolejki = $xpath->query('//*[@data-kolejka-dane]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $stanKolejki);
        $this->assertStringNotContainsString((string) $zupaPierwsze->getKey(), $stanKolejki->getAttribute('data-kolejka-dane'));
        $this->assertStringNotContainsString('Zupa na początku', $stanKolejki->getAttribute('data-kolejka-dane'));
        $this->assertStringContainsString('data-kolejka-minutnik', $html, 'Powiększenie nie może usuwać minutnika z aktywnego kroku.');
        $this->assertStringNotContainsString($zupaPierwsze->object_key, $html, 'Oryginał uploadu nie może trafić do HTML.');
    }

    public function test_brak_zdjecia_i_stany_mediów_zachowuja_reguly_pojedynczego_trybu(): void
    {
        $autor = $this->user('autor2828stany');
        [$przepis, $pierwsze, $drugie] = $this->przepis($autor, 'Zapiekanka');
        $adres = fn (int $krok): string => route('kolejka-gotowania', ['p' => $przepis->slug.':'.$krok]);

        $pierwsze->forceFill(['status' => Media::STATUS_SECURED])->save();
        $html = $this->actingAs($autor)->get($adres(1))->assertOk()->getContent();
        $this->assertSame([], $this->zrodlaZdjecKroku($html), 'Zabezpieczone media nie mogą zostać podane jako obraz.');

        $pierwsze->forceFill(['status' => Media::STATUS_PENDING, 'metadata' => ['variants' => []]])->save();
        $html = $this->actingAs($autor)->get($adres(1))->assertOk()->getContent();
        $this->assertSame([], $this->zrodlaZdjecKroku($html));
        $this->assertStringContainsString('Twoje zdjęcie się jeszcze przygotowuje.', $html);

        $pierwsze->forceFill(['status' => Media::STATUS_REJECTED])->save();
        $html = $this->actingAs($autor)->get($adres(1))->assertOk()->getContent();
        $this->assertStringContainsString('Wymień zdjęcie', $html, 'Autor ma tę samą drogę wymiany jak w pojedynczym gotowaniu.');

        $drugie->forceFill(['status' => Media::STATUS_PENDING])->save();
        $html = $this->get($adres(2))->assertOk()->getContent();
        $this->assertSame([$drugie->url('feed')], $this->zrodlaZdjecKroku($html), 'Istniejący przetworzony podgląd wolno pokazać mimo statusu pending.');

        $przepis->steps()->where('position', 1)->update(['media_id' => null]);
        $this->assertSame([], $this->zrodlaZdjecKroku($this->get($adres(2))->assertOk()->getContent()));
    }

    public function test_dawny_slug_zachowuje_zdjecie_i_policy_nie_ujawnia_prywatnego(): void
    {
        $autor = $this->user('autor2828slug');
        [$publiczny, $zdjecie] = $this->przepis($autor, 'Publiczna zupa');
        [$prywatny, $tajne] = $this->przepis($autor, 'Prywatna zupa');
        $prywatny->forceFill(['visibility' => 'private'])->save();
        DB::table('recipe_slug_redirects')->insert(['slug' => 'dawna-zupa', 'recipe_id' => $publiczny->getKey(), 'created_at' => now()]);

        $html = $this->get(route('kolejka-gotowania', ['p' => 'dawna-zupa:1,'.$prywatny->slug.':1', 'a' => 'dawna-zupa']))
            ->assertOk()->getContent();
        $this->assertSame([$zdjecie->url('feed')], $this->zrodlaZdjecKroku($html), 'ZDJECIE_2828_DAWNY_SLUG');
        $this->assertStringNotContainsString($tajne->url('feed'), $html);
        $this->assertStringNotContainsString($tajne->alt_text, $html);
    }

    public function test_media_sa_ladowane_jednym_zapytaniem_dla_czterech_potraw(): void
    {
        $autor = $this->user('autor2828zapytania');
        $slugi = [];
        foreach (['A', 'B', 'C', 'D'] as $tytul) {
            [$przepis] = $this->przepis($autor, $tytul);
            $slugi[] = $przepis->slug.':1';
        }

        DB::enableQueryLog();
        $this->get(route('kolejka-gotowania', ['p' => implode(',', $slugi)]))->assertOk();
        $zapytania = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'from "media"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $zapytania, 'Zdjęcia kroków mają być załadowane dla kolejki razem, bez N+1.');
    }
}

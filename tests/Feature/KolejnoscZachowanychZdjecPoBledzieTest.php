<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #934 — błąd formularza mógł przestawić kolejność zachowanych zdjęć.
 *
 * Kontroler i oba widoki czytały `media_ids` przez `whereIn(...)` bez
 * `ORDER BY`, więc kolejność pochodziła z planu bazy, nie od człowieka.
 * Pierwsze zdjęcie otwiera układ, karuzelę i kolaż — za długi opis mógł
 * po cichu zmienić opowieść zdjęciową mimo „Twoje zdjęcia są zachowane".
 *
 * Zdjęcia powstają w kolejności A–B–C, a wysyłamy C–A–B: prosty odczyt
 * z tabeli oddaje je w kolejności wstawienia, więc bez mapowania według
 * listy wejściowej każdy z tych testów jest czerwony (sprawdzone ręcznie).
 */
class KolejnoscZachowanychZdjecPoBledzieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function trzyZdjecia(string $ownerId): array
    {
        return [
            Media::factory()->create(['owner_id' => $ownerId])->getKey(),
            Media::factory()->create(['owner_id' => $ownerId])->getKey(),
            Media::factory()->create(['owner_id' => $ownerId])->getKey(),
        ];
    }

    /**
     * @return list<string>
     */
    private function ukryteMediaIds(string $html): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $wynik = [];

        foreach ((new \DOMXPath($dom))->query('//input[@type="hidden"][@name="media_ids[]"]') as $pole) {
            $wynik[] = $pole->getAttribute('value');
        }

        return $wynik;
    }

    public function test_wpis_zachowuje_kolejnosc_zdjec_przez_blad_render_i_publikacje(): void
    {
        $basia = $this->user('basia');
        [$a, $b, $c] = $this->trzyZdjecia($basia->getKey());

        $this->actingAs($basia)->from(route('posts.create'))->post(route('posts.store'), [
            'media_ids' => [$c, $a, $b],
            'body' => str_repeat('a', 4001),
            'visibility' => 'public',
        ])->assertRedirect(route('posts.create'))->assertSessionHasErrors('body');

        $this->assertSame([$c, $a, $b], session()->getOldInput('media_ids'));

        $html = $this->get(route('posts.create'))->assertOk()->getContent();
        $this->assertSame([$c, $a, $b], $this->ukryteMediaIds($html));

        $this->post(route('posts.store'), [
            'media_ids' => $this->ukryteMediaIds($html),
            'body' => 'Krótszy tekst',
            'visibility' => 'public',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pozycje = DB::table('post_media')->where('post_id', Post::firstOrFail()->getKey())
            ->pluck('position', 'media_id')->all();
        $this->assertSame([$c => 0, $a => 1, $b => 2], [$c => $pozycje[$c], $a => $pozycje[$a], $b => $pozycje[$b]]);
        $this->assertCount(3, $pozycje);
    }

    public function test_odrzucone_identyfikatory_nie_przestawiaja_pozostalych(): void
    {
        $basia = $this->user('basia');
        $obcy = $this->user('obcy');
        [$a, , $c] = $this->trzyZdjecia($basia->getKey());
        $cudze = Media::factory()->create(['owner_id' => $obcy->getKey()])->getKey();
        $nieistniejace = (string) Str::uuid();

        $przypiete = Media::factory()->create(['owner_id' => $basia->getKey()])->getKey();
        $this->actingAs($basia)->post(route('posts.store'), [
            'media_ids' => [$przypiete], 'body' => 'Pierwszy', 'visibility' => 'public',
        ])->assertRedirect();

        $this->from(route('posts.create'))->post(route('posts.store'), [
            // Sześć pozycji to limit pola; każda kategoria odrzucenia jest
            // w środku listy, a powtórzone C po A nie może przesunąć C na koniec.
            'media_ids' => [$cudze, $c, $przypiete, $a, $c, $nieistniejace],
            'body' => str_repeat('a', 4001),
            'visibility' => 'public',
        ])->assertSessionHasErrors('body');

        $this->assertSame([$c, $a], session()->getOldInput('media_ids'));
        $this->assertSame([$c, $a], $this->ukryteMediaIds($this->get(route('posts.create'))->getContent()));
    }

    public function test_pytanie_zachowuje_kolejnosc_i_pojedyncze_zdjecie(): void
    {
        config(['kuking.questions.enabled' => true]);
        $pytajacy = $this->user('pytajacy');
        [$a, $b, $c] = $this->trzyZdjecia($pytajacy->getKey());
        $cudze = Media::factory()->create(['owner_id' => $this->user('obcy')->getKey()])->getKey();

        // Droga „Usuń zdjęcie z pytania" też buduje `old('media_ids')`.
        $this->actingAs($pytajacy)->post(route('questions.store'), [
            'title' => 'Jak uratować zupę?', 'media_ids' => [$c, $a, $b], 'usun_zdjecie' => $a,
        ])->assertRedirect(route('questions.create'));
        $this->assertSame([$c, $b], session()->getOldInput('media_ids'));
        $this->assertSame([$c, $b], $this->ukryteMediaIds($this->get(route('questions.create'))->getContent()));

        // Pojedyncze zdjęcie pytania: cudze przed nim odpada, własne przeżywa
        // błąd tytułu i trafia do opublikowanego pytania.
        $this->from(route('questions.create'))->post(route('questions.store'), [
            'title' => 'Zupa?', 'media_ids' => [$cudze, $b],
        ])->assertSessionHasErrors('title');
        $this->assertSame([$b], session()->getOldInput('media_ids'));
        $html = $this->get(route('questions.create'))->getContent();
        $this->assertSame([$b], $this->ukryteMediaIds($html));

        $this->post(route('questions.store'), [
            'title' => 'Jak uratować przesoloną zupę?', 'media_ids' => $this->ukryteMediaIds($html),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            [$b => 0],
            DB::table('post_media')->where('post_id', Post::firstOrFail()->getKey())->pluck('position', 'media_id')->all(),
        );
    }
}

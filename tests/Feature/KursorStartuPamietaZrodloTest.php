<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class KursorStartuPamietaZrodloTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.feed.page_size', 2);
    }

    public function test_stabilne_zrodlo_kontynuuje_bez_duplikatow_i_pominiec(): void
    {
        $widz = $this->konto('widz-stabilny');
        $autor = $this->konto('autor-stabilny');
        $widz->following()->attach($autor->getKey(), ['created_at' => now()]);

        $wpisy = collect(range(1, 5))->map(fn (int $numer) => Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => "Stabilny wpis {$numer}",
            'published_at' => now()->subMinutes($numer),
        ]));

        $pierwsza = $this->actingAs($widz)->get(route('home'))->assertOk();
        $adres = $this->adresNastepnejStrony($pierwsza, 'obserwowani');
        $druga = $this->get($adres)->assertOk()->assertViewHas('zrodloFeedu', 'obserwowani');

        $pierwszeId = $pierwsza->viewData('posts')->getCollection()->modelKeys();
        $drugieId = $druga->viewData('posts')->getCollection()->modelKeys();

        $this->assertSame($wpisy->take(4)->pluck('id')->all(), [...$pierwszeId, ...$drugieId]);
        $this->assertSame([], array_values(array_intersect($pierwszeId, $drugieId)));
    }

    public function test_stabilne_tagi_kontynuuja_bez_duplikatow_i_pominiec(): void
    {
        $widz = $this->konto('widz-stabilne-tagi');
        $tag = Tag::factory()->create();
        $widz->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
        $wpisy = collect(range(1, 5))->map(function (int $numer) use ($tag): Post {
            $wpis = Post::factory()->create([
                'author_id' => $this->konto("autor-stabilne-tagi-{$numer}")->getKey(),
                'body' => "Stabilny tag {$numer}",
                'published_at' => now()->subMinutes($numer),
            ]);
            $wpis->tags()->attach($tag->getKey(), ['position' => 0]);

            return $wpis;
        });

        $this->assertDwieStronyBezDziur($widz, 'tagi', $wpisy->pluck('id')->all());
    }

    public function test_stabilne_odkrywanie_kontynuuje_bez_duplikatow_i_pominiec(): void
    {
        $widz = $this->konto('widz-stabilne-odkrywanie');
        $wpisy = collect(range(1, 5))->map(fn (int $numer) => Post::factory()->create([
            'author_id' => $this->konto("autor-stabilne-odkrywanie-{$numer}")->getKey(),
            'body' => "Stabilne odkrywanie {$numer}",
            'published_at' => now()->subMinutes($numer),
        ]));

        $this->assertDwieStronyBezDziur($widz, 'odkrywanie', $wpisy->pluck('id')->all());
    }

    public function test_obserwowani_zmienieni_na_tagi_zaczynaja_tagi_od_pierwszej_strony(): void
    {
        [$widz, $autor] = $this->feedObserwowanych('do-tagow');
        $tag = $this->feedTagu($widz, 'tag-po-obserwowanych');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'obserwowani');

        $widz->following()->detach($autor->getKey());

        $this->get($adres)->assertRedirect(route('home'));
        $this->get(route('home'))->assertOk()
            ->assertViewHas('zrodloFeedu', 'tagi')
            ->assertSee($tag['najnowszy']);
    }

    public function test_obserwowani_zmienieni_na_odkrywanie_zaczynaja_odkrywanie_od_pierwszej_strony(): void
    {
        [$widz, $autor] = $this->feedObserwowanych('do-odkrywania');
        $najnowszy = $this->feedOdkrywania('odkrywanie-po-obserwowanych');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'obserwowani');

        $widz->following()->detach($autor->getKey());

        $this->get($adres)->assertRedirect(route('home'));
        $this->get(route('home'))->assertOk()
            ->assertViewHas('zrodloFeedu', 'odkrywanie')
            ->assertSee($najnowszy);
    }

    public function test_tagi_zmienione_na_obserwowanych_zaczynaja_obserwowanych_od_pierwszej_strony(): void
    {
        $widz = $this->konto('widz-tagi-do-osob');
        $tag = $this->feedTagu($widz, 'tagi-do-osob');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'tagi');
        $autor = $this->konto('autor-po-tagach');
        $najnowszy = $this->wpisy($autor, 'Osoba po tagach', 3)->first()->body;

        $widz->following()->attach($autor->getKey(), ['created_at' => now()]);

        $this->get($adres)->assertRedirect(route('home'));
        $this->get(route('home'))->assertOk()
            ->assertViewHas('zrodloFeedu', 'obserwowani')
            ->assertSee($najnowszy);
    }

    public function test_tagi_zmienione_na_odkrywanie_zaczynaja_odkrywanie_od_pierwszej_strony(): void
    {
        $widz = $this->konto('widz-tagi-do-odkrywania');
        $tag = $this->feedTagu($widz, 'tagi-do-odkrywania');
        $najnowszy = $this->feedOdkrywania('odkrywanie-po-tagach');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'tagi');

        $widz->followedTags()->detach($tag['tag']->getKey());

        $this->get($adres)->assertRedirect(route('home'));
        $this->get(route('home'))->assertOk()
            ->assertViewHas('zrodloFeedu', 'odkrywanie')
            ->assertSee($najnowszy);
    }

    public function test_odkrywanie_zmienione_na_obserwowanych_zaczyna_obserwowanych_od_pierwszej_strony(): void
    {
        $widz = $this->konto('widz-odkrywanie-do-osob');
        $autor = $this->konto('autor-w-odkrywaniu');
        $najnowszy = $this->wpisy($autor, 'Osoba po odkrywaniu', 3)->first()->body;
        $this->feedOdkrywania('tlo-odkrywania-do-osob');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'odkrywanie');

        $widz->following()->attach($autor->getKey(), ['created_at' => now()]);

        $this->get($adres)->assertRedirect(route('home'));
        $this->get(route('home'))->assertOk()
            ->assertViewHas('zrodloFeedu', 'obserwowani')
            ->assertSee($najnowszy);
    }

    public function test_odkrywanie_zmienione_na_tagi_zaczyna_tagi_od_pierwszej_strony(): void
    {
        $widz = $this->konto('widz-odkrywanie-do-tagow');
        $tag = $this->feedTagu(null, 'tag-po-odkrywaniu');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'odkrywanie');

        $widz->followedTags()->attach($tag['tag']->getKey(), ['created_at' => now()]);

        $this->get($adres)->assertRedirect(route('home'));
        $this->get(route('home'))->assertOk()
            ->assertViewHas('zrodloFeedu', 'tagi')
            ->assertSee($tag['najnowszy']);
    }

    public function test_obcy_parametr_zrodla_nie_wybiera_innego_feedu(): void
    {
        [$widz] = $this->feedObserwowanych('zly-parametr');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'obserwowani');
        $adres = $this->zParametrem($adres, 'zrodlo', 'administracja');

        $this->get($adres)->assertRedirect(route('home'));
    }

    public function test_kursor_bez_zrodla_i_zrodlo_bez_kursora_wraca_do_czystej_pierwszej_strony(): void
    {
        [$widz] = $this->feedObserwowanych('niepelny-parametr');
        $adres = $this->adresNastepnejStrony($this->actingAs($widz)->get(route('home')), 'obserwowani');
        $parametry = [];
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $parametry);

        $this->get(route('home', ['cursor' => $parametry['cursor']]))->assertRedirect(route('home'));
        $this->get(route('home', ['zrodlo' => 'obserwowani']))->assertRedirect(route('home'));
    }

    /** @return array{User, User} */
    private function feedObserwowanych(string $sufiks): array
    {
        $widz = $this->konto("widz-{$sufiks}");
        $autor = $this->konto("autor-{$sufiks}");
        $widz->following()->attach($autor->getKey(), ['created_at' => now()]);
        $this->wpisy($autor, "Obserwowani {$sufiks}", 3);

        return [$widz, $autor];
    }

    /** @return array{tag: Tag, najnowszy: string} */
    private function feedTagu(?User $widz, string $sufiks): array
    {
        $tag = Tag::factory()->create(['name' => "Tag {$sufiks}"]);
        // Trzech RÓŻNYCH autorów, nie jeden. Od #940 „Świeżo z Kuking" pokazuje
        // najwyżej jeden wpis od osoby, więc trzy wpisy jednego autora dawały
        // w odkrywaniu jedną pozycję i żadnej drugiej strony — test startujący
        // z odkrywania (`tag-po-odkrywaniu`) padał na fiksturze. Feed tagu
        // i feed obserwowanych nie zależą od liczby autorów.
        $wpisy = collect(range(1, 3))->map(fn (int $numer) => Post::factory()->create([
            'author_id' => $this->konto("autor-tagu-{$sufiks}-{$numer}")->getKey(),
            'body' => "Tagi {$sufiks} {$numer}",
            'published_at' => now()->subMinutes($numer),
        ]));

        foreach ($wpisy as $pozycja => $wpis) {
            $wpis->tags()->attach($tag->getKey(), ['position' => $pozycja]);
        }

        $widz?->followedTags()->attach($tag->getKey(), ['created_at' => now()]);

        return ['tag' => $tag, 'najnowszy' => $wpisy->first()->body];
    }

    private function feedOdkrywania(string $sufiks): string
    {
        $najnowszy = '';

        foreach (range(1, 3) as $numer) {
            $autor = $this->konto("autor-odkrywania-{$sufiks}-{$numer}");
            $wpis = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'body' => "Odkrywanie {$sufiks} {$numer}",
                'published_at' => now()->subMinutes($numer),
            ]);
            $najnowszy = $najnowszy === '' ? $wpis->body : $najnowszy;
        }

        return $najnowszy;
    }

    /** @return Collection<int, Post> */
    private function wpisy(User $autor, string $prefiks, int $ile): Collection
    {
        return collect(range(1, $ile))->map(fn (int $numer) => Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => "{$prefiks} {$numer}",
            'published_at' => now()->subMinutes($numer),
        ]));
    }

    private function konto(string $seed): User
    {
        return $this->user('u'.substr(md5($seed), 0, 15));
    }

    /** @param list<string> $oczekiwaneId */
    private function assertDwieStronyBezDziur(User $widz, string $zrodlo, array $oczekiwaneId): void
    {
        $pierwsza = $this->actingAs($widz)->get(route('home'))->assertOk();
        $druga = $this->get($this->adresNastepnejStrony($pierwsza, $zrodlo))
            ->assertOk()
            ->assertViewHas('zrodloFeedu', $zrodlo);

        $pierwszeId = $pierwsza->viewData('posts')->getCollection()->modelKeys();
        $drugieId = $druga->viewData('posts')->getCollection()->modelKeys();

        $this->assertSame(array_slice($oczekiwaneId, 0, 4), [...$pierwszeId, ...$drugieId]);
        $this->assertSame([], array_values(array_intersect($pierwszeId, $drugieId)));
    }

    private function adresNastepnejStrony(TestResponse $response, string $zrodlo): string
    {
        $response->assertOk()->assertViewHas('zrodloFeedu', $zrodlo);
        $adres = $response->viewData('posts')->nextPageUrl();

        $this->assertNotNull($adres, 'Fixture nie utworzył drugiej strony feedu.');
        $parametry = [];
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $parametry);
        $this->assertSame($zrodlo, $parametry['zrodlo'] ?? null);
        $this->assertNotEmpty($parametry['cursor'] ?? null);

        return $adres;
    }

    private function zParametrem(string $adres, string $nazwa, string $wartosc): string
    {
        $parametry = [];
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $parametry);
        $parametry[$nazwa] = $wartosc;

        return route('home').'?'.http_build_query($parametry);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dyktowanie własnego przepisu (issue #2377, decyzja właściciela z 1.10.2026
 * „Budujemy z ostrzeżeniem”, D-333).
 *
 * Trzy rzeczy po stronie serwera:
 *
 *  1. Mikrofon jest odblokowany (`microphone=(self)`) dla zalogowanego na
 *     ekranach z dłuższym polem. Gość i pozostałe ekrany mają `microphone=()`.
 *  2. Przycisk „Dyktuj” nie istnieje w HTML-u: stoi tam tylko pusty host,
 *     który wypełnia skrypt, i to tylko w przeglądarce z rozpoznawaniem
 *     mowy (D-053 — bez skryptu formularz działa jak dotąd, bez martwego
 *     przycisku). Zachowanie skryptu mierzy `resources/js/dyktowanie.test.mjs`.
 *  3. Serwer nie ma żadnej drogi, którą dyktowanie mogłoby coś zapisać:
 *     do niego trafia wyłącznie tekst z pola, które człowiek sam wysłał.
 */
class DyktowanieNaTrasachKreatoraTest extends TestCase
{
    use RefreshDatabase;

    private const POLITYKA_Z_MIKROFONEM = 'geolocation=(), microphone=(self), camera=(), payment=()';

    private const POLITYKA_DOMYSLNA = 'geolocation=(), microphone=(), camera=(), payment=()';

    /** @return array<string, array{0: string}> */
    public static function trasyKreatora(): array
    {
        return [
            'kreator dodawania' => ['recipes.create'],
            'dodawanie na jednej stronie' => ['recipes.create.simple'],
            'dopisywanie szczegółów' => ['recipes.details'],
            'edycja przepisu' => ['recipes.edit'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function trasyPozaKreatorem(): array
    {
        return [
            'start' => ['home'],
            'tryb gotowania' => ['cooking.show'],
            'profil' => ['profile.show'],
        ];
    }

    #[DataProvider('trasyKreatora')]
    public function test_kreator_przepisu_odblokowuje_mikrofon_tylko_dla_siebie(string $trasa): void
    {
        $autor = $this->user();

        $odpowiedz = $this->actingAs($autor)->get($this->adres($trasa, $autor))->assertOk();

        $this->assertSame(self::POLITYKA_Z_MIKROFONEM, $odpowiedz->headers->get('Permissions-Policy'));
    }

    #[DataProvider('trasyPozaKreatorem')]
    public function test_reszta_serwisu_zostaje_z_zablokowanym_mikrofonem(string $trasa): void
    {
        $autor = $this->user();

        $odpowiedz = $this->actingAs($autor)->get($this->adres($trasa, $autor))->assertOk();

        $this->assertSame(self::POLITYKA_DOMYSLNA, $odpowiedz->headers->get('Permissions-Policy'));
    }

    public function test_strona_bledu_nie_dostaje_mikrofonu(): void
    {
        $odpowiedz = $this->get('/tej-strony-nie-ma');

        $this->assertSame(self::POLITYKA_DOMYSLNA, $odpowiedz->headers->get('Permissions-Policy'));
    }

    public function test_dyktowanie_obejmuje_komentarze_ale_nie_tryb_gotowania_ani_start(): void
    {
        $autor = $this->user();
        $mikrofon = [];

        foreach (['recipes.create', 'home', 'cooking.show', 'recipes.show'] as $trasa) {
            $naglowek = (string) $this->actingAs($autor)->get($this->adres($trasa, $autor))->headers->get('Permissions-Policy');
            $mikrofon[$trasa] = str_contains($naglowek, 'microphone=(self)');
        }

        $this->assertSame([
            'recipes.create' => true,
            'home' => false,
            'cooking.show' => false,
            'recipes.show' => true,
        ], $mikrofon);
    }

    public function test_zalogowany_przy_publicznym_przepisie_ma_mikrofon_ale_nigdy_cache_cdn(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $autor = $this->user();
        $odpowiedz = $this->actingAs($autor)->get($this->adres('recipes.show', $autor))->assertOk();

        $this->assertSame(self::POLITYKA_Z_MIKROFONEM, $odpowiedz->headers->get('Permissions-Policy'), 'DICTATION_AUTH_HEADER');
        $this->assertTrue($odpowiedz->headers->hasCacheControlDirective('private'));
        $this->assertTrue($odpowiedz->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($odpowiedz->headers->hasCacheControlDirective('s-maxage'));
        $odpowiedz->assertHeaderMissing('CDN-Cache-Control')
            ->assertHeaderMissing('Cloudflare-CDN-Cache-Control')
            ->assertHeaderMissing('Surrogate-Control');
    }

    public function test_gosc_przy_publicznym_przepisie_ma_cache_brzegu_ale_nie_mikrofon(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $autor = $this->user();
        $odpowiedz = $this->get($this->adres('recipes.show', $autor))->assertOk();

        $this->assertSame(self::POLITYKA_DOMYSLNA, $odpowiedz->headers->get('Permissions-Policy'), 'DICTATION_GUEST_HEADER');
        $this->assertTrue($odpowiedz->headers->hasCacheControlDirective('public'));
        $this->assertSame('120', (string) $odpowiedz->headers->getCacheControlDirective('s-maxage'));
        $this->assertStringNotContainsString('data-dyktowanie', (string) $odpowiedz->getContent());
    }

    /** @return array<string, array{string}> */
    public static function publicCommentAndNoteRoutes(): array
    {
        return [
            'wpis' => ['posts.show'],
            'pytanie' => ['questions.show'],
            'wykonanie' => ['cooked.show'],
            'zeszyt' => ['collections.show'],
        ];
    }

    #[DataProvider('publicCommentAndNoteRoutes')]
    public function test_publiczny_ekran_z_dluzszym_polem_oddziela_goscia_od_zalogowanego(string $route): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $author = $this->user();
        $subject = match ($route) {
            'posts.show' => Post::factory()->create(['author_id' => $author->id]),
            'questions.show' => Post::factory()->question()->create(['author_id' => $author->id]),
            'cooked.show' => CookedEvent::factory()->create([
                'user_id' => $author->id,
                'recipe_id' => Recipe::factory()->create(['author_id' => $author->id])->id,
            ]),
            'collections.show' => Collection::create([
                'owner_id' => $author->id, 'name' => 'Zeszyt do dyktowania',
                'visibility' => 'public', 'is_default' => false,
            ]),
        };
        $url = route($route, $subject);
        $guest = $this->get($url)->assertOk();
        $this->assertSame(self::POLITYKA_DOMYSLNA, $guest->headers->get('Permissions-Policy'), 'DICTATION_GUEST_HEADER');
        $this->assertStringNotContainsString('data-dyktowanie', (string) $guest->getContent());

        $authenticated = $this->actingAs($author)->get($url)->assertOk();
        $this->assertSame(self::POLITYKA_Z_MIKROFONEM, $authenticated->headers->get('Permissions-Policy'), 'DICTATION_AUTH_HEADER');
        $this->assertTrue($authenticated->headers->hasCacheControlDirective('private'));
        $this->assertTrue($authenticated->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($authenticated->headers->hasCacheControlDirective('s-maxage'));
        $authenticated->assertHeaderMissing('CDN-Cache-Control')
            ->assertHeaderMissing('Cloudflare-CDN-Cache-Control')
            ->assertHeaderMissing('Surrogate-Control');
    }

    #[DataProvider('trasyKreatora')]
    public function test_w_html_nie_ma_przycisku_dyktuj_tylko_pusty_host_dla_skryptu(string $trasa): void
    {
        $autor = $this->user();

        $html = $this->actingAs($autor)->get($this->adres($trasa, $autor))->assertOk()->getContent();

        $this->assertStringNotContainsString('Dyktuj', $html, 'Przycisk rysuje wyłącznie skrypt, gdy przeglądarka umie rozpoznawać mowę (D-053).');
        $this->assertStringNotContainsString('Wstaw do przepisu', $html);
        $this->assertStringNotContainsString('Dyktowanie obsługuje', $html);

        // Kreator Livewire (`recipes.details`) otwiera się na kroku 1 (nazwa i opis);
        // pola składników i kroków są w krokach 2 i 3 — mierzy je test niżej.
        if ($trasa !== 'recipes.details') {
            $this->assertMatchesRegularExpression('/<div data-dyktowanie data-cel="[^"]+" data-separator="[a-z-]+" wire:ignore><\/div>/', $html);
        }
    }

    public function test_kroki_2_i_3_kreatora_maja_pusty_host_przy_kazdym_wierszu_i_zadnego_przycisku(): void
    {
        view()->share('errors', new ViewErrorBag);

        $skladniki = Blade::render('<x-kreator.krok-skladniki :ingredients="$wiersze" :liczba-krokow="3" />', [
            'wiersze' => [['_key' => 'w1', 'text' => ''], ['_key' => 'w2', 'text' => '']],
        ]);
        $kroki = Blade::render('<x-kreator.krok-przygotowanie :steps="$wiersze" :liczba-krokow="3" />', [
            'wiersze' => [['_key' => 'w1', 'instruction' => ''], ['_key' => 'w2', 'instruction' => '']],
        ]);

        $this->assertSame(2, substr_count($skladniki, '<div data-dyktowanie data-cel="f-ingredients-'));
        $this->assertSame(2, substr_count($kroki, '<div data-dyktowanie data-cel="f-steps-'));
        $this->assertStringContainsString('data-cel="f-ingredients-1-text"', $skladniki);
        $this->assertStringContainsString('id="f-ingredients-1-text"', $skladniki);
        $this->assertStringContainsString('data-cel="f-steps-1-instruction"', $kroki);
        $this->assertStringContainsString('id="f-steps-1-instruction"', $kroki);
        $this->assertStringContainsString('wire:ignore', $skladniki);
        $this->assertStringNotContainsString('Dyktuj', $skladniki.$kroki);
    }

    #[DataProvider('trasyKreatora')]
    public function test_kazdy_host_wskazuje_istniejace_pole_na_tej_stronie(string $trasa): void
    {
        $autor = $this->user();

        $html = $this->actingAs($autor)->get($this->adres($trasa, $autor))->assertOk()->getContent();

        preg_match_all('/data-dyktowanie data-cel="([^"]+)"/', $html, $trafienia);

        // Kreator Livewire (`recipes.details`) rysuje krok 1 bez pól składników
        // i kroków; pola są w krokach 2 i 3, więc tam host może się jeszcze nie pojawić.
        if ($trasa !== 'recipes.details') {
            $this->assertNotEmpty($trafienia[1], "Trasa {$trasa} nie ma żadnego miejsca na dyktowanie.");
        }

        foreach ($trafienia[1] as $cel) {
            $this->assertStringContainsString('id="'.$cel.'"', $html, "Host dyktowania na {$trasa} wskazuje pole {$cel}, którego nie ma na stronie.");
        }
    }

    public function test_dopisz_przepis_do_wpisu_ma_mikrofon_i_host_dyktowania(): void
    {
        Storage::fake('public');
        $autor = $this->user();
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $wpis = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Pierogi jak u mamy.']);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $odpowiedz = $this->actingAs($autor)->get(route('recipes.create.from-post', $wpis))->assertOk();

        $this->assertSame(self::POLITYKA_Z_MIKROFONEM, $odpowiedz->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('data-dyktowanie data-cel="', (string) $odpowiedz->getContent());
        $this->assertStringNotContainsString('Dyktuj', (string) $odpowiedz->getContent());
    }

    #[DataProvider('trasyKreatora')]
    public function test_strony_kreatora_nie_trafiaja_do_cache_brzegu(string $trasa): void
    {
        $autor = $this->user();

        $naglowek = (string) $this->actingAs($autor)->get($this->adres($trasa, $autor))->assertOk()->headers->get('Cache-Control');

        // Strona z odblokowanym mikrofonem jest tylko dla zalogowanego i nie może
        // zostać podana komu innemu z cache CDN ani przeglądarki.
        $this->assertMatchesRegularExpression('/\b(private|no-store)\b/', $naglowek);
        $this->assertDoesNotMatchRegularExpression('/\b(public|s-maxage)\b/', $naglowek);
    }

    public function test_serwer_nie_ma_zadnej_drogi_na_dyktowanie_ani_dzwiek(): void
    {
        $trafione = [];

        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            $opis = mb_strtolower($trasa->uri().' '.($trasa->getName() ?? ''));

            if (preg_match('/dyktow|dźwięk|dzwiek|audio|speech|transkryp/u', $opis) === 1) {
                $trafione[] = $trasa->uri();
            }
        }

        $this->assertSame([], $trafione, 'Kuking nie przyjmuje dźwięku ani transkrypcji — tylko tekst z pola, który człowiek sam wysłał.');
    }

    private function adres(string $trasa, User $autor): string
    {
        return match ($trasa) {
            'recipes.edit' => route($trasa, Recipe::factory()->draft()->create(['author_id' => $autor->id])->slug),
            // Szkic „Dopisz szczegóły” przekierowuje do kreatora — wizard dostaje opublikowany przepis.
            'recipes.details', 'recipes.show' => route($trasa, Recipe::factory()->create(['author_id' => $autor->id])->slug),
            'cooking.show' => route($trasa, $this->przepisZKrokiem($autor)->slug),
            'profile.show' => route($trasa, $autor->profile->username),
            default => route($trasa),
        };
    }

    private function przepisZKrokiem(User $autor): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => $autor->id]);
        $recipe->ingredients()->create(['position' => 0, 'ingredient_text' => 'szklanka mąki']);
        $recipe->steps()->create(['position' => 0, 'instruction' => 'Wymieszaj.']);

        return $recipe;
    }
}

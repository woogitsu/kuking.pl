<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Feed\HeroKolaz;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mapa witryny i kolaż powitalny NIE mają `zWidocznymPrzepisem()` — i nie
 * muszą go mieć. Ten plik jest dowodem, a nie poprawką.
 *
 * DLACZEGO TO W OGÓLE STOI W REPOZYTORIUM. Oba te miejsca pytają
 * `Post::publiclyVisible()`, czyli filtrem po widoczności WPISU — a wpis
 * wskazujący przepis (#368) jest na stałe `public`. Z samego zapytania
 * wynika więc, że zapowiedź powinna tam wejść. Nie wchodzi, ale z powodów,
 * które nie są zapisane obok tych zapytań i które da się skasować jedną
 * niewinną zmianą gdzie indziej:
 *
 *   MAPA WITRYNY (`SitemapController::index()`) — bo zapytanie o wpisy ma
 *   `whereNotNull('body')`, a zapowiedź ma `body = null` z założenia
 *   („wpis WSKAZUJE, nie KOPIUJE", `WpisWskazujacyPrzepis`). Warunek stoi
 *   tam z innego powodu — „do mapy trafia tylko to, co realnie ma wartość
 *   dla czytelnika" — i nikt go nie pisał jako bramki prywatności. Gdyby
 *   zapowiedź kiedykolwiek dostała treść, mapa zaczęłaby PODAWAĆ
 *   WYSZUKIWARCE adres, spod którego leci 302 z `Location` na slug przepisu
 *   (czyli na tytuł) — wyciek trwały i publiczny.
 *
 *   KOLAŻ POWITALNY (`HeroKolaz`) — bo każda droga do kafla przechodzi przez
 *   `gotoweZdjecia($wpis)`, czyli przez WŁASNE zdjęcia wpisu (`post_media`),
 *   a zapowiedź własnych zdjęć nie ma żadnych. Zdjęcie główne przepisu wisi
 *   przy PRZEPISIE, nie przy wpisie, więc do kolażu nie ma czym trafić.
 *
 * Oba powody są prawdziwe i oba są PRZYPADKOWE z punktu widzenia
 * prywatności. Testy niżej trzymają je na miejscu i mają zęby: zmiana
 * założenia (zapowiedź z treścią, zapowiedź z własnym zdjęciem) zapala je
 * na czerwono. Bez nich cisza tutaj znaczyłaby „nikt nie sprawdzał".
 */
class ZapowiedzPrzepisuPozaMapaIKolazemTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapowiedz_nie_niesie_wlasnej_tresci_ani_wlasnych_zdjec(): void
    {
        ['zapowiedz' => $zapowiedz] = $this->scena();

        // To są DWA założenia, na których stoi bezpieczeństwo mapy i kolażu.
        // Stoją tu wprost, żeby ich zniknięcie miało własny, czytelny błąd,
        // a nie objawiało się dopiero cudzym testem trzy pliki dalej.
        $this->assertNull(
            $zapowiedz->body,
            'Zapowiedź przepisu dostała własną treść. Od tej chwili przechodzi przez '
            .'`whereNotNull(\'body\')` w mapie witryny i wchodzi do sitemap.xml.',
        );

        $this->assertSame(
            0,
            $zapowiedz->media()->count(),
            'Zapowiedź przepisu dostała własne zdjęcie. Od tej chwili `HeroKolaz` ma czym '
            .'zrobić kafel i zdjęcie wchodzi na stronę powitalną.',
        );

        $this->assertSame(
            Post::VISIBILITY_PUBLIC,
            $zapowiedz->visibility,
            'Zapowiedź nie jest już publiczna — wtedy odcina ją zwykły filtr widoczności wpisu '
            .'i cały ten plik mierzy coś innego, niż opisuje.',
        );
    }

    public function test_mapa_witryny_nie_oglasza_zapowiedzi_cudzego_przepisu(): void
    {
        ['zapowiedz' => $zapowiedz, 'zwykly' => $zwykly, 'przepis' => $przepis, 'jawny' => $jawny] = $this->scena();

        $xml = $this->mapa();

        // KONTROLA DODATNIA — mapa w ogóle coś ogłasza, i to obu rodzajów.
        // Bez tego asercje niżej przechodziłyby na pustym pliku.
        $this->assertStringContainsString(
            route('posts.show', $zwykly),
            $xml,
            'W mapie nie ma nawet zwykłego publicznego wpisu z treścią — asercje niżej '
            .'mówiłyby o pustej mapie, nie o widoczności przepisu.',
        );

        $this->assertStringContainsString(
            route('recipes.show', $jawny->slug),
            $xml,
            'W mapie nie ma nawet przepisu PUBLICZNEGO — kontrola dodatnia nie przeszła.',
        );

        $this->assertStringNotContainsString(
            route('posts.show', $zapowiedz),
            $xml,
            'Mapa witryny podaje wyszukiwarce adres zapowiedzi przepisu „tylko dla '
            .'obserwujących". Spod tego adresu leci 302 z `Location` na slug przepisu.',
        );

        $this->assertStringNotContainsString(
            $przepis->slug,
            $xml,
            'Slug przepisu „tylko dla obserwujących" stoi w mapie witryny.',
        );
    }

    /**
     * ZĘBY POWYŻSZEGO: gdy zapowiedź dostanie treść, mapa ją ogłasza.
     *
     * To nie jest opis pożądanego stanu, tylko dowód, że test wyżej NIE jest
     * zielony „z powietrza". Asercja „nie zawiera X" przechodzi na 404,
     * na pustym pliku i na obcym ekranie — więc trzeba pokazać warunek,
     * w którym ta sama asercja pada.
     */
    public function test_zapowiedz_z_dopisana_trescia_jedna_k_wchodzi_do_mapy(): void
    {
        ['zapowiedz' => $zapowiedz] = $this->scena();

        $zapowiedz->forceFill(['body' => 'Dopisana ręcznie treść.'])->save();

        $this->assertStringContainsString(
            route('posts.show', $zapowiedz),
            $this->mapa(),
            'Zapowiedź Z TREŚCIĄ nie weszła do mapy — czyli test wyżej jest zielony z innego '
            .'powodu niż `whereNotNull(\'body\')` i nie pilnuje tego, co obiecuje.',
        );
    }

    public function test_kolaz_powitalny_nie_pokazuje_zdjecia_cudzego_przepisu(): void
    {
        ['zapowiedz' => $zapowiedz, 'przepis' => $przepis, 'zdjecieZwyklego' => $zdjecieZwyklego] = $this->scena();

        $kafle = app(HeroKolaz::class)->doKolazu();
        $zdjeciaWKolazu = $kafle->map(fn (array $kafel) => (string) $kafel['media']->getKey())->all();

        // KONTROLA DODATNIA: kolaż w ogóle coś zebrał.
        $this->assertContains(
            (string) $zdjecieZwyklego->getKey(),
            $zdjeciaWKolazu,
            'Kolaż nie wziął nawet zdjęcia zwykłego publicznego wpisu — asercja niżej '
            .'mówiłaby o pustym kolażu, nie o widoczności przepisu.',
        );

        $this->assertNotContains(
            (string) $przepis->hero_media_id,
            $zdjeciaWKolazu,
            'Zdjęcie główne przepisu „tylko dla obserwujących" stoi na stronie powitalnej.',
        );

        $this->assertFalse(
            app(HeroKolaz::class)->kandydaci()->contains(
                fn (Post $post) => (string) $post->getKey() === (string) $zapowiedz->getKey(),
            ),
            'Zapowiedź cudzego przepisu „tylko dla obserwujących" jest do wzięcia w panelu '
            .'gospodarza jako kafel kolażu.',
        );
    }

    /**
     * ZĘBY POWYŻSZEGO: gdy zapowiedź dostanie własne zdjęcie, kolaż je bierze.
     */
    public function test_zapowiedz_z_wlasnym_zdjeciem_jedna_k_wchodzi_do_kolazu(): void
    {
        ['zapowiedz' => $zapowiedz, 'basia' => $basia] = $this->scena();

        $wlasne = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $zapowiedz->media()->attach($wlasne->getKey(), ['position' => 0]);

        $this->assertContains(
            (string) $wlasne->getKey(),
            app(HeroKolaz::class)->doKolazu()->map(fn (array $kafel) => (string) $kafel['media']->getKey())->all(),
            'Zapowiedź Z WŁASNYM ZDJĘCIEM nie weszła do kolażu — czyli test wyżej jest zielony '
            .'z innego powodu niż brak własnych zdjęć i nie pilnuje tego, co obiecuje.',
        );
    }

    private function mapa(): string
    {
        // Mapa jest cache'owana na sześć godzin (`sitemap.urls`), więc bez
        // tego kolejny test w tej samej klasie czytałby cudzy wynik.
        cache()->forget('sitemap.urls');

        return $this->get(route('sitemap'))->assertOk()->getContent();
    }

    /**
     * @return array{basia: User, przepis: Recipe, jawny: Recipe, zapowiedz: Post, zwykly: Post, zdjecieZwyklego: Media}
     */
    private function scena(): array
    {
        $basia = $this->user('basia');

        $przepis = $this->przepis($basia, 'Bigos z kapusty kiszonej', 'followers');
        $jawny = $this->przepis($basia, 'Naleśniki z serem', 'public');

        /** @var Post $zapowiedz */
        $zapowiedz = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();

        $zwykly = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Zwykły obiad, bez przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $zdjecieZwyklego = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $zwykly->media()->attach($zdjecieZwyklego->getKey(), ['position' => 0]);

        return [
            'basia' => $basia,
            'przepis' => $przepis->fresh(),
            'jawny' => $jawny->fresh(),
            'zapowiedz' => $zapowiedz->fresh(),
            'zwykly' => $zwykly->refresh(),
            'zdjecieZwyklego' => $zdjecieZwyklego,
        ];
    }

    private function przepis(User $autor, string $tytul, string $widocznosc): Recipe
    {
        $przepis = app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => $tytul, 'visibility' => $widocznosc, 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
        ])->save();

        return $przepis;
    }
}

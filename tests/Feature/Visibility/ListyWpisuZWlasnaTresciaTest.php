<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Feed\DailyBoard;
use App\Domain\Posts\SasiedniWpisAutora;
use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Tags\LiczbyTagowWCache;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1377: wpis z WŁASNĄ treścią, który wskazuje przepis, idzie na
 * listach za własną widocznością — tak jak na swojej stronie
 * (`PostPolicy::view()` + `PostController::show()`). Gdy przepis stanie się
 * niedostępny, wpis zostaje na odkrywaniu, w feedzie i na profilu, ale karta
 * nie zdradza tytułu, sluga ani zdjęcia przepisu. Czysta zapowiedź nadal
 * znika razem z przepisem (#368/#941). To samo na stronie tagu, w liczniku
 * spisu tagów, w nawigacji „następny wpis” i na tablicy (recenzja #1377).
 *
 * Kontrola dodatnia: `test_kontrola_dodatnia_*` dowodzi, że te same asercje
 * „brak tytułu / sluga / zdjęcia przepisu” widzą każde z nich, gdy przepis
 * jest dostępny — inaczej przechodziłyby na karcie, która nigdy ich nie ma.
 */
class ListyWpisuZWlasnaTresciaTest extends TestCase
{
    use RefreshDatabase;

    private const TRESC = 'Dzisiaj ugotowałam ponownie, z własnym koprem.';

    private User $autorPrzepisu;

    private User $autorWpisu;

    private Recipe $przepis;

    private Media $zdjeciePrzepisu;

    private Post $wpis;

    private ?Post $zapowiedz;

    private Tag $tag;

    /** Własne zdjęcie wpisu, gdy wpis nie ma tekstu (gałąź EXISTS). */
    private ?Media $zdjecieWpisu = null;

    /** @return array<string, array{string}> */
    public static function niedostepnosci(): array
    {
        return [
            'tylko dla obserwujących' => ['followers'],
            'prywatny' => ['private'],
            'usunięty' => ['soft-delete'],
            'ukryty przez moderację' => ['hidden'],
        ];
    }

    private function przygotuj(bool $wspolnyAutor = true, bool $tylkoZdjecie = false): void
    {
        $this->autorPrzepisu = $this->user('autorprzepisu');
        $this->autorWpisu = $wspolnyAutor ? $this->autorPrzepisu : $this->user('autorwpisu');
        $this->zdjeciePrzepisu = Media::factory()->create(['owner_id' => $this->autorPrzepisu->getKey()]);
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autorPrzepisu->getKey(),
            'title' => 'Sekretna Zupa Szczawiowa',
            'slug' => 'sekretna-zupa-szczawiowa',
            'visibility' => 'public',
            'hero_media_id' => $this->zdjeciePrzepisu->getKey(),
        ]);
        // Zapowiedź powstaje tak jak w produkcji; potem autor dopisuje własny
        // tekst do DRUGIEGO wpisu wskazującego ten sam przepis.
        $this->zapowiedz = $wspolnyAutor ? WpisWskazujacyPrzepis::dopisz($this->przepis) : null;
        $this->zapowiedz?->forceFill(['published_at' => now()])->save();
        $this->wpis = Post::factory()->create([
            'author_id' => $this->autorWpisu->getKey(),
            'body' => $tylkoZdjecie ? null : self::TRESC,
            'recipe_id' => $this->przepis->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
        if ($tylkoZdjecie) {
            $this->zdjecieWpisu = Media::factory()->create(['owner_id' => $this->autorWpisu->getKey()]);
            $this->wpis->media()->attach($this->zdjecieWpisu->getKey(), ['position' => 0]);
        }

        $this->tag = Tag::factory()->create(['name' => 'Zupy kwaśne']);
        $this->wpis->tags()->attach($this->tag->getKey(), ['position' => 0]);
        $this->zapowiedz?->tags()->attach($this->tag->getKey(), ['position' => 0]);
    }

    private function ukryjPrzepis(string $jak): void
    {
        match ($jak) {
            'followers', 'private' => $this->przepis->update(['visibility' => $jak]),
            'soft-delete' => $this->przepis->delete(),
            'hidden' => Recipe::query()->whereKey($this->przepis->getKey())->update(['status' => Recipe::STATUS_HIDDEN]),
        };
    }

    private function assertWpisBezPrzepisu(string $html, string $gdzie): void
    {
        $this->assertStringContainsString($this->wlasnaTresc(), $html, $gdzie.': wpis z własną treścią zniknął z listy.');
        $this->assertStringNotContainsString($this->przepis->title, $html, $gdzie.': tytuł przepisu jest na karcie.');
        $this->assertStringNotContainsString($this->przepis->slug, $html, $gdzie.': slug przepisu jest na karcie.');
        $this->assertStringNotContainsString((string) $this->zdjeciePrzepisu->getKey(), $html, $gdzie.': zdjęcie przepisu jest na karcie.');
    }

    /** Lustro `assertWpisBezPrzepisu()`: te same napisy MUSZĄ być na karcie, gdy przepis jest dostępny. */
    private function assertWpisZPrzepisem(string $html, string $gdzie): void
    {
        $this->assertStringContainsString($this->wlasnaTresc(), $html, $gdzie.': brak wpisu z własną treścią.');
        $this->assertStringContainsString($this->przepis->title, $html, $gdzie.': brak tytułu dostępnego przepisu.');
        $this->assertStringContainsString($this->przepis->slug, $html, $gdzie.': brak sluga dostępnego przepisu.');
        $this->assertStringContainsString((string) $this->zdjeciePrzepisu->getKey(), $html, $gdzie.': brak zdjęcia dostępnego przepisu.');
    }

    private function wlasnaTresc(): string
    {
        return $this->zdjecieWpisu !== null ? (string) $this->zdjecieWpisu->getKey() : self::TRESC;
    }

    private function licznikTagu(?User $widz = null): int
    {
        // Liczby spisu siedzą w cache (audyt B4 W2), a zmiana PRZEPISU go nie
        // czyści — świeżość `CACHE_SEKUND` ma własne testy. Tu liczymy zakres.
        LiczbyTagowWCache::zapomnij([(string) $this->tag->getKey()]);
        $odp = $widz === null ? $this->get(route('tags.index')) : $this->actingAs($widz)->get(route('tags.index'));

        return (int) $odp->assertOk()->viewData('tagi')->getCollection()->firstWhere('id', $this->tag->getKey())->posts_count;
    }

    /** @return list<string> */
    private function idWpisow(TestResponse $odpowiedz): array
    {
        return collect($odpowiedz->viewData('posts')->items())->map(fn (Post $p): string => (string) $p->getKey())->all();
    }

    private function assertZapowiedziNieMa(TestResponse $odpowiedz, string $gdzie): void
    {
        $this->assertNotNull($this->zapowiedz);
        $ids = $this->idWpisow($odpowiedz);
        $this->assertContains((string) $this->wpis->getKey(), $ids, $gdzie.': lista nie zawiera wpisu z własną treścią.');
        $this->assertNotContains((string) $this->zapowiedz->getKey(), $ids, $gdzie.': czysta zapowiedź wyszła na listę.');
    }

    #[DataProvider('niedostepnosci')]
    public function test_gosc_widzi_wpis_z_wlasna_trescia_na_odkrywaniu_i_profilu_bez_przepisu(string $jak): void
    {
        $this->przygotuj();
        $this->ukryjPrzepis($jak);

        $odkrywanie = $this->get(route('discover'))->assertOk();
        $this->assertWpisBezPrzepisu($odkrywanie->getContent(), 'Odkrywanie');
        $this->assertZapowiedziNieMa($odkrywanie, 'Odkrywanie');

        $profil = $this->get(route('profile.show', $this->autorWpisu->profile->username))->assertOk();
        $this->assertWpisBezPrzepisu($profil->getContent(), 'Profil');
        $this->assertZapowiedziNieMa($profil, 'Profil');
        $this->assertSame(1, $profil->viewData('stats')['posts'], 'Licznik wpisów liczy tą samą regułą co lista.');
    }

    public function test_obserwujacy_autora_wpisu_widzi_go_w_feedzie_bez_cudzego_prywatnego_przepisu(): void
    {
        $this->przygotuj(wspolnyAutor: false);
        $obserwujacy = $this->user('obserwujacy');
        app(FollowUser::class)->handle($obserwujacy, $this->autorWpisu);
        $this->ukryjPrzepis('private');

        $feed = $this->actingAs($obserwujacy)->get(route('home'))->assertOk()->getContent();

        $this->assertWpisBezPrzepisu($feed, 'Feed obserwowanych');
    }

    /**
     * Blokada autora PRZEPISU (innej osoby niż autor wpisu) zabiera kartę
     * przepisu, ale nie cudzy wpis z własną treścią.
     */
    public function test_blokada_autora_przepisu_zdejmuje_przepis_z_karty_cudzego_wpisu(): void
    {
        $this->przygotuj(wspolnyAutor: false);
        $widz = $this->user('widz');
        app(BlockUser::class)->handle($widz, $this->autorPrzepisu);

        $odkrywanie = $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();
        $this->assertWpisBezPrzepisu($odkrywanie, 'Odkrywanie po blokadzie');

        $profil = $this->actingAs($widz)->get(route('profile.show', $this->autorWpisu->profile->username))->assertOk()->getContent();
        $this->assertWpisBezPrzepisu($profil, 'Profil po blokadzie');
    }

    public function test_kontrola_dodatnia_dostepny_przepis_jest_na_karcie_i_zapowiedz_na_liscie(): void
    {
        $this->przygotuj();

        // „Świeżo z Kuking" pokazuje jeden wpis na autora (#940) — najnowszy,
        // czyli zapowiedź. Wpis z własną treścią sprawdzają tag i profil niżej.
        $odkrywanie = $this->get(route('discover'))->assertOk();
        $this->assertNotNull($this->zapowiedz);
        $this->assertContains((string) $this->zapowiedz->getKey(), $this->idWpisow($odkrywanie));
        $this->assertStringContainsString($this->przepis->title, $odkrywanie->getContent(), 'Odkrywanie: brak tytułu dostępnego przepisu.');

        $tag = $this->get(route('tags.show', $this->tag))->assertOk();
        $this->assertWpisZPrzepisem($tag->getContent(), 'Strona tagu');
        $this->assertContains((string) $this->zapowiedz->getKey(), $this->idWpisow($tag));

        $profil = $this->get(route('profile.show', $this->autorWpisu->profile->username))->assertOk();
        $this->assertWpisZPrzepisem($profil->getContent(), 'Profil');
    }

    /** Recenzja #1377: strona tagu i licznik w spisie tagów — gość i zalogowany bez dostępu. */
    #[DataProvider('niedostepnosci')]
    public function test_strona_tagu_i_licznik_pokazuja_wpis_z_wlasna_trescia_bez_przepisu(string $jak): void
    {
        $this->przygotuj();
        $this->assertSame(2, $this->licznikTagu(), 'Kontrola sceny: przed ukryciem liczą się oba wpisy.');
        $this->ukryjPrzepis($jak);

        $gosc = $this->get(route('tags.show', $this->tag))->assertOk();
        $this->assertWpisBezPrzepisu($gosc->getContent(), 'Strona tagu (gość)');
        $this->assertZapowiedziNieMa($gosc, 'Strona tagu (gość)');
        $this->assertSame(1, $this->licznikTagu(), 'Licznik spisu tagów: wpis z własną treścią się liczy, zapowiedź nie.');

        $obcy = $this->user('obcy');
        $zalogowany = $this->actingAs($obcy)->get(route('tags.show', $this->tag))->assertOk();
        $this->assertWpisBezPrzepisu($zalogowany->getContent(), 'Strona tagu (zalogowany bez dostępu)');
        $this->assertZapowiedziNieMa($zalogowany, 'Strona tagu (zalogowany bez dostępu)');
        $this->assertSame(1, $this->licznikTagu($obcy));
    }

    /** Wpis bez tekstu, tylko z własnym zdjęciem — gałąź `EXISTS post_media` w `zWlasnaTrescia()`. */
    public function test_wpis_z_samym_wlasnym_zdjeciem_zostaje_na_listach_bez_przepisu(): void
    {
        $this->przygotuj(tylkoZdjecie: true);
        $this->ukryjPrzepis('private');

        $odkrywanie = $this->get(route('discover'))->assertOk();
        $this->assertWpisBezPrzepisu($odkrywanie->getContent(), 'Odkrywanie (samo zdjęcie)');
        $this->assertZapowiedziNieMa($odkrywanie, 'Odkrywanie (samo zdjęcie)');

        $tag = $this->get(route('tags.show', $this->tag))->assertOk();
        $this->assertWpisBezPrzepisu($tag->getContent(), 'Strona tagu (samo zdjęcie)');
        $this->assertZapowiedziNieMa($tag, 'Strona tagu (samo zdjęcie)');
    }

    public function test_nawigacja_do_sasiedniego_wpisu_prowadzi_do_wpisu_z_wlasna_trescia(): void
    {
        $this->przygotuj();
        $starszy = Post::factory()->create([
            'author_id' => $this->autorWpisu->getKey(),
            'body' => 'Wcześniejszy obiad.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subDay(),
        ]);
        $sasiedzi = app(SasiedniWpisAutora::class);
        $this->assertTrue($sasiedzi->nastepny($this->wpis, null)?->is($this->zapowiedz), 'Kontrola sceny: zapowiedź jest sąsiadem, póki przepis jest dostępny.');

        $this->ukryjPrzepis('private');

        $this->assertTrue($sasiedzi->nastepny($starszy, null)?->is($this->wpis), 'Nawigacja pominęła wpis z własną treścią.');
        $this->assertNull($sasiedzi->nastepny($this->wpis, null), 'Nawigacja prowadzi do zapowiedzi niedostępnego przepisu.');
    }

    public function test_tablica_bierze_wpis_z_wlasna_trescia_bez_przepisu(): void
    {
        $this->przygotuj();
        $przed = app(DailyBoard::class)->forViewer(null)['posts'];
        $this->assertTrue($przed->contains(fn (Post $p) => $p->is($this->zapowiedz)), 'Kontrola sceny: najnowszy wpis autora to zapowiedź.');

        $this->ukryjPrzepis('private');

        $wpis = app(DailyBoard::class)->forViewer(null)['posts']->first(fn (Post $p) => $p->is($this->wpis));
        $this->assertNotNull($wpis, 'Tablica pominęła wpis z własną treścią.');
        $this->assertNull($wpis->recipe, 'Tablica dostała relację niedostępnego przepisu.');
    }
}

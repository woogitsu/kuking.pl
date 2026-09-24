<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
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
 * znika razem z przepisem (#368/#941).
 *
 * Kontrola dodatnia: `test_kontrola_dodatnia_*` dowodzi, że ta sama asercja
 * „brak tytułu przepisu” widzi tytuł, gdy przepis jest dostępny.
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

    private function przygotuj(bool $wspolnyAutor = true): void
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
        $this->wpis = Post::factory()->create([
            'author_id' => $this->autorWpisu->getKey(),
            'body' => self::TRESC,
            'recipe_id' => $this->przepis->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
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
        $this->assertStringContainsString(self::TRESC, $html, $gdzie.': wpis z własną treścią zniknął z listy.');
        $this->assertStringNotContainsString($this->przepis->title, $html, $gdzie.': tytuł przepisu jest na karcie.');
        $this->assertStringNotContainsString($this->przepis->slug, $html, $gdzie.': slug przepisu jest na karcie.');
        $this->assertStringNotContainsString((string) $this->zdjeciePrzepisu->getKey(), $html, $gdzie.': zdjęcie przepisu jest na karcie.');
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

        $odkrywanie = $this->get(route('discover'))->assertOk();
        $this->assertStringContainsString(self::TRESC, $odkrywanie->getContent());
        $this->assertStringContainsString($this->przepis->title, $odkrywanie->getContent());
        $this->assertNotNull($this->zapowiedz);
        $this->assertContains((string) $this->zapowiedz->getKey(), $this->idWpisow($odkrywanie));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Strona tagu i spis tagów a widoczność PRZEPISU wskazywanego przez wpis
 * (issue #941, D-193).
 *
 * Zapowiedź przepisu (issue #368) jest na stałe `public`, bo widoczność trzyma
 * PRZEPIS. `Post::widoczneDla()` i `publiclyVisible()` jej więc nie odcinają —
 * robi to dopiero `zWidocznymPrzepisem()`. `FeedTagowNiePokazujeCudzegoPrzepisuTest`
 * mierzy na `/tag/{slug}` jeden przypadek („tylko dla obserwujących"); tu stoi
 * każdy pozostały, który ta bramka ma zamykać: prywatny, szkic, ukryty i usunięty
 * przez moderację, miękko skasowany, blokada w obie strony, autor pod sankcją.
 *
 * KAŻDY PRZYPADEK MA KONTROLĘ DODATNIĄ: publiczny wpis innej osoby z tym samym
 * tagiem. Bez niej „nie widać tytułu" przechodziłoby też na pustej liście.
 */
class StronaTaguBramkaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Pierogi z kaszą gryczaną';

    private const KONTROLA = 'Zwykły obiad z tym samym tagiem.';

    /**
     * Każdy przypadek dostaje autorkę, widza i przepis, i sam ustawia to,
     * co ma odciąć zapowiedź.
     *
     * @return array<string, array{0: Closure(User, User, Recipe): void}>
     */
    public static function przypadki(): array
    {
        return [
            'przepis tylko dla mnie' => [fn (User $autorka, User $widz, Recipe $przepis) => $przepis->forceFill(['visibility' => 'private'])->save()],
            'przepis tylko dla obserwujących, widz nie obserwuje' => [fn (User $autorka, User $widz, Recipe $przepis) => $przepis->forceFill(['visibility' => 'followers'])->save()],
            'przepis cofnięty do szkicu' => [fn (User $autorka, User $widz, Recipe $przepis) => $przepis->forceFill(['status' => Recipe::STATUS_DRAFT, 'published_at' => null])->save()],
            'przepis ukryty przez moderację' => [fn (User $autorka, User $widz, Recipe $przepis) => $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save()],
            'przepis usunięty przez moderację' => [fn (User $autorka, User $widz, Recipe $przepis) => $przepis->forceFill(['status' => Recipe::STATUS_REMOVED])->save()],
            'przepis miękko skasowany' => [fn (User $autorka, User $widz, Recipe $przepis) => $przepis->delete()],
            'widz zablokował autorkę' => [fn (User $autorka, User $widz, Recipe $przepis) => app(BlockUser::class)->handle($widz, $autorka)],
            'autorka zablokowała widza' => [fn (User $autorka, User $widz, Recipe $przepis) => app(BlockUser::class)->handle($autorka, $widz)],
            'autorka zawieszona' => [fn (User $autorka, User $widz, Recipe $przepis) => $autorka->forceFill(['status' => User::STATUS_SUSPENDED])->save()],
        ];
    }

    /** @param  Closure(User, User, Recipe): void  $odetnij */
    #[DataProvider('przypadki')]
    public function test_zapowiedz_niewidocznego_przepisu_nie_stoi_na_stronie_tagu(Closure $odetnij): void
    {
        [$tag, $autorka, $widz, $przepis, $zapowiedz, $kontrola] = $this->scena();

        // Kontrola sceny: PRZED odcięciem widz naprawdę widzi zapowiedź.
        // Inaczej przypadek niżej przechodziłby z byle powodu.
        $this->assertContains((string) $zapowiedz->getKey(), $this->idNaStronie($tag, $widz));

        $odetnij($autorka->fresh(), $widz->fresh(), $przepis->fresh());

        $odp = $this->actingAs($widz->fresh())->get(route('tags.show', $tag))->assertOk();
        $idki = $odp->viewData('posts')->getCollection()->map(fn (Post $p) => (string) $p->getKey())->all();

        $this->assertContains((string) $kontrola->getKey(), $idki, 'Strona tagu nie oddała nawet kontrolnego publicznego wpisu.');
        $odp->assertSee(self::KONTROLA, false);

        $this->assertNotContains((string) $zapowiedz->getKey(), $idki, 'Zapowiedź niewidocznego przepisu przeszła do listy na stronie tagu.');
        $odp->assertDontSee(self::TYTUL, false);
        $odp->assertDontSee('Zdjęcie do przepisu: '.self::TYTUL, false);
    }

    public function test_gosc_nie_widzi_zapowiedzi_przepisu_tylko_dla_obserwujacych(): void
    {
        [$tag, , , $przepis, $zapowiedz] = $this->scena();
        $przepis->forceFill(['visibility' => 'followers'])->save();

        $odp = $this->get(route('tags.show', $tag))->assertOk()->assertSee(self::KONTROLA, false);

        $this->assertNotContains(
            (string) $zapowiedz->getKey(),
            $odp->viewData('posts')->getCollection()->map(fn (Post $p) => (string) $p->getKey())->all(),
        );
        $odp->assertDontSee(self::TYTUL, false);
    }

    /**
     * Druga strona granicy: obserwująca widzi przepis „dla obserwujących",
     * autorka widzi własny prywatny. Bez tego „nikt nie widzi" przechodziłoby
     * na bramce, która odcina za dużo.
     */
    public function test_obserwujaca_i_autorka_widza_to_co_im_wolno(): void
    {
        [$tag, $autorka, $widz, $przepis, $zapowiedz] = $this->scena();

        $przepis->forceFill(['visibility' => 'followers'])->save();
        app(FollowUser::class)->handle($widz, $autorka);
        $this->assertContains((string) $zapowiedz->getKey(), $this->idNaStronie($tag, $widz->fresh()));

        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->assertNotContains((string) $zapowiedz->getKey(), $this->idNaStronie($tag, $widz->fresh()));
        $this->assertContains((string) $zapowiedz->getKey(), $this->idNaStronie($tag, $autorka->fresh()));
    }

    /**
     * Spis `/tagi` liczy to, co zobaczy gość — tą samą bramką co
     * `TagPublicStats` i kolaż, więc zapowiedź niedostępnego przepisu
     * liczby nie podnosi, a prawdziwie publiczny wpis tak.
     */
    public function test_spis_tagow_nie_liczy_zapowiedzi_niedostepnego_przepisu(): void
    {
        [$tag, , $widz, $przepis] = $this->scena();

        $this->assertSame(2, $this->licznik($tag, null), 'Kontrola: publiczny przepis i publiczny wpis liczą się oba.');

        $przepis->forceFill(['visibility' => 'followers'])->save();

        $this->assertSame(1, $this->licznik($tag, null), 'Zapowiedź przepisu „tylko dla obserwujących" podniosła licznik w spisie tagów.');
        $this->assertSame(1, $this->licznik($tag, $widz->fresh()), 'Licznik ma być ten sam dla każdego widza (D-087).');

        $przepis->forceFill(['visibility' => 'public', 'status' => Recipe::STATUS_HIDDEN])->save();
        $this->assertSame(1, $this->licznik($tag, null), 'Zapowiedź przepisu ukrytego przez moderację podniosła licznik.');
    }

    private function licznik(Tag $tag, ?User $widz): int
    {
        // Liczby spisu leżą w cache (audyt B4 W2); ten test sprawdza regułę
        // liczenia po każdej zmianie przepisu, nie świeżość cache.
        Cache::flush();

        $odp = $widz === null ? $this->get(route('tags.index')) : $this->actingAs($widz)->get(route('tags.index'));

        return (int) $odp->assertOk()->viewData('tagi')->getCollection()->firstWhere('id', $tag->getKey())->posts_count;
    }

    /** @return list<string> */
    private function idNaStronie(Tag $tag, User $widz): array
    {
        return $this->actingAs($widz)->get(route('tags.show', $tag))->assertOk()
            ->viewData('posts')->getCollection()->map(fn (Post $p) => (string) $p->getKey())->all();
    }

    /** @return array{0: Tag, 1: User, 2: User, 3: Recipe, 4: Post, 5: Post} */
    private function scena(): array
    {
        $tag = Tag::create(['slug' => 'pierogi', 'name' => 'Pierogi', 'normalized_name' => 'pierogi']);

        $autorka = $this->user('basia');
        $widz = $this->user('ola');
        $trzecia = $this->user('kasia');

        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => self::TYTUL, 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: [['text' => 'kasza gryczana']],
            steps: [['instruction' => 'Ulep i ugotuj.']],
            publish: true,
        );
        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $autorka->getKey()])->getKey(),
        ])->save();

        /** @var Post $zapowiedz */
        $zapowiedz = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();
        $zapowiedz->tags()->attach($tag->getKey(), ['position' => 0]);
        $this->assertSame(Post::VISIBILITY_PUBLIC, $zapowiedz->visibility);

        $kontrola = Post::factory()->create([
            'author_id' => $trzecia->getKey(),
            'body' => self::KONTROLA,
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subMinute(),
        ]);
        $kontrola->tags()->attach($tag->getKey(), ['position' => 0]);

        return [$tag, $autorka, $widz, $przepis, $zapowiedz, $kontrola];
    }
}

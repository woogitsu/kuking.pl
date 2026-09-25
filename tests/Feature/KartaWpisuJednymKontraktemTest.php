<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jedna karta wpisu, jeden kontrakt danych — na każdej liście (#1037).
 *
 * Do 24.09.2026 zestaw relacji karty (`author.profile.avatar`, `media`,
 * `recipe:id,title,slug,visibility,hero_media_id`, `recipe.heroMedia`,
 * `tags`) plus liczniki stały ręcznie przepisany w siedmiu zapytaniach.
 * Kopie już się rozjechały (#447, #464, #941), a zeszyt nie ładował tagów,
 * więc ta sama karta była tam bez tematów. Pominięta relacja nie rzuca
 * błędu: Eloquent dociąga ją leniwie (zapytanie na kartę) albo zwraca
 * `null` (karta po cichu bez zdjęcia).
 *
 * Teraz wszystkie listy biorą `Post::scopeDlaKarty()`. Ten plik pilnuje
 * skutku, nie wywołania: na każdej powierzchni ta sama karta ma zdjęcie
 * przepisu, tytuł, plakietkę widoczności, tematy i akcję „Ugotowałem",
 * a `preventLazyLoading()` nie łapie dociągania żadnej z relacji karty.
 *
 * Kontrola ujemna jest w `scripts/kontrole-negatywne-alfa08.py`: mutacja
 * zdejmuje `recipe.heroMedia` albo tagi ze wspólnej listy i ten test oblewa
 * na wszystkich zależnych powierzchniach.
 */
class KartaWpisuJednymKontraktemTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Gołąbki kontraktowe';

    private const TEMAT = 'Obiady kontraktowe';

    /** @var list<string> */
    private array $leniwe = [];

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);

        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function powierzchnie(): array
    {
        return [
            'feed obserwowanych' => ['obserwowani'],
            'feed tagów' => ['tagi'],
            'odkrywanie' => ['odkrywanie'],
            'tablica dnia' => ['tablica'],
            'profil' => ['profil'],
            'strona tagu' => ['tag'],
            'zeszyt' => ['zeszyt'],
        ];
    }

    #[DataProvider('powierzchnie')]
    public function test_ta_sama_karta_na_kazdej_powierzchni_bez_leniwego_dociagania(string $powierzchnia): void
    {
        [$autor, $widz, $tag, $zeszyt] = $this->scena();
        // DWIE KARTY NA KAŻDEJ POWIERZCHNI, I TO JEST WARUNEK POMIARU.
        // `preventLazyLoading()` zgłasza dociąganie tylko modelu wczytanego
        // w kolekcji większej niż jeden — przy jednej karcie brak relacji
        // przechodzi po cichu. Druga autorka jest dla tablicy dnia, która
        // bierze najwyżej jedno danie od osoby; profil ma dwa wpisy autora.
        $druga = $this->user('drugakarty');
        $this->wpisZPrzepisem($autor, $tag, $zeszyt, self::TYTUL);
        $this->wpisZPrzepisem($autor, $tag, $zeszyt, self::TYTUL.' drugie');
        $this->wpisZPrzepisem($druga, $tag, $zeszyt, self::TYTUL.' od drugiej');

        if ($powierzchnia === 'obserwowani') {
            app(FollowUser::class)->handle($widz, $autor);
            app(FollowUser::class)->handle($widz, $druga);
        }
        if ($powierzchnia === 'tagi') {
            $widz->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
        }

        [$adres, $klucz] = $this->adres($powierzchnia, $autor, $tag, $zeszyt);

        $this->lapLeniwe();
        $odpowiedz = $this->actingAs($widz)->get($adres)->assertOk();
        Model::preventLazyLoading(false);

        $this->assertSame([], $this->leniwe, "Karta dociąga relacje leniwie na powierzchni „{$powierzchnia}”.");

        $wpisy = $this->wpisyZWidoku($odpowiedz->viewData($klucz), $powierzchnia);
        // KONTROLA DODATNIA: lista naprawdę oddała co najmniej dwie karty
        // z przepisem — inaczej pomiar leniwego dociągania nic nie znaczy.
        $this->assertGreaterThanOrEqual(2, count($wpisy), "Powierzchnia „{$powierzchnia}” nie oddała dwóch wpisów z przepisem.");
        $wpis = collect($wpisy)->firstWhere('author_id', $autor->getKey());
        $this->assertInstanceOf(Post::class, $wpis);

        foreach (['author', 'media', 'recipe'] as $relacja) {
            $this->assertTrue($wpis->relationLoaded($relacja), "Brak relacji {$relacja} na „{$powierzchnia}”.");
        }
        $this->assertTrue($wpis->author->relationLoaded('profile'));
        $this->assertTrue($wpis->author->profile->relationLoaded('avatar'));
        $this->assertTrue($wpis->recipe->relationLoaded('heroMedia'));
        // `visibility` w selekcie przepisu — bez niej wraca cichy `null`,
        // a karta pisze „publicznie" pod przepisem dla obserwujących (#368).
        $this->assertSame('public', $wpis->recipe->getAttributes()['visibility'] ?? null);
        $this->assertNotNull($wpis->recipe->heroMedia, "Przepis bez zdjęcia głównego na „{$powierzchnia}”.");

        $html = (string) $odpowiedz->getContent();
        $this->assertStringContainsString(e(self::TYTUL), $html);

        if ($powierzchnia === 'tablica') {
            // Kafelek tablicy: bez tematów i bez liczby zapisów, ale ze
            // zdjęciem przepisu (`kuking-board/posts.blade.php`).
            return;
        }

        $this->assertTrue($wpis->relationLoaded('tags'), "Brak tematów na „{$powierzchnia}”.");
        $this->assertArrayHasKey('comments_count', $wpis->getAttributes());
        $this->assertArrayHasKey('zapisow_count', $wpis->getAttributes());
        $this->assertArrayHasKey('czy_zapisany', $wpis->getAttributes());

        $this->assertStringContainsString(e('Zdjęcie do przepisu: '.self::TYTUL), $html);
        $this->assertStringContainsString(e(self::TEMAT), $html);
        $this->assertStringContainsString(route('cooked.create', $wpis->recipe->slug), $html);
        $this->assertStringContainsString('<span>publicznie</span>', $html);
    }

    /** @return array<string, array{0: string}> */
    public static function powierzchnieKosztu(): array
    {
        return [
            'profil' => ['profil'],
            'strona tagu' => ['tag'],
            'zeszyt' => ['zeszyt'],
        ];
    }

    /**
     * Liczba zapytań nie rośnie z liczbą kart wskazujących przepisy.
     * Mierzona CAŁA strona, nie wybrane tabele: brak którejkolwiek relacji
     * karty dokłada zapytanie na wpis i zmienia sumę.
     */
    #[DataProvider('powierzchnieKosztu')]
    public function test_liczba_zapytan_nie_rosnie_z_liczba_kart(string $powierzchnia): void
    {
        [$autor, $widz, $tag, $zeszyt] = $this->scena();
        [$adres, $klucz] = $this->adres($powierzchnia, $autor, $tag, $zeszyt);

        $this->wpisZPrzepisem($autor, $tag, $zeszyt, self::TYTUL.' 0');
        $this->wpisZPrzepisem($autor, $tag, $zeszyt, self::TYTUL.' 1');
        $dwie = $this->zapytania($widz, $adres, $klucz, 2);

        for ($i = 2; $i < 8; $i++) {
            $this->wpisZPrzepisem($autor, $tag, $zeszyt, self::TYTUL.' '.$i);
        }
        $osiem = $this->zapytania($widz, $adres, $klucz, 8);

        $this->assertSame($dwie, $osiem, "„{$powierzchnia}”: {$dwie} zapytań przy 2 kartach, {$osiem} przy 8.");
    }

    private function zapytania(User $widz, string $adres, string $klucz, int $karty): int
    {
        // OBA POMIARY Z PUSTYM CACHE. Strona tagu trzyma liczby i kolaż
        // w cache (`LiczbyTagowWCache`, `TagCollage`), a unieważnienie idzie
        // po zapisie wpisu — zanim test doczepi mu temat. Bez tego pierwszy
        // pomiar płacił za wypełnienie cache, a drugi czytał gotowe wartości
        // (17 zapytań przy 2 kartach, 15 przy 8) i test mierzył cache,
        // nie koszt karty.
        Cache::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $odpowiedz = $this->actingAs($widz)->get($adres)->assertOk();
        $ile = count(DB::getQueryLog());
        DB::disableQueryLog();

        // KONTROLA DODATNIA: zmierzyliśmy stronę z tyloma kartami, ile trzeba.
        $this->assertCount($karty, $odpowiedz->viewData($klucz)->items());
        $this->assertSame($karty, substr_count((string) $odpowiedz->getContent(), '<article class="card post-card">'));

        return $ile;
    }

    private function lapLeniwe(): void
    {
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relacja): void {
            // Tylko relacje czytane przez kartę; inne fragmenty stron mają
            // własne testy kosztu. Bez wyjątku: Laravel doładuje relację,
            // więc strona renderuje się dalej, a my zbieramy listę.
            $karty = match (true) {
                $model instanceof Post => in_array($relacja, ['author', 'media', 'recipe', 'tags'], true),
                $model instanceof Recipe => $relacja === 'heroMedia',
                $model instanceof User => $relacja === 'profile',
                $model instanceof Profile => $relacja === 'avatar',
                default => false,
            };
            if ($karty) {
                $this->leniwe[] = $model::class.'::'.$relacja;
            }
        });
    }

    /** @return array{0: string, 1: string} adres i klucz danych widoku */
    private function adres(string $powierzchnia, User $autor, Tag $tag, Collection $zeszyt): array
    {
        return match ($powierzchnia) {
            'obserwowani', 'tagi' => [route('home'), 'posts'],
            'odkrywanie' => [route('discover'), 'posts'],
            'tablica' => [route('discover'), 'board'],
            'profil' => [route('profile.show', $autor->profile->username), 'posts'],
            'tag' => [route('tags.show', $tag), 'posts'],
            'zeszyt' => [route('collections.show', $zeszyt), 'posts'],
        };
    }

    /** @return list<Post> */
    private function wpisyZWidoku(mixed $dane, string $powierzchnia): array
    {
        $wpisy = $powierzchnia === 'tablica' ? $dane['posts']->all() : $dane->items();

        return array_values(array_filter($wpisy, fn (Post $wpis) => $wpis->recipe_id !== null));
    }

    /** @return array{0: User, 1: User, 2: Tag, 3: Collection} */
    private function scena(): array
    {
        $autor = $this->user('autorkarty');
        $widz = $this->user('widzkarty');
        $tag = Tag::create(['slug' => 'obiady-kontrakt', 'name' => self::TEMAT, 'normalized_name' => 'obiady kontraktowe']);
        $zeszyt = $autor->collections()->create(['name' => 'Zeszyt kontraktu', 'visibility' => 'public']);

        return [$autor, $widz, $tag, $zeszyt];
    }

    private function wpisZPrzepisem(User $autor, Tag $tag, Collection $zeszyt, string $tytul): Post
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'hero_media_id' => Media::factory()->create(['owner_id' => $autor->getKey()])->getKey(),
        ]);
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => null,
        ]);
        $wpis->tags()->attach($tag->getKey(), ['position' => 1]);
        $zeszyt->posts()->attach($wpis->getKey());

        return $wpis;
    }
}

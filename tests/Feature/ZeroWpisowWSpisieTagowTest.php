<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Dodałem wpis, a tag pokazuje 0 wpisów" — zgłoszenie właściciela z #681.
 *
 * CO TU JEST ROZSTRZYGNIĘTE
 * Liczba w spisie tagów jest PRAWIDŁOWA: D-087 liczy wyłącznie wpisy widoczne
 * dla wszystkich, żeby ta sama liczba znaczyła to samo dla każdego widza
 * i dała się policzyć jednym zapytaniem dla całej strony. Wpis prywatny albo
 * „tylko dla obserwujących" do niej nie wchodzi — także wtedy, gdy należy
 * do osoby, która właśnie patrzy.
 *
 * Usterką był BRAK ZDANIA, które to łączy: autor widział swój wpis na stronie
 * tagu i zero w spisie, i nic tego nie tłumaczyło. Ten test pilnuje trzech
 * rzeczy naraz:
 *   1. liczba w spisie NIE rośnie od wpisu niepublicznego (to jest granica
 *      prywatności i nie wolno jej „naprawić", żeby licznik ładniej wyglądał),
 *   2. autor dostaje wyjaśnienie na stronie tagu,
 *   3. nikt inny — gość ani obca zalogowana osoba — nie dowiaduje się
 *      o istnieniu tego wpisu ani nie widzi tego wyjaśnienia.
 *
 * Punkt 3 jest ważniejszy od pozostałych: zdanie tłumaczące licznik nie może
 * stać się kanałem, przez który wycieka informacja „ktoś tu ma coś ukrytego".
 */
class ZeroWpisowWSpisieTagowTest extends TestCase
{
    use RefreshDatabase;

    private const SEKRET = 'Tresc-ktorej-nikt-obcy-nie-moze-zobaczyc-681';

    private function tag(): Tag
    {
        return Tag::create(['slug' => 'sernik', 'name' => 'sernik', 'normalized_name' => 'sernik']);
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function wpis(Tag $tag, User $autor, array $atrybuty = []): Post
    {
        $post = Post::factory()->create(array_merge([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ], $atrybuty));
        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return $post;
    }

    /**
     * Osoba z bazy, nie ta z fabryki: `UserFactory` tworzy profil w
     * `afterCreating`, ale zwrócony obiekt ma już zapamiętaną pustą relację.
     * `actingAs()` na takim obiekcie wywraca układ strony na profilu `null`
     * i test mierzyłby wtedy błąd fikstury, nie zachowanie produktu.
     */
    private function swiezy(User $user): User
    {
        return $user->fresh() ?? $user;
    }

    /** Licznik z karty tego tagu w spisie A–Z, wyłącznie z tej sekcji. */
    private function licznikWSpisie(string $html, string $nazwaTagu): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $karty = $xpath->query('//nav[@aria-label="Wszystkie tagi, alfabetycznie"]//a[contains(concat(" ", normalize-space(@class), " "), " tag-directory-card ")]');
        foreach ($karty as $karta) {
            $tekst = trim(preg_replace('/\s+/', ' ', $karta->textContent ?? ''));
            if (str_starts_with($tekst, $nazwaTagu)) {
                return $tekst;
            }
        }

        return 'KARTY TAGU NIE MA W SPISIE';
    }

    private function liczbaKartWpisow(string $html): int
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return (new DOMXPath($dom))
            ->query('//article[contains(concat(" ", normalize-space(@class), " "), " post-card ")]')
            ->length;
    }

    /** Tekst akapitu wyjaśnienia ze spłaszczonymi odstępami. */
    private function wyjasnienie(string $html): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $wezel = (new DOMXPath($dom))->query('//p[@data-wlasne-niepubliczne]')->item(0);

        return $wezel === null ? 'BRAK WYJAŚNIENIA' : trim(preg_replace('/\s+/u', ' ', $wezel->textContent ?? ''));
    }

    /**
     * Każda widoczność ma własne zdanie (#1392): wpis „tylko dla obserwujących"
     * widzą też obserwujący (`PostPolicy::view()`), więc NIE WOLNO mówić o nim
     * „widzisz tylko Ty" — autor błędnie oceniłby prywatność swojej treści.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string, 3: string}>
     */
    public static function niepubliczneWidocznosci(): array
    {
        return [
            'wpis prywatny' => [
                Post::VISIBILITY_PRIVATE, [],
                'Jeden Twój wpis z tym tagiem widzisz tylko Ty.',
                'obserwują',
            ],
            'wpis tylko dla obserwujących' => [
                Post::VISIBILITY_FOLLOWERS, [],
                'Jeden Twój wpis z tym tagiem widzą tylko osoby, które Cię obserwują, i Ty.',
                'widzisz tylko Ty',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dodatkowe
     */
    #[DataProvider('niepubliczneWidocznosci')]
    public function test_wlasny_niepubliczny_wpis_nie_podnosi_licznika_ale_jest_wyjasniony(string $widocznosc, array $dodatkowe, string $oczekiwane, string $zakazane): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        $this->wpis($tag, $autor, array_merge(['visibility' => $widocznosc, 'body' => self::SEKRET], $dodatkowe));

        // 1. Spis tagów mówi zero — i tak ma być.
        $spis = $this->actingAs($this->swiezy($autor))->get(route('tags.index'))->assertOk()->getContent();
        $this->assertStringContainsString(
            '0 wpisów',
            $this->licznikWSpisie($spis, 'sernik'),
            'Wpis niepubliczny NIE MOŻE podnosić liczby w spisie tagów — to liczba wpisów widocznych dla wszystkich (D-087).',
        );

        // 2. Na stronie tagu autor widzi swój wpis i dostaje wyjaśnienie.
        $strona = $this->actingAs($this->swiezy($autor))->get(route('tags.show', $tag))->assertOk();
        $wyjasnienie = $this->wyjasnienie($strona->getContent());
        $this->assertStringContainsString($oczekiwane, $wyjasnienie, 'Wyjaśnienie musi opisywać faktyczną widoczność wpisu (#1392).');
        $this->assertStringNotContainsString($zakazane, $wyjasnienie, 'Wyjaśnienie opisało widoczność, której ten wpis nie ma (#1392).');
        $this->assertStringContainsString('W spisie tagów liczymy wpisy widoczne dla wszystkich', $wyjasnienie);
        $this->assertSame(
            1,
            $this->liczbaKartWpisow($strona->getContent()),
            'Autor ma widzieć swój własny wpis na stronie tagu — inaczej wyjaśnienie dotyczyłoby czegoś, czego nie widać.',
        );
    }

    public function test_gosc_i_obca_osoba_nie_widza_ani_wpisu_ani_wyjasnienia(): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        $obcy = User::factory()->create();
        $this->wpis($tag, $autor, ['visibility' => Post::VISIBILITY_PRIVATE, 'body' => self::SEKRET]);
        $this->wpis($tag, $autor, ['visibility' => Post::VISIBILITY_FOLLOWERS, 'body' => self::SEKRET]);

        foreach ([null, $obcy] as $widz) {
            $kto = $widz === null ? 'gość' : 'obca zalogowana osoba';
            $odp = $widz === null
                ? $this->get(route('tags.show', $tag))
                : $this->actingAs($this->swiezy($widz))->get(route('tags.show', $tag));

            $html = $odp->assertOk()->getContent();

            $this->assertStringNotContainsString(self::SEKRET, $html, "Prywatny wpis wypłynął przez stronę tagu do: {$kto}.");
            $this->assertStringNotContainsString('widzisz tylko Ty', $html, "Wyjaśnienie licznika pokazało się komuś, kto nie ma tam własnego wpisu: {$kto}.");
            $this->assertStringNotContainsString('które Cię obserwują', $html, "Wyjaśnienie licznika pokazało się komuś, kto nie ma tam własnego wpisu: {$kto}.");
            $this->assertStringNotContainsString('data-wlasne-niepubliczne', $html, "Znacznik wyjaśnienia trafił do: {$kto}.");
            $this->assertSame(0, $this->liczbaKartWpisow($html), "Karta prywatnego wpisu pokazała się: {$kto}.");
            $this->assertStringContainsString('Tu jeszcze nikt nic nie ugotował', $html, "Brak pustego stanu dla: {$kto}.");
        }
    }

    /**
     * Grupa mieszana (#1392): zdanie nie może sugerować, że wszystkie wpisy
     * widzi wyłącznie autor, skoro część widzą też obserwujący.
     */
    public function test_mieszane_wpisy_dostaja_neutralne_wyjasnienie_i_nie_podnosza_licznika(): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        $this->wpis($tag, $autor, ['visibility' => Post::VISIBILITY_PRIVATE]);
        $this->wpis($tag, $autor, ['visibility' => Post::VISIBILITY_FOLLOWERS]);

        $spis = $this->actingAs($this->swiezy($autor))->get(route('tags.index'))->assertOk()->getContent();
        $this->assertStringContainsString('0 wpisów', $this->licznikWSpisie($spis, 'sernik'));

        $html = $this->actingAs($this->swiezy($autor))->get(route('tags.show', $tag))->assertOk()->getContent();
        $wyjasnienie = $this->wyjasnienie($html);

        $this->assertStringContainsString('2 Twoje wpisy z tym tagiem nie są widoczne dla wszystkich.', $wyjasnienie);
        $this->assertStringContainsString('te wpisy się tam nie liczą', $wyjasnienie);
        $this->assertStringNotContainsString('widzisz tylko Ty', $wyjasnienie, 'Część tych wpisów widzą obserwujący — „tylko Ty" byłoby nieprawdą.');
        $this->assertSame(2, $this->liczbaKartWpisow($html));
    }

    /** Liczebnik 5+ wymaga dopełniacza: „5 Twoich wpisów", nie „5 Twoje wpisy". */
    public function test_piec_prywatnych_wpisow_ma_poprawna_odmiane(): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->wpis($tag, $autor, ['visibility' => Post::VISIBILITY_PRIVATE]);
        }

        $html = $this->actingAs($this->swiezy($autor))->get(route('tags.show', $tag))->assertOk()->getContent();

        $this->assertStringContainsString('5 Twoich wpisów z tym tagiem widzisz tylko Ty.', $this->wyjasnienie($html));
    }

    public function test_publiczny_wpis_liczy_sie_i_nie_wywoluje_wyjasnienia(): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        $this->wpis($tag, $autor);

        $spis = $this->actingAs($this->swiezy($autor))->get(route('tags.index'))->assertOk()->getContent();
        $this->assertStringContainsString(
            '1 wpis',
            $this->licznikWSpisie($spis, 'sernik'),
            'Publiczny opublikowany wpis MUSI się liczyć — inaczej test wyżej przechodziłby na zepsutym liczniku.',
        );

        $this->actingAs($this->swiezy($autor))
            ->get(route('tags.show', $tag))
            ->assertOk()
            ->assertDontSee('widzisz tylko Ty', false)
            ->assertDontSee('data-wlasne-niepubliczne', false);
    }

    /**
     * Szkic nie jest wpisem niepublicznym „do wyjaśnienia" — na stronie tagu
     * w ogóle się nie pokazuje (`published()` odcina go przed widocznością),
     * więc nie ma tam czego tłumaczyć. Bez tego przypadku wyjaśnienie mogłoby
     * obiecywać wpis, którego autor na tej stronie nie znajdzie.
     */
    public function test_szkic_nie_pokazuje_sie_i_nie_wywoluje_wyjasnienia(): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        $this->wpis($tag, $autor, ['status' => Post::STATUS_DRAFT, 'published_at' => null, 'body' => self::SEKRET]);

        $html = $this->actingAs($this->swiezy($autor))->get(route('tags.show', $tag))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::SEKRET, $html, 'Szkic nie należy do strony tagu.');
        $this->assertStringNotContainsString('widzisz tylko Ty', $html, 'Nie tłumaczymy licznika wpisem, którego na tej stronie nie ma.');
        $this->assertStringContainsString('Tu jeszcze nikt nic nie ugotował', $html);
    }

    /**
     * Wyjaśnienie mówi o WŁASNYCH wpisach, więc moderator patrzący na cudzy
     * niepubliczny wpis nie może go dostać — ani zobaczyć cudzej treści.
     */
    public function test_moderator_nie_dostaje_wyjasnienia_o_cudzym_wpisie(): void
    {
        $tag = $this->tag();
        $autor = User::factory()->create();
        $moderator = User::factory()->create(['role' => User::ROLE_MODERATOR]);
        $this->wpis($tag, $autor, ['visibility' => Post::VISIBILITY_PRIVATE, 'body' => self::SEKRET]);

        $html = $this->actingAs($this->swiezy($moderator))->get(route('tags.show', $tag))->assertOk()->getContent();

        $this->assertStringNotContainsString('widzisz tylko Ty', $html, 'Wyjaśnienie dotyczy wyłącznie własnych wpisów.');
        $this->assertStringNotContainsString(self::SEKRET, $html, 'Panel moderacji ma swoje ścieżki; strona tagu nie jest jedną z nich.');
    }
}

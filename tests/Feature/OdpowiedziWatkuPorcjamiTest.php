<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Models\Block;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Issue #939: paginacja ograniczała WĄTKI, ale każdy wątek na stronie
 * dociągał WSZYSTKIE odpowiedzi. Nic nie ogranicza, ile razy ta sama osoba
 * odpowie, więc jeden gorący wątek ładował całą rozmowę.
 *
 * Teraz odpowiedzi idą porcjami (`kuking.comments.replies_per_thread`),
 * a dalsze odsłania link „Pokaż dalsze odpowiedzi (N)” — zwykły adres,
 * bez JavaScriptu. Mierzymy zarówno HTML, jak i liczbę modeli odpowiedzi
 * pobranych z bazy — sam HTML nie wykazałby, że baza nie oddała całości.
 */
class OdpowiedziWatkuPorcjamiTest extends TestCase
{
    use RefreshDatabase;

    private const ODPOWIEDZI = 30;

    /** Kolejny wątek w jednym teście dostaje inne nazwy kont. */
    private int $seria = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.comments.replies_per_thread' => 12]);
    }

    private function przepis(): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->user('kucharka939')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
    }

    /**
     * @param  array<string, string>  $gdzie  np. ['recipe_id' => ...]
     * @param  User|null  $jedenAutor  wszystkie odpowiedzi od jednej osoby
     * @param  bool  $remis  wszystkie w tej samej sekundzie — kolejność rozstrzyga `id`
     */
    private function watek(array $gdzie, int $ile, ?User $jedenAutor = null, bool $remis = false): Comment
    {
        $seria = ++$this->seria;
        $korzen = Comment::create($gdzie + [
            'author_id' => $this->user('korzen939_'.$seria)->getKey(),
            'body' => 'KORZEN-939',
            'status' => Comment::STATUS_PUBLISHED,
        ]);
        $korzen->forceFill(['created_at' => now()->subDay()])->save();

        for ($i = 1; $i <= $ile; $i++) {
            $odpowiedz = Comment::create($gdzie + [
                'parent_id' => $korzen->getKey(),
                'author_id' => ($jedenAutor ?? $this->user('odp939_'.$seria.'_'.$i))->getKey(),
                'body' => sprintf('Odpowiedz-939-%03d.', $i),
                'status' => Comment::STATUS_PUBLISHED,
            ]);
            $odpowiedz->forceFill(['created_at' => $remis ? now()->subHour() : now()->subHours(2)->addMinutes($i)])->save();
        }

        return $korzen;
    }

    /** @return list<string> numery odpowiedzi widoczne w HTML */
    private function widoczne(string $html): array
    {
        preg_match_all('/Odpowiedz-939-(\d{3})\./', $html, $m);

        return $m[1];
    }

    private function hrefLinku(string $html, string $tekst): string
    {
        $this->assertMatchesRegularExpression('/href="([^"]+)"[^>]*>'.preg_quote($tekst, '/').'/u', $html, "Brak linku „{$tekst}”.");
        preg_match('/href="([^"]+)"[^>]*>'.preg_quote($tekst, '/').'/u', $html, $m);

        return html_entity_decode($m[1]);
    }

    /** Kontrola dodatnia: krótki wątek pokazuje wszystko i nie ma linku. */
    public function test_kontrola_krotki_watek_pokazuje_wszystkie_odpowiedzi_bez_linku(): void
    {
        $przepis = $this->przepis();
        $this->watek(['recipe_id' => $przepis->getKey()], 3);

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertSame(['001', '002', '003'], $this->widoczne($html));
        $this->assertStringNotContainsString('Pokaż dalsze odpowiedzi', $html);
        $this->assertStringNotContainsString('Pokaż wcześniejsze odpowiedzi', $html);
    }

    /** Jedna osoba odpowiada 30 razy: z bazy wychodzi jedna porcja, nie 30. */
    public function test_przepis_wczytuje_tylko_pierwsza_porcje_odpowiedzi_jednego_autora(): void
    {
        $przepis = $this->przepis();
        $korzen = $this->watek(['recipe_id' => $przepis->getKey()], self::ODPOWIEDZI, $this->user('gadula939'));

        $pobraneOdpowiedzi = 0;
        Event::listen('eloquent.retrieved: '.Comment::class, function (Comment $c) use (&$pobraneOdpowiedzi, $korzen): void {
            if ($c->parent_id === $korzen->getKey()) {
                $pobraneOdpowiedzi++;
            }
        });

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertSame(12, $pobraneOdpowiedzi, 'Baza oddała więcej odpowiedzi niż jedna porcja.');
        $this->assertSame(array_map(fn ($i) => sprintf('%03d', $i), range(1, 12)), $this->widoczne($html));
        $this->assertStringContainsString('Pokaż dalsze odpowiedzi (18)', $html);
    }

    /** Link prowadzi do drugiej i trzeciej porcji; chronologia bez dziur i powtórzeń. */
    public function test_dalsze_porcje_przez_link_bez_javascriptu(): void
    {
        $przepis = $this->przepis();
        $korzen = $this->watek(['recipe_id' => $przepis->getKey()], self::ODPOWIEDZI);

        $pierwsza = (string) $this->get(route('recipes.show', $przepis->slug))->getContent();
        $adres = $this->hrefLinku($pierwsza, 'Pokaż dalsze odpowiedzi (18)');
        $this->assertStringEndsWith('#komentarz-'.$korzen->getKey(), $adres, 'Powrót ma trafić do wątku.');

        $druga = (string) $this->get($adres)->assertOk()->getContent();
        $this->assertSame(array_map(fn ($i) => sprintf('%03d', $i), range(13, 24)), $this->widoczne($druga));
        $this->assertStringContainsString('Pokaż wcześniejsze odpowiedzi', $druga);

        $trzecia = (string) $this->get($this->hrefLinku($druga, 'Pokaż dalsze odpowiedzi (6)'))->assertOk()->getContent();
        $this->assertSame(array_map(fn ($i) => sprintf('%03d', $i), range(25, 30)), $this->widoczne($trzecia));
        $this->assertStringNotContainsString('Pokaż dalsze odpowiedzi', $trzecia);

        // „Wcześniejsze” z drugiej porcji wraca do adresu bez parametrów wątku.
        $wstecz = $this->hrefLinku($druga, 'Pokaż wcześniejsze odpowiedzi');
        $this->assertStringNotContainsString('odpowiedzi=', $wstecz);
    }

    /**
     * Wielu autorów w tej samej sekundzie: porcje rozłączne, razem cały wątek.
     *
     * UCZCIWIE: bez `orderBy('id')` w `Comment::replies()` ten test też
     * przechodzi — mała tabela świeżo po wstawieniu wraca z PostgreSQL
     * w kolejności fizycznej. Pilnuje wyniku, nie wykrywa usunięcia
     * rozstrzygania remisów (to robi dopiero duża, przemieszana tabela).
     */
    public function test_remis_czasu_rozstrzyga_id_porcje_sa_rozlaczne(): void
    {
        $przepis = $this->przepis();
        $korzen = $this->watek(['recipe_id' => $przepis->getKey()], self::ODPOWIEDZI, remis: true);

        $wszystkie = [];
        for ($porcja = 1; $porcja <= 3; $porcja++) {
            $html = (string) $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'watek' => $korzen->getKey(), 'odpowiedzi' => $porcja]))->getContent();
            $wszystkie = array_merge($wszystkie, $this->widoczne($html));
        }

        $this->assertCount(self::ODPOWIEDZI, $wszystkie);
        $this->assertCount(self::ODPOWIEDZI, array_unique($wszystkie), 'Ta sama odpowiedź w dwóch porcjach.');
    }

    /** Wpis i wykonanie idą tą samą ścieżką. */
    public function test_wpis_i_wykonanie_tez_ograniczaja_odpowiedzi(): void
    {
        $wpis = Post::factory()->create([
            'author_id' => $this->user('autorka939')->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
        $this->watek(['post_id' => $wpis->getKey()], self::ODPOWIEDZI);

        $html = (string) $this->get(route('posts.show', $wpis))->assertOk()->getContent();
        $this->assertCount(12, $this->widoczne($html));
        $this->assertStringContainsString('Pokaż dalsze odpowiedzi (18)', $html);

        Comment::query()->forceDelete();

        $wykonanie = CookedEvent::factory()->create(['recipe_id' => $this->przepis()->getKey()]);
        $this->watek(['cooked_event_id' => $wykonanie->getKey()], self::ODPOWIEDZI);

        $html = (string) $this->get(route('cooked.show', $wykonanie))->assertOk()->getContent();
        $this->assertCount(12, $this->widoczne($html));
        $this->assertStringContainsString('Pokaż dalsze odpowiedzi (18)', $html);
    }

    /** Blokada działa w liczniku i w każdej porcji. */
    public function test_blokada_poza_licznikiem_i_porcjami(): void
    {
        $przepis = $this->przepis();
        $zablokowany = $this->user('zablokowany939');
        $korzen = $this->watek(['recipe_id' => $przepis->getKey()], 20, $zablokowany);
        for ($i = 1; $i <= 14; $i++) {
            Comment::create([
                'recipe_id' => $przepis->getKey(),
                'parent_id' => $korzen->getKey(),
                'author_id' => $this->user('inny939_'.$i)->getKey(),
                'body' => 'Widoczna-939-'.$i,
                'status' => Comment::STATUS_PUBLISHED,
            ]);
        }

        $widz = $this->user('widz939');
        Block::create(['blocker_id' => $widz->getKey(), 'blocked_id' => $zablokowany->getKey()]);

        $html = (string) $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertSame([], $this->widoczne($html));
        $this->assertSame(12, preg_match_all('/Widoczna-939-\d+</', $html));
        $this->assertStringContainsString('Pokaż dalsze odpowiedzi (2)', $html);
    }

    /** Powiadomienie o odpowiedzi spoza pierwszej porcji prowadzi do porcji, w której ją widać. */
    public function test_powiadomienie_o_odpowiedzi_prowadzi_do_jej_porcji(): void
    {
        $przepis = $this->przepis();
        $autorWatku = $this->user('autorwatku939');
        $korzen = Comment::create([
            'recipe_id' => $przepis->getKey(),
            'author_id' => $autorWatku->getKey(),
            'body' => 'KORZEN-939',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $publikuj = app(PublishComment::class);
        for ($i = 1; $i <= 14; $i++) {
            $publikuj->handle($this->user('odp939_'.$i), $przepis, sprintf('Odpowiedz-939-%03d.', $i), $korzen);
        }

        $powiadomienia = Notification::query()
            ->where('user_id', $autorWatku->getKey())
            ->where('type', Notification::TYPE_REPLY)
            ->get();
        $this->assertCount(14, $powiadomienia);

        $adresy = Notification::destinationUrls($powiadomienia, $autorWatku);
        foreach ($powiadomienia as $powiadomienie) {
            $odpowiedz = Comment::findOrFail($powiadomienie->data['comment_id']);
            $numer = $this->widoczne($odpowiedz->body)[0];
            $adres = $adresy[(string) $powiadomienie->getKey()];

            $this->assertStringEndsWith('#komentarz-'.$odpowiedz->getKey(), $adres);
            if ((int) $numer <= 12) {
                // Kontrola dodatnia: pierwsza porcja nie dostaje parametrów.
                $this->assertStringNotContainsString('watek=', $adres);

                continue;
            }

            $this->assertStringContainsString('watek='.$korzen->getKey().'&odpowiedzi=2', $adres);
            $html = (string) $this->actingAs($autorWatku)->get($adres)->assertOk()->getContent();
            $this->assertContains($numer, $this->widoczne($html));
            $this->assertStringContainsString('id="komentarz-'.$odpowiedz->getKey().'"', $html);
        }
    }
}

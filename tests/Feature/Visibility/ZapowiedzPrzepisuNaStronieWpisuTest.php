<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Social\Actions\FollowUser;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Wpis wskazujący przepis (#368) na DRODZE 1 — „WIDOK/Policy".
 *
 * CO WYCIEKAŁO
 * `PostPolicy::view()` nie pytała o widoczność PRZEPISU, który wpis
 * zapowiada. Zapowiedź ma `visibility = 'public'` na stałe — nie jako kopię
 * widoczności przepisu, tylko jako brak własnego zawężenia
 * (`WpisWskazujacyPrzepis::dopisz()`) — więc `match ($post->visibility)`
 * przepuszczał ją każdemu.
 *
 * Ratowało to przekierowanie w `PostController::show()`: wpis będący samym
 * wskazaniem przepisu odsyłał na `recipes.show`, a tam bramka działa. Ale
 * `Post::jestSamymPrzepisem()` zwraca `false`, gdy wpis ma choć jeden
 * komentarz — a komentowanie przechodzi przez `PostPolicy::comment()`, czyli
 * przez tę samą, dziurawą `view()`. OBCY SAM WYTWARZAŁ WARUNEK, KTÓRY
 * WYŁĄCZAŁ ZABEZPIECZENIE: wystarczyło skomentować cudzą zapowiedź.
 *
 * ODTWORZONE POMIAREM przed poprawką (przepis `followers`, komentarz od
 * obcej osoby):
 *
 *   [obca osoba]          status: 200  TYTUŁ: true  SLUG: true  ZDJĘCIE: true
 *   [gość niezalogowany]  status: 200  TYTUŁ: true  SLUG: true  ZDJĘCIE: true
 *
 * DLACZEGO TEN PLIK MA POŁOWĘ TESTÓW „NA TAK"
 * `PostPolicy::comment()` woła `view()`, więc źle postawiona bramka nie
 * przecieka — ona ODMAWIA WSZYSTKIM, łącznie z autorem, i wygląda przy tym
 * na udaną poprawkę. Taki błąd w tym repozytorium już był. Testy niżej
 * pilnują drugiej strony: autora, obserwującego, przepisu publicznego
 * i samego komentowania.
 */
class ZapowiedzPrzepisuNaStronieWpisuTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Zupa ogórkowa babci';

    /**
     * Zapowiedź w kształcie, jaki zakłada `kuking:dopisz-wpisy-przepisow`
     * (#368): `body = null`, zero własnych zdjęć, `visibility = 'public'`.
     */
    private function zapowiedz(Recipe $przepis): Post
    {
        return Post::factory()->create([
            'author_id' => $przepis->author_id,
            'recipe_id' => $przepis->getKey(),
            'body' => null,
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);
    }

    private function przepis(string $widocznosc, ?string $status = null): Recipe
    {
        $autor = $this->user('ula');
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);

        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => self::TYTUL,
            'visibility' => $widocznosc,
            'hero_media_id' => $zdjecie->getKey(),
        ] + ($status === null ? [] : ['status' => $status]));
    }

    /** Komentarz zdejmuje z wpisu status „samo wskazanie przepisu". */
    private function skomentuj(Post $wpis, User $kto): Comment
    {
        return Comment::factory()->create([
            'author_id' => $kto->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Robiłam, wyszło znakomicie.',
        ]);
    }

    /**
     * Asercja NIEOBECNOŚCI — sam status 200 niczego nie dowodzi, a wyciek
     * prywatnej treści nie wywala testu (patrz `WidocznoscTestCase`).
     */
    private function assertNicZPrzepisu(string $html, Recipe $przepis, string $opis): void
    {
        $this->assertStringNotContainsString(self::TYTUL, $html, $opis.' — wyciekł TYTUŁ przepisu.');
        $this->assertStringNotContainsString('/przepisy/'.$przepis->slug, $html, $opis.' — wyciekł SLUG przepisu.');
        $this->assertStringNotContainsString('Zdjęcie do przepisu', $html, $opis.' — wyciekło ZDJĘCIE przepisu.');
    }

    // -----------------------------------------------------------------
    // Wyciek: zamknięty
    // -----------------------------------------------------------------

    public function test_obcy_nie_widzi_zapowiedzi_przepisu_dla_obserwujacych_mimo_wlasnego_komentarza(): void
    {
        $przepis = $this->przepis('followers');
        $wpis = $this->zapowiedz($przepis);
        $obcy = $this->user('obca');

        // Warunek, który obcy wytwarzał SAM. Zapisujemy komentarz wprost
        // (nie przez trasę), żeby test odtwarzał stan sprzed poprawki także
        // wtedy, gdy komentowanie jest już — słusznie — zamknięte.
        $this->skomentuj($wpis, $obcy);
        $this->assertFalse($wpis->fresh()->jestSamymPrzepisem(), 'Komentarz nie zdjął statusu „samo wskazanie przepisu" — test nie bada tego, co miał badać.');

        $odpowiedz = $this->actingAs($obcy)->get($wpis->url());

        $this->assertSame(403, $odpowiedz->getStatusCode());
        $this->assertNicZPrzepisu((string) $odpowiedz->getContent(), $przepis, 'Obca osoba');
    }

    public function test_gosc_nie_widzi_zapowiedzi_przepisu_dla_obserwujacych_mimo_cudzego_komentarza(): void
    {
        $przepis = $this->przepis('followers');
        $wpis = $this->zapowiedz($przepis);
        $this->skomentuj($wpis, $this->user('obca'));

        Auth::logout();
        $this->assertGuest();

        $odpowiedz = $this->get($wpis->url());

        $this->assertSame(403, $odpowiedz->getStatusCode());
        $this->assertNicZPrzepisu((string) $odpowiedz->getContent(), $przepis, 'Gość niezalogowany');
    }

    public function test_obcy_nie_widzi_zapowiedzi_przepisu_prywatnego_mimo_wlasnego_komentarza(): void
    {
        $przepis = $this->przepis('private');
        $wpis = $this->zapowiedz($przepis);
        $obcy = $this->user('obca');
        $this->skomentuj($wpis, $obcy);

        $this->actingAs($obcy)->get($wpis->url())->assertStatus(403);
    }

    public function test_obcy_nie_widzi_zapowiedzi_przepisu_zdjetego_przez_moderacje(): void
    {
        $przepis = $this->przepis('public', Recipe::STATUS_HIDDEN);
        $wpis = $this->zapowiedz($przepis);
        $obcy = $this->user('obca');
        $this->skomentuj($wpis, $obcy);

        $this->actingAs($obcy)->get($wpis->url())->assertStatus(403);
    }

    public function test_obcy_nie_moze_skomentowac_zapowiedzi_przepisu_dla_obserwujacych(): void
    {
        $przepis = $this->przepis('followers');
        $wpis = $this->zapowiedz($przepis);
        $obcy = $this->user('obca');

        // TU JEST SEDNO: dopóki `comment` przechodziło, obcy sam otwierał
        // sobie stronę wpisu. Bramka musi paść ZANIM komentarz powstanie.
        $this->assertFalse(Gate::forUser($obcy)->allows('comment', $wpis));

        $this->actingAs($obcy)
            ->post(route('posts.comment', $wpis), ['body' => 'Podrzucę sobie komentarz.'])
            ->assertStatus(403);

        $this->assertSame(0, $wpis->comments()->count());
    }

    // -----------------------------------------------------------------
    // KONTROLA DODATNIA — druga strona. Bez niej „naprawa" mogłaby po
    // prostu odmawiać wszystkim i dalej wyglądać na zieloną.
    // -----------------------------------------------------------------

    public function test_autor_widzi_zapowiedz_wlasnego_przepisu_prywatnego(): void
    {
        $przepis = $this->przepis('private');
        $wpis = $this->zapowiedz($przepis);
        $this->skomentuj($wpis, $przepis->author);

        $html = (string) $this->actingAs($przepis->author)->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString(self::TYTUL, $html, 'Autor stracił dostęp do własnej zapowiedzi — poprawka odmawia za szeroko.');
    }

    public function test_autor_widzi_zapowiedz_przepisu_zdjetego_przez_moderacje(): void
    {
        $przepis = $this->przepis('public', Recipe::STATUS_HIDDEN);
        $wpis = $this->zapowiedz($przepis);
        $this->skomentuj($wpis, $przepis->author);

        $this->assertTrue(Gate::forUser($przepis->author)->allows('view', $wpis));
        $this->actingAs($przepis->author)->get($wpis->url())->assertOk();
    }

    public function test_obserwujacy_widzi_zapowiedz_przepisu_dla_obserwujacych(): void
    {
        $przepis = $this->przepis('followers');
        $wpis = $this->zapowiedz($przepis);
        $obserwujacy = $this->user('obserwujaca');
        app(FollowUser::class)->handle($obserwujacy, $przepis->author);
        $this->skomentuj($wpis, $obserwujacy);

        $html = (string) $this->actingAs($obserwujacy)->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString(self::TYTUL, $html, 'Obserwujący stracił dostęp do zapowiedzi przepisu „tylko dla obserwujących".');
    }

    public function test_zapowiedz_przepisu_publicznego_zostaje_widoczna_dla_wszystkich(): void
    {
        $przepis = $this->przepis('public');
        $wpis = $this->zapowiedz($przepis);
        $obcy = $this->user('obca');
        $this->skomentuj($wpis, $obcy);

        $html = (string) $this->actingAs($obcy)->get($wpis->url())->assertOk()->getContent();
        $this->assertStringContainsString(self::TYTUL, $html);

        Auth::logout();
        $this->assertGuest();
        $html = (string) $this->get($wpis->url())->assertOk()->getContent();
        $this->assertStringContainsString(self::TYTUL, $html);
    }

    public function test_komentowanie_zapowiedzi_przepisu_publicznego_dalej_dziala(): void
    {
        $przepis = $this->przepis('public');
        $wpis = $this->zapowiedz($przepis);
        $obcy = $this->user('obca');

        $this->assertTrue(Gate::forUser($obcy)->allows('comment', $wpis));

        $this->actingAs($obcy)
            ->post(route('posts.comment', $wpis), ['body' => 'Robiłam, wyszło znakomicie.'])
            ->assertRedirect();

        $this->assertSame(1, $wpis->comments()->count());
    }

    public function test_zwykly_wpis_bez_przepisu_nic_nie_traci(): void
    {
        // KONTROLA GRANICY: bramka dotyczy WYŁĄCZNIE wpisów z `recipe_id`.
        $autorka = $this->user('gotujaca');
        $wpis = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'body' => 'Rosół jak u mamy.',
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->get($wpis->url())->assertOk()->assertSee('Rosół jak u mamy.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zgłoszenie treści nie ujawnia, że prywatna treść ISTNIEJE (audyt W7-05, P1).
 *
 * CO BYŁO NIE TAK
 * `ReportController::resolveTarget()` rozwiązywał cel przez `findOrFail` /
 * `firstOrFail` i nigdzie po drodze nie wołał Policy `view`. Skutek był
 * podwójny:
 *
 *   1. ORACLE ISTNIENIA. `GET /zglos/recipe/{slug}` zwracał 404 dla sluga
 *      nieistniejącego i 200 (formularz) dla sluga prywatnego albo szkicu.
 *      Zalogowany mógł więc iterować sluggi i po samym kodzie odpowiedzi
 *      stwierdzać, co istnieje w bazie — niezależnie od widoczności.
 *   2. ZGŁOSZENIE BEZ PRAWA DOSTĘPU. `POST` na taki cel faktycznie TWORZYŁ
 *      wiersz w `reports`, wskazujący na treść, której zgłaszający nie ma
 *      prawa zobaczyć.
 *
 * Ten plik testuje macierz przypadków wzorowaną na kanonicznej tabeli prawdy
 * z `tests/Feature/Visibility/WidocznoscTestCase.php` (autor / obserwujący /
 * obcy / moderator × public / followers / private / szkic), ale przez
 * ENDPOINT ZGŁOSZENIA, nie przez stronę samej treści — to inna droga wycieku
 * i wymaga własnych testów (patrz komentarz w `WidocznoscTestCase`: „widok
 * nie pilnuje listy, lista nie pilnuje wyszukiwarki" — tu dochodzi czwarta
 * droga, zgłoszenie).
 *
 * PUBLICZNA ŚCIEŻKA DSA ART. 16 (`/zglos-nielegalna-tresc`) JEST CELOWO
 * INNA i nie jest tu ruszana — patrz `ZgloszenieNielegalnejTresciTest`.
 */
class ZgloszenieNieUjawniaPrywatnejTresciTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $obserwujacy;

    private User $obcy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka_w705');
        $this->obserwujacy = $this->user('obserwujaca_w705');
        $this->obcy = $this->user('obca_w705');

        app(FollowUser::class)->handle($this->obserwujacy, $this->autor);
    }

    // -----------------------------------------------------------------
    // Droga 1: GET /zglos/... — oracle istnienia.
    // -----------------------------------------------------------------

    public function test_get_na_cudzy_prywatny_przepis_daje_ten_sam_wynik_co_nieistniejacy_slug(): void
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
        ]);

        $prywatny = $this->actingAs($this->obcy)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]));

        $nieistniejacy = $this->actingAs($this->obcy)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => 'nie-ma-takiego-przepisu-'.Str::lower(Str::random(8))]));

        $prywatny->assertNotFound();
        $nieistniejacy->assertNotFound();
        $this->assertSame(
            $nieistniejacy->getStatusCode(),
            $prywatny->getStatusCode(),
            'Prywatny slug i slug, który w ogóle nie istnieje, muszą dawać ten sam status — inaczej kod odpowiedzi sam w sobie jest oracle istnienia.',
        );
    }

    public function test_get_na_cudzy_szkic_przepisu_daje_ten_sam_wynik_co_nieistniejacy_slug(): void
    {
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $this->autor->getKey(),
        ]);

        $szkicowy = $this->actingAs($this->obcy)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $szkic->slug]));

        $nieistniejacy = $this->actingAs($this->obcy)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => 'nie-ma-takiego-przepisu-'.Str::lower(Str::random(8))]));

        $szkicowy->assertNotFound();
        $nieistniejacy->assertNotFound();
        $this->assertSame($nieistniejacy->getStatusCode(), $szkicowy->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Droga 2: POST /zglos/... — zgłoszenie treści bez prawa dostępu.
    // -----------------------------------------------------------------

    public function test_post_na_cudzy_szkic_przepisu_nie_tworzy_zgloszenia(): void
    {
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $this->autor->getKey(),
        ]);

        $this->actingAs($this->obcy)
            ->post(route('reports.store', ['type' => 'recipe', 'id' => $szkic->slug]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_post_na_cudzy_prywatny_wpis_nie_tworzy_zgloszenia(): void
    {
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);

        $this->actingAs($this->obcy)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_post_na_wpis_tylko_dla_obserwujacych_widziany_przez_obcego_nie_tworzy_zgloszenia(): void
    {
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_FOLLOWERS,
        ]);

        $this->actingAs($this->obcy)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_obserwujacy_moze_zglosic_wpis_tylko_dla_obserwujacych(): void
    {
        // Kontrast z testem wyżej: ta sama treść, ale widz, którego widoczność
        // `followers` faktycznie wpuszcza — zgłoszenie ma przejść normalnie.
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_FOLLOWERS,
        ]);

        $this->actingAs($this->obserwujacy)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'])
            ->assertRedirectContains('/zgloszenia/');

        $this->assertDatabaseHas('reports', [
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_komentarz_pod_niewidocznym_wpisem_nie_jest_zglaszalny(): void
    {
        // Komentarz nie ma własnej widoczności — dziedziczy ją po wpisie
        // rodzica (`Comment::subject()`, `CommentPolicy::view()`). Wpis jest
        // prywatny, więc komentarz pod nim ma być równie niedostępny do
        // zgłoszenia jak sam wpis.
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);
        $komentarz = Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'author_id' => $this->obserwujacy->getKey(),
        ]);

        $this->actingAs($this->obcy)
            ->post(route('reports.store', ['type' => 'comment', 'id' => $komentarz->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }

    // -----------------------------------------------------------------
    // Kontrola: ścieżka, która MA dalej działać.
    // -----------------------------------------------------------------

    public function test_zglaszanie_publicznego_przepisu_i_publicznego_wpisu_dziala_jak_dotad(): void
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
        ]);
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($this->obcy)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))
            ->assertOk();

        $this->actingAs($this->obcy)
            ->post(route('reports.store', ['type' => 'recipe', 'id' => $przepis->slug]), ['reason' => 'spam'])
            ->assertRedirectContains('/zgloszenia/');

        $this->actingAs($this->obcy)
            ->get(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]))
            ->assertOk();

        $this->actingAs($this->obcy)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'])
            ->assertRedirectContains('/zgloszenia/');

        $this->assertDatabaseHas('reports', ['target_type' => 'recipe', 'target_id' => $przepis->getKey()]);
        $this->assertDatabaseHas('reports', ['target_type' => 'post', 'target_id' => $wpis->getKey()]);
    }

    public function test_autor_moze_otworzyc_formularz_zgloszenia_wlasnej_prywatnej_tresci(): void
    {
        // Rozstrzygnięcie: `RecipePolicy::view()`/`PostPolicy::view()` wpuszczają
        // właściciela niezależnie od `visibility` — to ISTNIEJĄCY warunek tych
        // Policy, a bramka W7-05 świadomie go NIE zawęża (plan naprawy pkt 2:
        // korzystamy z Policy, nie powtarzamy ani nie zaostrzamy jej warunków).
        // Zgłoszenie własnej treści ma też realny sens produktowy — przejęte
        // konto może publikować coś w czyimś imieniu, a autor musi mieć jak to
        // zgłosić tą samą drogą co każdy inny.
        $przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'private',
        ]);

        $this->actingAs($this->autor)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))
            ->assertOk();

        $this->actingAs($this->autor)
            ->post(route('reports.store', ['type' => 'recipe', 'id' => $przepis->slug]), ['reason' => 'spam'])
            ->assertRedirectContains('/zgloszenia/');

        $this->assertDatabaseHas('reports', [
            'target_type' => 'recipe',
            'target_id' => $przepis->getKey(),
        ]);
    }

    public function test_moderator_nie_widzi_ani_nie_zglasza_cudzego_szkicu_przepisu(): void
    {
        // Do 24.09.2026 ten test nazywał się `test_moderator_widzi_i_zglasza_
        // szkic_przepisu` i utrwalał szerszą regułę: `RecipePolicy::view()`
        // wpuszczała moderatora do KAŻDEGO nieopublikowanego przepisu. Szkic
        // nie jest sprawą moderacyjną — widzi go wyłącznie autor, tak jak
        // szkic wpisu (#1359, audyt AUTHZ-01). Formularz zgłoszenia idzie
        // przez tę samą Policy, więc odmawia tak samo jak strona przepisu:
        // 404, bez zdradzania, że taki szkic istnieje.
        $moderator = $this->moderator();

        $szkicPrzepisu = Recipe::factory()->draft()->create([
            'author_id' => $this->autor->getKey(),
        ]);

        $this->actingAs($moderator)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $szkicPrzepisu->slug]))
            ->assertNotFound();

        $this->actingAs($moderator)
            ->post(route('reports.store', ['type' => 'recipe', 'id' => $szkicPrzepisu->slug]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertDatabaseMissing('reports', ['target_type' => 'recipe', 'target_id' => $szkicPrzepisu->getKey()]);
    }

    public function test_moderator_otwiera_formularz_zgloszenia_przepisu_ukrytego_przez_moderacje(): void
    {
        // Kontrola dodatnia do testu wyżej: odmowa dotyczy SZKICU, nie
        // każdego nieopublikowanego przepisu. Przepis ukryty decyzją
        // moderacji dalej otwiera się obsłudze (#1359).
        $moderator = $this->moderator();

        $ukryty = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'status' => Recipe::STATUS_HIDDEN,
        ]);

        $this->actingAs($moderator)
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $ukryty->slug]))
            ->assertOk();
    }

    public function test_zablokowany_nie_moze_zglosic_publicznego_wpisu_autora_ktory_go_zablokowal(): void
    {
        // Blokada ma pierwszeństwo przed wszystkim innym i działa w OBIE
        // strony (`AGENTS.md` §4, `WidocznoscTestCase`). Bramka zgłoszenia ma
        // ją respektować dokładnie tak samo jak zwykłe wejście na treść.
        app(BlockUser::class)->handle($this->autor, $this->obcy);

        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $this->actingAs($this->obcy)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }
}

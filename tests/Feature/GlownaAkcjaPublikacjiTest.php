<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Główna akcja serwisu — „Co dziś gotujesz?” → zdjęcie + kilka słów →
 * Opublikuj — sprawdzona ZACHOWANIEM, od kafla na Starcie do wpisu
 * w feedzie obserwującej osoby (audyt B7-23).
 *
 * Do 25 września 2026 każdy test `posts.store` wysyłał formularz jako
 * zalogowane, aktywne konto. Nikt nie sprawdzał, że gość i konto
 * zbanowane NIE opublikują niczego — a to jest jedyna rzecz, która
 * w tej akcji chroni innych ludzi. Zawieszenie ma osobny test
 * (`AccountStatusTest::test_zawieszone_konto_nie_opublikuje_wpisu`).
 *
 * Każda próba odmowy liczy też zdjęcia (`media`), nie tylko wpisy: zdjęcia
 * trafiają na dysk PRZED walidacją reszty formularza (PostController::store,
 * „C1”), więc odmowa, która przepuściłaby sam plik, zostawiałaby na
 * dysku zdjęcie od kogoś, kto nie miał prawa go wgrać.
 */
class GlownaAkcjaPublikacjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function formularz(): array
    {
        return [
            'photos' => [UploadedFile::fake()->image('rosol.jpg', 1200, 900)],
            'body' => 'Rosół na niedzielę. Wyszedł złoty.',
            'visibility' => 'public',
        ];
    }

    public function test_od_kafla_na_starcie_do_wpisu_w_feedzie_obserwujacej_osoby(): void
    {
        $basia = $this->user('basia');
        $zosia = $this->user('zosia');
        $zosia->following()->attach($basia->getKey(), ['created_at' => now()]);

        // Start prowadzi do formularza — bez tego kafla główna akcja nie
        // ma wejścia, a reszta testu sprawdzałaby formularz, do którego nikt
        // nie trafi.
        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Co dziś gotujesz?')
            ->assertSee(route('posts.create'), false);

        $this->actingAs($basia)->get(route('posts.create'))
            ->assertOk()
            ->assertSee('action="'.route('posts.store').'"', false)
            ->assertSee('name="photos[]"', false)
            ->assertSee('name="body"', false);

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), $this->formularz());

        $post = Post::query()->sole();
        $odpowiedz->assertRedirect(route('posts.show', $post));

        $this->assertSame($basia->getKey(), $post->author_id);
        $this->assertSame(Post::STATUS_PUBLISHED, $post->status);
        $this->assertSame('Rosół na niedzielę. Wyszedł złoty.', $post->body);
        $this->assertSame(1, $post->media()->count(), 'Zdjęcie jest sednem wpisu — musi być przypięte.');

        $this->actingAs($basia)->get(route('posts.show', $post))
            ->assertOk()
            ->assertSee('Rosół na niedzielę. Wyszedł złoty.');

        // Chronologiczny feed obserwowanych: wpis Basi jest u Zosi.
        $this->actingAs($zosia)->get(route('home'))
            ->assertOk()
            ->assertSee('Rosół na niedzielę. Wyszedł złoty.');
    }

    public function test_gosc_nie_opublikuje_wpisu_i_trafia_do_logowania(): void
    {
        $this->post(route('posts.store'), $this->formularz())
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_zbanowany_w_trakcie_sesji_nie_opublikuje_wpisu(): void
    {
        $basia = $this->user('basia');

        // Sesja już otwarta — ban przychodzi w trakcie, zanim Basia kliknie
        // „Opublikuj”. To ta sytuacja, w której ban kiedyś działał dopiero
        // po wylogowaniu (#39).
        $this->actingAs($basia)->get(route('posts.create'))->assertOk();
        $basia->ban();

        $this->actingAs($basia)->post(route('posts.store'), $this->formularz())
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_konto_oznaczone_do_usuniecia_nie_opublikuje_wpisu(): void
    {
        $basia = $this->user('basia');
        $basia->markForDeletion();

        $this->actingAs($basia)->post(route('posts.store'), $this->formularz())
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('posts', 0);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_kontrola_dodatnia_ten_sam_formularz_od_aktywnego_konta_publikuje(): void
    {
        // Bez tej kontroli trzy odmowy wyżej przeszłyby także wtedy, gdyby
        // formularz był zepsuty dla wszystkich (np. zła nazwa pola zdjęcia).
        $basia = $this->user('basia');
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);

        $this->actingAs($basia)->post(route('posts.store'), $this->formularz())
            ->assertRedirect();

        $this->assertDatabaseCount('posts', 1);
        $this->assertSame(1, Media::query()->where('owner_id', $basia->getKey())->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1018: moderator nie mógł otworzyć wpisu ukrytego przez moderację.
 *
 * `PostPolicy::view()` przy każdym wpisie nieopublikowanym wpuszczała
 * wyłącznie autora, więc przywrócenie i rozstrzygnięcie odwołania szły
 * „w ciemno" — bez zdjęć, wątku i skutku edycji autora. Przepis
 * (`RecipePolicy`) i komentarz (`CommentPolicy`) miały ten wyjątek od dawna.
 *
 * Kontrola ujemna: usunięcie wyjątku `STATUS_HIDDEN` w `PostPolicy::view()`
 * oblewa `test_macierz_statusu_i_widza` (moderator z 2FA / administrator
 * przy `hidden`) i testy zdjęć oraz komentarzy niżej.
 *
 * Podgląd jest tylko do odczytu: przywrócenie samego `view` w `savePost`
 * albo `ReportContent` (zamiast `save`/`report`) oblewa testy zapisu
 * i zgłoszenia ukrytego wpisu — patrz `tests/mutacje/widocznosc.txt`.
 */
class ModeratorWidziUkrytyWpisTest extends TestCase
{
    use RefreshDatabase;

    private const TRESC = 'Pierogi z kaszą po babci — wersja po poprawce autora';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function macierz(): array
    {
        $oczekiwane = [
            Post::STATUS_PUBLISHED => ['autor' => true, 'moderator z 2FA' => true, 'administrator z 2FA' => true, 'moderator bez 2FA' => true, 'obcy' => true, 'gość' => true],
            Post::STATUS_HIDDEN => ['autor' => true, 'moderator z 2FA' => true, 'administrator z 2FA' => true, 'moderator bez 2FA' => false, 'obcy' => false, 'gość' => false],
            // Miękko usunięty — wiązanie trasy go nie znajduje; przywraca się go
            // z panelu (#65), strona wpisu nie jest dla niego rozszerzana.
            Post::STATUS_REMOVED => ['autor' => false, 'moderator z 2FA' => false, 'administrator z 2FA' => false, 'moderator bez 2FA' => false, 'obcy' => false, 'gość' => false],
            // Szkic to wyłącznie sprawa autora — nie ma czego moderować.
            Post::STATUS_DRAFT => ['autor' => true, 'moderator z 2FA' => false, 'administrator z 2FA' => false, 'moderator bez 2FA' => false, 'obcy' => false, 'gość' => false],
        ];

        $wynik = [];
        foreach ($oczekiwane as $status => $widzowie) {
            foreach ($widzowie as $kto => $widzi) {
                $wynik["{$status} / {$kto}"] = [$status, $kto, $widzi];
            }
        }

        return $wynik;
    }

    #[DataProvider('macierz')]
    public function test_macierz_statusu_i_widza(string $status, string $kto, bool $widzi): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, $status);
        $widz = $this->widz($kto, $autor);

        $odpowiedz = ($widz === null ? $this : $this->actingAs($widz))
            ->get(route('posts.show', $wpis->getKey()));

        if ($widzi) {
            $odpowiedz->assertOk()->assertSee(self::TRESC);
        } else {
            $this->assertContains($odpowiedz->status(), [403, 404]);
            $odpowiedz->assertDontSee(self::TRESC);
        }
    }

    public function test_moderator_widzi_znacznik_zdjecia_i_komentarze_ukrytego_wpisu(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);
        $zdjecie = $this->zdjecieWpisu($wpis, $autor);
        $komentarz = $this->komentarz($wpis, 'Robiłam tak samo, wyszły świetnie');
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('posts.show', $wpis->getKey()))
            ->assertOk()
            ->assertSee('Ukryte przez moderację.')
            ->assertSee(self::TRESC)
            ->assertSee($zdjecie->url('feed'), false)
            ->assertSee($komentarz->body)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('name="body"', false);

        $this->actingAs($moderator)
            ->get($zdjecie->url('feed'))
            ->assertStatus(302)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_obcy_i_gosc_nie_widza_zdjecia_ukrytego_wpisu(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);
        $zdjecie = $this->zdjecieWpisu($wpis, $autor);

        $this->get($zdjecie->url('feed'))->assertNotFound();
        $this->actingAs($this->user())->get($zdjecie->url('feed'))->assertNotFound();
    }

    /** Kontrola dodatnia: zdjęcie tego samego wpisu po publikacji widać. */
    public function test_zdjecie_opublikowanego_wpisu_widzi_obcy(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_PUBLISHED);
        $zdjecie = $this->zdjecieWpisu($wpis, $autor);

        $this->actingAs($this->user())->get($zdjecie->url('feed'))->assertStatus(302);
    }

    public function test_moderator_nie_komentuje_ukrytego_wpisu(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);

        $this->actingAs($this->moderator())
            ->post(route('posts.comment', $wpis->getKey()), ['body' => 'Komentarz moderatora'])
            ->assertForbidden();

        $this->assertDatabaseMissing('comments', ['body' => 'Komentarz moderatora']);
    }

    /**
     * Podgląd jest TYLKO do odczytu: `savePost` pytał wyłącznie o `view`,
     * więc moderator z 2FA odkładał cudzy ukryty wpis do własnego zeszytu.
     */
    public function test_moderator_nie_zapisuje_ukrytego_wpisu_do_zeszytu(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);

        $this->actingAs($this->moderator())
            ->post(route('collections.save-post', $wpis->getKey()))
            ->assertForbidden();

        $this->assertDatabaseMissing('collection_items', ['post_id' => $wpis->getKey()]);
    }

    /** `ReportContent` pytał o `view`, więc moderator zgłaszał ukryty wpis. */
    public function test_moderator_nie_zglasza_ukrytego_wpisu(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]))
            ->assertNotFound();

        $this->actingAs($moderator)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertDatabaseMissing('reports', ['target_id' => $wpis->getKey()]);
    }

    /** Bez martwych przycisków (AGENTS.md §5): na podglądzie nie ma ani zapisu, ani zgłoszenia. */
    public function test_podglad_ukrytego_wpisu_nie_pokazuje_zapisu_ani_zgloszenia(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);

        $this->actingAs($this->moderator())
            ->get(route('posts.show', $wpis->getKey()))
            ->assertOk()
            ->assertSee('Otwórz wpis')
            ->assertDontSee(route('collections.save-post', $wpis), false)
            ->assertDontSee('Zapisuję')
            ->assertDontSee(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]), false)
            ->assertDontSee('Zgłoś ten wpis');
    }

    /** Kontrola dodatnia: opublikowany wpis moderator dalej zapisze i zgłosi. */
    public function test_opublikowany_wpis_moderator_zapisuje_i_zglasza(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_PUBLISHED);
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('posts.show', $wpis->getKey()))
            ->assertOk()
            ->assertSee(route('collections.save-post', $wpis), false)
            ->assertSee('Zapisuję')
            ->assertSee(route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]), false)
            ->assertSee('Zgłoś ten wpis');

        $this->actingAs($moderator)
            ->post(route('collections.save-post', $wpis->getKey()))
            ->assertRedirect();
        $this->assertDatabaseHas('collection_items', ['post_id' => $wpis->getKey()]);

        $this->actingAs($moderator)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), ['reason' => 'spam'])
            ->assertRedirect();
        $this->assertDatabaseHas('reports', ['target_id' => $wpis->getKey()]);
    }

    public function test_wglad_moderatora_zostawia_slad_w_dzienniku_a_autora_nie(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);
        $moderator = $this->moderator();

        $this->actingAs($autor)->get(route('posts.show', $wpis->getKey()))->assertOk();
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'moderation.hidden_post_viewed')->count());

        $this->actingAs($moderator)->get(route('posts.show', $wpis->getKey()))->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'moderation.hidden_post_viewed',
            'actor_id' => $moderator->getKey(),
            'subject_type' => 'Post',
            'subject_id' => $wpis->getKey(),
        ]);
    }

    public function test_autor_widzi_znacznik_i_droge_do_odwolania(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);

        $this->actingAs($autor)
            ->get(route('posts.show', $wpis->getKey()))
            ->assertOk()
            ->assertSee('Ten wpis jest ukryty przez moderację.')
            ->assertSee(route('notifications.index'), false)
            ->assertDontSee('Wróć do zgłoszeń');
    }

    /**
     * Wyjątek jest w Policy strony wpisu, nie w listach. Moderator z 2FA
     * nie dostaje ukrytego wpisu z powrotem na profil autora, a gość nie
     * widzi go na profilu ani w mapie strony.
     */
    public function test_listy_nadal_filtruja_ukryty_wpis(): void
    {
        $autor = $this->user('autor');
        $ukryty = $this->wpis($autor, Post::STATUS_HIDDEN);
        $this->wpis($autor, Post::STATUS_PUBLISHED, 'Zupa ogórkowa na co dzień');
        $profil = route('profile.show', $autor->profile->username);

        $this->actingAs($this->moderator())->get($profil)
            ->assertOk()
            ->assertSee('Zupa ogórkowa na co dzień')
            ->assertDontSee(self::TRESC);

        $this->app['auth']->forgetGuards();

        $this->get($profil)->assertOk()->assertSee('Zupa ogórkowa na co dzień')->assertDontSee(self::TRESC);
        $this->get(route('sitemap'))->assertDontSee($ukryty->getKey());
    }

    /** Po edycji autora (#936) moderator widzi wersję, która wróci do publikacji. */
    public function test_moderator_widzi_aktualna_tresc_przed_przywroceniem(): void
    {
        $autor = $this->user('autor');
        $wpis = $this->wpis($autor, Post::STATUS_HIDDEN);
        $wpis->forceFill(['body' => 'Treść po poprawce przed przywróceniem'])->save();

        $this->actingAs($this->moderator())
            ->get(route('posts.show', $wpis->getKey()))
            ->assertOk()
            ->assertSee('Treść po poprawce przed przywróceniem')
            ->assertDontSee(self::TRESC);
    }

    private function widz(string $kto, User $autor): ?User
    {
        return match ($kto) {
            'autor' => $autor,
            'moderator z 2FA' => $this->moderator(),
            'administrator z 2FA' => $this->admin(),
            'moderator bez 2FA' => $this->user(null, ['role' => User::ROLE_MODERATOR]),
            'obcy' => $this->user(),
            'gość' => null,
        };
    }

    private function wpis(User $autor, string $status, string $tresc = self::TRESC): Post
    {
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'status' => $status,
            'published_at' => $status === Post::STATUS_DRAFT ? null : now()->subDay(),
        ]);

        if ($status === Post::STATUS_REMOVED) {
            $wpis->delete();
        }

        return $wpis;
    }

    private function zdjecieWpisu(Post $wpis, User $autor): Media
    {
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return $zdjecie;
    }

    private function komentarz(Post $wpis, string $tresc): Comment
    {
        return Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'author_id' => $this->user()->getKey(),
            'body' => $tresc,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Issue #1396 — odpowiedź pod ukrytym komentarzem głównym przechodziła
 * bramkę zgłoszeń.
 *
 * Strona wpisu ładuje odpowiedzi WYŁĄCZNIE przez widoczne komentarze główne:
 * ukryty korzeń zabiera ze sobą całą gałąź. `CommentPolicy::view()` badała
 * jednak tylko samą odpowiedź, więc `/zglos/comment/{uuid}` odpowiadało 200
 * dla odpowiedzi, której widz w rozmowie nie widzi — oracle istnienia,
 * a do tego zgłoszenie treści, do której zgłaszający nie ma dostępu.
 *
 * Każdy przypadek najpierw sprawdza, że LISTA ukrywa odpowiedź, a potem, że
 * bramka odmawia na obu ścieżkach (`create()` i `store()`) tym samym 404.
 */
class OdpowiedzPodUkrytymKorzeniemTest extends TestCase
{
    use RefreshDatabase;

    public function test_korzen_ukryty_przez_moderacje_zamyka_bramke_dla_odpowiedzi(): void
    {
        [$wpis, $korzen, $odpowiedz, $czytelnik] = $this->watek();
        $korzen->forceFill(['status' => Comment::STATUS_HIDDEN])->save();

        $this->assertBramkaZamknieta($wpis, $odpowiedz, $czytelnik);
    }

    public function test_blokada_widza_z_autorem_korzenia_zamyka_bramke_dla_odpowiedzi(): void
    {
        [$wpis, $korzen, $odpowiedz, $czytelnik] = $this->watek();
        app(BlockUser::class)->handle($czytelnik, $korzen->author);

        $this->assertBramkaZamknieta($wpis, $odpowiedz, $czytelnik->refresh());
    }

    public function test_blokada_w_druga_strone_zamyka_bramke_dla_odpowiedzi(): void
    {
        [$wpis, $korzen, $odpowiedz, $czytelnik] = $this->watek();
        app(BlockUser::class)->handle($korzen->author, $czytelnik);

        $this->assertBramkaZamknieta($wpis, $odpowiedz, $czytelnik->refresh());
    }

    public function test_zbanowany_autor_korzenia_zamyka_bramke_dla_odpowiedzi(): void
    {
        [$wpis, $korzen, $odpowiedz, $czytelnik] = $this->watek();
        $korzen->author->ban();

        $this->assertBramkaZamknieta($wpis, $odpowiedz, $czytelnik);
    }

    public function test_usuniety_korzen_zamyka_bramke_dla_odpowiedzi(): void
    {
        [$wpis, $korzen, $odpowiedz, $czytelnik] = $this->watek();
        $korzen->delete();

        $this->assertBramkaZamknieta($wpis, $odpowiedz, $czytelnik);
    }

    /**
     * KONTROLA DODATNIA: odpowiedź pod widocznym korzeniem dalej jest
     * zgłaszalna na obu ścieżkach. Bez tego „naprawa" wyłączająca zgłaszanie
     * odpowiedzi przeszłaby wszystkie testy powyżej.
     */
    public function test_odpowiedz_pod_widocznym_korzeniem_dalej_jest_zgloszalna(): void
    {
        [$wpis, , $odpowiedz, $czytelnik] = $this->watek();

        $this->assertTrue($this->naLiscie($wpis, $odpowiedz, $czytelnik));

        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $odpowiedz->getKey()]))
            ->assertOk();

        $this->actingAs($czytelnik)
            ->post(route('reports.store', ['type' => 'comment', 'id' => $odpowiedz->getKey()]), ['reason' => 'spam'])
            ->assertRedirectContains('/zgloszenia/');

        $this->assertDatabaseHas('reports', [
            'target_type' => 'comment',
            'target_id' => $odpowiedz->getKey(),
        ]);
    }

    /**
     * Furtki zostają: autor odpowiedzi widzi własną wypowiedź (odwołanie,
     * DSA art. 20), moderator widzi gałąź ukrytą przez moderację. Autor
     * ukrytego korzenia NIE dostaje przez to dostępu do cudzej odpowiedzi.
     */
    public function test_furtki_autora_odpowiedzi_i_moderatora_zostaja(): void
    {
        [, $korzen, $odpowiedz] = $this->watek();
        $korzen->forceFill(['status' => Comment::STATUS_HIDDEN])->save();

        $this->assertTrue(Gate::forUser($odpowiedz->author)->allows('view', $odpowiedz->refresh()));
        $this->assertTrue(Gate::forUser($this->moderator())->allows('view', $odpowiedz));
        $this->assertFalse(Gate::forUser($korzen->author)->allows('view', $odpowiedz));
    }

    /** @return array{0: Post, 1: Comment, 2: Comment, 3: User} */
    private function watek(): array
    {
        $autorWpisu = $this->user('autorkawpisu');
        $autorKorzenia = $this->user('autorkorzenia');
        $autorOdpowiedzi = $this->user('autorodpowiedzi');
        $czytelnik = $this->user('czytelniczka');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $korzen = Comment::create([
            'author_id' => $autorKorzenia->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz glowny watku.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $odpowiedz = Comment::create([
            'author_id' => $autorOdpowiedzi->getKey(),
            'post_id' => $wpis->getKey(),
            'parent_id' => $korzen->getKey(),
            'body' => 'Odpowiedz pod komentarzem glownym.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        return [$wpis, $korzen, $odpowiedz, $czytelnik];
    }

    /** Ta sama droga co `PostController::show()`: widoczne korzenie → ich widoczne odpowiedzi. */
    private function naLiscie(Post $wpis, Comment $odpowiedz, User $widz): bool
    {
        return $wpis->comments()
            ->widoczneDla($widz)
            ->with(['replies' => fn ($q) => $q->widoczneDla($widz)])
            ->get()
            ->flatMap->replies
            ->contains(fn (Comment $c) => $c->getKey() === $odpowiedz->getKey());
    }

    private function assertBramkaZamknieta(Post $wpis, Comment $odpowiedz, User $czytelnik): void
    {
        $this->assertFalse(
            $this->naLiscie($wpis, $odpowiedz, $czytelnik),
            'Test zakłada, że lista ukrywa odpowiedź — jeśli nie ukrywa, sprawdza co innego.',
        );

        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $odpowiedz->getKey()]))
            ->assertNotFound();

        $this->actingAs($czytelnik)
            ->post(route('reports.store', ['type' => 'comment', 'id' => $odpowiedz->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();

        $this->assertSame(0, Report::query()->where('target_id', $odpowiedz->getKey())->count());
    }
}

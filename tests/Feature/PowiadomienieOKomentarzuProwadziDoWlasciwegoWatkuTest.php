<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #759: „Zobacz” przy powiadomieniu o komentarzu/odpowiedzi prowadził
 * wyłącznie do PIERWSZEJ STRONY wątku (`data.url` = `$subject->url()`, bez
 * numeru strony ani kotwicy). Przy wielu stronach rozmowy wskazana wypowiedź
 * nie mieściła się w zwróconym HTML, a powiadomienie było już oznaczone jako
 * przeczytane.
 *
 * `CelPowiadomienia::adresKomentarza()` liczy teraz stronę PO WIDOCZNOŚCI DLA
 * ODBIORCY w chwili kliknięcia (nie po numerze zapisanym przy publikacji)
 * i dokleja kotwicę `#komentarz-{uuid}` wskazującą samą wypowiedź.
 */
class PowiadomienieOKomentarzuProwadziDoWlasciwegoWatkuTest extends TestCase
{
    use RefreshDatabase;

    public function test_zobacz_przy_odpowiedzi_poza_pierwsza_strona_otwiera_strone_z_ta_odpowiedzia(): void
    {
        $w = $this->user('wlascicielwpisu759');
        $wpis = Post::factory()->for($w, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $rozmiarStrony = (int) config('kuking.comments.page_size');
        $this->assertGreaterThan(0, $rozmiarStrony, 'Test zakłada realną paginację.');

        // Wypełniamy pierwszą stronę (i trochę więcej), żeby wątek, w którym
        // padnie odpowiedź, na pewno leżał na DRUGIEJ stronie.
        for ($i = 0; $i < $rozmiarStrony; $i++) {
            Comment::create([
                'author_id' => $this->user('wypelniacz759_'.$i)->getKey(),
                'post_id' => $wpis->getKey(),
                'body' => 'Wypełniacz numer '.$i,
                'status' => Comment::STATUS_PUBLISHED,
                'created_at' => now()->subMinutes($rozmiarStrony - $i + 10),
            ]);
        }

        $korzenNaDrugiejStronie = $this->user('korzendrugastrona759');
        $komentarzGlowny = Comment::create([
            'author_id' => $korzenNaDrugiejStronie->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'KORZEN-NA-DRUGIEJ-STRONIE-759',
            'status' => Comment::STATUS_PUBLISHED,
            'created_at' => now()->subMinutes(5),
        ]);

        // Odpowiedź przez rzeczywistą akcję domenową — ona generuje prawdziwe
        // powiadomienie z comment_id.
        app(PublishComment::class)->handle(
            $this->user('odpowiadajacy759'),
            $wpis,
            'ODPOWIEDZ-KTORA-MUSI-BYC-WIDOCZNA-759',
            $komentarzGlowny,
        );

        $powiadomienie = Notification::query()
            ->where('user_id', $korzenNaDrugiejStronie->getKey())
            ->where('type', Notification::TYPE_REPLY)
            ->firstOrFail();

        $odpowiedzHttp = $this->actingAs($korzenNaDrugiejStronie)
            ->post(route('notifications.open', $powiadomienie));

        // Adres celu ma numer strony i kotwicę konkretnej odpowiedzi.
        $reply = Comment::where('body', 'ODPOWIEDZ-KTORA-MUSI-BYC-WIDOCZNA-759')->firstOrFail();
        $odpowiedzHttp->assertRedirect($wpis->url().'?komentarze=2#komentarz-'.$reply->getKey());

        // I ten adres RZECZYWIŚCIE zawiera odpowiedź w zwróconym HTML.
        $strona = $this->actingAs($korzenNaDrugiejStronie)->get($odpowiedzHttp->headers->get('Location'));
        $strona->assertOk();
        $strona->assertSee('ODPOWIEDZ-KTORA-MUSI-BYC-WIDOCZNA-759');
    }

    /** Kontrola dodatnia: wątek na PIERWSZEJ stronie nie dostaje numeru strony w adresie. */
    public function test_zobacz_przy_komentarzu_na_pierwszej_stronie_nie_dokleja_numeru_strony(): void
    {
        $w = $this->user('wlascicielwpisu759b');
        $wpis = Post::factory()->for($w, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $komentator = $this->user('komentator759b');
        app(PublishComment::class)->handle($komentator, $wpis, 'KOMENTARZ-NA-PIERWSZEJ-STRONIE-759');

        $powiadomienie = Notification::query()
            ->where('user_id', $w->getKey())
            ->where('type', Notification::TYPE_COMMENT)
            ->firstOrFail();

        $komentarz = Comment::where('body', 'KOMENTARZ-NA-PIERWSZEJ-STRONIE-759')->firstOrFail();

        $odpowiedzHttp = $this->actingAs($w)->post(route('notifications.open', $powiadomienie));

        $odpowiedzHttp->assertRedirect($wpis->url().'#komentarz-'.$komentarz->getKey());
    }

    /** To samo pod przepisem — RecipeController ma osobną paginację. */
    public function test_zobacz_przy_odpowiedzi_pod_przepisem_poza_pierwsza_strona(): void
    {
        $autor = $this->user('autorprzepisu759');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        $rozmiarStrony = (int) config('kuking.comments.page_size');

        for ($i = 0; $i < $rozmiarStrony; $i++) {
            Comment::create([
                'author_id' => $this->user('wypelniaczprzepis759_'.$i)->getKey(),
                'recipe_id' => $przepis->getKey(),
                'body' => 'Wypełniacz przepisu numer '.$i,
                'status' => Comment::STATUS_PUBLISHED,
                'created_at' => now()->subMinutes($rozmiarStrony - $i + 10),
            ]);
        }

        $korzen = $this->user('korzenprzepis759');
        $komentarzGlowny = Comment::create([
            'author_id' => $korzen->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => 'KORZEN-PRZEPIS-DRUGA-STRONA-759',
            'status' => Comment::STATUS_PUBLISHED,
            'created_at' => now()->subMinutes(5),
        ]);

        app(PublishComment::class)->handle(
            $this->user('odpowiadajacyprzepis759'),
            $przepis,
            'ODPOWIEDZ-PRZEPIS-WIDOCZNA-759',
            $komentarzGlowny,
        );

        $powiadomienie = Notification::query()
            ->where('user_id', $korzen->getKey())
            ->where('type', Notification::TYPE_REPLY)
            ->firstOrFail();

        $reply = Comment::where('body', 'ODPOWIEDZ-PRZEPIS-WIDOCZNA-759')->firstOrFail();

        $odpowiedzHttp = $this->actingAs($korzen)->post(route('notifications.open', $powiadomienie));

        $odpowiedzHttp->assertRedirect($przepis->url().'?komentarze=2#komentarz-'.$reply->getKey());
    }
}

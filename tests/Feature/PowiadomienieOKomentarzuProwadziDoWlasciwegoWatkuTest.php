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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #759: „Zobacz” przy powiadomieniu o komentarzu/odpowiedzi prowadził
 * wyłącznie do PIERWSZEJ STRONY wątku (`data.url` = `$subject->url()`, bez
 * numeru strony ani kotwicy). Przy wielu stronach rozmowy wskazana wypowiedź
 * nie mieściła się w zwróconym HTML, a powiadomienie było już oznaczone jako
 * przeczytane.
 *
 * `Notification::urlDoKomentarza()` liczy teraz stronę PO WIDOCZNOŚCI DLA
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

    /**
     * „UGOTOWAŁEM" NIE STRONICUJE KOMENTARZY — sam adres z kotwicą, bez
     * numeru strony.
     *
     * `CookedEventController::show()` ładuje wątek w całości, więc numer
     * strony byłby tu wymyślony. Gałąź jest osobna w kodzie i psuje się
     * osobno: policzenie pozycji dla tej treści dałoby adres `?komentarze=2`
     * prowadzący na stronę, której ten kontroler w ogóle nie obsługuje.
     */
    public function test_zobacz_przy_komentarzu_pod_ugotowalem_nie_dokleja_numeru_strony(): void
    {
        $gotujacy = $this->user('gotujacy759u');
        $wykonanie = CookedEvent::factory()->for($gotujacy, 'user')->create();

        $komentator = $this->user('komentator759u');
        app(PublishComment::class)->handle($komentator, $wykonanie, 'KOMENTARZ-POD-UGOTOWALEM-759');

        $powiadomienie = Notification::query()
            ->where('user_id', $gotujacy->getKey())
            ->where('type', Notification::TYPE_COMMENT)
            ->firstOrFail();

        $komentarz = Comment::where('body', 'KOMENTARZ-POD-UGOTOWALEM-759')->firstOrFail();

        $this->actingAs($gotujacy)
            ->post(route('notifications.open', $powiadomienie))
            ->assertRedirect($wykonanie->url().'#komentarz-'.$komentarz->getKey());
    }

    /**
     * KORZEŃ WĄTKU NIEWIDOCZNY DLA ODBIORCY — wracamy do zwykłego adresu
     * treści, NIE zdradzając, gdzie ten wątek leży.
     *
     * Blokada założona po fakcie sprawia, że wątek, do którego odnosi się
     * powiadomienie, przestaje być dla odbiorcy widoczny. Adres z numerem
     * strony i kotwicą mówiłby wtedy, ILE wypowiedzi stoi przed wątkiem,
     * którego ta osoba nie może zobaczyć — a to jest informacja, której
     * przed tą poprawką też nie dostawała.
     */
    public function test_zobacz_przy_niewidocznym_korzeniu_wraca_do_zwyklego_adresu_tresci(): void
    {
        $wlasciciel = $this->user('wlascicielwpisu759z');
        $wpis = Post::factory()->for($wlasciciel, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $komentator = $this->user('komentator759z');
        app(PublishComment::class)->handle($komentator, $wpis, 'KOMENTARZ-PO-BLOKADZIE-759');

        $powiadomienie = Notification::query()
            ->where('user_id', $wlasciciel->getKey())
            ->where('type', Notification::TYPE_COMMENT)
            ->firstOrFail();

        // Kontrola dodatnia: PRZED blokadą adres prowadzi do komentarza.
        $komentarz = Comment::where('body', 'KOMENTARZ-PO-BLOKADZIE-759')->firstOrFail();
        $this->assertSame(
            $wpis->url().'#komentarz-'.$komentarz->getKey(),
            Notification::query()->findOrFail($powiadomienie->getKey())->adresDocelowy(),
        );

        Block::create([
            'blocker_id' => $wlasciciel->getKey(),
            'blocked_id' => $komentator->getKey(),
        ]);

        $this->assertSame(
            $wpis->url(),
            Notification::query()->findOrFail($powiadomienie->getKey())->adresDocelowy(),
            'Adres zdradza położenie wątku, którego odbiorca nie może zobaczyć.',
        );
    }
}

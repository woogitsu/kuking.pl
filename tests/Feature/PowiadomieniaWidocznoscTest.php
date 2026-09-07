<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Notifications\Actions\NotifyUser;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Policies\PostPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Powiadomienie jest migawką zdarzenia z przeszłości — a treść, której
 * dotyczy, mogła w międzyczasie zniknąć, zostać ukryta albo zmienić
 * właściciela statusu konta. `Notification::scopeVisibleTo()` filtrował
 * dotąd WYŁĄCZNIE blokadę; ta klasa testów sprawdza, czy filtruje też
 * resztę granic, które w innych warstwach (Policy, `scopeWidoczneDla`)
 * już obowiązują.
 *
 * Każdy test ma asercję KONTROLNĄ: obok powiadomienia, które ma zniknąć,
 * stoi identyczne powiadomienie od osoby/treści BEZ sankcji — inaczej
 * pusta lista przeszłaby test z zupełnie innego powodu.
 */
class PowiadomieniaWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    private function komentarz(User $autor, Post $post, string $tresc, ?Comment $rodzic = null): Comment
    {
        return Comment::create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
            'parent_id' => $rodzic?->getKey(),
            'body' => $tresc,
            'status' => Comment::STATUS_PUBLISHED,
        ]);
    }

    /**
     * Scenariusz 1: wpis, pod którym K1 skomentował, autor W ustawia
     * później na `private`. K2 odpowiada K1 PRZED zmianą widoczności —
     * K1 dostaje TYPE_REPLY z fragmentem odpowiedzi K2. Po zmianie
     * widoczności K1 nie ma już wstępu do wpisu W (`PostPolicy::view`
     * odmawia — nie jest ani autorem, ani obserwującym), a mimo to lista
     * powiadomień dalej niosła fragment rozmowy spod tego wpisu.
     */
    public function test_odpowiedz_pod_wpisem_ktory_stal_sie_prywatny_znika_z_listy_odbiorcy(): void
    {
        $w = $this->user('autorwpisu');
        $k1 = $this->user('pierwszykomentator');
        $k2 = $this->user('drugikomentator');

        $wpisPrywatniejacy = Post::factory()->for($w, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
        // Przez akcję domenową, nie `Comment::create()` wprost — to ona,
        // a nie test, decyduje, KOMU idzie powiadomienie o odpowiedzi
        // (patrz `PublishComment::handle()`: skoro K1 nie jest właścicielem
        // wpisu, dostaje TYPE_REPLY, a nie TYPE_COMMENT).
        $c1 = app(PublishComment::class)->handle($k1, $wpisPrywatniejacy, 'Pierwszy komentarz K1.');
        app(PublishComment::class)->handle($k2, $wpisPrywatniejacy, 'FRAGMENT-KTORY-MA-ZNIKNAC', $c1);

        // KONTROLA: ta sama para K1/K2, ten sam typ powiadomienia, ale wpis
        // zostaje publiczny. Jeśli test przejdzie także wtedy, gdy ta linia
        // zniknie z odpowiedzi, to lista była pusta z innego powodu.
        $wpisKontrolny = Post::factory()->for($w, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
        $c1k = app(PublishComment::class)->handle($k1, $wpisKontrolny, 'Pierwszy komentarz K1 (kontrola).');
        app(PublishComment::class)->handle($k2, $wpisKontrolny, 'FRAGMENT-KONTROLNY-MA-ZOSTAC', $c1k);

        // Dopiero TERAZ autor chowa pierwszy wpis — powiadomienia już istniały.
        $wpisPrywatniejacy->update(['visibility' => Post::VISIBILITY_PRIVATE]);

        $this->assertFalse(
            (new PostPolicy)->view($k1->fresh(), $wpisPrywatniejacy->fresh()),
            'Test zakłada, że K1 rzeczywiście stracił dostęp do wpisu (inaczej to nie jest ten scenariusz).',
        );

        $odpowiedz = $this->actingAs($k1)->get(route('notifications.index'));

        $odpowiedz->assertDontSee('FRAGMENT-KTORY-MA-ZNIKNAC');
        $odpowiedz->assertSee('FRAGMENT-KONTROLNY-MA-ZOSTAC');

        $this->assertSame(
            1,
            $k1->fresh()->unreadNotificationsCount(),
            'Licznik nieprzeczytanych musi liczyć dokładnie to, co pokazuje lista (tylko kontrolne powiadomienie).',
        );
    }

    /**
     * Scenariusz 2: osoba, która wywołała zdarzenie (tu: ugotowała z przepisu
     * odbiorcy), zostaje potem zbanowana. `UserPolicy::viewProfile()` daje na
     * jej profil 403 (poza moderatorem) — `scopeVisibleTo()` o tej regule
     * w ogóle nie wiedział, więc nazwa i awatar zbanowanej osoby wisiały
     * na liście dalej.
     */
    public function test_powiadomienie_od_zbanowanego_autora_znika_z_listy_i_z_licznika(): void
    {
        $odbiorca = $this->user('odbiorca1');
        $zbanowany = $this->user('zbanowanapozniej', ['display_name' => 'ZBANOWANA-OSOBA']);
        $aktywny = $this->user('aktywnastale', ['display_name' => 'AKTYWNA-OSOBA']);

        // TYPE_COOKED celowo, nie komentarz: odbiorca jest tu zawsze
        // właścicielem przepisu, więc ten test mierzy WYŁĄCZNIE regułę
        // „sprawca zbanowany", bez mieszania jej z regułą o znikniętym
        // komentarzu (ta ma osobne testy niżej).
        $przepis = Recipe::factory()->for($odbiorca, 'author')->create();

        app(NotifyUser::class)->handle(
            recipient: $odbiorca,
            type: Notification::TYPE_COOKED,
            actor: $zbanowany,
            data: ['recipe_id' => $przepis->getKey(), 'recipe_title' => $przepis->title, 'has_photo' => false],
        );

        // KONTROLA: identyczne powiadomienie od osoby, której konto zostaje aktywne.
        app(NotifyUser::class)->handle(
            recipient: $odbiorca,
            type: Notification::TYPE_COOKED,
            actor: $aktywny,
            data: ['recipe_id' => $przepis->getKey(), 'recipe_title' => $przepis->title, 'has_photo' => false],
        );

        $zbanowany->ban();

        $this->assertFalse(
            (new UserPolicy)->viewProfile($odbiorca->fresh(), $zbanowany->fresh()),
            'Test zakłada, że profil zbanowanej osoby faktycznie daje 403.',
        );

        $odpowiedz = $this->actingAs($odbiorca)->get(route('notifications.index'));

        $odpowiedz->assertDontSee('ZBANOWANA-OSOBA');
        $odpowiedz->assertSee('AKTYWNA-OSOBA');

        $this->assertSame(1, $odbiorca->fresh()->unreadNotificationsCount());
    }

    /** To samo co wyżej, ale status `pending_delete` zamiast `banned`. */
    public function test_powiadomienie_od_osoby_oznaczonej_do_usuniecia_znika_z_listy(): void
    {
        $odbiorca = $this->user('odbiorca2');
        $doUsuniecia = $this->user('doskasowania', ['display_name' => 'DO-USUNIECIA-OSOBA']);
        $aktywny = $this->user('aktywna2', ['display_name' => 'AKTYWNA-OSOBA-2']);

        app(NotifyUser::class)->handle(
            recipient: $odbiorca,
            type: Notification::TYPE_FOLLOW,
            actor: $doUsuniecia,
            data: ['username' => $doUsuniecia->profile?->username],
        );
        app(NotifyUser::class)->handle(
            recipient: $odbiorca,
            type: Notification::TYPE_FOLLOW,
            actor: $aktywny,
            data: ['username' => $aktywny->profile?->username],
        );

        $doUsuniecia->markForDeletion();

        $odpowiedz = $this->actingAs($odbiorca)->get(route('notifications.index'));

        $odpowiedz->assertDontSee('DO-USUNIECIA-OSOBA');
        $odpowiedz->assertSee('AKTYWNA-OSOBA-2');
        $this->assertSame(1, $odbiorca->fresh()->unreadNotificationsCount());
    }

    /**
     * Scenariusz 3 (sprawdzenie, nie zakładam usterki): blokada w OBIE
     * strony. Ten mechanizm już istniał w `scopeVisibleTo` — test go
     * potwierdza z asercją kontrolną, nie naprawia.
     */
    public function test_blokada_w_obie_strony_chowa_powiadomienie_z_kontrola(): void
    {
        $ja = $this->user('blokujacyja');
        $zablokowany = $this->user('zablokowanywatek', ['display_name' => 'ZABLOKOWANA-OSOBA']);
        $blokujeMnie = $this->user('blokujacymnie', ['display_name' => 'BLOKUJACA-MNIE-OSOBA']);
        $obcy = $this->user('obcywatek', ['display_name' => 'OBCA-OSOBA']);

        $ja->blocking()->attach($zablokowany->getKey(), ['created_at' => now()]);
        $blokujeMnie->blocking()->attach($ja->getKey(), ['created_at' => now()]);

        foreach ([$zablokowany, $blokujeMnie, $obcy] as $aktor) {
            app(NotifyUser::class)->handle(
                recipient: $ja,
                type: Notification::TYPE_FOLLOW,
                actor: $aktor,
                data: ['username' => $aktor->profile?->username],
            );
        }

        $odpowiedz = $this->actingAs($ja)->get(route('notifications.index'));

        $odpowiedz->assertDontSee('ZABLOKOWANA-OSOBA');
        $odpowiedz->assertDontSee('BLOKUJACA-MNIE-OSOBA');
        $odpowiedz->assertSee('OBCA-OSOBA');
        $this->assertSame(1, $ja->fresh()->unreadNotificationsCount());
    }

    /**
     * Scenariusz 4: komentarz, o którym mówi powiadomienie, zostaje
     * skasowany (soft delete) albo ukryty przez moderację PO utworzeniu
     * powiadomienia. `data.excerpt` jest własną kopią treści, więc sam
     * z siebie nie zauważa zniknięcia oryginału.
     */
    public function test_powiadomienie_o_skasowanym_komentarzu_nie_pokazuje_juz_fragmentu(): void
    {
        $w = $this->user('wlascicielwpisu');
        $k = $this->user('komentujacy');

        $wpis = Post::factory()->for($w, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $skasowany = $this->komentarz($k, $wpis, 'FRAGMENT-SKASOWANEGO-KOMENTARZA');
        app(NotifyUser::class)->handle(
            recipient: $w,
            type: Notification::TYPE_COMMENT,
            actor: $k,
            data: ['comment_id' => $skasowany->getKey(), 'excerpt' => 'FRAGMENT-SKASOWANEGO-KOMENTARZA', 'url' => route('posts.show', $wpis)],
        );

        $zostajacy = $this->komentarz($k, $wpis, 'FRAGMENT-KTORY-ZOSTAJE');
        app(NotifyUser::class)->handle(
            recipient: $w,
            type: Notification::TYPE_COMMENT,
            actor: $k,
            data: ['comment_id' => $zostajacy->getKey(), 'excerpt' => 'FRAGMENT-KTORY-ZOSTAJE', 'url' => route('posts.show', $wpis)],
        );

        // Autor kasuje swój komentarz (soft delete).
        $skasowany->delete();

        $odpowiedz = $this->actingAs($w)->get(route('notifications.index'));

        $odpowiedz->assertDontSee('FRAGMENT-SKASOWANEGO-KOMENTARZA');
        $odpowiedz->assertSee('FRAGMENT-KTORY-ZOSTAJE');
        $this->assertSame(1, $w->fresh()->unreadNotificationsCount());
    }

    /** To samo, ale ukrycie przez moderację (`status = hidden`), nie kasowanie. */
    public function test_powiadomienie_o_komentarzu_ukrytym_przez_moderacje_nie_pokazuje_fragmentu(): void
    {
        $w = $this->user('wlascicielwpisu2');
        $k = $this->user('komentujacy2');

        $wpis = Post::factory()->for($w, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $ukryty = $this->komentarz($k, $wpis, 'FRAGMENT-UKRYTY-PRZEZ-MODERACJE');
        app(NotifyUser::class)->handle(
            recipient: $w,
            type: Notification::TYPE_COMMENT,
            actor: $k,
            data: ['comment_id' => $ukryty->getKey(), 'excerpt' => 'FRAGMENT-UKRYTY-PRZEZ-MODERACJE', 'url' => route('posts.show', $wpis)],
        );

        $widoczny = $this->komentarz($k, $wpis, 'FRAGMENT-KTORY-ZOSTAJE-WIDOCZNY');
        app(NotifyUser::class)->handle(
            recipient: $w,
            type: Notification::TYPE_COMMENT,
            actor: $k,
            data: ['comment_id' => $widoczny->getKey(), 'excerpt' => 'FRAGMENT-KTORY-ZOSTAJE-WIDOCZNY', 'url' => route('posts.show', $wpis)],
        );

        $ukryty->update(['status' => Comment::STATUS_HIDDEN]);

        $odpowiedz = $this->actingAs($w)->get(route('notifications.index'));

        $odpowiedz->assertDontSee('FRAGMENT-UKRYTY-PRZEZ-MODERACJE');
        $odpowiedz->assertSee('FRAGMENT-KTORY-ZOSTAJE-WIDOCZNY');
        $this->assertSame(1, $w->fresh()->unreadNotificationsCount());
    }
}

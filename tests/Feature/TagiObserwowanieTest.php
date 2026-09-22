<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Obserwowanie tagów, strona tagu i feed z nich zbudowany — etap 3/5 D-021
 * (`docs/DECISIONS.md`). Zastępuje odpowiadające testy z
 * `ObserwowanieTematowTest.php`/`TematyWpisowTest.php` (te dwa pliki
 * zostają do usunięcia Tematów w kolejnym etapie — patrz komentarz
 * w `ObserwowanieTematowTest.php`).
 *
 * Onboarding czyta `Tag::promowane()` — listę gospodarza (D-021, „tag
 * promowany"), NIE popularność. Testy tej sekcji dlatego same tworzą
 * promocję (`TagPromotion::create(...)`), a nie tylko tag.
 */
class TagiObserwowanieTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $slug, string $nazwa): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
    }

    private function promowany(string $slug, string $nazwa, int $pozycja = 1): Tag
    {
        $tag = $this->tag($slug, $nazwa);
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $pozycja]);

        return $tag;
    }

    private function wpis(Tag $tag, User $autor, string $tresc): Post
    {
        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
        $post->tags()->attach($tag->getKey(), ['position' => 0]);

        return $post;
    }

    // ---------------------------------------------------------------
    // Onboarding (przeniesione z `ObserwowanieTematowTest`, przepisane na tagi)
    // ---------------------------------------------------------------

    public function test_onboarding_zapisuje_tagi_do_bazy_a_nie_do_sesji(): void
    {
        $zupy = $this->promowany('zupy', 'Zupy');
        $ciasta = $this->promowany('ciasta', 'Ciasta', 2);
        $basia = $this->user('basia');

        // Ten sam wymóg co przy Temacie: odpowiedź NIE MOŻE ginąć do sesji —
        // to jedyny moment, w którym człowiek chętnie odpowiada na pytania
        // o siebie, i decyduje o tym, czy jego pierwszy feed będzie pusty.
        $this->actingAs($basia)
            ->post(route('onboarding.interests'), ['tags' => [$zupy->getKey()]])
            ->assertRedirect(route('onboarding.people'));

        $this->assertTrue($basia->fresh()->isFollowingTag($zupy));
        $this->assertFalse($basia->fresh()->isFollowingTag($ciasta));

        $this->actingAs($basia)->get(route('onboarding.done'))->assertOk();
        $this->assertTrue($basia->fresh()->isFollowingTag($zupy));
    }

    public function test_pominiecie_kroku_nie_blokuje_onboardingu(): void
    {
        $this->promowany('zupy', 'Zupy');

        $this->actingAs($this->user('basia'))
            ->post(route('onboarding.interests'), [])
            ->assertRedirect(route('onboarding.people'));
    }

    public function test_onboarding_pokazuje_tylko_tagi_promowane(): void
    {
        $this->promowany('zupy', 'Zupy');
        $this->tag('sernik', 'Sernik'); // zwykły tag, NIE promowany

        $html = $this->actingAs($this->user('basia'))
            ->get(route('onboarding.interests'))->assertOk()->getContent();

        $this->assertStringContainsString('Zupy', $html);
        $this->assertStringNotContainsString('Sernik', $html);
    }

    public function test_niewybrany_promowany_tag_nie_da_sie_podstawic(): void
    {
        // Zwykły tag (nie z listy promowanej) podstawiony w żądaniu nie ma
        // prawa zostać zaobserwowany przez onboarding — ten sam mechanizm co
        // `Topic::doWyboru()` w starym onboardingu.
        $poza = $this->tag('sernik', 'Sernik');
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('onboarding.interests'), ['tags' => [$poza->getKey()]])
            ->assertRedirect(route('onboarding.people'));

        $this->assertFalse($basia->fresh()->isFollowingTag($poza));
    }

    public function test_onboarding_bez_promowanych_tagow_pokazuje_zachete_do_pominiecia(): void
    {
        // Gospodarz jeszcze niczego nie promował — pusta siatka
        // checkboxów wyglądałaby jak błąd, nie jak krok do pominięcia.
        $this->actingAs($this->user('basia'))
            ->get(route('onboarding.interests'))
            ->assertOk()
            ->assertSee('Dalej', false);
    }

    // ---------------------------------------------------------------
    // Obserwowanie ze strony tagu
    // ---------------------------------------------------------------

    public function test_tag_da_sie_zaobserwowac_i_odobserwowac_bez_javascriptu(): void
    {
        $tag = $this->tag('zupy', 'Zupy');
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('tags.follow', $tag))->assertRedirect();
        $this->assertTrue($basia->fresh()->isFollowingTag($tag));

        $this->actingAs($basia)->delete(route('tags.unfollow', $tag))->assertRedirect();
        $this->assertFalse($basia->fresh()->isFollowingTag($tag));
    }

    public function test_dwukrotne_klikniecie_obserwuj_nie_wywala_bledu(): void
    {
        $tag = $this->tag('zupy', 'Zupy');
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('tags.follow', $tag))->assertRedirect();
        $this->actingAs($basia)->post(route('tags.follow', $tag))->assertRedirect();

        $this->assertSame(1, DB::table('tag_follows')->where('user_id', $basia->getKey())->count());
    }

    public function test_scalonego_tagu_nie_da_sie_zaczac_obserwowac(): void
    {
        $kanoniczny = $this->tag('sernik', 'Sernik');
        $scalony = $this->tag('serniki', 'Serniki');
        $scalony->forceFill(['status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $kanoniczny->getKey()])->save();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('tags.follow', $scalony))->assertRedirect();

        $this->assertFalse($basia->fresh()->isFollowingTag($scalony));
    }

    public function test_gosc_nie_obserwuje_tagow(): void
    {
        $tag = $this->tag('zupy', 'Zupy');

        $this->post(route('tags.follow', $tag))->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('tag_follows')->count());
    }

    // ---------------------------------------------------------------
    // Strona tagu
    // ---------------------------------------------------------------

    public function test_strona_tagu_pokazuje_wpisy_z_tego_tagu(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $ciasta = $this->tag('ciasta', 'Ciasta');
        $autor = $this->user('basia');

        $this->wpis($zupy, $autor, 'Rosół na niedzielę');
        $this->wpis($ciasta, $autor, 'Sernik na sobotę');

        $this->get(route('tags.show', $zupy))
            ->assertOk()
            ->assertSee('Rosół na niedzielę')
            ->assertDontSee('Sernik na sobotę');
    }

    public function test_strona_tagu_jest_otwarta_dla_gosci(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $this->wpis($zupy, $this->user('basia'), 'Rosół na niedzielę');

        $this->get(route('tags.show', $zupy))->assertOk()->assertSee('Rosół na niedzielę');
    }

    public function test_tag_nie_omija_ustawien_prywatnosci(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $autor = $this->user('basia');
        $obcy = $this->user('obcy');

        foreach (['public' => 'Widoczny dla wszystkich',
            'followers' => 'Tylko dla obserwujących',
            'private' => 'Tylko dla mnie'] as $widocznosc => $tresc) {
            $post = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'body' => $tresc,
                'status' => Post::STATUS_PUBLISHED,
                'visibility' => $widocznosc,
                'published_at' => now(),
            ]);
            $post->tags()->attach($zupy->getKey(), ['position' => 0]);
        }

        // Strona tagu zbiera wpisy WIELU autorów naraz — skopiowanie filtra
        // z profilu przepuściłoby wpisy „tylko dla obserwujących" od osób,
        // których widz nie obserwuje.
        $this->actingAs($obcy)
            ->get(route('tags.show', $zupy))
            ->assertOk()
            ->assertSee('Widoczny dla wszystkich')
            ->assertDontSee('Tylko dla obserwujących')
            ->assertDontSee('Tylko dla mnie');
    }

    public function test_obserwujacy_widzi_wpis_dla_obserwujacych(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $autor = $this->user('basia');
        $obserwujacy = $this->user('marek');

        DB::table('follows')->insert([
            'follower_id' => $obserwujacy->getKey(),
            'followed_id' => $autor->getKey(),
            'created_at' => now(),
        ]);

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Tylko dla obserwujących',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'followers',
            'published_at' => now(),
        ]);
        $post->tags()->attach($zupy->getKey(), ['position' => 0]);

        $this->actingAs($obserwujacy)
            ->get(route('tags.show', $zupy))
            ->assertOk()
            ->assertSee('Tylko dla obserwujących');
    }

    public function test_zablokowana_osoba_nie_wyplywa_przez_tag(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $nieprzyjemny = $this->user('nieprzyjemny');
        $basia = $this->user('basia');

        $this->wpis($zupy, $nieprzyjemny, 'Wpis od zablokowanego');

        DB::table('blocks')->insert([
            'blocker_id' => $basia->getKey(),
            'blocked_id' => $nieprzyjemny->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($basia)
            ->get(route('tags.show', $zupy))
            ->assertOk()
            ->assertDontSee('Wpis od zablokowanego');
    }

    public function test_scalony_tag_przekierowuje_do_kanonicznego(): void
    {
        $kanoniczny = $this->tag('sernik', 'Sernik');
        $scalony = $this->tag('serniki', 'Serniki');
        $scalony->forceFill(['status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $kanoniczny->getKey()])->save();

        $this->get(route('tags.show', $scalony))->assertRedirect(route('tags.show', $kanoniczny));
    }

    public function test_ukryty_tag_nie_ma_publicznej_strony(): void
    {
        $tag = $this->tag('do-usuniecia', 'Do usunięcia');
        $tag->forceFill(['status' => Tag::STATUS_HIDDEN])->save();

        $this->get(route('tags.show', $tag))->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Feed
    // ---------------------------------------------------------------

    public function test_feed_pokazuje_wpisy_z_obserwowanych_tagow(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $ciasta = $this->tag('ciasta', 'Ciasta');
        $obcy = $this->user('obcy');
        $basia = $this->user('basia');

        $this->wpis($zupy, $obcy, 'Rosol na niedziele');
        $this->wpis($ciasta, $obcy, 'Sernik na sobote');

        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $odpowiedz = $this->actingAs($basia)->get(route('home'))->assertOk();

        $odpowiedz->assertViewHas('zrodloFeedu', 'tagi');
        $trescFeedu = $odpowiedz->viewData('posts')->pluck('body');

        $this->assertContains('Rosol na niedziele', $trescFeedu);
        $this->assertNotContains('Sernik na sobote', $trescFeedu);
        $odpowiedz->assertSee('To wpisy z tagów, które obserwujesz.', escape: false);
    }

    public function test_wpis_z_dwoma_obserwowanymi_tagami_wystepuje_w_feedzie_raz(): void
    {
        // TO JEST NAJWAŻNIEJSZY TEST W TYM PLIKU (SPEC §1.9): ten sam wpis
        // nie może wystąpić kilka razy dlatego, że ma kilka obserwowanych
        // tagów. `whereHas()` w `TagFeed` sprawdza ISTNIENIE dopasowania
        // (EXISTS), nie robi JOIN-a — gdyby robił, ten wpis wypłynąłby
        // na liście dwa razy.
        $zupy = $this->tag('zupy', 'Zupy');
        $rozgrzewajace = $this->tag('rozgrzewajace', 'Rozgrzewające');
        $obcy = $this->user('obcy');
        $basia = $this->user('basia');

        $post = Post::factory()->create([
            'author_id' => $obcy->getKey(),
            'body' => 'Zupa na dwa tagi',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
        $post->tags()->attach([$zupy->getKey() => ['position' => 0], $rozgrzewajace->getKey() => ['position' => 1]]);

        $basia->followedTags()->attach([$zupy->getKey(), $rozgrzewajace->getKey()], ['created_at' => now()]);

        $odpowiedz = $this->actingAs($basia)->get(route('home'))->assertOk();
        $trescFeedu = $odpowiedz->viewData('posts')->pluck('body')->all();

        $this->assertSame(['Zupa na dwa tagi'], $trescFeedu, 'Wpis z dwoma obserwowanymi tagami wystąpił więcej niż raz.');
    }

    public function test_wpisy_obserwowanych_ludzi_sa_wazniejsze_niz_tagi(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $obcy = $this->user('obcy');
        $znajoma = $this->user('znajoma');
        $basia = $this->user('basia');

        $this->wpis($zupy, $obcy, 'Wpis z tagu');
        Post::factory()->create([
            'author_id' => $znajoma->getKey(),
            'body' => 'Wpis od znajomej',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);
        DB::table('follows')->insert([
            'follower_id' => $basia->getKey(),
            'followed_id' => $znajoma->getKey(),
            'created_at' => now(),
        ]);

        $odpowiedz = $this->actingAs($basia)->get(route('home'))->assertOk();

        $odpowiedz->assertViewHas('zrodloFeedu', 'obserwowani');
        $trescFeedu = $odpowiedz->viewData('posts')->pluck('body');

        $this->assertContains('Wpis od znajomej', $trescFeedu);
        $this->assertNotContains('Wpis z tagu', $trescFeedu);
    }

    public function test_tag_bez_wpisow_nie_daje_pustego_ekranu(): void
    {
        $pusty = $this->tag('wedzenie', 'Wędzenie');
        $basia = $this->user('basia');
        $basia->followedTags()->attach($pusty->getKey(), ['created_at' => now()]);

        Post::factory()->create([
            'author_id' => $this->user('obcy')->getKey(),
            'body' => 'Cokolwiek swiezego',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Cokolwiek swiezego');
    }

    public function test_tag_w_feedzie_nie_omija_prywatnosci_ani_blokady(): void
    {
        $zupy = $this->tag('zupy', 'Zupy');
        $autor = $this->user('autor');
        $nieprzyjemny = $this->user('nieprzyjemny');
        $basia = $this->user('basia');

        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $this->wpis($zupy, $autor, 'Widoczny dla wszystkich');

        $prywatny = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Tylko dla obserwujacych',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'followers',
            'published_at' => now(),
        ]);
        $prywatny->tags()->attach($zupy->getKey(), ['position' => 0]);

        $this->wpis($zupy, $nieprzyjemny, 'Wpis od zablokowanego');

        DB::table('blocks')->insert([
            'blocker_id' => $basia->getKey(),
            'blocked_id' => $nieprzyjemny->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Widoczny dla wszystkich')
            ->assertDontSee('Tylko dla obserwujacych')
            ->assertDontSee('Wpis od zablokowanego');
    }

    // ---------------------------------------------------------------
    // Ustawienia
    // ---------------------------------------------------------------

    public function test_ekran_twoje_tagi_zapisuje_wybor(): void
    {
        $zupy = $this->promowany('zupy', 'Zupy');
        $ciasta = $this->promowany('ciasta', 'Ciasta', 2);
        $basia = $this->user('basia');
        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);

        $form = $this->actingAs($basia)->get(route('settings.tags'))->viewData('formScope');
        $this->actingAs($basia)
            ->put(route('settings.tags.update'), ['form_scope' => $form, 'tags' => [$ciasta->getKey()]])
            ->assertRedirect(route('settings.tags'));

        $this->assertFalse($basia->fresh()->isFollowingTag($zupy));
        $this->assertTrue($basia->fresh()->isFollowingTag($ciasta));
    }

    public function test_zapis_ustawien_nie_gubi_daty_obserwowania(): void
    {
        $zupy = $this->promowany('zupy', 'Zupy');
        $basia = $this->user('basia');

        $dawno = now()->subMonths(3);
        $basia->followedTags()->attach($zupy->getKey(), ['created_at' => $dawno]);

        $form = $this->actingAs($basia)->get(route('settings.tags'))->viewData('formScope');
        $this->actingAs($basia)
            ->put(route('settings.tags.update'), ['form_scope' => $form, 'tags' => [$zupy->getKey()]])
            ->assertRedirect();

        $wiersz = DB::table('tag_follows')
            ->where('user_id', $basia->getKey())
            ->where('tag_id', $zupy->getKey())
            ->first();

        $this->assertNotNull($wiersz);
        $this->assertSame(
            $dawno->format('Y-m-d'),
            substr((string) $wiersz->created_at, 0, 10),
            'Zapis formularza przepisał datę obserwowania tagu.',
        );
    }

    public function test_ekran_pozwala_odznaczyc_tag_spoza_listy_promowanej(): void
    {
        // TO JEST NAJWAŻNIEJSZY TEST TEJ SEKCJI. Wszechświat tagów nie jest
        // zamknięty (w odróżnieniu od Tematu) — ktoś mógł zacząć obserwować
        // tag ze strony `/tag/{slug}`, którego nie ma na liście promowanej.
        // Ekran ustawień musi pokazać TAKI tag, żeby dało się go odznaczyć.
        $spozaListy = $this->tag('sernik', 'Sernik'); // NIE promowany
        $basia = $this->user('basia');
        $basia->followedTags()->attach($spozaListy->getKey(), ['created_at' => now()]);

        $html = $this->actingAs($basia)->get(route('settings.tags'))->assertOk()->getContent();
        $this->assertStringContainsString('Sernik', $html);

        $form = $this->get(route('settings.tags'))->viewData('formScope');
        $this->actingAs($basia)
            ->put(route('settings.tags.update'), ['form_scope' => $form, 'tags' => []])
            ->assertRedirect();

        $this->assertFalse($basia->fresh()->isFollowingTag($spozaListy));
    }
}

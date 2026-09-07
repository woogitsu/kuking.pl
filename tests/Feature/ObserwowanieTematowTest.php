<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Obserwowanie tematów i feed z nich zbudowany — część druga issue #31.
 *
 * PO CO TO ISTNIEJE
 * Nowe konto nikogo nie obserwuje, więc feed obserwowanych jest z definicji
 * pusty. Pusty ekran dla kogoś po sześćdziesiątce znaczy „to nie jest dla
 * mnie" — i taka osoba nie wraca.
 *
 * Tematy wybrane w onboardingu są jedyną rzeczą, którą o kimś wiemy
 * w pierwszej minucie. Do tej pory ta odpowiedź szła DO SESJI i ginęła
 * na końcu onboardingu.
 */
class ObserwowanieTematowTest extends TestCase
{
    use RefreshDatabase;

    private function temat(string $slug, string $nazwa, bool $aktywny = true): Topic
    {
        $temat = Topic::create(['slug' => $slug, 'name' => $nazwa, 'position' => 1]);

        if (! $aktywny) {
            $temat->is_active = false;
            $temat->save();
        }

        return $temat;
    }

    private function wpis(Topic $temat, User $autor, string $tresc): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'topic_id' => $temat->getKey(),
            'body' => $tresc,
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // Onboarding — PRZENIESIONE DO TAGÓW (D-021, etap 3/5)
    //
    // `OnboardingController::interests()`/`saveInterests()` czytają dziś
    // `Tag::promowane()`, nie `Topic::doWyboru()` — onboarding zapisuje
    // wybór do `tag_follows`. Trzy testy, które tu stały
    // (`test_onboarding_zapisuje_tematy_do_bazy_a_nie_do_sesji`,
    // `test_pominiecie_kroku_nie_blokuje_onboardingu`,
    // `test_onboarding_pokazuje_tematy_z_bazy_bez_wycofanych`) sprawdzałyby
    // dziś ekran, który już nie istnieje — przeniesione i przepisane na
    // tagi w `tests/Feature/TagiObserwowanieTest.php`. Reszta tego pliku
    // (obserwowanie ZE STRONY TEMATU i feed TEMATÓW) zostaje bez zmian:
    // `TopicFollowController`/`TopicFeed` i ich trasy nadal działają,
    // znikną dopiero w kolejnym etapie D-021 razem z całym Tematem.
    // ---------------------------------------------------------------

    // ---------------------------------------------------------------
    // Obserwowanie ze strony tematu
    // ---------------------------------------------------------------

    public function test_temat_da_sie_zaobserwowac_i_odobserwowac_bez_javascriptu(): void
    {
        $temat = $this->temat('zupy', 'Zupy');
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('topics.follow', $temat))->assertRedirect();
        $this->assertTrue($basia->fresh()->isFollowingTopic($temat));

        $this->actingAs($basia)->delete(route('topics.unfollow', $temat))->assertRedirect();
        $this->assertFalse($basia->fresh()->isFollowingTopic($temat));
    }

    public function test_dwukrotne_kliknięcie_obserwuj_nie_wywala_bledu(): void
    {
        $temat = $this->temat('zupy', 'Zupy');
        $basia = $this->user('basia');

        // „Obserwuj" bywa klikane dwa razy z niepewności. Klucz główny
        // na parze zamieniłby drugie kliknięcie w błąd bazy danych.
        $this->actingAs($basia)->post(route('topics.follow', $temat))->assertRedirect();
        $this->actingAs($basia)->post(route('topics.follow', $temat))->assertRedirect();

        $this->assertSame(1, DB::table('topic_follows')
            ->where('user_id', $basia->getKey())->count());
    }

    public function test_wycofanego_tematu_nie_da_sie_zaczac_obserwowac(): void
    {
        $temat = $this->temat('stary', 'Wycofany', aktywny: false);
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('topics.follow', $temat))->assertRedirect();

        // Strona wycofanego tematu zostaje (ktoś mógł wysłać link), ale
        // budowanie komuś feedu z listy, która przestała istnieć, nie ma sensu.
        $this->assertFalse($basia->fresh()->isFollowingTopic($temat));
    }

    public function test_gosc_nie_obserwuje_tematow(): void
    {
        $temat = $this->temat('zupy', 'Zupy');

        $this->post(route('topics.follow', $temat))->assertRedirect(route('login'));
        $this->assertSame(0, DB::table('topic_follows')->count());
    }

    // ---------------------------------------------------------------
    // Feed
    // ---------------------------------------------------------------

    public function test_feed_pokazuje_wpisy_z_obserwowanych_tematow(): void
    {
        $zupy = $this->temat('zupy', 'Zupy');
        $ciasta = $this->temat('ciasta', 'Ciasta');
        $obcy = $this->user('obcy');
        $basia = $this->user('basia');

        $this->wpis($zupy, $obcy, 'Rosol na niedziele');
        $this->wpis($ciasta, $obcy, 'Sernik na sobote');

        $basia->followedTopics()->attach($zupy->getKey(), ['created_at' => now()]);

        $odpowiedz = $this->actingAs($basia)->get(route('home'))->assertOk();

        // Sprawdzamy ZAWARTOŚĆ FEEDU, a nie sam HTML strony. Na `/home` stoi
        // też tablica „kuKINGi na dziś", która pokazuje świeże wpisy niezależnie
        // od feedu — `assertDontSee` na całej stronie łapałby ją i oblewał
        // z niewłaściwego powodu.
        $odpowiedz->assertViewHas('zrodloFeedu', 'tematy');
        $trescFeedu = $odpowiedz->viewData('posts')->pluck('body');

        $this->assertContains('Rosol na niedziele', $trescFeedu);
        $this->assertNotContains('Sernik na sobote', $trescFeedu);
        $odpowiedz->assertSee('To wpisy z tematów, które obserwujesz.', escape: false);
    }

    public function test_wpisy_obserwowanych_ludzi_sa_wazniejsze_niz_tematy(): void
    {
        $zupy = $this->temat('zupy', 'Zupy');
        $obcy = $this->user('obcy');
        $znajoma = $this->user('znajoma');
        $basia = $this->user('basia');

        $this->wpis($zupy, $obcy, 'Wpis z tematu');
        Post::factory()->create([
            'author_id' => $znajoma->getKey(),
            'body' => 'Wpis od znajomej',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $basia->followedTopics()->attach($zupy->getKey(), ['created_at' => now()]);
        DB::table('follows')->insert([
            'follower_id' => $basia->getKey(),
            'followed_id' => $znajoma->getKey(),
            'created_at' => now(),
        ]);

        // Ludzie są ważniejsi od kategorii — to jest serwis o ludziach,
        // którzy gotują, a nie baza przepisów (AGENTS.md).
        $odpowiedz = $this->actingAs($basia)->get(route('home'))->assertOk();

        $odpowiedz->assertViewHas('zrodloFeedu', 'obserwowani');
        $trescFeedu = $odpowiedz->viewData('posts')->pluck('body');

        $this->assertContains('Wpis od znajomej', $trescFeedu);
        $this->assertNotContains('Wpis z tematu', $trescFeedu);
    }

    public function test_temat_bez_wpisow_nie_daje_pustego_ekranu(): void
    {
        $pusty = $this->temat('wedzenie', 'Wędzenie');
        $basia = $this->user('basia');
        $basia->followedTopics()->attach($pusty->getKey(), ['created_at' => now()]);

        Post::factory()->create([
            'author_id' => $this->user('obcy')->getKey(),
            'body' => 'Cokolwiek swiezego',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        // Ktoś, kto zaznaczył trzy tematy, w których nikt jeszcze nic nie
        // ugotował, dostałby inaczej dokładnie tę pustkę, której ta zmiana
        // ma zapobiegać. Wtedy wracamy do „Świeżo z Kuking".
        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Cokolwiek swiezego');
    }

    public function test_temat_w_feedzie_nie_omija_prywatnosci_ani_blokady(): void
    {
        $zupy = $this->temat('zupy', 'Zupy');
        $autor = $this->user('autor');
        $nieprzyjemny = $this->user('nieprzyjemny');
        $basia = $this->user('basia');

        $basia->followedTopics()->attach($zupy->getKey(), ['created_at' => now()]);

        $this->wpis($zupy, $autor, 'Widoczny dla wszystkich');
        Post::factory()->create([
            'author_id' => $autor->getKey(),
            'topic_id' => $zupy->getKey(),
            'body' => 'Tylko dla obserwujacych',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'followers',
            'published_at' => now(),
        ]);
        $this->wpis($zupy, $nieprzyjemny, 'Wpis od zablokowanego');

        DB::table('blocks')->insert([
            'blocker_id' => $basia->getKey(),
            'blocked_id' => $nieprzyjemny->getKey(),
            'created_at' => now(),
        ]);

        // Feed tematów zbiera wpisy WIELU autorów naraz — to jest dokładnie
        // to miejsce, w którym prywatność i blokada mogłyby przeciec.
        $this->actingAs($basia)->get(route('home'))
            ->assertOk()
            ->assertSee('Widoczny dla wszystkich')
            ->assertDontSee('Tylko dla obserwujacych')
            ->assertDontSee('Wpis od zablokowanego');
    }

    public function test_pierwsza_wlasna_publikacja_nie_odcina_od_reszty_serwisu(): void
    {
        $basia = $this->user('basia');

        Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Moj pierwszy wpis',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        Post::factory()->create([
            'author_id' => $this->user('obcy')->getKey(),
            'body' => 'Wpis kogos innego',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        // `isEmptyFor` pytał wcześniej „czy ten człowiek zrobił cokolwiek",
        // a nie „czy feed jest pusty". Osoba, która opublikowała JEDEN wpis
        // i nikogo nie obserwuje, dostawała feed złożony wyłącznie z własnego
        // wpisu — a reszta serwisu znikała jej z ekranu na zawsze.
        // Znowu przez dane widoku, a nie przez HTML: tablica „kuKINGi na dziś"
        // pokazuje świeże wpisy niezależnie od feedu, więc `assertSee` na całej
        // stronie przechodziło TAKŻE ze starą, zepsutą semantyką. Pierwsza
        // wersja tego testu była z tego powodu fałszywie zielona.
        $odpowiedz = $this->actingAs($basia)->get(route('home'))->assertOk();

        $odpowiedz->assertViewHas('zrodloFeedu', 'odkrywanie');
        $this->assertContains('Wpis kogos innego', $odpowiedz->viewData('posts')->pluck('body'));
    }

    // ---------------------------------------------------------------
    // Ustawienia
    // ---------------------------------------------------------------

    public function test_ekran_twoje_tematy_zapisuje_wybor(): void
    {
        $zupy = $this->temat('zupy', 'Zupy');
        $ciasta = $this->temat('ciasta', 'Ciasta');
        $basia = $this->user('basia');
        $basia->followedTopics()->attach($zupy->getKey(), ['created_at' => now()]);

        $this->actingAs($basia)
            ->put(route('settings.topics.update'), ['topics' => [$ciasta->getKey()]])
            ->assertRedirect(route('settings.topics'));

        $this->assertFalse($basia->fresh()->isFollowingTopic($zupy));
        $this->assertTrue($basia->fresh()->isFollowingTopic($ciasta));
    }

    public function test_zapis_ustawien_nie_gubi_daty_ani_wycofanego_tematu(): void
    {
        $zupy = $this->temat('zupy', 'Zupy');
        $wycofany = $this->temat('stary', 'Wycofany', aktywny: false);
        $basia = $this->user('basia');

        $dawno = now()->subMonths(3);
        $basia->followedTopics()->attach($zupy->getKey(), ['created_at' => $dawno]);
        $basia->followedTopics()->attach($wycofany->getKey(), ['created_at' => $dawno]);

        $this->actingAs($basia)
            ->put(route('settings.topics.update'), ['topics' => [$zupy->getKey()]])
            ->assertRedirect();

        // Dwie rzeczy naraz. Po pierwsze: `sync()` przepisałby `created_at`
        // wszystkim wierszom przy każdym zapisie, także tym sprzed miesięcy —
        // a „od kiedy" jest jedyną informacją, jaką ta tabela niesie poza
        // samym faktem.
        $wiersz = DB::table('topic_follows')
            ->where('user_id', $basia->getKey())
            ->where('topic_id', $zupy->getKey())
            ->first();
        $this->assertNotNull($wiersz);
        $this->assertSame(
            $dawno->format('Y-m-d'),
            substr((string) $wiersz->created_at, 0, 10),
            'Zapis formularza przepisał datę obserwowania tematu.',
        );

        // Po drugie: temat wycofany przez redakcję nie jest na liście, więc
        // nie ma go w żądaniu — zwykły `sync()` zdjąłby go po cichu przy
        // pierwszym zapisie tego formularza.
        $this->assertTrue($basia->fresh()->isFollowingTopic($wycofany));
    }
}

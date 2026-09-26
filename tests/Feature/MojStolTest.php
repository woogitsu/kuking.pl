<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\MojStol;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\DailyPick;
use App\Models\Hide;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * „Mój stół" (issue #1749, D-304) — dobrowolna półka przepisów dobierana
 * WYŁĄCZNIE regułami z zamkniętej listy AGENTS.md §8: czas, równość autorów,
 * oznaczony wybór gospodarza, bramki i blokady, jawne polecenia widza
 * (obserwowane tagi, ukrycia z #1810).
 *
 * Kontrole ujemne (sprawdzone przy pisaniu):
 *  - `bramki()` bez `bezUkrytychOsob()` → `test_bramki_blokady_i_ukrycia_przed_wyborem` oblewa
 *    (ukryta osoba wraca na półkę);
 *  - `where('po_osobie.kolejny_wpis_osoby', 1)` zdjęte → `test_z_tagow_po_czasie_i_po_jednym_od_osoby`
 *    oblewa (dwa przepisy jednej osoby);
 *  - `whereNotIn('tags.id', $obserwowane)` zdjęte z tematu gospodarza →
 *    `test_temat_gospodarza_tylko_nieobserwowany_i_bez_powtorzen_autora` oblewa;
 *  - `where('wybor.kolejny_wybor_osoby', 1)` zdjęte z `naDzis()` → `test_kukingi_na_dzis_w_kolejnosci_gospodarza_z_filtrami`
 *    oblewa (dwa przepisy jednej osoby z wyboru na dziś);
 *  - zbiorcze zapytanie tematu gospodarza zamienione z powrotem na pętlę
 *    „zapytanie na każdy tag" → `test_temat_gospodarza_ma_stala_liczbe_zapytan_niezalezna_od_liczby_tagow`
 *    oblewa (23 zapytania przy 1 tagu, 32 przy 10; #1968);
 *  - `limit(TEMATOW_DO_ROZPATRZENIA)` zdjęte → `test_temat_gospodarza_rozpatruje_ograniczona_liczbe_tagow` oblewa;
 *  - `miejsce_w_temacie <= NA_POLCE_OD_GOSPODARZA` albo `kolejny_wpis_osoby = 1` zdjęte
 *    ze zbiorczego zapytania → ten sam test oblewa;
 *  - `limit(NA_POLCE_NA_DZIS)` zdjęte z `naDzis()` → `test_kukingi_na_dzis_najwyzej_limit`
 *    oblewa.
 *  - kolejność półki po liczbie wykonań → strażnik `FeedNieSortujePoMierzeReakcjiTest`
 *    (mutacja w `scripts/kontrole-negatywne-alfa08.py`).
 */
class MojStolTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $slug, string $nazwa): Tag
    {
        return Tag::create(['slug' => $slug, 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
    }

    /** @param  array<string, mixed>  $inne */
    private function przepis(User $autor, string $tytul, int $minutTemu, ?Tag $tag = null, array $inne = []): Post
    {
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => $tytul, 'published_at' => now()->subMinutes($minutTemu)]);
        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'recipe_id' => $recipe->getKey(),
            'body' => null,
            'published_at' => now()->subMinutes($minutTemu),
            ...$inne,
        ]);
        $tag?->posts()->attach($post->getKey(), ['position' => 0]);

        return $post;
    }

    private function obserwujTag(User $widz, Tag $tag): void
    {
        $widz->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
    }

    private function naDzis(Post $wpis, int $pozycja, ?string $dzien = null): void
    {
        DailyPick::create([
            'shown_on' => $dzien ?? Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $wpis->getKey(),
            'position' => $pozycja,
            'curator_id' => $this->user('gospodarz'.$pozycja.substr(md5((string) $wpis->getKey()), 0, 4))->getKey(),
        ]);
    }

    private function wlaczony(string $nazwa = 'widz'): User
    {
        return $this->user($nazwa, ['moj_stol_enabled' => true]);
    }

    /** @return list<string> */
    private function tytulyZTagow(User $widz): array
    {
        return array_map(fn (array $p) => $p['post']->recipe->title, app(MojStol::class)->dlaWidza($widz)['z_tagow']);
    }

    public function test_domyslnie_wylaczony_i_niczego_nie_pokazuje(): void
    {
        $widz = $this->user('widz');
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwujTag($widz, $zupy);
        $this->przepis($this->user('ala'), 'Rosół Ali', 5, $zupy);

        $this->assertFalse($widz->fresh()->moj_stol_enabled);

        $this->actingAs($widz)->get(route('moj-stol'))
            ->assertOk()
            ->assertSee('Włącz Mój stół')
            ->assertSee(MojStol::DLACZEGO)
            ->assertDontSee('Rosół Ali');

        $this->actingAs($widz)->get(route('home'))
            ->assertOk()
            ->assertSee('Jest wyłączona, dopóki jej nie włączysz.')
            // Sam przepis stoi na Starcie w Obserwowanych (tag, D-277) —
            // wyłączona półka nie dokłada go drugi raz z powodem.
            ->assertDontSee('Pokazujemy, bo')
            ->assertDontSee('Cały Mój stół');
    }

    public function test_gosc_trafia_do_logowania(): void
    {
        $this->get(route('moj-stol'))->assertRedirect(route('login'));
        $this->put(route('moj-stol.ustaw'), ['wlaczony' => '1'])->assertRedirect(route('login'));
    }

    public function test_wlaczenie_i_wylaczenie(): void
    {
        $widz = $this->user('widz');

        $this->actingAs($widz)->put(route('moj-stol.ustaw'), ['wlaczony' => '1'])
            ->assertRedirect(route('moj-stol'))
            ->assertSessionHas('status');
        $this->assertTrue($widz->fresh()->moj_stol_enabled);

        $this->actingAs($widz)->get(route('moj-stol'))->assertSee('Wyłącz Mój stół')->assertSee('Dlaczego to widzę');

        $this->actingAs($widz)->put(route('moj-stol.ustaw'), ['wlaczony' => '0'])->assertRedirect(route('moj-stol'));
        $this->assertFalse($widz->fresh()->moj_stol_enabled);
    }

    public function test_bledna_wartosc_nie_zmienia_ustawienia_i_mowi_co_zrobic(): void
    {
        $widz = $this->wlaczony();

        $this->actingAs($widz)->from(route('moj-stol'))->put(route('moj-stol.ustaw'), ['wlaczony' => 'może'])
            ->assertRedirect(route('moj-stol'))
            ->assertSessionHasErrors(['wlaczony' => 'Wybierz, czy chcesz włączyć Mój stół, i kliknij przycisk jeszcze raz.']);

        $this->assertTrue($widz->fresh()->moj_stol_enabled);
    }

    public function test_z_tagow_po_czasie_i_po_jednym_od_osoby(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $ciasta = $this->tag('ciasta', 'Ciasta');
        $obce = $this->tag('obce', 'Obce');
        $this->obserwujTag($widz, $zupy);
        $this->obserwujTag($widz, $ciasta);
        $ala = $this->user('ala');
        $ola = $this->user('ola');

        $this->przepis($ala, 'Starszy Ali', 30, $zupy);
        $this->przepis($ala, 'Nowszy Ali', 10, $ciasta);
        $this->przepis($ola, 'Oli', 20, $zupy);
        $this->przepis($ola, 'Oli spoza tagów', 1, $obce);
        $this->przepis($widz, 'Mój własny', 2, $zupy);
        // Zwykły wpis ze zdjęciem, bez przepisu — nie na tę półkę.
        $bezPrzepisu = Post::factory()->create(['author_id' => $this->user('ewa')->getKey(), 'body' => 'Obiad Ewy', 'published_at' => now()->subMinute()]);
        $zupy->posts()->attach($bezPrzepisu->getKey(), ['position' => 0]);

        $this->assertSame(['Nowszy Ali', 'Oli'], $this->tytulyZTagow($widz));
    }

    public function test_reakcje_innych_nie_zmieniaja_kolejnosci(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwujTag($widz, $zupy);

        $popularny = $this->przepis($this->user('ala'), 'Popularny starszy', 60, $zupy);
        $this->przepis($this->user('ola'), 'Cichy nowszy', 5, $zupy);

        foreach (range(1, 5) as $i) {
            CookedEvent::factory()->create(['recipe_id' => $popularny->recipe_id, 'user_id' => $this->user("kucharz{$i}")->getKey()]);
        }

        $this->assertSame(['Cichy nowszy', 'Popularny starszy'], $this->tytulyZTagow($widz));
    }

    public function test_bramki_blokady_i_ukrycia_przed_wyborem(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwujTag($widz, $zupy);

        $this->przepis($this->user('prywatna'), 'Prywatny', 1, $zupy, ['visibility' => Post::VISIBILITY_PRIVATE]);
        $this->przepis($this->user('dlaobs'), 'Dla obserwujących', 2, $zupy, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $zablokowana = $this->user('zablokowana');
        $this->przepis($zablokowana, 'Od zablokowanej', 3, $zupy);
        Block::create(['blocker_id' => $widz->getKey(), 'blocked_id' => $zablokowana->getKey(), 'created_at' => now()]);
        $blokujaca = $this->user('blokujaca');
        $this->przepis($blokujaca, 'Od blokującej', 4, $zupy);
        Block::create(['blocker_id' => $blokujaca->getKey(), 'blocked_id' => $widz->getKey(), 'created_at' => now()]);
        $this->przepis($this->user('zbanowana', ['status' => User::STATUS_BANNED]), 'Od zbanowanej', 5, $zupy);

        // Ukryta osoba ma też starszy, nieukryty przepis — nie może wskoczyć
        // na jej miejsce (bramki PRZED numeracją „po jednym od osoby").
        $ukryta = $this->user('ukryta');
        $this->przepis($ukryta, 'Od ukrytej osoby', 6, $zupy);
        Hide::ukryjDla($widz, 'hidden_user_id', (string) $ukryta->getKey());
        $ala = $this->user('ala');
        $ukrytyWpis = $this->przepis($ala, 'Ukryty wpis Ali', 7, $zupy);
        Hide::ukryjDla($widz, 'post_id', (string) $ukrytyWpis->getKey());
        $this->przepis($ala, 'Starszy Ali', 8, $zupy);

        $this->przepis($this->user('widoczna'), 'Widoczny', 9, $zupy);

        $this->assertSame(['Starszy Ali', 'Widoczny'], $this->tytulyZTagow($widz));
    }

    public function test_przycisk_nie_pokazuj_ukrywa_wpis_tylko_dla_widza(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwujTag($widz, $zupy);
        $wpis = $this->przepis($this->user('ala'), 'Rosół Ali', 5, $zupy);

        $this->actingAs($widz)->get(route('moj-stol'))
            ->assertOk()
            ->assertSee('Rosół Ali')
            ->assertSee('Pokazujemy, bo obserwujesz tag: Zupy.')
            ->assertSee(route('posts.hide', $wpis), false)
            ->assertSee('Nie pokazuj mi tego');

        $this->actingAs($widz)->from(route('moj-stol'))->post(route('posts.hide', $wpis))->assertRedirect();

        $this->actingAs($widz)->get(route('moj-stol'))->assertDontSee('Rosół Ali');
        // Ukrycie jest tylko dla widza — inna osoba dalej ma ten przepis.
        $inna = $this->wlaczony('inna');
        $this->obserwujTag($inna, $zupy);
        $this->assertSame(['Rosół Ali'], $this->tytulyZTagow($inna));
    }

    public function test_zimny_start_bez_tagow_pokazuje_wybor_gospodarza_i_droge_do_tagow(): void
    {
        $widz = $this->wlaczony();
        $ciasta = $this->tag('ciasta', 'Ciasta');
        TagPromotion::create(['tag_id' => $ciasta->getKey(), 'position' => 1]);
        $this->przepis($this->user('ala'), 'Sernik Ali', 5, $ciasta);

        $this->actingAs($widz)->get(route('moj-stol'))
            ->assertOk()
            ->assertSee('Nie obserwujesz jeszcze żadnego tagu.')
            ->assertSee(route('settings.tags'), false)
            ->assertSee('Wybór gospodarza: tag Ciasta')
            ->assertSee('Sernik Ali')
            ->assertSee('Pokazujemy, bo gospodarz poleca tag: Ciasta.')
            ->assertSee('Obserwuj tag: Ciasta');
    }

    public function test_temat_gospodarza_tylko_nieobserwowany_i_bez_powtorzen_autora(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $ciasta = $this->tag('ciasta', 'Ciasta');
        $pierogi = $this->tag('pierogi', 'Pierogi');
        $this->obserwujTag($widz, $zupy);
        // Zupy są promowane NA PIERWSZYM miejscu, ale widz je obserwuje —
        // tematem „nowym" zostają Ciasta (następne w kolejności gospodarza).
        TagPromotion::create(['tag_id' => $zupy->getKey(), 'position' => 1]);
        TagPromotion::create(['tag_id' => $ciasta->getKey(), 'position' => 2]);
        TagPromotion::create(['tag_id' => $pierogi->getKey(), 'position' => 3]);

        $ala = $this->user('ala');
        $this->przepis($ala, 'Rosół Ali', 5, $zupy);
        $this->przepis($ala, 'Sernik Ali', 1, $ciasta);
        $this->przepis($this->user('ola'), 'Szarlotka Oli', 3, $ciasta);
        $this->przepis($this->user('ewa'), 'Pierogi Ewy', 2, $pierogi);

        // Siedem osób w obserwowanych Zupach: szósta mieści się w „Z Twoich
        // tagów", siódma już nie — i nie może wrócić tylnymi drzwiami jako
        // „nowy temat", skoro widz ten tag obserwuje.
        foreach (range(1, 7) as $i) {
            $this->przepis($this->user("zupiarka{$i}"), "Zupa {$i}", 40 + $i, $zupy);
        }

        $polka = app(MojStol::class)->dlaWidza($widz);

        $this->assertCount(MojStol::NA_POLCE_Z_TAGOW, $polka['z_tagow']);
        $this->assertSame('Ciasta', $polka['od_gospodarza']['tag']->name);
        $this->assertSame(['Szarlotka Oli'], array_map(fn (Post $p) => $p->recipe->title, $polka['od_gospodarza']['wpisy']));
    }

    public function test_szyna_startu_pokazuje_polke_z_powodem_gdy_wlaczona(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwujTag($widz, $zupy);
        $this->przepis($this->user('ala'), 'Rosół Ali', 5, $zupy);

        $this->actingAs($widz)->get(route('home'))
            ->assertOk()
            ->assertSee('Rosół Ali')
            ->assertSee('Pokazujemy, bo obserwujesz tag: Zupy.')
            ->assertSee('Cały Mój stół')
            ->assertSee('Dlaczego to widzę');
    }

    public function test_wymazanie_konta_zdejmuje_preferencje_polki(): void
    {
        $odchodzi = $this->user('odchodzi', [
            'moj_stol_enabled' => true,
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi->fresh()));
        $this->assertFalse($odchodzi->fresh()->moj_stol_enabled);
    }

    public function test_rollback_migracji_zdejmuje_kolumne_i_wraca_wylaczony(): void
    {
        $sciezka = 'database/migrations/2026_09_26_190000_add_moj_stol_enabled_to_users.php';
        $widz = $this->wlaczony();

        Artisan::call('migrate:rollback', ['--path' => $sciezka]);
        $this->assertFalse(Schema::hasColumn('users', 'moj_stol_enabled'));

        Artisan::call('migrate', ['--path' => $sciezka]);
        $this->assertTrue(Schema::hasColumn('users', 'moj_stol_enabled'));
        // Kierunek utraty jest bezpieczny (D-304): po cyklu półka jest
        // wyłączona, nikt nie widzi propozycji, których nie chciał.
        $this->assertFalse($widz->fresh()->moj_stol_enabled);
    }

    public function test_kukingi_na_dzis_w_kolejnosci_gospodarza_z_filtrami(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwujTag($widz, $zupy);

        $ala = $this->user('ala');
        $ola = $this->user('ola');
        $ewa = $this->user('ewa');
        $iza = $this->user('iza');
        $ukryta = $this->user('ukryta');
        $zablokowana = $this->user('zablokowana');

        // Ala stoi już na półce z tagu — jej wybór na dziś nie wchodzi drugi raz.
        $this->przepis($ala, 'Rosół Ali', 5);
        $zupy->posts()->attach(Post::query()->where('author_id', $ala->getKey())->value('id'), ['position' => 0]);
        $this->naDzis($this->przepis($ala, 'Sernik Ali', 1), 1);

        // Kolejność gospodarza, nie czas i nie reakcje: starszy przepis Oli
        // wybrany na pozycji 2 stoi przed nowszym przepisem Ewy z pozycji 3.
        $olaStary = $this->przepis($ola, 'Bigos Oli', 90);
        $this->naDzis($olaStary, 2);
        $this->naDzis($this->przepis($ewa, 'Pierogi Ewy', 2), 3);
        foreach (range(1, 4) as $i) {
            CookedEvent::factory()->create(['recipe_id' => Post::query()->where('author_id', $ewa->getKey())->value('recipe_id'), 'user_id' => $this->user("kucharz{$i}")->getKey()]);
        }
        // Drugi wybór Oli — jedna osoba, jeden przepis na półce.
        $this->naDzis($this->przepis($ola, 'Drugi przepis Oli', 3), 4);

        // Filtry: ukryta osoba, blokada, własny przepis, wpis bez przepisu, wczorajszy wybór.
        $this->naDzis($this->przepis($ukryta, 'Od ukrytej', 4), 5);
        Hide::ukryjDla($widz, 'hidden_user_id', (string) $ukryta->getKey());
        $this->naDzis($this->przepis($zablokowana, 'Od zablokowanej', 4), 6);
        Block::create(['blocker_id' => $zablokowana->getKey(), 'blocked_id' => $widz->getKey(), 'created_at' => now()]);
        $this->naDzis($this->przepis($widz, 'Mój własny', 4), 7);
        $this->naDzis(Post::factory()->create(['author_id' => $iza->getKey(), 'body' => 'Obiad Izy', 'published_at' => now()->subMinute()]), 8);
        $this->naDzis($this->przepis($iza, 'Wczorajszy Izy', 4), 9, now()->subDay()->toDateString());

        $polka = app(MojStol::class)->dlaWidza($widz);

        $this->assertSame(['Rosół Ali'], $this->tytulyZTagow($widz));
        $this->assertSame(['Bigos Oli', 'Pierogi Ewy'], array_map(fn (Post $p) => $p->recipe->title, $polka['na_dzis']));

        $this->actingAs($widz)->get(route('moj-stol'))
            ->assertOk()
            ->assertSee('Te przepisy gospodarz wybrał na dziś.')
            ->assertSee('Pokazujemy, bo gospodarz wybrał ten przepis na dziś.')
            ->assertSee('Bigos Oli')
            ->assertDontSee('Drugi przepis Oli');
    }

    public function test_kukingi_na_dzis_bez_wyboru_nie_pokazuja_sekcji(): void
    {
        $widz = $this->wlaczony();

        $this->actingAs($widz)->get(route('moj-stol'))
            ->assertOk()
            ->assertDontSee('Te przepisy gospodarz wybrał na dziś.');
        $this->assertSame([], app(MojStol::class)->dlaWidza($widz)['na_dzis']);
    }

    /**
     * #1968: temat od gospodarza nie może kosztować jednego zapytania na
     * każdy promowany tag. Ta sama półka (obserwowany tag, wybór na dziś,
     * temat gospodarza) mierzona przy 1 i przy 10 promowanych tagach — liczba
     * zapytań ma być TA SAMA. Dziewięć tagów przed właściwym ma kandydatów,
     * których odsiewają reguły D-304 (ukryta osoba, blokada, autor już na
     * półce, własny przepis) — więc przy okazji widać, że reguły działają
     * w jednym zbiorczym zapytaniu, a nie tylko w pętli.
     */
    public function test_temat_gospodarza_ma_stala_liczbe_zapytan_niezalezna_od_liczby_tagow(): void
    {
        $widz = $this->wlaczony();
        $zupy = $this->tag('zupy', 'Zupy');
        $this->obserwujTag($widz, $zupy);
        $ala = $this->user('ala');
        $this->przepis($ala, 'Rosół Ali', 5, $zupy);
        $this->naDzis($this->przepis($this->user('ewa'), 'Bigos Ewy', 7), 1);

        $ciasta = $this->tag('ciasta', 'Ciasta');
        TagPromotion::create(['tag_id' => $ciasta->getKey(), 'position' => 100]);
        $this->przepis($this->user('ola'), 'Sernik Oli', 3, $ciasta);

        $zmierz = function () use ($widz): array {
            app(MojStol::class)->dlaWidza($widz);
            $zapytan = 0;
            DB::listen(function () use (&$zapytan): void {
                $zapytan++;
            });
            $polka = app(MojStol::class)->dlaWidza($widz);

            return [$zapytan, $polka];
        };

        [$przyJednym, $polka] = $zmierz();
        $this->assertSame('Ciasta', $polka['od_gospodarza']['tag']->name);

        $ukryta = $this->user('ukryta');
        Hide::ukryjDla($widz, 'hidden_user_id', (string) $ukryta->getKey());
        $zablokowana = $this->user('zablokowana');
        Block::create(['blocker_id' => $widz->getKey(), 'blocked_id' => $zablokowana->getKey(), 'created_at' => now()]);
        $odsiani = [$ukryta, $zablokowana, $ala, $widz];

        foreach (range(1, 9) as $i) {
            $tag = $this->tag("pusty-{$i}", "Pusty {$i}");
            TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $i]);
            $this->przepis($odsiani[$i % 4], "Odsiany {$i}", 1, $tag);
        }

        [$przyDziesieciu, $polka] = $zmierz();
        $this->assertSame('Ciasta', $polka['od_gospodarza']['tag']->name);
        $this->assertSame(['Sernik Oli'], array_map(fn (Post $p) => $p->recipe->title, $polka['od_gospodarza']['wpisy']));
        $this->assertSame(['Bigos Ewy'], array_map(fn (Post $p) => $p->recipe->title, $polka['na_dzis']));
        $this->assertSame($przyJednym, $przyDziesieciu, "Przy 1 promowanym tagu {$przyJednym} zapytań, przy 10 — {$przyDziesieciu}.");
    }

    /** #1968: półka rozpatruje najwyżej `TEMATOW_DO_ROZPATRZENIA` tagów gospodarza. */
    public function test_temat_gospodarza_rozpatruje_ograniczona_liczbe_tagow(): void
    {
        $widz = $this->wlaczony();

        foreach (range(1, MojStol::TEMATOW_DO_ROZPATRZENIA) as $i) {
            TagPromotion::create(['tag_id' => $this->tag("pusty-{$i}", "Pusty {$i}")->getKey(), 'position' => $i]);
        }

        $ostatni = $this->tag('ostatni', 'Ostatni');
        TagPromotion::create(['tag_id' => $ostatni->getKey(), 'position' => 1000]);
        $ola = $this->user('ola');
        $this->przepis($ola, 'Sernik Oli', 3, $ostatni);

        $this->assertNull(app(MojStol::class)->dlaWidza($widz)['od_gospodarza']);

        // Kontrola dodatnia: gdy jeden pusty tag schodzi z listy, ostatni
        // mieści się w limicie i wraca.
        // W tym samym zbiorczym zapytaniu: po jednym od osoby (najnowszy)
        // i najwyżej `NA_POLCE_OD_GOSPODARZA` od najnowszego.
        TagPromotion::query()->where('position', 1)->delete();
        $this->przepis($ola, 'Nowy sernik Oli', 1, $ostatni);
        $this->przepis($this->user('ewa'), 'Placek Ewy', 2, $ostatni);
        $this->przepis($this->user('iza'), 'Babka Izy', 4, $ostatni);
        $this->przepis($this->user('zosia'), 'Tarta Zosi', 5, $ostatni);

        $temat = app(MojStol::class)->dlaWidza($widz)['od_gospodarza'];
        $this->assertSame('Ostatni', $temat['tag']->name);
        $this->assertSame(['Nowy sernik Oli', 'Placek Ewy', 'Babka Izy'], array_map(fn (Post $p) => $p->recipe->title, $temat['wpisy']));
    }

    /** #1968: „kuKINGi na dziś" — najwyżej `NA_POLCE_NA_DZIS`, limit w zapytaniu. */
    public function test_kukingi_na_dzis_najwyzej_limit(): void
    {
        $widz = $this->wlaczony();

        foreach (range(1, MojStol::NA_POLCE_NA_DZIS + 2) as $i) {
            $this->naDzis($this->przepis($this->user("osoba{$i}"), "Wybór {$i}", 10), $i);
        }

        $this->assertSame(
            array_map(fn (int $i) => "Wybór {$i}", range(1, MojStol::NA_POLCE_NA_DZIS)),
            array_map(fn (Post $p) => $p->recipe->title, app(MojStol::class)->dlaWidza($widz)['na_dzis']),
        );
    }
}

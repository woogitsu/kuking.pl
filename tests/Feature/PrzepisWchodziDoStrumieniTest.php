<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Feed\FollowingFeed;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Opublikowany przepis trafia do strumieni — i znika z nich razem
 * z przepisem (issue #368).
 *
 * ZGŁOSZENIE WŁAŚCICIELA: „dodałem przepis z tym bigosem i nigdzie go nie
 * widzę poza profilem autora i w wyszukiwarce". Stan faktyczny był dokładnie
 * taki: wszystkie trzy strumienie pytają wyłącznie o `posts`, a nikt nigdy
 * nie tworzył wiersza `posts` dla przepisu. Kolumna `posts.recipe_id`,
 * relacja, doładowanie w strumieniach i karta z przyciskiem „Ugotowałem"
 * stały gotowe od pierwszego dnia i stały puste.
 *
 * CZEGO TE TESTY PILNUJĄ POZA SAMYM „POJAWIA SIĘ"
 * Rozwiązanie świadomie NIE KOPIUJE stanu przepisu na wpis (tytuł, zdjęcie,
 * widoczność) — wskazuje przepis i wszystko liczy z relacji. Połowa tego
 * pliku pilnuje więc drugiej strony tej decyzji: że przepis schowany,
 * usunięty albo zawężony ZABIERA wpis ze strumienia, choć wiersz `posts`
 * dalej stoi w bazie nietknięty.
 *
 * DOBÓR DANYCH JEST CZĘŚCIĄ TESTU (docs/PULAPKI_TESTOW.md §1 i §4).
 * Każdy strumień dostaje tu kilka zwykłych wpisów innych osób, żeby asercja
 * nie brzmiała „w strumieniu jest jedna pozycja" — taka przeszłaby także
 * dla kodu bez żadnej zmiany, gdyby limit strony stał akurat na jedynce.
 * Pytamy o KONKRETNY identyfikator wpisu, nie o liczbę pozycji.
 */
class PrzepisWchodziDoStrumieniTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Przepis wchodzi do strumieni
    // -----------------------------------------------------------------

    public function test_opublikowany_przepis_pojawia_sie_w_swiezo_z_kuking(): void
    {
        $basia = $this->user('basia');
        $this->tloZwyklychWpisow();

        $przepis = $this->opublikujPrzepis($basia, 'Bigos z kapusty kiszonej');

        $wpis = Post::query()->where('recipe_id', $przepis->getKey())->first();

        $this->assertNotNull($wpis, 'Publikacja przepisu nie utworzyła wpisu wskazującego przepis.');
        $this->assertNull($wpis->body, 'Wpis wskazujący przepis nie ma własnej treści — prowadzi do przepisu.');
        $this->assertSame(0, $wpis->media()->count(), 'Wpis wskazujący przepis nie ma własnych zdjęć.');

        $strumien = app(DiscoverFeed::class)->paginate(null);

        $this->assertContains(
            $wpis->getKey(),
            $this->klucze($strumien),
            'Opublikowanego przepisu nie ma w „Świeżo z Kuking".',
        );
    }

    public function test_opublikowany_przepis_pojawia_sie_w_feedzie_obserwowanych_u_obserwujacego(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');
        $marek->following()->attach($basia->getKey(), ['created_at' => now()]);

        // Tło: obserwowana osoba ma też zwykłe wpisy, więc feed nie jest
        // jednoelementowy i asercja nie przechodzi „bo cokolwiek tam jest".
        Post::factory()->count(3)->create(['author_id' => $basia->getKey()]);

        $przepis = $this->opublikujPrzepis($basia, 'Zupa ogórkowa jak u mamy');
        $wpis = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();

        $strumien = app(FollowingFeed::class)->paginate($marek->fresh());

        $this->assertContains(
            $wpis->getKey(),
            $this->klucze($strumien),
            'Opublikowanego przepisu nie ma w feedzie osoby, która obserwuje autora.',
        );
    }

    // -----------------------------------------------------------------
    // …i znika razem z przepisem
    // -----------------------------------------------------------------

    public function test_przepis_ukryty_przez_moderacje_znika_ze_strumieni_choc_wiersz_wpisu_zostaje(): void
    {
        [$marek, $wpis, $przepis] = $this->przepisWStrumieniuObserwujacego('Pierogi ruskie');

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $this->assertWpisDalejIstnieje($wpis);
        $this->assertZniknalZeStrumieni($wpis, $marek);
    }

    public function test_przepis_usuniety_miekko_znika_ze_strumieni_choc_wiersz_wpisu_zostaje(): void
    {
        [$marek, $wpis, $przepis] = $this->przepisWStrumieniuObserwujacego('Sernik babci Wandy');

        $przepis->delete();

        $this->assertNotNull($przepis->fresh()->deleted_at ?? null);
        $this->assertWpisDalejIstnieje($wpis);
        $this->assertZniknalZeStrumieni($wpis, $marek);
    }

    public function test_przepis_zawezony_do_obserwujacych_wychodzi_do_obserwujacego_a_nie_do_obcego(): void
    {
        [$marek, $wpis, $przepis] = $this->przepisWStrumieniuObserwujacego('Rosół niedzielny');

        $przepis->forceFill(['visibility' => 'followers'])->save();

        // KONTROLA DODATNIA: zawężenie nie ma prawa odciąć obserwującego.
        // Bez niej ten test przeszedłby także dla bramki, która po prostu
        // wyrzuca ze strumieni każdy wpis z przepisem.
        $this->assertContains(
            $wpis->getKey(),
            $this->klucze(app(FollowingFeed::class)->paginate($marek->fresh())),
            'Zawężenie do obserwujących odcięło osobę, która AUTORA OBSERWUJE.',
        );

        $obcy = $this->user('obcy');

        $this->assertWpisDalejIstnieje($wpis);
        $this->assertNotContains(
            $wpis->getKey(),
            $this->klucze(app(DiscoverFeed::class)->paginate($obcy)),
            'Przepis „tylko dla obserwujących" wyszedł do kogoś, kto autora nie obserwuje.',
        );
        $this->assertNotContains(
            $wpis->getKey(),
            $this->klucze(app(DiscoverFeed::class)->paginate(null)),
            'Przepis „tylko dla obserwujących" wyszedł do gościa.',
        );
    }

    // -----------------------------------------------------------------
    // Nic nie jest kopiowane
    // -----------------------------------------------------------------

    public function test_zmiana_tytulu_przepisu_widac_na_karcie_w_strumieniu_od_razu(): void
    {
        $basia = $this->user('basia');
        $this->tloZwyklychWpisow();

        $przepis = $this->opublikujPrzepis($basia, 'Bigos z kapusty kiszonej');

        $this->get(route('discover'))
            ->assertOk()
            ->assertSee('Bigos z kapusty kiszonej');

        // Tytuł zmieniany PROSTO NA PRZEPISIE, bez dotykania wpisu — bo
        // właśnie o to chodzi: wpis nie ma czego synchronizować.
        $przepis->forceFill(['title' => 'Bigos staropolski z grzybami'])->save();

        $this->assertSame(
            0,
            Post::query()->where('recipe_id', $przepis->getKey())->whereNotNull('body')->count(),
            'Wpis dostał własną treść — czyli jednak coś zostało skopiowane.',
        );

        $this->get(route('discover'))
            ->assertOk()
            ->assertSee('Bigos staropolski z grzybami')
            ->assertDontSee('Bigos z kapusty kiszonej');
    }

    // -----------------------------------------------------------------
    // Jeden wpis, choćby publikacja poszła dwa razy
    // -----------------------------------------------------------------

    public function test_druga_publikacja_tego_samego_przepisu_nie_robi_drugiego_wpisu(): void
    {
        $basia = $this->user('basia');

        $przepis = $this->opublikujPrzepis($basia, 'Gołąbki z kaszą');

        // Drugie kliknięcie „Opublikuj" na tym samym przepisie — w grupie 50+
        // to nie jest pomyłka, tylko sposób obsługi komputera (D-027).
        $this->opublikujPrzepis($basia, 'Gołąbki z kaszą', $przepis->fresh());

        $this->assertSame(
            1,
            Post::query()->withTrashed()->where('recipe_id', $przepis->getKey())->count(),
            'Druga publikacja tego samego przepisu zrobiła drugi wpis.',
        );
    }

    public function test_wpis_skasowany_przez_czlowieka_nie_wraca_przy_kolejnej_publikacji(): void
    {
        $basia = $this->user('basia');

        $przepis = $this->opublikujPrzepis($basia, 'Kopytka z masłem');
        $wpis = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();
        $wpis->delete();

        $this->opublikujPrzepis($basia, 'Kopytka z masłem', $przepis->fresh());

        $this->assertSame(
            0,
            Post::query()->where('recipe_id', $przepis->getKey())->count(),
            'Kolejna publikacja odtworzyła wpis, który człowiek świadomie skasował.',
        );
    }

    // -----------------------------------------------------------------
    // Komenda uzupełniająca stare przepisy
    // -----------------------------------------------------------------

    public function test_komenda_dopisuje_wpisy_przepisom_opublikowanym_wczesniej_i_jest_idempotentna(): void
    {
        $basia = $this->user('basia');

        // Przepisy sprzed tej zmiany: opublikowane, bez wpisu.
        $stare = Recipe::factory()->count(3)->create(['author_id' => $basia->getKey()]);
        $szkic = Recipe::factory()->draft()->create(['author_id' => $basia->getKey()]);

        $this->artisan('kuking:dopisz-wpisy-przepisow')->assertSuccessful();

        foreach ($stare as $przepis) {
            $this->assertSame(
                1,
                Post::query()->where('recipe_id', $przepis->getKey())->count(),
                'Komenda nie dopisała wpisu dla przepisu opublikowanego wcześniej.',
            );
        }

        $this->assertSame(
            0,
            Post::query()->where('recipe_id', $szkic->getKey())->count(),
            'Komenda dopisała wpis dla SZKICU — a szkic nie jest opublikowany.',
        );

        // Wpis stoi w chronologii tam, gdzie przepis, a nie „teraz".
        $wpis = Post::query()->where('recipe_id', $stare->first()->getKey())->firstOrFail();
        $this->assertSame(
            $stare->first()->published_at->toDateTimeString(),
            $wpis->published_at->toDateTimeString(),
        );

        $ileWpisow = Post::query()->count();

        // IDEMPOTENCJA: drugie uruchomienie nie ma czego znaleźć.
        $this->artisan('kuking:dopisz-wpisy-przepisow')->assertSuccessful();

        $this->assertSame($ileWpisow, Post::query()->count(), 'Drugie uruchomienie komendy dopisało wpisy po raz drugi.');
    }

    public function test_komenda_na_sucho_liczy_i_niczego_nie_zapisuje(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->count(4)->create(['author_id' => $basia->getKey()]);

        $przedtem = Post::query()->withTrashed()->count();

        $this->artisan('kuking:dopisz-wpisy-przepisow', ['--na-sucho' => true])
            ->expectsOutputToContain('Do dopisania: 4')
            ->assertSuccessful();

        $this->assertSame(
            $przedtem,
            Post::query()->withTrashed()->count(),
            'Przebieg „na sucho" zapisał wiersze do bazy.',
        );
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function opublikujPrzepis(User $autor, string $tytul, ?Recipe $istniejacy = null): Recipe
    {
        return app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => $tytul, 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
            existing: $istniejacy,
        );
    }

    /**
     * Kilka zwykłych wpisów innych osób — żeby żaden strumień w tym pliku
     * nie był jednoelementowy (docs/PULAPKI_TESTOW.md §4).
     */
    private function tloZwyklychWpisow(): void
    {
        foreach (['ala', 'ola', 'ela'] as $nazwa) {
            Post::factory()->create(['author_id' => $this->user($nazwa)->getKey()]);
        }
    }

    /**
     * @return array{0: User, 1: Post, 2: Recipe}
     */
    private function przepisWStrumieniuObserwujacego(string $tytul): array
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');
        $marek->following()->attach($basia->getKey(), ['created_at' => now()]);

        Post::factory()->count(2)->create(['author_id' => $basia->getKey()]);

        $przepis = $this->opublikujPrzepis($basia, $tytul);
        $wpis = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();

        // KONTROLA DODATNIA: zanim cokolwiek zepsujemy, wpis MUSI być
        // w obu strumieniach. Bez tego „zniknął" niżej nie dowodziłoby
        // niczego — mógłby nigdy tam nie być.
        $this->assertContains($wpis->getKey(), $this->klucze(app(FollowingFeed::class)->paginate($marek->fresh())));
        $this->assertContains($wpis->getKey(), $this->klucze(app(DiscoverFeed::class)->paginate(null)));

        return [$marek, $wpis, $przepis];
    }

    private function assertWpisDalejIstnieje(Post $wpis): void
    {
        $this->assertSame(
            1,
            (int) DB::table('posts')->where('id', $wpis->getKey())->whereNull('deleted_at')->count(),
            'Wiersz wpisu zniknął z bazy — a miał zostać nietknięty.',
        );
    }

    private function assertZniknalZeStrumieni(Post $wpis, User $obserwujacy): void
    {
        $this->assertNotContains(
            $wpis->getKey(),
            $this->klucze(app(FollowingFeed::class)->paginate($obserwujacy->fresh())),
            'Wpis do niedostępnego przepisu został w feedzie obserwowanych.',
        );

        $this->assertNotContains(
            $wpis->getKey(),
            $this->klucze(app(DiscoverFeed::class)->paginate(null)),
            'Wpis do niedostępnego przepisu został w „Świeżo z Kuking".',
        );
    }

    /** @return list<string> */
    private function klucze(mixed $strumien): array
    {
        return array_map(
            static fn (Post $post): string => (string) $post->getKey(),
            $strumien->items(),
        );
    }
}

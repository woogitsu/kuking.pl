<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\TagFeed;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Obserwowanie tagu nie może być obejściem widoczności PRZEPISU.
 *
 * `TagFeed` filtruje wpisy przez `widoczneDla()` — i to działa. Ale wpis
 * wskazujący przepis (issue #368) jest na stałe `public`
 * (`WpisWskazujacyPrzepis::dopisz()`), bo widoczność ma trzymać PRZEPIS,
 * nie jego zapowiedź. Filtr po widoczności wpisu przepuszcza więc zapowiedź
 * przepisu, którego widz zobaczyć nie ma prawa.
 *
 * Pozostałe trzy strumienie mają na to `zWidocznymPrzepisem($widz)`:
 * `FollowingFeed` (dwa razy), `DiscoverFeed`, `DailyBoard` (cztery razy).
 * `TagFeed` jako jedyny go nie miał, a że `with('recipe:id,title,slug,…')`
 * dociąga tytuł i zdjęcie główne, karta wypisywała jedno i drugie.
 *
 * CO DOKŁADNIE WYCIEKAŁO. Nie sam przepis — w niego nie da się wejść,
 * `RecipePolicy` trzyma. Wyciekał TYTUŁ i ZDJĘCIE GŁÓWNE, czyli to, co
 * karta rysuje bez pytania o zgodę. Dla przepisu „tylko dla obserwujących"
 * to cała treść, jaką widać z zewnątrz.
 *
 * DOBÓR WIDZA JEST CZĘŚCIĄ TESTU. Ola obserwuje TAG, ale nie obserwuje
 * BASI — i to jedyna konfiguracja, w której ten wyciek widać. Gdyby Ola
 * obserwowała Basię, przepis byłby dla niej widoczny zgodnie z ustawieniem
 * i test przechodziłby z niewłaściwego powodu.
 */
class FeedTagowNiePokazujeCudzegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    public function test_przepis_tylko_dla_obserwujacych_nie_wchodzi_do_strumienia_tagow_obcej_osoby(): void
    {
        [$ola, $wpisPrzepisu, $wpisPubliczny] = $this->strumien();

        $widziane = collect(app(TagFeed::class)->paginate($ola)->items())
            ->map(fn (Post $wpis) => (string) $wpis->getKey())
            ->all();

        // KONTROLA DODATNIA: strumień w ogóle coś oddaje i ten sam tag
        // przepuszcza wpis, który przepuścić ma. Bez tego asercja niżej
        // przechodziłaby także wtedy, gdyby feed był pusty z byle powodu.
        $this->assertContains(
            (string) $wpisPubliczny->getKey(),
            $widziane,
            'Strumień tagów nie oddał nawet publicznego wpisu z tym tagiem — asercja niżej '
            .'nie mówiłaby wtedy o widoczności przepisu, tylko o pustym feedzie.',
        );

        $this->assertNotContains(
            (string) $wpisPrzepisu->getKey(),
            $widziane,
            'Zapowiedź przepisu „tylko dla obserwujących" weszła do strumienia tagów osoby, '
            .'która autora nie obserwuje. Obserwowanie tagu obeszło ustawienie prywatności.',
        );
    }

    public function test_tytul_i_zdjecie_cudzego_przepisu_nie_pojawiaja_sie_na_stronie_tagu(): void
    {
        [$ola] = $this->strumien();

        $html = $this->actingAs($ola)->get(route('home'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'Bigos z kapusty kiszonej',
            $html,
            'Tytuł przepisu „tylko dla obserwujących" stoi na ekranie osoby, która autora nie obserwuje.',
        );

        $this->assertStringNotContainsString(
            'Zdjęcie do przepisu: Bigos z kapusty kiszonej',
            $html,
            'Zdjęcie główne przepisu „tylko dla obserwujących" stoi na ekranie osoby, '
            .'która autora nie obserwuje.',
        );
    }

    public function test_autor_swoj_wlasny_przepis_w_strumieniu_tagow_dalej_widzi(): void
    {
        [, $wpisPrzepisu] = $this->strumien();

        $basia = User::query()->whereKey($wpisPrzepisu->author_id)->firstOrFail();

        $widziane = collect(app(TagFeed::class)->paginate($basia)->items())
            ->map(fn (Post $wpis) => (string) $wpis->getKey())
            ->all();

        $this->assertContains(
            (string) $wpisPrzepisu->getKey(),
            $widziane,
            'Poprawka odcięła autorowi jego własny przepis. „Poprawne dane nigdy nie znikają" — '
            .'własne archiwum widać zawsze, także prywatne.',
        );
    }

    /**
     * FURTKA „AUTOR WIDZI SWOJE" DZIAŁA — I NIE SIĘGA DALEJ.
     *
     * `Post::scopeZWidocznymPrzepisem()` ma nad sobą komentarz obiecujący
     * JEDEN Świadomy wyjątek: autor zobaczy w strumieniu własny przepis
     * „tylko dla obserwujących", „a nawet tylko dla mnie" (AGENTS.md §5,
     * „poprawne dane nigdy nie znikają"). Zmierzona była dotąd połowa pierwsza:
     * `followers`, w teście wyżej. Połowa druga — `private`, czyli ta, przy
     * której wyciek kosztuje najwięcej — nie miała w całym repozytorium żadnej
     * asercji: ani w stronę „działa", ani w stronę „nie sięga dalej".
     *
     * ŚWIADOMA FURTKA BEZ ASERCJI TO NIE TO SAMO, CO FURTKA ZAMKNIĘTA. Kod,
     * który przepuszcza autorowi jego własną treść, i kod, który przepuszcza za
     * dużo, wyglądają na zielono identycznie — dopóki nikt nie zapyta, KOMU
     * jeszcze się otwiera. Dlatego obie strony stoją w jednym teście: osobno,
     * każda z nich zgnije bez drugiej.
     *
     * DLACZEGO GOŚĆ MIERZONY JEST ZAKRESEM, A NIE STRUMIENIEM. `TagFeed`
     * bierze `User`, nie `?User` — strumień tagów to lista obserwowanych tagów,
     * a gość żadnych nie obserwuje. Pytanie „czy gość to zobaczy" i tak jest
     * pytaniem do tego zakresu, bo przy zapowiedzi przepisu jest on JEDYNĄ
     * bramką, więc stawiam je zakresowi wprost.
     */
    public function test_wlasny_przepis_tylko_dla_mnie_widzi_autor_i_nikt_wiecej(): void
    {
        [$ola, $wpisPrzepisu, $wpisPubliczny, $kasia] = $this->strumien('private');

        $basia = User::query()->whereKey($wpisPrzepisu->author_id)->firstOrFail();

        $zapowiedz = (string) $wpisPrzepisu->getKey();
        $kontrolaDodatnia = (string) $wpisPubliczny->getKey();

        // FURTKA DZIAŁA: autorka widzi własny przepis „tylko dla mnie".
        $this->assertContains(
            $zapowiedz,
            $this->strumienOczami($basia),
            'Autorka nie widzi w strumieniu własnego przepisu „tylko dla mnie". Świadomy wyjątek '
            .'z komentarza nad `Post::scopeZWidocznymPrzepisem()` przestał działać, a AGENTS.md §5 mówi, '
            .'że poprawne dane nigdy nie znikają — także własne, także prywatne.',
        );

        // FURTKA NIE SIĘGA DALEJ: ani obserwująca, ani obca.
        foreach (['obserwująca autorkę' => $kasia, 'obca osoba' => $ola] as $kto => $widz) {
            $widziane = $this->strumienOczami($widz);

            // KONTROLA DODATNIA (pułapka 4): ten widz w ogóle coś ze strumienia
            // dostaje. Bez niej „nie widzi przepisu" znaczyłoby tylko tyle, że
            // strumień jest pusty z byle powodu.
            $this->assertContains(
                $kontrolaDodatnia,
                $widziane,
                "Strumień tagów nie oddał nawet publicznego wpisu ({$kto}) — asercja niżej nie mówiłaby "
                .'wtedy o widoczności przepisu, tylko o pustym feedzie.',
            );

            $this->assertNotContains(
                $zapowiedz,
                $widziane,
                "Przepis „tylko dla mnie\" wyszedł poza autorkę ({$kto}). Furtka „autor widzi swoje\" "
                .'sięga dalej, niż obiecuje komentarz nad `Post::scopeZWidocznymPrzepisem()`.',
            );
        }

        // I GOŚĆ — pytany zakresem wprost, patrz opis metody.
        $widzianePrzezGoscia = Post::query()
            ->zWidocznymPrzepisem(null)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $this->assertContains(
            $kontrolaDodatnia,
            $widzianePrzezGoscia,
            'Zakres odciął gościowi nawet zwykły wpis bez przepisu — asercja niżej nie mierzyłaby '
            .'wtedy widoczności przepisu, tylko pustego wyniku.',
        );

        $this->assertNotContains(
            $zapowiedz,
            $widzianePrzezGoscia,
            'Zapowiedź przepisu „tylko dla mnie" przeszła przez zakres dla gościa. Wpis wskazujący '
            .'przepis jest na stałe `public`, więc ten zakres jest przy nim JEDYNĄ bramką.',
        );
    }

    /**
     * Identyfikatory wpisów, które strumień tagów oddaje temu widzowi.
     *
     * @return list<string>
     */
    private function strumienOczami(User $widz): array
    {
        return collect(app(TagFeed::class)->paginate($widz)->items())
            ->map(fn (Post $wpis) => (string) $wpis->getKey())
            ->all();
    }

    /**
     * `maTresci()` musi pytać DOKŁADNIE tak samo jak `paginate()`.
     *
     * `FeedController::home()` wybiera strumień po tej metodzie. Gdyby pytała
     * szerzej, odpowiadałaby „jest co pokazać" o wpisach, których `paginate()`
     * i tak nie odda — i widz dostałby pusty strumień zamiast ekranu pustego
     * stanu, który mówi, co zrobić dalej. Rozjazd między tymi dwoma
     * zapytaniami nie rzuca wyjątku i nie zostawia śladu w dzienniku.
     */
    public function test_sam_ukryty_przepis_to_dla_strumienia_tagow_brak_tresci(): void
    {
        [$ola, $wpisPrzepisu, $wpisPubliczny] = $this->strumien();

        // KONTROLA DODATNIA: dopóki stoi publiczny wpis, treść JEST.
        $this->assertTrue(
            app(TagFeed::class)->maTresci($ola),
            'Strumień tagów nie widzi nawet publicznego wpisu — asercja niżej nie mówiłaby wtedy '
            .'o bramce przepisu.',
        );

        $wpisPubliczny->forceDelete();

        $this->assertSame(
            1,
            Post::query()->whereKey($wpisPrzepisu->getKey())->count(),
            'Zapowiedź przepisu zniknęła razem z publicznym wpisem — nie ma czego mierzyć.',
        );

        $this->assertFalse(
            app(TagFeed::class)->maTresci($ola),
            'Zostaje sama zapowiedź cudzego przepisu „tylko dla obserwujących", a strumień tagów '
            .'mówi, że jest co pokazać. `paginate()` odda pustą listę i widz zobaczy pusty ekran '
            .'zamiast pustego stanu.',
        );
    }

    /**
     * Basia ma przepis z tagiem, Ola obserwuje ten sam tag i nie obserwuje
     * Basi, a Kasia obserwuje Basię.
     *
     * KASIA JEST CZĘŚCIĄ POMIARU. Bez niej „nikt inny nie widzi" znaczy tylko
     * „obca osoba nie widzi" — a to samo powiedziałaby zwykłe zawężenie do
     * obserwujących. Dopiero OBSERWUJĄCA odróżnia „przepis jest zawężony" od
     * „przepis jest wyłącznie dla autora".
     *
     * @return array{0: User, 1: Post, 2: Post, 3: User}
     */
    private function strumien(string $widocznosc = 'followers'): array
    {
        $tag = Tag::create(['slug' => 'obiady', 'name' => 'Obiady', 'normalized_name' => 'obiady']);

        $basia = $this->user('basia');
        $ola = $this->user('ola');
        $kasia = $this->user('kasia');
        $ola->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
        $basia->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
        $kasia->followedTags()->attach($tag->getKey(), ['created_at' => now()]);
        app(FollowUser::class)->handle($kasia, $basia);

        $this->assertTrue(
            $kasia->following()->whereKey($basia->getKey())->exists(),
            'Kasia nie obserwuje Basi — wtedy „Kasia nie widzi przepisu tylko dla mnie" nie mówi '
            .'nic o zasięgu furtki dla autora, bo Kasia nie zobaczyłaby też przepisu dla obserwujących.',
        );

        $this->assertFalse(
            $ola->following()->whereKey($basia->getKey())->exists(),
            'Ola obserwuje Basię — wtedy przepis „tylko dla obserwujących" jest dla niej widoczny '
            .'zgodnie z ustawieniem i ten test nie mierzy wycieku.',
        );

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Bigos z kapusty kiszonej', 'visibility' => $widocznosc, 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $basia->getKey()])->getKey(),
        ])->save();

        $this->assertSame($widocznosc, $przepis->fresh()->visibility);

        /** @var Post $wpisPrzepisu */
        $wpisPrzepisu = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();
        $wpisPrzepisu->tags()->attach($tag->getKey(), ['position' => 0]);

        $this->assertSame(
            Post::VISIBILITY_PUBLIC,
            $wpisPrzepisu->visibility,
            'Zapowiedź przepisu nie jest publiczna — wtedy odciąłby ją zwykły filtr widoczności wpisu '
            .'i ten test przechodziłby z niewłaściwego powodu.',
        );

        $wpisPubliczny = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Zwykły obiad, bez przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subMinute(),
        ]);
        $wpisPubliczny->tags()->attach($tag->getKey(), ['position' => 0]);

        return [$ola, $wpisPrzepisu, $wpisPubliczny, $kasia];
    }
}

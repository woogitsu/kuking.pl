<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wpis, który jest samym wskazaniem przepisu (issue #447).
 *
 * ZGŁOSZENIE WŁAŚCICIELA, 12 września 2026
 * „Na stronie głównej klikam na bigos z cukinii, przekierowuje mnie na to okno
 * gdzie jest info Ula bigos napisz komentarz itp a nie ma przepisu ani
 * zdjęcia. Muszę szukać i klikać w bigos z cukinii żeby przejść do przepisu…
 * To nie ma sensu".
 *
 * ODTWORZONE NA PRODUKCJI: `GET /wpisy/01a091cf-…` zwracało 200, a w całym
 * `<main>` było ZERO obrazków — przy zdjęciu widocznym na tej samej karcie
 * w strumieniu. Dwa ekrany rysowały ten sam komponent i nie zgadzały się co
 * do tego, czy wpis ma zdjęcie.
 *
 * DWIE RZECZY, KTÓRE TU NAPRAWIAMY, MAJĄ JEDNĄ PRZYCZYNĘ
 * `PostController::show()` doładowywał `recipe:id,title,slug`. To zawężenie
 * gubiło dwie kolumny, a żadna z nich nie zgłaszała się błędem:
 *   • `hero_media_id` — bez niej relacja `heroMedia` zwraca `null`, więc
 *     karta po cichu nie rysuje zdjęcia;
 *   • `visibility` — bez niej karta bierze widoczność WPISU, a ta dla wpisu
 *     z przepisu jest zawsze `public`, więc strona pisała „publicznie" także
 *     pod przepisem widocznym wyłącznie dla obserwujących.
 *
 * A ZA TYM IDZIE DECYZJA PRODUKTOWA (właściciel, 12 września): wpis bez
 * własnej treści nie ma własnej strony — karta i adres prowadzą wprost do
 * przepisu, bo tam jest zdjęcie, składniki, kroki i komentarze.
 */
class WpisZPrzepisuProwadziDoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /** Wpis w kształcie, jaki zakłada `kuking:dopisz-wpisy-przepisow` (#368). */
    private function wpisSamegoPrzepisu(string $tytul = 'Bigos z cukinii', string $widocznosc = 'public'): Post
    {
        $autor = $this->user('ula');
        $zdjecie = Media::factory()->create(['owner_id' => $autor->getKey()]);

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'visibility' => $widocznosc,
            'hero_media_id' => $zdjecie->getKey(),
        ]);

        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => null,
        ]);
    }

    public function test_strona_takiego_wpisu_przekierowuje_na_przepis(): void
    {
        $wpis = $this->wpisSamegoPrzepisu();

        $this->get($wpis->url())
            ->assertRedirect(route('recipes.show', $wpis->recipe->slug));
    }

    public function test_karta_w_strumieniu_prowadzi_wprost_do_przepisu(): void
    {
        $wpis = $this->wpisSamegoPrzepisu();
        $adresPrzepisu = route('recipes.show', $wpis->recipe->slug);

        $html = (string) $this->get(route('discover'))->assertOk()->getContent();

        // Asercja kontrolna: to naprawdę karta tego wpisu, a nie pusty ekran
        // (pułapka 2 z `docs/PULAPKI_TESTOW.md`).
        $this->assertStringContainsString('Bigos z cukinii', $html);

        /*
         * DOWODEM JEST BRAK ADRESU WPISU W KARCIE, nie sama obecność adresu
         * przepisu. Adres przepisu stał w karcie także PRZED tą zmianą — na
         * zdjęciu i na pasku „Z przepisu". Gdyby test pytał tylko o niego,
         * przechodziłby na obu wersjach i nie dowodziłby niczego.
         *
         * Wyjątek, który zostaje: „Otwórz wpis" w menu „⋮" i przycisk
         * „Podziel się" celują dalej w kanoniczny adres wpisu. Dlatego
         * liczymy wystąpienia, a nie pytamy o zero.
         */
        $this->assertStringNotContainsString(
            '<a href="'.$wpis->url().'" class="link-jak-tekst"',
            $html,
            'Data na karcie dalej prowadzi na pustą stronę wpisu zamiast do przepisu.',
        );

        $this->assertStringContainsString(
            '<a class="btn btn-secondary" href="'.$adresPrzepisu.'"',
            $html,
            'Przycisk komentarzy dalej prowadzi na stronę wpisu, a komentarze stoją przy przepisie.',
        );
    }

    public function test_wpis_z_komentarzem_zachowuje_strone_i_pokazuje_zdjecie_przepisu(): void
    {
        $wpis = $this->wpisSamegoPrzepisu();

        Comment::factory()->create([
            'author_id' => $this->user('czytelniczka')->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Robiłam, wyszło znakomicie.',
        ]);

        /*
         * WPIS Z KOMENTARZEM MA JUŻ COŚ WŁASNEGO — rozmowę ludzi. Gdyby
         * przekierowanie go obejmowało, ta rozmowa zostałaby pod adresem,
         * do którego nic nie prowadzi.
         */
        $html = (string) $this->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString('Robiłam, wyszło znakomicie.', $html);

        // I TO JEST DRUGA POŁOWA #447: skoro ta strona zostaje, musi pokazać
        // zdjęcie. Na produkcji miała ZERO obrazków.
        $this->assertStringContainsString(
            'Zdjęcie do przepisu: Bigos z cukinii',
            $html,
            'Strona wpisu dalej nie pokazuje zdjęcia z przepisu — `hero_media_id` nie doszło do widoku.',
        );
    }

    public function test_strona_wpisu_mowi_widocznosc_przepisu_a_nie_wpisu(): void
    {
        $wpis = $this->wpisSamegoPrzepisu('Zupa ogórkowa babci', 'followers');

        Comment::factory()->create([
            'author_id' => $wpis->author_id,
            'post_id' => $wpis->getKey(),
            'body' => 'Notatka dla siebie.',
        ]);

        $html = (string) $this->actingAs($wpis->author)->get($wpis->url())->assertOk()->getContent();

        /*
         * Wpis wskazujący przepis ma `visibility = 'public'` NA STAŁE — to nie
         * jest jego widoczność, tylko brak własnego zawężenia; bramką jest
         * przepis (`Post::scopeZWidocznymPrzepisem()`). Bez kolumny
         * `visibility` w doładowaniu strona pisała autorowi „publicznie" pod
         * przepisem, który widzą wyłącznie jego obserwujący.
         */
        $this->assertStringContainsString('Tylko dla obserwujących', $html);
        $this->assertStringNotContainsString('· publicznie', $html);
    }

    public function test_zwykly_wpis_ze_zdjeciem_i_trescia_nic_nie_traci(): void
    {
        $autorka = $this->user('gotujaca');
        $zdjecie = Media::factory()->create(['owner_id' => $autorka->getKey()]);

        $wpis = Post::factory()->create([
            'author_id' => $autorka->getKey(),
            'body' => 'Rosół jak u mamy.',
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        // KONTROLA GRANICY: przekierowanie ma obejmować WYŁĄCZNIE wpisy bez
        // własnej treści. Zwykły wpis zostaje przy swojej stronie.
        $html = (string) $this->get($wpis->url())->assertOk()->getContent();

        $this->assertStringContainsString('Rosół jak u mamy.', $html);
        $this->assertFalse($wpis->jestSamymPrzepisem());
    }
}

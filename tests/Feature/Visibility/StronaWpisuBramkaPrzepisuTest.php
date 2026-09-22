<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * STRONA WPISU POD BEZPOŚREDNIM ADRESEM — bramką jest PRZEPIS (#368).
 *
 * To jest DROGA 1 z `WidocznoscTestCase` (WIDOK), czwarte miejsce z przeglądu
 * po #941. Trzy wcześniejsze naprawy zamykały LISTY — stronę tagu i nawigację
 * „kolejne zdjęcie" — czyli drogi, którymi gość TRAFIAŁ na adres wpisu.
 * Bezpośredni adres został wtedy otwarty, a to on wypisywał treść.
 *
 * DLACZEGO FILTR WIDOCZNOŚCI WPISU TEGO NIE ŁAPIE
 * `WpisWskazujacyPrzepis::dopisz()` zapisuje zapowiedzi `visibility = 'public'`
 * — i mówi wprost, że to nie jest decyzja o jawności, tylko brak własnego
 * zawężenia, bo bramką ma być przepis. `PostPolicy::view()` widziała `public`
 * i przepuszczała każdego.
 *
 * ZMIERZONY WYCIEK (gość, przepis „tylko dla obserwujących"), przed poprawką:
 *
 *   1. wpis z WŁASNĄ treścią wskazujący ukryty przepis → HTTP 200,
 *      w treści `<a href="/przepisy/{slug}">{tytuł}</a>`, zdjęcie główne
 *      przepisu jako zamiennik braku własnych zdjęć oraz jego opis
 *      zastępczy „Zdjęcie do przepisu: {tytuł}";
 *   2. zapowiedź z choć jednym komentarzem → `jestSamymPrzepisem()` daje
 *      `false`, więc przekierowania na przepis NIE MA, i strona oddaje
 *      to samo: 200, tytuł, slug w adresie i zdjęcie główne.
 *
 * Tytuł wycieka też przez sam slug: slug liczy się z tytułu.
 *
 * DWA ROZSTRZYGNIĘCIA, BO TO DWIE RÓŻNE SYTUACJE DLA CZŁOWIEKA
 *  - zapowiedź (bez własnej treści i bez własnych zdjęć) → ODMOWA.
 *    Po zdjęciu przepisu nie zostaje z niej nic poza nagłówkiem, a jej
 *    `public` nigdy nie było własną decyzją.
 *  - wpis z własną treścią → STRONA BEZ ODWOŁANIA DO PRZEPISU. Wpis jest
 *    czyimś „co dziś ugotowałem", publicznym z własnych powodów, i ma się
 *    otwierać; znika wyłącznie to, co należy do przepisu.
 *
 * KONTROLA DODATNIA JEST TU OBOWIĄZKOWA. Asercja „strona nie zawiera tytułu"
 * przechodzi na 404, na pustej stronie i na cudzym ekranie — dokładnie tak
 * przechodziła przez cały czas przy #941. Każdy test poniżej dowodzi więc
 * najpierw, że mierzy WŁAŚCIWĄ, WYRENDEROWANĄ stronę, a osobne testy
 * `test_kontrola_dodatnia_*` dowodzą, że ta sama asercja UMIE PAŚĆ.
 */
class StronaWpisuBramkaPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $obcy;

    private User $obserwujacy;

    private Media $zdjeciePrzepisu;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->obcy = $this->user('obca');
        $this->obserwujacy = $this->user('obserwujaca');

        app(FollowUser::class)->handle($this->obserwujacy, $this->autor);

        $this->zdjeciePrzepisu = Media::factory()->create(['owner_id' => $this->autor->getKey()]);
    }

    private function przepis(string $widocznosc, string $tytul): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'title' => $tytul,
            'slug' => 'sekretny-'.strtolower(str_replace(' ', '-', $tytul)),
            'visibility' => $widocznosc,
            'hero_media_id' => $this->zdjeciePrzepisu->getKey(),
        ]);
    }

    /** Wpis z WŁASNĄ treścią, który dodatkowo wskazuje przepis (`PublishPost`). */
    private function wpisZWlasnaTrescia(Recipe $przepis): Post
    {
        return Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'body' => 'Zrobiłam to dziś drugi raz w tym tygodniu.',
            'recipe_id' => $przepis->getKey(),
        ]);
    }

    /**
     * Zapowiedź przepisu (#368) Z KOMENTARZEM.
     *
     * Komentarz jest tu WARUNKIEM SENSU TESTU, nie ozdobą: bez niego
     * `jestSamymPrzepisem()` daje `true`, kontroler przekierowuje na przepis
     * i strona wpisu w ogóle się nie renderuje. Cały wyciek polegał na tym,
     * że jedno zdanie napisane pod zapowiedzią zdejmowało to przekierowanie.
     */
    private function zapowiedzZKomentarzem(Recipe $przepis): Post
    {
        $zapowiedz = WpisWskazujacyPrzepis::dopisz($przepis);

        $this->assertNotNull($zapowiedz, 'Zapowiedź nie powstała — test nie miałby czego mierzyć.');

        app(PublishComment::class)->handle(
            author: $this->autor,
            subject: $zapowiedz,
            body: 'Następnym razem dodam więcej czosnku.',
        );

        $zapowiedz->refresh();

        $this->assertFalse(
            $zapowiedz->jestSamymPrzepisem(),
            'Zapowiedź z komentarzem ma NIE być „samym przepisem" — inaczej test mierzy przekierowanie, nie stronę.',
        );

        return $zapowiedz;
    }

    /** Komplet asercji „na tej stronie NIE MA nic z tego przepisu". */
    private function assertStronaNieZdradza(string $html, Recipe $przepis, string $gdzie): void
    {
        $this->assertStringNotContainsString($przepis->title, $html, $gdzie.': tytuł przepisu jest na stronie.');
        $this->assertStringNotContainsString($przepis->slug, $html, $gdzie.': slug przepisu (czyli jego tytuł) jest na stronie.');
        $this->assertStringNotContainsString(
            (string) $this->zdjeciePrzepisu->getKey(),
            $html,
            $gdzie.': zdjęcie główne przepisu jest na stronie.',
        );
        $this->assertStringNotContainsString('Z przepisu', $html, $gdzie.': pasek „Z przepisu" jest na stronie.');
    }

    // -----------------------------------------------------------------
    // PRZYPADEK 1 — wpis z własną treścią: strona zostaje, przepis znika
    // -----------------------------------------------------------------

    public function test_wpis_z_wlasna_trescia_nie_zdradza_gosciowi_tytulu_ukrytego_przepisu(): void
    {
        $przepis = $this->przepis('followers', 'Tajny Bigos Z Cukinii');
        $wpis = $this->wpisZWlasnaTrescia($przepis);

        $html = $this->get(route('posts.show', $wpis))->assertOk()->getContent();

        // KONTROLA DODATNIA: to jest NAPRAWDĘ strona tego wpisu, a nie 404
        // ani pusty ekran. Własna treść wpisu ma tu być — wpis jest publiczny
        // z własnych powodów i odebranie go byłoby inną szkodą niż wyciek.
        $this->assertStringContainsString('Zrobiłam to dziś drugi raz w tym tygodniu.', $html);
        $this->assertStringContainsString($this->autor->displayName(), $html);

        $this->assertStronaNieZdradza($html, $przepis, 'Wpis z własną treścią, gość');
    }

    public function test_wpis_z_wlasna_trescia_nie_zdradza_tez_zalogowanemu_obcemu(): void
    {
        $przepis = $this->przepis('private', 'Przysmak Tylko Dla Mnie');
        $wpis = $this->wpisZWlasnaTrescia($przepis);

        $html = $this->actingAs($this->obcy)->get(route('posts.show', $wpis))->assertOk()->getContent();

        $this->assertStringContainsString('Zrobiłam to dziś drugi raz w tym tygodniu.', $html);
        $this->assertStronaNieZdradza($html, $przepis, 'Wpis z własną treścią, zalogowany obcy');

        // Przycisk „Ugotowałem" prowadzi na `cooked.create/{slug}` — czyli
        // sam jest adresem niosącym tytuł. Widzi go tylko zalogowany, więc
        // gość powyżej by go nie wykrył; dlatego sprawdzamy go TUTAJ.
        $this->assertStringNotContainsString('Ugotowałem', $html);
    }

    // -----------------------------------------------------------------
    // PRZYPADEK 2 — zapowiedź z komentarzem: odmowa
    // -----------------------------------------------------------------

    public function test_zapowiedz_z_komentarzem_nie_otwiera_gosciowi_ukrytego_przepisu(): void
    {
        $przepis = $this->przepis('followers', 'Drugi Tajny Przysmak');
        $zapowiedz = $this->zapowiedzZKomentarzem($przepis);

        $odpowiedz = $this->get(route('posts.show', $zapowiedz));

        $this->assertContains(
            $odpowiedz->getStatusCode(),
            [403, 404],
            'Zapowiedź ukrytego przepisu ma odmówić gościowi — dostano HTTP '.$odpowiedz->getStatusCode().'.',
        );

        $this->assertStronaNieZdradza($odpowiedz->getContent(), $przepis, 'Zapowiedź, gość');
    }

    public function test_pod_zapowiedzia_ukrytego_przepisu_nie_da_sie_dopisac_komentarza(): void
    {
        // Komentarz był tu narzędziem wycieku: zdejmował przekierowanie.
        // Gdyby bramka stała tylko na wyświetlaniu, obcy dalej mógłby
        // odblokować cudzą zapowiedź jednym żądaniem POST.
        $przepis = $this->przepis('followers', 'Trzeci Tajny Przysmak');
        $zapowiedz = $this->zapowiedzZKomentarzem($przepis);

        $this->actingAs($this->obcy)
            ->post(route('posts.comment', $zapowiedz), ['body' => 'A co to takiego?'])
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // KONTROLE DODATNIE — asercje wyżej MUSZĄ umieć paść
    // -----------------------------------------------------------------

    public function test_kontrola_dodatnia_wpis_z_publicznym_przepisem_pokazuje_tytul(): void
    {
        $przepis = $this->przepis('public', 'Jawny Bigos Z Cukinii');
        $wpis = $this->wpisZWlasnaTrescia($przepis);

        $html = $this->get(route('posts.show', $wpis))->assertOk()->getContent();

        // Ta sama strona, ta sama asercja, przepis jawny — i wszystko, czego
        // wyżej ma NIE BYĆ, tutaj JEST. Bez tego testu poprawka mogłaby po
        // prostu wyłączyć pasek „Z przepisu" dla wszystkich i nikt by tego
        // nie zauważył.
        $this->assertStringContainsString($przepis->title, $html);
        $this->assertStringContainsString($przepis->slug, $html);
        $this->assertStringContainsString((string) $this->zdjeciePrzepisu->getKey(), $html);
        $this->assertStringContainsString('Z przepisu', $html);
    }

    public function test_kontrola_dodatnia_zapowiedz_z_komentarzem_i_jawnym_przepisem_dziala(): void
    {
        $przepis = $this->przepis('public', 'Jawny Drugi Przysmak');
        $zapowiedz = $this->zapowiedzZKomentarzem($przepis);

        $html = $this->get(route('posts.show', $zapowiedz))->assertOk()->getContent();

        $this->assertStringContainsString($przepis->title, $html);
        // Rozmowa pod zapowiedzią jest jej własną treścią i ma zostać — to
        // jest cały powód, dla którego `jestSamymPrzepisem()` pyta
        // o komentarze i dla którego ta strona w ogóle istnieje.
        $this->assertStringContainsString('Następnym razem dodam więcej czosnku.', $html);
    }

    public function test_kontrola_dodatnia_obserwujacy_i_autor_widza_swoje(): void
    {
        $przepis = $this->przepis('followers', 'Czwarty Tajny Przysmak');
        $zapowiedz = $this->zapowiedzZKomentarzem($przepis);
        $wpis = $this->wpisZWlasnaTrescia($this->przepis('followers', 'Piaty Tajny Przysmak'));

        // Obserwujący MA prawo do przepisu „tylko dla obserwujących" —
        // poprawka nie może zabrać treści komuś, kto ją widzi zgodnie
        // z tabelą prawdy („poprawne dane nigdy nie znikają", AGENTS.md §5).
        $html = $this->actingAs($this->obserwujacy)->get(route('posts.show', $zapowiedz))->assertOk()->getContent();
        $this->assertStringContainsString($przepis->title, $html);

        // I autor u siebie.
        $html = $this->actingAs($this->autor)->get(route('posts.show', $wpis))->assertOk()->getContent();
        $this->assertStringContainsString('Piaty Tajny Przysmak', $html);
    }

    // -----------------------------------------------------------------
    // Zapowiedź BEZ komentarza dalej przekierowuje — dla uprawnionych
    // -----------------------------------------------------------------

    public function test_zapowiedz_bez_komentarza_dalej_przekierowuje_uprawnionego_na_przepis(): void
    {
        $przepis = $this->przepis('followers', 'Szosty Tajny Przysmak');
        $zapowiedz = WpisWskazujacyPrzepis::dopisz($przepis);
        $this->assertNotNull($zapowiedz);

        $this->actingAs($this->obserwujacy)
            ->get(route('posts.show', $zapowiedz))
            ->assertRedirect(route('recipes.show', $przepis->slug));

        // A gościowi to samo przekierowanie wydawało slug, czyli tytuł.
        //
        // `Auth::logout()` NIE JEST TU OZDOBĄ: `actingAs()` utrzymuje
        // zalogowanie na kolejne żądania w tym samym teście, więc bez niego
        // „gość" byłby dalej obserwującym i test cicho sprawdzałby to samo
        // co dwie linijki wyżej (ta sama pułapka jest opisana
        // w `WidocznoscTestCase::test_macierz_widoku`).
        Auth::logout();

        $odpowiedz = $this->get(route('posts.show', $zapowiedz));
        $this->assertContains($odpowiedz->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString($przepis->slug, (string) $odpowiedz->headers->get('Location'));
    }
}

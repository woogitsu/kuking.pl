<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wyszukiwarka MVP: PostgreSQL + pg_trgm + unaccent.
 *
 * Te testy MUSZĄ chodzić na PostgreSQL — SQLite nie ma ani unaccent,
 * ani similarity(), więc przechodziłyby na zielono nic nie sprawdzając.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_znajduje_przepis_mimo_braku_polskich_znakow_w_zapytaniu(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Żurek z jajkiem',
            'slug' => 'zurek-z-jajkiem',
        ]);

        // Osoba 60+ na telefonie często nie przełącza się na polską klawiaturę.
        $wyniki = app(SearchQuery::class)->recipes('zurek');

        $this->assertCount(1, $wyniki);
        $this->assertSame('Żurek z jajkiem', $wyniki->first()->title);
    }

    public function test_znajduje_przepis_po_skladniku(): void
    {
        $basia = $this->user('basia');
        $recipe = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Coś zupełnie inaczej nazwane',
            'slug' => 'cos-zupelnie-inaczej-nazwane',
        ]);

        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'kiszona kapusta',
            'position' => 0,
        ]);

        $wyniki = app(SearchQuery::class)->recipes('kapusta');

        $this->assertCount(1, $wyniki);
    }

    public function test_nie_pokazuje_szkicow_ani_tresci_prywatnych(): void
    {
        $basia = $this->user('basia');

        Recipe::factory()->draft()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Sekretny żurek',
            'slug' => 'sekretny-zurek',
        ]);

        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Prywatny żurek',
            'slug' => 'prywatny-zurek',
            'visibility' => 'private',
        ]);

        $this->assertCount(0, app(SearchQuery::class)->recipes('zurek'));
    }

    public function test_znajduje_osobe_po_imieniu_i_po_specjalnosci(): void
    {
        $basia = $this->user('basia_z_podkarpacia', ['display_name' => 'Basia']);
        $basia->profile->update(['speciality' => 'zupy i kiszonki']);

        $this->assertCount(1, app(SearchQuery::class)->people('Basia'));
        $this->assertCount(1, app(SearchQuery::class)->people('kiszonki'));
    }

    /** Ile zapytań poszło do bazy w trakcie wywołania. */
    private function ileZapytan(callable $akcja): int
    {
        $ile = 0;

        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }

    public function test_bardzo_krotkie_zapytanie_nie_obciaza_bazy(): void
    {
        // TEN TEST PYTA O BAZĘ, NIE O WYNIK.
        //
        // Wcześniej stały tu dwa `assertCount(0, ...)` — a pusty wynik przy
        // frazie „a" jest w testach pusty także wtedy, gdy bramka długości
        // zniknęła i zapytanie POSZŁO do bazy: tabela `recipes` jest w tym
        // teście pusta, więc odpowiedź i tak jest zerowa. Zmierzone: po
        // zamianie warunku `mb_strlen($phrase) < 2` na `< 0`
        // w `SearchQuery::recipes()` i `::people()` cały ten plik był dalej
        // zielony (12 passed).
        $wyszukiwarka = app(SearchQuery::class);

        $this->assertSame(
            0,
            $this->ileZapytan(fn () => $wyszukiwarka->recipes('a')),
            'Jednoznakowa fraza poszła do bazy — a to jest zapytanie trigramowe na całej tabeli.',
        );

        $this->assertSame(
            0,
            $this->ileZapytan(fn () => $wyszukiwarka->people('')),
            'Pusta fraza poszła do bazy.',
        );

        // KONTROLA DRUGIEJ STRONY: fraza dostatecznie długa NAPRAWDĘ odpytuje
        // bazę. Bez tego „zero zapytań" przechodziłoby też wtedy, gdyby
        // wyszukiwarka przestała szukać czegokolwiek.
        $this->assertGreaterThan(
            0,
            $this->ileZapytan(fn () => $wyszukiwarka->recipes('zurek')),
            'Kontrola: dwuznakowa i dłuższa fraza ma odpytywać bazę.',
        );

        // I wynik nadal jest pusty — to była dotychczasowa treść tego testu.
        $this->assertCount(0, $wyszukiwarka->recipes('a'));
        $this->assertCount(0, $wyszukiwarka->people(''));
    }

    /**
     * Etap D kitu v2 — „za krótka" i „bez wyników" to DWA RÓŻNE stany, nie
     * jeden. Przy jednym znaku SearchQuery w ogóle nie odpytuje bazy (patrz
     * wyżej), więc ekran „Nic nie znaleźliśmy" kłamałby: sugerowałby, że
     * przeszukaliśmy Kuking i nic tam nie ma pasującego do „a".
     */
    public function test_fraza_jednoznakowa_pokazuje_uczciwy_komunikat_a_nie_brak_wynikow(): void
    {
        $this->get(route('search', ['q' => 'a']))
            ->assertOk()
            ->assertSee('za krótka, żeby zacząć szukać')
            ->assertDontSee('Nic nie znaleźliśmy');
    }

    /**
     * Tekst „Nic nie znaleźliśmy" + wyjaśnienie jest dosłownym cytatem
     * z docs/brand/COPY_STYLE.md §6 „Puste stany" — ten dokument wiąże
     * każdy tekst widoczny dla użytkownika (AGENTS.md §11).
     */
    public function test_brak_wynikow_uzywa_tekstu_z_copy_style_i_daje_droge_dalej(): void
    {
        $html = $this->get(route('search', ['q' => 'kartacze', 'sekcja' => 'przepisy']))
            ->assertOk()
            ->assertSee('Nic nie znaleźliśmy')
            ->assertSee('Nie ma jeszcze przepisu, który by pasował do „kartacze”. Może to Ty go dodasz?')
            ->getContent();

        // Człowiek, który nic nie znalazł, dostaje DWIE drogi dalej, nie
        // ślepy zaułek: dodanie własnego przepisu i Świeżo z Kuking.
        $this->assertMatchesRegularExpression(
            '~href="[^"]*'.preg_quote(route('recipes.create'), '~').'"~',
            (string) $html,
        );
        $this->assertMatchesRegularExpression(
            '~href="[^"]*'.preg_quote(route('discover'), '~').'"~',
            (string) $html,
        );
    }

    public static function frazyBezTrafienWeWszystkim(): array
    {
        return [
            'danie' => ['kartacze'],
            'imię' => ['Bożenka'],
        ];
    }

    /**
     * Regresja #944: zakres „Wszystko" szuka przepisów I ludzi, więc pusty
     * stan nie może mówić wyłącznie o przepisie. Sprawdzamy tekst WEWNĄTRZ
     * pustego stanu, nie echo frazy w polu formularza.
     */
    #[DataProvider('frazyBezTrafienWeWszystkim')]
    public function test_pusty_stan_wszystko_mowi_o_przepisach_i_ludziach(string $fraza): void
    {
        $response = $this->get(route('search', ['q' => $fraza]))->assertOk();
        $opis = $this->opisPustegoStanu((string) $response->getContent());

        $this->assertStringContainsString('ani przepisu, ani osoby pasującej do „'.$fraza.'”', $opis);
        $this->assertStringNotContainsString('Nie ma jeszcze przepisu', $opis);
        $this->assertStringNotContainsString('Może to Ty go dodasz', $opis);
        // Dodanie przepisu zostaje jedną z dróg, ale bez „taki" — fraza
        // mogła być imieniem. Świeżo z Kuking też zostaje.
        $response->assertSee('Dodaj przepis')
            ->assertDontSee('Dodaj taki przepis')
            ->assertSee('href="'.route('discover').'"', false);
    }

    /** Kontrola dodatnia: pozostałe zakresy zachowują swoje teksty. */
    public function test_pozostale_zakresy_zachowuja_wlasne_puste_stany(): void
    {
        $przepisy = $this->get(route('search', ['q' => 'kartacze', 'sekcja' => 'przepisy']))->assertOk();
        $this->assertStringContainsString(
            'Nie ma jeszcze przepisu, który by pasował do „kartacze”. Może to Ty go dodasz?',
            $this->opisPustegoStanu((string) $przepisy->getContent()),
        );
        $przepisy->assertSee('Dodaj taki przepis');

        $ludzie = $this->get(route('search', ['q' => 'Bożenka', 'sekcja' => 'ludzie']))->assertOk();
        $this->assertStringContainsString('Nie ma tu osoby o nazwie „Bożenka”.', $this->opisPustegoStanu((string) $ludzie->getContent()));
        $ludzie->assertDontSee('Dodaj taki przepis')->assertDontSee('Dodaj przepis');

        $szybkie = $this->get(route('search', ['q' => 'kartacze', 'sekcja' => 'szybkie']))->assertOk();
        $opis = $this->opisPustegoStanu((string) $szybkie->getContent());
        $this->assertStringContainsString('zmieściłby się w pół godziny', $opis);
        $this->assertStringContainsString('Spróbuj zakresu „Przepisy”', $opis);
    }

    private function opisPustegoStanu(string $html): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $wezel = (new \DOMXPath($dom))->query('//p[contains(@class, "empty-state-opis")]')->item(0);
        $this->assertNotNull($wezel, 'Brak pustego stanu na stronie.');

        return (string) preg_replace('/\s+/u', ' ', trim($wezel->textContent));
    }

    /**
     * Wejście na /szukaj bez frazy nie może kończyć się na samej instrukcji
     * „wpisz coś" — to też ślepy zaułek dla kogoś, kto nie wie, czego szukać
     * (docs/product/SOUL.md 4.11).
     */
    public function test_pusta_fraza_ma_droge_dalej_do_swiezo_z_kuking(): void
    {
        $html = $this->get(route('search'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~href="[^"]*'.preg_quote(route('discover'), '~').'"~',
            (string) $html,
        );
    }

    /**
     * Domyślny zakres „Wszystko" (UI kit v2, ekran 03).
     *
     * Człowiek, który wpisał „Basia", nie zadeklarował, czy szuka osoby,
     * czy jej przepisów. Przed tym zakresem strona bez parametru `sekcja`
     * pokazywała WYŁĄCZNIE ludzi — przepisy istniały, ale były nieosiągalne
     * bez kliknięcia zakładki, o której nikt nie wiedział, że jest potrzebna.
     */
    public function test_bez_wybranego_zakresu_widac_i_przepisy_i_ludzi(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół Basi',
            'slug' => 'rosol-basi',
        ]);

        $this->get(route('search', ['q' => 'basi']))
            ->assertOk()
            ->assertSee('Rosół Basi')
            ->assertSee('Basia');
    }

    /**
     * Zakres „Do 30 minut" — i przepis BEZ podanych czasów, który do niego
     * nie wpada.
     *
     * Brak danych nie znaczy „szybki". Obiecanie, że coś zajmie pół godziny,
     * gdy nikt tego nie zmierzył, jest gorsze niż nieujęcie przepisu w wynikach.
     */
    public function test_do_30_minut_pomija_dlugie_i_te_bez_czasow(): void
    {
        $basia = $this->user('basia');

        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół szybki',
            'slug' => 'rosol-szybki',
            'prep_minutes' => 10,
            'cook_minutes' => 15,
        ]);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół całodniowy',
            'slug' => 'rosol-calodniowy',
            'prep_minutes' => 20,
            'cook_minutes' => 180,
        ]);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół bez czasów',
            'slug' => 'rosol-bez-czasow',
            'prep_minutes' => null,
            'cook_minutes' => null,
        ]);

        $wyniki = app(SearchQuery::class)->recipes('rosol', null, 20, 30);

        $this->assertCount(1, $wyniki);
        $this->assertSame('Rosół szybki', $wyniki->first()->title);
    }

    /**
     * Który zakres jest włączony, musi być SŁYSZALNE, nie tylko widoczne —
     * sam kolor chipa nie istnieje dla czytnika ekranu (AGENTS.md, UX 50+).
     */
    public function test_wlaczony_zakres_ma_aria_current(): void
    {
        $html = $this->get(route('search', ['q' => 'rosol', 'sekcja' => 'szybkie']))
            ->assertOk()
            ->assertSee('Do 30 minut')
            ->getContent();

        // Wzorzec luźny co do białych znaków i kolejności atrybutów: Blade
        // łamie długie znaczniki na kilka linii, a test przypięty do jednej
        // konkretnej postaci HTML-a psuje się przy każdym przeformatowaniu
        // widoku, nie mówiąc nic o tym, co miał pilnować.
        $this->assertMatchesRegularExpression(
            '~sekcja=szybkie"\s[^>]*aria-current="page"~',
            (string) $html,
        );

        // I odwrotnie: zakres, który NIE jest włączony, nie może się tak ogłaszać.
        $this->assertDoesNotMatchRegularExpression(
            '~sekcja=ludzie"\s[^>]*aria-current="page"~',
            (string) $html,
        );
    }

    public function test_strona_wyszukiwania_nie_jest_indeksowana(): void
    {
        $this->get(route('search', ['q' => 'rosol']))
            ->assertOk()
            ->assertSee('noindex', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * Issue #753 — `%` z frazy MUSI zostać dosłownym tekstem, nie wzorcem
     * LIKE. Celowo przez SKŁADNIK (`ingredient_text_search`), nie przez
     * tytuł: tytuł ma jeszcze osobną, LEGALNĄ gałąź trigramową (`<%`), która
     * mogłaby dopasować „1000" do „100%" przez samo podobieństwo — a to nie
     * miałoby nic wspólnego z metaznakami LIKE i fałszywie potwierdzałoby
     * poprawkę (zmierzone: `word_similarity('100%', '1000 domowe pierogi')
     * = 0.8`, powyżej progu 0,5). Oba tytuły niżej są celowo niepodobne do
     * „100%", żeby gałąź trigramowa nie mogła dorzucić żadnego z nich.
     *
     * Kontrola ujemna: przywrócenie gołego `'%'.$needle.'%'` (bez ucieczki)
     * sprawia, że ten test oblewa, bo `100%` zaczyna dopasowywać każdy
     * składnik zawierający „100".
     */
    public function test_procent_z_frazy_nie_dziala_jak_wzorzec_like(): void
    {
        $basia = $this->user('basia');
        $trafienie = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Zupa jarzynowa',
            'slug' => 'zupa-jarzynowa-753',
        ]);
        RecipeIngredient::create([
            'recipe_id' => $trafienie->getKey(),
            'ingredient_text' => '100% masło',
            'position' => 0,
        ]);
        $niepasujacy = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Placki ziemniaczane',
            'slug' => 'placki-ziemniaczane-753',
        ]);
        RecipeIngredient::create([
            'recipe_id' => $niepasujacy->getKey(),
            'ingredient_text' => '1000 gramów ziemniaków',
            'position' => 0,
        ]);

        $wyniki = app(SearchQuery::class)->recipes('100%');

        $this->assertCount(1, $wyniki);
        $this->assertSame($trafienie->getKey(), $wyniki->first()->getKey());
    }

    /**
     * Issue #753 — `_` w LIKE dopasowuje DOWOLNY jeden znak. Fraza
     * „kapu_ta” (dosłowny podkreślnik ze zgłoszenia) nie ma prawa znaleźć
     * składnika „kapusta”, bo to oznaczałoby, że podkreślnik zadziałał jak
     * wieloznacznik, a nie jak zwykły znak.
     */
    public function test_podkreslnik_z_frazy_nie_dziala_jak_wzorzec_like(): void
    {
        $basia = $this->user('basia');
        $recipe = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Coś zupełnie inaczej nazwane',
            'slug' => 'cos-zupelnie-inaczej-nazwane-2',
        ]);
        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'kiszona kapusta',
            'position' => 0,
        ]);

        $this->assertCount(0, app(SearchQuery::class)->recipes('kapu_ta'));
        // Kontrola dodatnia: dosłowny podkreślnik NAPRAWDĘ jest w bazie i da
        // się go znaleźć, gdy fraza go zawiera dokładnie.
        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'kapu_ta wpisana z podkreślnikiem',
            'position' => 1,
        ]);
        $this->assertGreaterThanOrEqual(1, app(SearchQuery::class)->recipes('kapu_ta')->count());
    }

    /**
     * Sama fraza złożona wyłącznie z metaznaków (`%%`) ma dwa znaki, więc
     * przechodzi bramkę długości — i nie może zwrócić wszystkiego, co jest
     * w bazie, tak jak zrobiłby to nieucieczkowany wzorzec `%%%%`.
     */
    public function test_fraza_z_samych_metaznakow_nie_dopasowuje_wszystkiego(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Zwyczajny rosół',
            'slug' => 'zwyczajny-rosol',
        ]);

        $this->assertCount(0, app(SearchQuery::class)->recipes('%%'));
        $this->assertCount(0, app(SearchQuery::class)->people('%%'));
    }

    /**
     * To samo dla wyszukiwania ludzi (`people()`) — inna metoda, ten sam
     * błąd źródłowy w budowie wzorca LIKE.
     */
    public function test_procent_z_frazy_nie_dziala_jak_wzorzec_like_dla_ludzi(): void
    {
        $trafienie = $this->user('sto_procent', ['display_name' => '100% Basia']);
        $this->user('tysiac', ['display_name' => '1000 Basia']);

        $wyniki = app(SearchQuery::class)->people('100%');

        $this->assertCount(1, $wyniki);
        $this->assertSame($trafienie->profile->getKey(), $wyniki->first()->getKey());
    }
}

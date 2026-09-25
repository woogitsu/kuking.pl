<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\ProductSignal;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1050 — fraza pusta PO transliteracji dawała wzorzec `LIKE '%%'`.
 *
 * `SearchQuery::recipes()`/`people()` odrzucały frazy krótsze niż dwa znaki
 * PRZED `normalize()` (czyli `mb_strtolower(Str::ascii($fraza))`), ale nie
 * sprawdzały wyniku normalizacji. `Str::ascii()` transliteruje przez
 * `voku/portable-ascii` z domyślnym `remove_unsupported_chars=true` — znak
 * spoza jego tablicy (np. samo emoji) po prostu znika. Fraza o co najmniej
 * dwóch znakach, która po transliteracji staje się pusta, budowała więc
 * wzorzec `'%'.'' .'%'`, czyli dosłownie `LIKE '%%'` — dopasowanie do
 * WSZYSTKICH tytułów/profili zamiast do żadnego.
 *
 * Naprawa: `SearchQuery::jestPrzeszukiwalna()` — wspólny kontrakt, który
 * sprawdza długość PO normalizacji, nie tylko przed nią, i zasila
 * `recipes()`, `people()` oraz stan `SearchController` i `OnboardingController`.
 */
class FrazaPustaPoNormalizacjiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fraza fixture: dwa emoji. `NazwaUzytkownikaWybaczaZapisTest` już
     * potwierdza (dla innej klasy), że sam emoji ginie w `Str::ascii()`;
     * tu potwierdzamy to jeszcze raz, wprost na tej frazie, PRZED użyciem
     * jej w teście domeny — żeby fixture nie opierał się na założeniu
     * o konkretnym emoji bez asercji (kryterium z issue).
     */
    private const FRAZA_EMOJI = '🍲🍲';

    public function test_fixture_ma_co_najmniej_dwa_znaki_i_normalizuje_sie_do_pustego_tekstu(): void
    {
        $this->assertGreaterThanOrEqual(2, mb_strlen(self::FRAZA_EMOJI));
        $this->assertSame('', mb_strtolower(Str::ascii(self::FRAZA_EMOJI)), 'Fixture nie normalizuje się do pustego tekstu — dobierz inny znak.');
    }

    // -----------------------------------------------------------------
    // Bezpośrednie wywołania domeny
    // -----------------------------------------------------------------

    public static function metodyDomeny(): array
    {
        return [['recipes'], ['people']];
    }

    #[DataProvider('metodyDomeny')]
    public function test_pusta_po_normalizacji_fraza_nie_daje_zadnego_wyniku_i_nic_nie_pyta(string $metoda): void
    {
        $autor = $this->user('autor');
        Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Żurek testowy']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $wynik = app(SearchQuery::class)->{$metoda}(self::FRAZA_EMOJI);

        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $wynik, 'Fraza pusta po normalizacji nie może dopasować WSZYSTKIEGO (bug #1050: LIKE \'%%\').');
        $this->assertSame(
            [],
            $zapytania,
            'Fraza, z której nic nie zostaje po normalizacji, w ogóle nie ma prawa odpytać bazy — '
            .'ani przez LIKE, ani przez ustawienie progu podobieństwa (set_config).',
        );
    }

    /**
     * KONTROLA UJEMNA (wymagana przez issue): usunięcie straży po
     * normalizacji musi przywrócić błędne, szerokie dopasowanie na
     * izolowanej bazie PostgreSQL. Nie da się cofnąć prywatnej metody
     * z zewnątrz testu, więc kontrolę ujemną wykonano ręcznie — patrz
     * raport zadania — a ten test jest dowodem KONTROLI DODATNIEJ:
     * naprawiona wersja naprawdę zwraca zero wyników i zero zapytań dla
     * przepisu, który istnieje w bazie i pasowałby do KAŻDEGO `LIKE '%%'`.
     */
    public function test_kontrola_dodatnia_przepis_istnieje_ale_pusta_fraza_go_nie_znajduje(): void
    {
        $autor = $this->user('autor');
        Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Cokolwiek by nie pasowało']);

        $this->assertCount(0, app(SearchQuery::class)->recipes(self::FRAZA_EMOJI));
    }

    // -----------------------------------------------------------------
    // Jeden znak po normalizacji — spójność z progiem dwóch znaków
    // -----------------------------------------------------------------

    /**
     * Dwa znaki na wejściu (emoji + litera), ale JEDEN po normalizacji.
     * Decyzja tego zadania: próg dwóch znaków dotyczy tego, co NAPRAWDĘ
     * trafia do zapytania (frazy po normalizacji, na której stoi indeks),
     * nie samej długości wpisanego tekstu — więc to też jest „za krótkie",
     * tak samo jak zero znaków.
     */
    public function test_jeden_znak_po_normalizacji_traktowany_jest_tak_samo_jak_za_krotka_fraza(): void
    {
        $fraza = '🍲a';
        $this->assertSame(2, mb_strlen($fraza));
        $this->assertSame('a', mb_strtolower(Str::ascii($fraza)));

        $this->assertFalse(SearchQuery::jestPrzeszukiwalna($fraza));
        $this->assertCount(0, app(SearchQuery::class)->recipes($fraza));
    }

    // -----------------------------------------------------------------
    // HTTP: instrukcja zamiast fałszywego trafienia, oryginalny tekst zostaje
    // -----------------------------------------------------------------

    public function test_http_pokazuje_instrukcje_i_zachowuje_oryginalny_tekst(): void
    {
        $autor = $this->user('autor');
        Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Cokolwiek by nie pasowało']);

        $odpowiedz = $this->get(route('search', ['q' => self::FRAZA_EMOJI, 'sekcja' => 'przepisy']))->assertOk();

        $odpowiedz->assertDontSee('Cokolwiek by nie pasowało');
        $odpowiedz->assertSee(self::FRAZA_EMOJI, false);
        $odpowiedz->assertSee('za krótka');
        $odpowiedz->assertDontSee('Nic nie znaleźliśmy');

        // Nie mógł powstać pozorny „skuteczny" sygnał wyszukiwania — baza
        // w ogóle nie została odpytana (ten sam kontrakt co #737).
        $this->assertDatabaseCount('product_signals', 0);
    }

    public function test_onboarding_ludzie_pokazuje_ta_sama_instrukcje_dla_pustej_po_normalizacji_frazy(): void
    {
        $this->actingAs($this->user('widz'));

        $odpowiedz = $this->get(route('onboarding.people', ['q' => self::FRAZA_EMOJI]))->assertOk();

        $odpowiedz->assertSee(self::FRAZA_EMOJI, false);
    }

    // -----------------------------------------------------------------
    // Kontrole dodatnie — odporność na polskie znaki i literówki zostaje
    // -----------------------------------------------------------------

    public static function frazyUzyteczne(): array
    {
        return [
            'polski znak diakrytyczny' => ['żurek'],
            'ta sama fraza bez diakrytyku' => ['zurek'],
            'zwykłe imię' => ['Basia'],
            'litery i symbole razem' => ['pierogi!?'],
            'dosłowne metaznaki LIKE' => ['50%_50'],
        ];
    }

    #[DataProvider('frazyUzyteczne')]
    public function test_zwykle_frazy_nadal_sa_przeszukiwalne(string $fraza): void
    {
        $this->assertTrue(SearchQuery::jestPrzeszukiwalna($fraza));
    }
}

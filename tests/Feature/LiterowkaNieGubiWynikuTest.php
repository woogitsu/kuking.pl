<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Domain\Tags\TagSuggester;
use App\Models\Recipe;
use App\Models\Tag;
use App\Support\ProgPodobienstwa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Literówka nie może gubić wyniku (błąd zgłoszony przy etapie D wyszukiwarki).
 *
 * CO BYŁO ZŁE
 * `SearchQuery` miała stałą `SIMILARITY_THRESHOLD = 0.12` z komentarzem
 * „poniżej tego progu wyniki są już przypadkowe" — i nigdy jej nie używała.
 * Operator `%` z pg_trgm nie przyjmuje progu jako argumentu: bierze go
 * z ustawienia sesji, którego nikt nie ustawiał, czyli z domyślnego 0.3.
 *
 * Zmierzone wprost w Postgresie:
 *   similarity('sernik babci haliny', 'sernk') = 0.1818   (powyżej 0.12)
 *   'sernik babci haliny' % 'sernk'            = false    (bo próg to 0.3)
 *
 * Czyli cała odporność na literówki, którą obiecuje komentarz klasy, była
 * martwa dla dłuższych tytułów.
 *
 * DLACZEGO TO BOLI WŁAŚNIE TUTAJ
 * Osoba, która wpisuje na telefonie jednym palcem, dostawała „nic nie
 * znaleźliśmy" i wyciągała wniosek, że przepisu nie ma. Nie spróbuje drugi
 * raz z inną pisownią — po prostu odejdzie.
 */
class LiterowkaNieGubiWynikuTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(string $tytul): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->user('kucharka')->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
    }

    /**
     * KONTROLA W BAZIE. To jest ten sam pomiar, który wykrył błąd — bez niego
     * nie wiadomo, czy test niżej mierzy próg, czy cokolwiek innego.
     */
    public function test_kontrola_domyslny_prog_postgresa_odrzuca_te_literowke(): void
    {
        $podobienstwo = (float) DB::selectOne(
            "SELECT similarity('sernik babci haliny', 'sernk') AS s",
        )->s;

        // Podobieństwo JEST powyżej progu, który ten projekt uznał za granicę
        // sensu — więc odrzucenie wyniku nie brało się z podobieństwa.
        $this->assertGreaterThan(ProgPodobienstwa::PROG, $podobienstwo);
        $this->assertLessThan(0.3, $podobienstwo, 'Kontrola: przy podobieństwie ponad 0.3 domyślny próg by nie przeszkadzał i test nic nie mierzy.');
    }

    /** WŁAŚCIWY POMIAR. Literówka w dłuższym tytule trafia. */
    public function test_literowka_znajduje_przepis(): void
    {
        $this->przepis('Sernik babci Haliny');

        $wyniki = app(SearchQuery::class)->recipes('sernk')->pluck('title')->all();

        $this->assertContains('Sernik babci Haliny', $wyniki, 'Literówka „sernk” nie znalazła „Sernika babci Haliny”.');
    }

    /**
     * KONTROLA DRUGIEJ STRONY. Próg nie zniknął — fraza bez żadnego
     * podobieństwa nadal nic nie znajduje. Bez tego „naprawa" mogłaby po
     * prostu przestać filtrować i zwracać wszystko.
     */
    public function test_fraza_bez_podobienstwa_nadal_nic_nie_znajduje(): void
    {
        $this->przepis('Sernik babci Haliny');

        $this->assertSame([], app(SearchQuery::class)->recipes('xqzwvb')->pluck('title')->all());
    }

    /** Poprawna pisownia oczywiście też trafia — najtańsza możliwa kontrola. */
    public function test_poprawna_pisownia_trafia(): void
    {
        $this->przepis('Sernik babci Haliny');

        $this->assertContains(
            'Sernik babci Haliny',
            app(SearchQuery::class)->recipes('sernik')->pluck('title')->all(),
        );
    }

    /**
     * PODPOWIEDZI TAGÓW: ten sam błąd, mniej widoczny i dlatego groźniejszy.
     * Nazwy tagów są krótkie, więc `similarity('sernik','sernk')` wychodzi
     * ponad 0.5 i literówka trafiała mimo złego progu. Rozsypywało się
     * dokładnie tam, gdzie tag jest dłuższy.
     */
    public function test_literowka_znajduje_dlugi_tag(): void
    {
        Tag::create([
            'name' => 'zakwas na barszcz biały',
            'normalized_name' => 'zakwas na barszcz biały',
            'slug' => 'zakwas-na-barszcz-bialy',
            'is_seeded' => true,
        ]);

        $nazwy = app(TagSuggester::class)->sugeruj('zakwas na barszc')->pluck('name')->all();

        $this->assertContains('zakwas na barszcz biały', $nazwy);
    }
}

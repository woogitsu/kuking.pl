<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Audyt zewnętrzny T20 — `CollectionController::show()` czytał WSZYSTKIE
 * zapisane wpisy zeszytu jednym `->get()` bez żadnego limitu.
 *
 * DLACZEGO TO JEST T20, A NIE N03
 * Liczba zapytań nie rośnie z liczbą zapisanych wpisów (eager load pokrywa
 * autora, profil, awatar i zdjęcia jednym zapytaniem na relację) — problem
 * jest w LICZBIE WIERSZY: zeszyt rośnie z użyciem serwisu (każde kliknięcie
 * „Zapisz" na cudzym wpisie dokłada tam jedną pozycję, bez górnej granicy),
 * a strona renderowała je WSZYSTKIE naraz, tak jak `recipes()` w tej samej
 * metodzie już dawno by zrobiła, gdyby ktoś zapomniał tam `paginate()`.
 *
 * ZMIERZONE, NIE ZAŁOŻONE (AGENTS.md §3): 30 zapisanych wpisów, każdy z 3
 * zdjęciami — liczby z zadania audytu — plus dwa dodatkowe zablokowane
 * wpisy, które test filtra widoczności (`niewidoczne`) i tak już musiał
 * policzyć OSOBNYM zapytaniem (to jego świadomy koszt, nieruszany tutaj).
 */
class ZeszytZapisanychWpisowWydajnoscTest extends TestCase
{
    use RefreshDatabase;

    private const ILE_WPISOW = 30;

    private const ZDJEC_NA_WPIS = 3;

    public function test_zeszyt_pokazuje_zapisane_wpisy_stronami_a_nie_wszystkie_naraz(): void
    {
        $widz = $this->user('ogladajaca');
        $autor = $this->user('autorka');

        $collection = Collection::create([
            'owner_id' => $widz->getKey(),
            'name' => 'Na potem',
            'visibility' => 'private',
        ]);

        for ($i = 0; $i < self::ILE_WPISOW; $i++) {
            $post = Post::factory()->for($autor, 'author')->create([
                'published_at' => now()->subMinutes($i),
            ]);

            for ($z = 0; $z < self::ZDJEC_NA_WPIS; $z++) {
                $media = Media::factory()->for($autor, 'owner')->create();
                $post->media()->attach($media->getKey(), ['position' => $z]);
            }

            $collection->posts()->attach($post->getKey(), ['created_at' => now()->subMinutes($i)]);
        }

        // ASERCJA KONTROLNA (AGENTS.md §4): baza naprawdę ma 30 zapisanych
        // wpisów. Bez tego licznik zapytań mógłby przejść na pustym zeszycie
        // i test nie mierzyłby niczego.
        $this->assertSame(self::ILE_WPISOW, $collection->posts()->count());

        $zapytania = [];
        DB::listen(function ($event) use (&$zapytania): void {
            $zapytania[] = $event->sql;
        });

        $response = $this->actingAs($widz)->get(route('collections.show', $collection));

        $response->assertOk();

        $liczbaZapytan = count($zapytania);
        $wpisowNaStronie = $response->viewData('posts')->count();

        fwrite(STDERR, sprintf(
            "\n[T20 zeszyt] zapytan: %d, zapisanych wpisow w bazie: %d, wpisow na stronie: %d\n",
            $liczbaZapytan,
            self::ILE_WPISOW,
            $wpisowNaStronie,
        ));

        // PRZED POPRAWKĄ: strona ładowała WSZYSTKIE 30 zapisanych wpisów
        // (i przez nie 90 zdjęć) w jednym zapytaniu `->get()` bez limitu —
        // liczba zapytań i tak zostawała mała (eager load), ale liczba
        // wierszy rosła bez końca wraz z zeszytem. Po poprawce strona bierze
        // tylko pierwszą stronę (12 pozycji, tak jak `recipes()` obok).
        // DOKŁADNIE dwanaście, nie „najwyżej dwanaście". Górna granica sama
        // przechodzi też wtedy, gdy strona nie pokazuje NICZEGO — zmierzone:
        // po zepsuciu filtra widoczności (`->whereRaw('1 = 0')`) strona
        // oddawała zero wpisów, a asercja `assertLessThanOrEqual` dalej
        // świeciła zielono. Pełna pierwsza strona jest tu jedyną wartością,
        // która jednocześnie dowodzi, że limit DZIAŁA (nie 30) i że lista
        // NIE ZNIKŁA (nie 0).
        $this->assertSame(
            (int) config('kuking.collections.saved_posts_page_size'),
            $wpisowNaStronie,
            'Zeszyt ma oddać pełną pierwszą stronę zapisanych wpisów: '
            .'ani wszystkich 30 naraz, ani zera.',
        );
    }
}

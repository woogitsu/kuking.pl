<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Issue #1952: `/odkryj` (trasa `discover`) była publiczna, dostępna bez
 * konta i BEZ ŻADNEGO limitu zapytań — inaczej niż `/szukaj` i `/pytania`,
 * które od dawna stoją za `throttle:{$limits['search']}`. Zapytanie za tą
 * trasą liczy `row_number() OVER (PARTITION BY posts.author_id ...)` na
 * wszystkich publicznych wpisach PRZED odcięciem strony
 * (`DiscoverFeed::paginate()`, issue #1807) i dokłada eager loading autora,
 * zdjęć, przepisu, zdjęcia przepisu, tagów oraz liczników komentarzy
 * i zapisów — więc powtarzalne, automatyczne odpytywanie tej trasy jest
 * wyraźnie droższe niż zwykły `SELECT`.
 *
 * WZORZEC TEGO TESTU jest ten sam co `ZdjeciaLimitZapytanTest` (audyt
 * W7-02): najpierw dowód, że sensowna ilość normalnego przeglądania mieści
 * się w budżecie, potem wyczerpanie całego zadeklarowanego budżetu bez
 * odbicia, i DOPIERO WTEDY kontrola, że limit naprawdę istnieje i się
 * odzywa. Limit czytamy z `config/kuking.php`, nie wklejamy liczby — patrz
 * uzasadnienie tego wzorca w `LimityTrasZapisujacychTest`.
 */
class OdkrywanieLimitZapytanTest extends TestCase
{
    use RefreshDatabase;

    public function test_limit_z_configu_dziala_i_miesci_zwykle_przegladanie(): void
    {
        $limit = (int) explode(',', (string) config('kuking.limits.discover'))[0];

        $this->assertGreaterThanOrEqual(
            30,
            $limit,
            'Limit trasy „Odkryj" spadł poniżej rzędu wielkości, w którym mieści się '
            .'zwykły wieczór przeglądania i klikania „Pokaż więcej" — to jest zmiana '
            .'w config/kuking.php, nie w kodzie tego testu, i wymaga świadomej decyzji.',
        );

        // Mniej niż połowa budżetu — symulacja zwykłego przeglądania z jednego
        // gospodarstwa domowego (kilka osób za jednym łączem, kilka kliknięć
        // „Pokaż więcej"). Żadne z tych żądań nie może dostać 429 — inaczej
        // zwykłe wejście na stronę wyglądałoby jak awaria serwisu.
        $polowaZapasu = intdiv($limit, 2) - 1;

        for ($i = 0; $i < $polowaZapasu; $i++) {
            $status = $this->get(route('discover'))->getStatusCode();

            $this->assertNotSame(
                429,
                $status,
                "Żądanie numer {$i} (poniżej połowy budżetu {$limit}/min) o „Odkryj\" "
                .'dostało 429 — zwykłe przeglądanie zostałoby odbite.',
            );
        }

        // Reszta budżetu, do granicy włącznie.
        for ($i = $polowaZapasu; $i < $limit; $i++) {
            $status = $this->get(route('discover'))->getStatusCode();

            $this->assertNotSame(
                429,
                $status,
                "Żądanie numer {$i} (w granicach zadeklarowanego limitu {$limit}/min) dostało 429.",
            );
        }

        // I dopiero TERAZ, po wyczerpaniu całego zadeklarowanego budżetu,
        // limit ma się odezwać. KONTROLA UJEMNA: bez tej asercji test
        // przechodziłby też wtedy, gdyby limitu nie było wcale.
        $odbicie = $this->get(route('discover'));

        $this->assertSame(
            429,
            $odbicie->getStatusCode(),
            'Po wyczerpaniu całego limitu z config/kuking.php trasa „Odkryj" powinna '
            .'odpowiedzieć 429 — jeśli nie odpowiada, limit nie działa wcale, a trasa '
            .'jest bez żadnej ochrony przed powtarzalnym, kosztownym zapytaniem '
            .'(issue #1952).',
        );
    }

    /**
     * REGRESJA WPROST: przed poprawką `/odkryj` nie miała ŻADNEGO middleware
     * `throttle:` w routes/web.php. Test wyżej już to łapie (nie dostałby
     * nigdy 429), ale ten sprawdza samą trasę wprost, więc literówka w nazwie
     * middleware albo usunięcie go przy następnej zmianie tego pliku psuje
     * ten test, zanim ktoś zdąży to zmierzyć ręcznie.
     */
    public function test_trasa_odkryj_ma_middleware_throttle(): void
    {
        $trasa = Route::getRoutes()->getByName('discover');

        $this->assertNotNull($trasa, 'Trasa „discover" zniknęła z routingu.');

        $maLimit = false;

        foreach ($trasa->gatherMiddleware() as $warstwa) {
            if (is_string($warstwa) && str_starts_with($warstwa, 'throttle:')) {
                $maLimit = true;
                break;
            }
        }

        $this->assertTrue(
            $maLimit,
            'Trasa „Odkryj" (`/odkryj`) znowu nie ma middleware `throttle:` — '
            .'zobacz issue #1952 i uzasadnienie przy `limits.discover` '
            .'w config/kuking.php.',
        );
    }

    /**
     * Odkrywanie zostaje CHRONOLOGICZNE mimo limitu — ten test nie sprawdza
     * limitu, tylko to, że dodanie go nie ruszyło reguły doboru z AGENTS.md
     * §8 (limit żąda middleware'u przed kontrolerem, nie zmienia zapytania).
     */
    public function test_limit_nie_zmienia_kolejnosci_chronologicznej_feedu(): void
    {
        $autor = $this->user('autorchron1952');
        $starszy = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'published_at' => now()->subMinutes(10),
        ]);
        $autor2 = $this->user('autorchron1952b');
        $nowszy = Post::factory()->create([
            'author_id' => $autor2->getKey(),
            'published_at' => now()->subMinute(),
        ]);

        $html = (string) $this->get(route('discover'))->assertOk()->getContent();

        $pozycjaNowszego = strpos($html, (string) $nowszy->getKey());
        $pozycjaStarszego = strpos($html, (string) $starszy->getKey());

        $this->assertNotFalse($pozycjaNowszego, 'Nowszy wpis nie pojawił się na „Odkryj".');
        $this->assertNotFalse($pozycjaStarszego, 'Starszy wpis nie pojawił się na „Odkryj".');
        $this->assertLessThan(
            $pozycjaStarszego,
            $pozycjaNowszego,
            'Wpis nowszy powinien stać przed starszym — limit zapytań nie ma prawa '
            .'ruszyć chronologii feedu (AGENTS.md §8).',
        );
    }
}

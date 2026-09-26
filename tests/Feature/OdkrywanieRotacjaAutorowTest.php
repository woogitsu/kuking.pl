<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1807 — „Świeżo z Kuking" rotuje autorów: najpierw najnowszy wpis
 * każdej osoby, potem drugi każdej i tak dalej.
 *
 * Zastępuje „jeden wpis na autora w całej sekwencji" z #940. Gwarancja z #940
 * zostaje (pierwsza runda: po jednym wpisie od osoby, seria jednej osoby nie
 * zasłania innych), ale starsze wpisy już nie giną — głębokość Odkrywania to
 * liczba wpisów, nie liczba autorów.
 *
 * Testy sprawdzają MODELE zwrócone przez `DiscoverFeed` (i widok landingu),
 * nie kolejność ułożoną w teście.
 *
 * Kontrola ujemna (sprawdzona przy pisaniu): `->orderBy('rotacja.runda')`
 * usunięte → pierwsza runda przestaje iść pierwsza i test 10 × 5 oblewa;
 * warunek `published_at <= chwila` usunięty → test nowego wpisu w trakcie
 * przeglądania dostaje duplikat.
 */
class OdkrywanieRotacjaAutorowTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, string $tresc, int $minutTemu, array $inne = []): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => $tresc,
            'published_at' => now()->subMinutes($minutTemu),
            ...$inne,
        ]);
    }

    /** @return list<string> */
    private function tresci(?User $widz, int $naStronie = 20): array
    {
        return (new DiscoverFeed)->paginate($widz, $naStronie)->getCollection()->pluck('body')->all();
    }

    /**
     * Przechodzi wszystkie strony tak, jak robi to przeglądarka: kursor
     * i `stan` z adresu następnej strony.
     *
     * @param  (callable(int): void)|null  $miedzyStronami
     * @return list<list<Post>>
     */
    private function wszystkieStrony(?User $widz, int $naStronie, ?callable $miedzyStronami = null): array
    {
        $feed = new DiscoverFeed;
        $strony = [];
        $stan = null;
        $this->app['request']->query->remove('cursor');

        while (true) {
            /** @var CursorPaginator<int, Post> $strona */
            $strona = $feed->paginate($widz, $naStronie, $stan);
            $strony[] = $strona->getCollection()->all();

            if (! $strona->hasMorePages()) {
                return $strony;
            }

            parse_str((string) parse_url((string) $strona->nextPageUrl(), PHP_URL_QUERY), $zapytanie);
            $this->assertArrayHasKey('stan', $zapytanie, 'Odnośnik następnej strony gubi chwilę listy.');
            $stan = $zapytanie['stan'];
            $this->app['request']->query->set('cursor', $zapytanie['cursor']);

            if ($miedzyStronami !== null) {
                $miedzyStronami(count($strony));
            }
        }
    }

    public function test_seria_jednej_osoby_nie_zaslania_innych_ale_nie_ginie(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('rzadka');
        $this->wpis($a, 'A najnowszy', 1);
        $this->wpis($a, 'A drugi', 2);
        $this->wpis($a, 'A trzeci', 3);
        $this->wpis($b, 'B starszy', 60);

        $this->assertSame(['A najnowszy', 'B starszy', 'A drugi', 'A trzeci'], $this->tresci(null));
        // Mały limit — pierwsza runda idzie pierwsza, nawet gdy strona jest krótka.
        $this->assertSame(['A najnowszy', 'B starszy'], $this->tresci(null, 2));
    }

    public function test_dziesiec_osob_po_piec_wpisow_to_piecdziesiat_kart_w_rundach(): void
    {
        $autorzy = [];
        foreach (range(0, 9) as $i) {
            $autorzy[$i] = $this->user("osoba{$i}");
        }
        // Wpisy przeplecione w czasie tak, żeby „najnowsze na górze" dałoby
        // inną kolejność niż rundy: osoba 0 publikuje wszystko najświeżej.
        foreach (range(0, 9) as $i) {
            foreach (range(1, 5) as $n) {
                $this->wpis($autorzy[$i], "O{$i}-{$n}", $i * 10 + $n);
            }
        }

        $strony = $this->wszystkieStrony(null, 15);
        $wszystkie = array_merge(...$strony);

        $this->assertCount(50, $wszystkie);
        $this->assertCount(50, array_unique(array_map(fn (Post $p) => $p->id, $wszystkie)), 'Kursor powtórzył wpis.');

        // Na pierwszej stronie jest każda z dziesięciu osób, a pierwsze
        // dziesięć kart to każda osoba dokładnie raz — jej najnowszy wpis.
        $pierwsza = $strony[0];
        $this->assertCount(10, array_unique(array_map(fn (Post $p) => $p->author_id, $pierwsza)));
        $pierwszaRunda = array_slice($pierwsza, 0, 10);
        $this->assertCount(10, array_unique(array_map(fn (Post $p) => $p->author_id, $pierwszaRunda)));
        $this->assertSame(
            array_map(fn (int $i) => "O{$i}-1", range(0, 9)),
            array_map(fn (Post $p) => $p->body, $pierwszaRunda),
        );

        // Całość: runda po rundzie, w rundzie od najnowszego.
        $oczekiwane = [];
        foreach (range(1, 5) as $n) {
            foreach (range(0, 9) as $i) {
                $oczekiwane[] = "O{$i}-{$n}";
            }
        }
        $this->assertSame($oczekiwane, array_map(fn (Post $p) => $p->body, $wszystkie));
    }

    public function test_kolejne_strony_nie_gubia_i_nie_powtarzaja(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('druga');
        $c = $this->user('trzecia');
        $this->wpis($a, 'A1', 1);
        $this->wpis($a, 'A2', 2);
        $this->wpis($b, 'B1', 3);
        $this->wpis($a, 'A3', 4);
        $this->wpis($c, 'C1', 5);

        $widziane = array_map(fn (Post $p) => $p->body, array_merge(...$this->wszystkieStrony(null, 1)));

        $this->assertSame(['A1', 'B1', 'C1', 'A2', 'A3'], $widziane);
    }

    public function test_nowy_wpis_w_trakcie_przegladania_nie_dubluje_starszych(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('druga');
        $this->wpis($a, 'A1', 10);
        $this->wpis($a, 'A2', 20);
        $this->wpis($a, 'A3', 30);
        $this->wpis($b, 'B1', 15);
        $this->wpis($b, 'B2', 25);

        $strony = $this->wszystkieStrony(null, 2, function (int $poStronie) use ($a): void {
            if ($poStronie === 1) {
                // Bez wspólnej chwili A1 spadłby do rundy drugiej i wrócił na
                // stronie drugiej — drugi raz ten sam wpis. Dwie sekundy
                // później, bo tak czyta się stronę (granica ma sekundową
                // dokładność — patrz komentarz w `DiscoverFeed`).
                $this->travel(2)->seconds();
                $this->wpis($a, 'A nowy w trakcie', 0);
            }
        });

        $widziane = array_map(fn (Post $p) => $p->body, array_merge(...$strony));
        $this->assertSame(['A1', 'B1', 'A2', 'B2', 'A3'], $widziane);

        // Następne wejście (pierwsza strona bez `stan`) widzi nowy wpis od razu.
        $this->app['request']->query->remove('cursor');
        $this->assertSame('A nowy w trakcie', $this->tresci(null)[0]);
    }

    public function test_stan_z_przyszlosci_albo_zepsuty_nie_zmienia_listy(): void
    {
        $a = $this->user('aktywna');
        $this->wpis($a, 'A1', 1);

        foreach ([(string) now()->addDay()->timestamp, 'nie-liczba', '1.5', (string) now()->subDays(3)->timestamp] as $stan) {
            $this->assertSame(['A1'], (new DiscoverFeed)->paginate(null, 20, $stan)->getCollection()->pluck('body')->all(), $stan);
        }
    }

    /**
     * Regresja (przegląd #1781, pkt 7): kursor rotacji z `stan` starszym niż
     * doba albo bez `stan` był łączony z NOWĄ chwilą — rundy liczone na inną
     * chwilę niż ta, na którą powstał kursor, więc lista po cichu gubiła albo
     * powtarzała wpisy. Teraz taki adres zaczyna od początku.
     *
     * Kontrola ujemna: `$kursor = $this->kursorRotacji()` bez warunku na
     * chwilę → stary `stan` daje „B1" (dalszą stronę) zamiast „A1".
     */
    public function test_kursor_ze_starym_albo_brakujacym_stanem_zaczyna_od_poczatku(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('druga');
        $this->wpis($a, 'A1', 1);
        $this->wpis($b, 'B1', 2);
        $this->wpis($a, 'A2', 3);

        $pierwsza = (new DiscoverFeed)->paginate(null, 1);
        parse_str((string) parse_url((string) $pierwsza->nextPageUrl(), PHP_URL_QUERY), $zapytanie);
        $kursor = $zapytanie['cursor'];
        config(['kuking.feed.page_size' => 1]);

        $tresci = fn (array $parametry): array => $this->get(route('discover', $parametry))
            ->assertOk()->viewData('posts')->getCollection()->pluck('body')->all();

        // Kontrola dodatnia: kursor ze swoją chwilą prowadzi dalej.
        $this->assertSame(['B1'], $tresci(['cursor' => $kursor, 'stan' => $zapytanie['stan']]));

        $this->assertSame(['A1'], $tresci(['cursor' => $kursor, 'stan' => (string) now()->subDays(3)->timestamp]), 'Stan starszy niż doba');
        $this->assertSame(['A1'], $tresci(['cursor' => $kursor]), 'Brak stanu');
        $this->assertSame(['A1'], $tresci(['cursor' => $kursor, 'stan' => 'nie-liczba']), 'Zepsuty stan');
    }

    /**
     * Regresja: kursor sprzed rotacji (#940 — `published_at` + `id`) z zakładki
     * albo z karty otwartej w chwili wdrożenia dawał 500 („Unable to find
     * parameter [rotacja.runda]"). Teraz zaczyna od pierwszej strony.
     */
    public function test_kursor_sprzed_rotacji_zaczyna_od_poczatku_zamiast_bledu(): void
    {
        $a = $this->user('aktywna');
        $this->wpis($a, 'Pierogi ruskie najnowsze', 1);
        $this->wpis($a, 'Bigos starszy', 2);
        $stary = (new Cursor(['published_at' => now()->toDateTimeString(), 'id' => $a->id]))->encode();

        foreach ([$stary, 'nieczytelny'] as $kursor) {
            $this->assertSame(
                ['Pierogi ruskie najnowsze', 'Bigos starszy'],
                $this->get(route('discover').'?cursor='.$kursor)->assertOk()->viewData('posts')->getCollection()->pluck('body')->all(),
            );
        }

        // Kontrola dodatnia: kursor rotacji dalej działa.
        $pierwsza = (new DiscoverFeed)->paginate(null, 1);
        $this->assertSame(
            ['posts.id', 'posts.published_at', 'rotacja.runda'],
            collect($pierwsza->nextCursor()->toArray())->except('_pointsToNextItems')->keys()->sort()->values()->all(),
        );
        // viewData, nie HTML: tablica „kuKINGi na dziś" w szynie pokazuje
        // dzisiejsze wpisy niezależnie od strony listy.
        $this->assertSame(
            ['Bigos starszy'],
            $this->get((string) $pierwsza->nextPageUrl())->assertOk()->viewData('posts')->getCollection()->pluck('body')->all(),
        );
    }

    public function test_remis_czasu_rozstrzyga_id_w_rundzie_i_w_kolejnosci(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('druga');
        $chwila = now()->subMinutes(5);
        $a1 = $this->wpis($a, 'A mniejsze id', 0, ['published_at' => $chwila]);
        $a2 = $this->wpis($a, 'A większe id', 0, ['published_at' => $chwila]);
        $b1 = $this->wpis($b, 'B ten sam czas', 0, ['published_at' => $chwila]);

        // Oczekiwanie liczone z faktycznych identyfikatorów, nie z kolejności
        // tworzenia — UUID z tej samej milisekundy nie musi rosnąć.
        [$reprezentantA, $drugiA] = strcmp($a1->id, $a2->id) > 0 ? [$a1, $a2] : [$a2, $a1];
        $pierwszaRunda = strcmp($b1->id, $reprezentantA->id) > 0
            ? [$b1->body, $reprezentantA->body]
            : [$reprezentantA->body, $b1->body];

        $this->assertSame([...$pierwszaRunda, $drugiA->body], $this->tresci(null));
    }

    public function test_niedostepny_najnowszy_wpis_oddaje_runde_starszemu(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('druga');
        $widz = $this->user('widz');
        $schowany = Recipe::factory()->create(['author_id' => $a->getKey(), 'status' => Recipe::STATUS_HIDDEN]);
        $this->wpis($a, 'A do schowanego przepisu', 1, ['recipe_id' => $schowany->getKey()]);
        $this->wpis($a, 'A tylko dla obserwujących', 2, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $this->wpis($a, 'A publiczny starszy', 30);
        $this->wpis($b, 'B', 10);
        $this->wpis($b, 'B starszy', 20);

        // Odsiane wpisy nie zostawiają dziury w rundach: publiczny wpis A
        // jest w PIERWSZEJ rundzie, nie w trzeciej.
        $this->assertSame(['B', 'A publiczny starszy', 'B starszy'], $this->tresci(null));
        $this->assertSame(['B', 'A publiczny starszy', 'B starszy'], $this->tresci($widz));
    }

    public function test_blokada_i_zawieszenie_zdejmuja_autora_ale_nie_innych(): void
    {
        $a = $this->user('zablokowana');
        $z = $this->user('zawieszona');
        $b = $this->user('zwykla');
        $widz = $this->user('widz');
        $this->wpis($a, 'A', 1);
        $this->wpis($z, 'Z', 2);
        $this->wpis($b, 'B', 3);
        $this->wpis($b, 'B starszy', 4);
        $z->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        DB::table('blocks')->insert(['blocker_id' => $widz->id, 'blocked_id' => $a->id, 'created_at' => now()]);

        $this->assertSame(['B', 'B starszy'], $this->tresci($widz));
        $this->assertSame(['A', 'B', 'B starszy'], $this->tresci(null));

        // Blokada w drugą stronę też odcina — ale widz jej nie „ukrywa".
        $odcieta = $this->user('odcieta');
        DB::table('blocks')->insert(['blocker_id' => $b->id, 'blocked_id' => $odcieta->id, 'created_at' => now()]);
        $this->assertSame(['A'], $this->tresci($odcieta));
        $this->assertSame(0, (new DiscoverFeed)->ileUkrywa($odcieta));
        $this->assertSame(1, (new DiscoverFeed)->ileUkrywa($widz));
    }

    public function test_landing_goscia_zaczyna_od_pierwszej_rundy(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('rzadka');
        foreach (range(1, 12) as $i) {
            $this->wpis($a, "A {$i}", $i);
        }
        $this->wpis($b, 'B starszy', 60);

        $posty = $this->get(route('landing'))->assertOk()->viewData('posts')->getCollection();

        $this->assertSame(['A 1', 'B starszy', 'A 2', 'A 3', 'A 4', 'A 5', 'A 6', 'A 7', 'A 8'], $posty->pluck('body')->all());
    }
}

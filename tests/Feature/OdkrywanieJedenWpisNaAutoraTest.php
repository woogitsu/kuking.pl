<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #940 — „Świeżo z Kuking" pokazuje najwyżej jeden wpis każdej osoby.
 *
 * Nagłówek `DiscoverFeed` obiecywał to od pierwszego commita, ale zapytanie
 * zwracało wszystko chronologicznie: jedna osoba z serią wpisów wypełniała
 * pierwszą stronę i landing gościa. Testy sprawdzają MODELE zwrócone przez
 * `DiscoverFeed` (i widok landingu), nie deduplikację zrobioną w teście.
 *
 * Kontrola dodatnia: bez `whereIn('posts.id', $najnowszyKazdegoAutora)`
 * pierwszy test dostaje trzy wpisy A zamiast A i B.
 */
class OdkrywanieJedenWpisNaAutoraTest extends TestCase
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

    public function test_seria_jednej_osoby_nie_zaslania_innych(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('rzadka');
        $this->wpis($a, 'A najnowszy', 1);
        $this->wpis($a, 'A drugi', 2);
        $this->wpis($a, 'A trzeci', 3);
        $this->wpis($b, 'B starszy', 60);

        $this->assertSame(['A najnowszy', 'B starszy'], $this->tresci(null));
        // Mały limit — dokładnie to, czego wymaga kontrola ujemna z issue.
        $this->assertSame(['A najnowszy', 'B starszy'], $this->tresci(null, 2));
    }

    public function test_kolejne_strony_nie_powtarzaja_autora_i_nie_gubia_innych(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('druga');
        $c = $this->user('trzecia');
        $this->wpis($a, 'A1', 1);
        $this->wpis($a, 'A2', 2);
        $this->wpis($b, 'B1', 3);
        $this->wpis($a, 'A3', 4);
        $this->wpis($c, 'C1', 5);

        $feed = new DiscoverFeed;
        $widziane = [];
        $strona = $feed->paginate(null, 1);
        while (true) {
            $widziane = [...$widziane, ...$strona->getCollection()->pluck('body')->all()];
            if (! $strona->hasMorePages()) {
                break;
            }
            $this->app['request']->query->set('cursor', $strona->nextCursor()->encode());
            $strona = $feed->paginate(null, 1);
        }

        $this->assertSame(['A1', 'B1', 'C1'], $widziane);
    }

    public function test_remis_czasu_rozstrzyga_id_w_wyborze_i_w_kolejnosci(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('druga');
        $chwila = now()->subMinutes(5);
        $a1 = $this->wpis($a, 'A mniejsze id', 0, ['published_at' => $chwila]);
        $a2 = $this->wpis($a, 'A większe id', 0, ['published_at' => $chwila]);
        $b1 = $this->wpis($b, 'B ten sam czas', 0, ['published_at' => $chwila]);

        // Oczekiwanie liczone z faktycznych identyfikatorów, nie z kolejności
        // tworzenia — UUID z tej samej milisekundy nie musi rosnąć.
        $reprezentantA = strcmp($a1->id, $a2->id) > 0 ? $a1 : $a2;
        $oczekiwane = strcmp($b1->id, $reprezentantA->id) > 0
            ? [$b1->body, $reprezentantA->body]
            : [$reprezentantA->body, $b1->body];

        $this->assertSame($oczekiwane, $this->tresci(null));
    }

    public function test_niedostepny_najnowszy_wpis_oddaje_miejsce_starszemu(): void
    {
        $a = $this->user('aktywna');
        $widz = $this->user('widz');
        $schowany = Recipe::factory()->create(['author_id' => $a->getKey(), 'status' => Recipe::STATUS_HIDDEN]);
        // Sama zapowiedź przepisu (bez własnej treści): wpis z własnym
        // tekstem zostaje na liście według własnej widoczności (issue #1377),
        // a zapowiedź znika razem ze schowanym przepisem.
        $this->wpis($a, 'A do schowanego przepisu', 1, ['recipe_id' => $schowany->getKey(), 'body' => null]);
        $this->wpis($a, 'A tylko dla obserwujących', 2, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $this->wpis($a, 'A publiczny starszy', 3);

        $this->assertSame(['A publiczny starszy'], $this->tresci(null));
        $this->assertSame(['A publiczny starszy'], $this->tresci($widz));
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

        $this->assertSame(['B'], $this->tresci($widz));
        $this->assertSame(['A', 'B'], $this->tresci(null));
    }

    public function test_landing_goscia_ma_te_sama_gwarancje(): void
    {
        $a = $this->user('aktywna');
        $b = $this->user('rzadka');
        foreach (range(1, 12) as $i) {
            $this->wpis($a, "A {$i}", $i);
        }
        $this->wpis($b, 'B starszy', 60);

        $posty = $this->get(route('landing'))->assertOk()->viewData('posts')->getCollection();

        $this->assertSame(['A 1', 'B starszy'], $posty->pluck('body')->all());
    }
}

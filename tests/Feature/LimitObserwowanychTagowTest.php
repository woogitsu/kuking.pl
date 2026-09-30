<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use App\Models\User;
use App\Support\LimityTagow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #2326 — liczba obserwowanych tagów ma granicę, a ekran „Twoje tagi”
 * pobiera ograniczoną porcję zamiast dwóch pełnych kolekcji modeli.
 *
 * Limit jest w `config('kuking.tags.max_followed')`; testy zawężają go do
 * kilku pozycji, żeby granica była widoczna bez tworzenia setek wierszy.
 */
class LimitObserwowanychTagowTest extends TestCase
{
    use RefreshDatabase;

    private int $pozycja = 0;

    private function promowany(string $nazwa): Tag
    {
        $tag = Tag::factory()->create(['name' => $nazwa, 'normalized_name' => Tag::znormalizujNazwe($nazwa)]);
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $this->pozycja++]);

        return $tag;
    }

    private function obserwowanych(User $user): int
    {
        return DB::table('tag_follows')->where('user_id', $user->getKey())->count();
    }

    /**
     * Wstawia `$ile` tagów z pominięciem akcji — tak wygląda konto sprzed
     * limitu (fixture obciążeniowy: 978).
     *
     * @return list<string> identyfikatory w kolejności nazw
     */
    private function obserwujHurtem(User $user, int $ile, string $prefiks = 'Stary'): array
    {
        $wiersze = array_map(fn (int $i): array => [
            'id' => (string) Str::uuid(),
            'slug' => Str::slug("{$prefiks}-{$i}"),
            'name' => sprintf('%s %03d', $prefiks, $i),
            'normalized_name' => mb_strtolower(sprintf('%s %03d', $prefiks, $i)),
        ], range(1, $ile));
        DB::table('tags')->insert($wiersze);
        DB::table('tag_follows')->insert(array_map(fn (array $t): array => [
            'user_id' => $user->getKey(), 'tag_id' => $t['id'], 'created_at' => now(),
        ], $wiersze));

        return array_column($wiersze, 'id');
    }

    private function poleUkryte(string $html, string $nazwa): ?string
    {
        preg_match('/<input type="hidden" name="'.preg_quote($nazwa, '/').'" value="([^"]*)"/', $html, $m);

        return $m[1] ?? null;
    }

    /** @return list<string> zaznaczone i ukryte pola `tags[]` — to, co odeśle przeglądarka */
    private function odsylane(string $html): array
    {
        preg_match_all('/<input\b[^>]*name="tags\[\]"[^>]*>/', $html, $pola);
        $wynik = [];
        foreach ($pola[0] as $pole) {
            preg_match('/value="([^"]*)"/', $pole, $w);
            if (str_contains($pole, 'type="hidden"') || preg_match('/\bchecked\b/', $pole)) {
                $wynik[] = $w[1];
            }
        }

        return $wynik;
    }

    public function test_przycisk_obserwuj_przy_pelnej_liscie_mowi_co_zrobic(): void
    {
        config(['kuking.tags.max_followed' => 2]);
        $user = $this->user();
        [$a, $b, $c] = [$this->promowany('Zupy'), $this->promowany('Ciasta'), $this->promowany('Pierogi')];

        $this->actingAs($user)->post(route('tags.follow', $a))->assertRedirect();
        $this->actingAs($user)->post(route('tags.follow', $b))->assertRedirect();
        // Kontrola dodatnia: do granicy wszystko wchodzi.
        $this->assertSame(2, $this->obserwowanych($user));

        $this->actingAs($user)->from(route('tags.show', $c))->post(route('tags.follow', $c))
            ->assertRedirect(route('tags.show', $c))
            ->assertSessionHas('status', LimityTagow::komunikatLimituObserwowanych())
            ->assertSessionHas('status_akcja.url', route('settings.tags'));
        $this->assertSame(2, $this->obserwowanych($user));
        $this->assertStringContainsString('najwyżej 2 tagi', LimityTagow::komunikatLimituObserwowanych());

        // Ponowne „Obserwuj” tagu już obserwowanego nie dokłada relacji,
        // więc nie ma czego odmawiać — także przy pełnej liście.
        $this->actingAs($user)->post(route('tags.follow', $a))
            ->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'Obserwujesz tag'));
    }

    public function test_formularz_ponad_limit_odmawia_i_zostawia_zaznaczenia(): void
    {
        config(['kuking.tags.max_followed' => 2]);
        $user = $this->user();
        $tagi = [$this->promowany('Zupy'), $this->promowany('Ciasta'), $this->promowany('Pierogi')];
        $id = array_map(fn (Tag $t): string => $t->getKey(), $tagi);

        $html = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
        $this->actingAs($user)->from(route('settings.tags'))->put(route('settings.tags.update'), [
            'form_scope' => $this->poleUkryte($html, 'form_scope'),
            'tags' => $id,
        ])->assertRedirect(route('settings.tags'))
            ->assertSessionHasErrors(['tags' => LimityTagow::komunikatLimituNaLiscieTwoichTagow()]);
        $this->assertSame(0, $this->obserwowanych($user));

        // Poprawne dane nie znikają: po powrocie wszystkie trzy nadal zaznaczone.
        // Przekierowanie w przeglądarce niesie tę samą sesję.
        $this->withCookie(config('session.cookie'), session()->getId());
        $powrot = $this->get(route('settings.tags'))->assertOk()->getContent();
        $this->assertEqualsCanonicalizing($id, $this->odsylane($powrot));
        $this->assertStringContainsString(e(LimityTagow::komunikatLimituNaLiscieTwoichTagow()), $powrot);
        $this->assertStringNotContainsString('jest w ustawieniach', $powrot, 'Na samej liście komunikat odsyła do tej samej listy.');
    }

    public function test_zamiana_tagu_przy_pelnej_liscie_przechodzi(): void
    {
        config(['kuking.tags.max_followed' => 2]);
        $user = $this->user();
        [$a, $b, $c] = [$this->promowany('Zupy'), $this->promowany('Ciasta'), $this->promowany('Pierogi')];
        $user->followedTags()->attach([$a->getKey() => ['created_at' => now()], $b->getKey() => ['created_at' => now()]]);

        $html = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
        $this->actingAs($user)->put(route('settings.tags.update'), [
            'form_scope' => $this->poleUkryte($html, 'form_scope'),
            'tags' => [$b->getKey(), $c->getKey()],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$b->getKey(), $c->getKey()],
            DB::table('tag_follows')->where('user_id', $user->getKey())->pluck('tag_id')->all(),
        );
    }

    public function test_krok_powitalny_ponad_limit_odmawia_bez_zapisu(): void
    {
        config(['kuking.tags.max_followed' => 1]);
        $user = $this->user();
        $a = $this->promowany('Zupy');
        $b = $this->promowany('Ciasta');

        $this->actingAs($user)->from(route('onboarding.interests'))
            ->post('/witaj/zainteresowania', ['tags' => [$a->getKey(), $b->getKey()]])
            ->assertRedirect(route('onboarding.interests'))
            ->assertSessionHasErrors(['tags' => LimityTagow::komunikatLimituObserwowanych()])
            ->assertSessionHasInput('tags', [$a->getKey(), $b->getKey()]);
        $this->assertSame(0, $this->obserwowanych($user));
    }

    /**
     * Audyt UX 50+: komunikat w kroku powitalnym odsyła do listy „Twoje tagi”,
     * więc obok stoi przycisk do ustawień tagów. Bez limitu przycisku nie ma.
     */
    public function test_krok_powitalny_przy_limicie_ma_przycisk_do_ustawien_tagow(): void
    {
        config(['kuking.tags.max_followed' => 1]);
        $user = $this->user();
        $a = $this->promowany('Zupy');
        $b = $this->promowany('Ciasta');
        $przycisk = 'href="'.route('settings.tags').'">Przejdź do „Twoich tagów”</a>';

        $this->actingAs($user)->get(route('onboarding.interests'))->assertOk()->assertDontSee($przycisk, false);

        $this->actingAs($user)->from(route('onboarding.interests'))
            ->post('/witaj/zainteresowania', ['tags' => [$a->getKey(), $b->getKey()]])
            ->assertSessionHasErrors('tags');
        // Przekierowanie w przeglądarce niesie tę samą sesję (jak wyżej).
        $this->withCookie(config('session.cookie'), session()->getId());
        $html = $this->get(route('onboarding.interests'))->assertOk()->getContent();
        $this->assertStringContainsString($przycisk, $html);
    }

    /**
     * Konto sprzed limitu niczego nie traci: nadal może zdejmować, nie może
     * tylko dodać nowego, dopóki nie zejdzie poniżej granicy.
     */
    public function test_konto_ponad_limit_moze_zdejmowac_ale_nie_dodac(): void
    {
        config(['kuking.tags.max_followed' => 3]);
        $user = $this->user();
        $stare = $this->obserwujHurtem($user, 5);
        $nowy = $this->promowany('Zupy');

        $html = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
        $scope = $this->poleUkryte($html, 'form_scope');

        // Zdjęcie jednego — przechodzi, choć po zapisie nadal ponad limitem.
        $this->actingAs($user)->put(route('settings.tags.update'), [
            'form_scope' => $scope,
            'tags' => array_slice($stare, 1),
        ])->assertSessionHasNoErrors();
        $this->assertSame(4, $this->obserwowanych($user));

        $this->actingAs($user)->post(route('tags.follow', $nowy))
            ->assertSessionHas('status', LimityTagow::komunikatLimituObserwowanych());
        $this->assertSame(4, $this->obserwowanych($user));
    }

    /**
     * Kształt i liczba zapytań ekranu „Twoje tagi”: jedno zapytanie o tagi,
     * z granicą, niezależnie od liczby relacji. Przed #2326 były to dwie
     * pełne kolekcje (obserwowane + promowane z promocją) — przy 40 + 40
     * wczytywało 80 modeli.
     */
    public function test_twoje_tagi_pobieraja_ograniczona_porcje(): void
    {
        config(['kuking.tags.max_followed' => 5]);
        $granica = LimityTagow::maksNaLiscieTwoichTagow();
        $user = $this->user();
        $obserwowane = $this->obserwujHurtem($user, 40, 'Obserwowany');
        foreach (range(1, 40) as $i) {
            // Nazwy przed „Obserwowany” w alfabecie: gdyby granica szła
            // samym alfabetem, wypchnęłyby obserwowane z listy.
            $this->promowany(sprintf('Arbuz %03d', $i));
        }

        $wczytane = 0;
        Event::listen('eloquent.retrieved: '.Tag::class, function () use (&$wczytane): void {
            $wczytane++;
        });
        $zapytania = [];
        DB::listen(function (QueryExecuted $q) use (&$zapytania): void {
            if (preg_match('/\bfrom "(tags|tag_follows|tag_promotions)"/', $q->sql)) {
                $zapytania[] = $q->sql;
            }
        });

        $html = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();

        $this->assertLessThanOrEqual($granica + 1, $wczytane, "Ekran wczytał {$wczytane} modeli Tag przy 80 w sumie.");
        $this->assertCount(1, $zapytania, "Zapytania o tagi:\n".implode("\n", $zapytania));
        $this->assertStringContainsString('limit '.($granica + 1), $zapytania[0]);

        // Obserwowane idą w granicy pierwsze — każde widoczne da się zdjąć.
        $this->assertSame(array_slice($obserwowane, 0, $granica), $this->odsylane($html));
        $this->assertStringContainsString('data-rola="lista-tagow-obcieta"', $html);
    }

    public function test_krotka_lista_nie_mowi_o_granicy(): void
    {
        $user = $this->user();
        $this->promowany('Zupy');

        $html = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();
        $this->assertStringContainsString('Zupy', $html);
        $this->assertStringNotContainsString('data-rola="lista-tagow-obcieta"', $html);
    }

    /**
     * Sprawdzenie limitu nie wczytuje relacji: dwa zapytania agregujące na
     * `tag_follows` (ile z wybranych już jest, czy jest miejsce) — żadne
     * nie zwraca listy wierszy konta.
     */
    public function test_sprawdzenie_limitu_nie_materializuje_relacji(): void
    {
        config(['kuking.tags.max_followed' => 100]);
        $user = $this->user();
        $this->obserwujHurtem($user, 60);
        $nowy = $this->promowany('Zupy');

        $zapytania = [];
        DB::listen(function (QueryExecuted $q) use (&$zapytania): void {
            if (str_contains($q->sql, '"tag_follows"')) {
                $zapytania[] = $q->sql;
            }
        });

        $this->actingAs($user)->post(route('tags.follow', $nowy))
            ->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'Obserwujesz tag'));

        $odczyty = array_values(array_filter($zapytania, fn (string $sql): bool => str_starts_with($sql, 'select')));
        $this->assertNotEmpty($odczyty);
        foreach ($odczyty as $sql) {
            $this->assertMatchesRegularExpression('/count\(\*\)|select exists\(/', $sql, "Zapytanie wczytuje wiersze: {$sql}");
        }
        $this->assertSame(61, $this->obserwowanych($user));
    }
}

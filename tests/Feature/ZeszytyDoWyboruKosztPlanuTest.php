<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\ZeszytyDoWyboru;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lista zeszytów do wyboru nie skanuje całej tabeli `collections`
 * (audyt wydajności, W1; `Collection::scopeDostepneDoZapisuDla()`).
 *
 * `owner_id = ? OR (is_default = false AND EXISTS(członkostwo) AND …)` zmuszało
 * planer do Seq Scan po wszystkich zeszytach: szacunek ok. 137 tys. przy 6 tys.
 * zeszytów, ponad `jit_above_cost`, więc PostgreSQL kompilował zapytanie przez
 * JIT na KAŻDEJ stronie z kartami dla zalogowanego (15–33 ms przy 6 tys.,
 * 158 ms przy 30 tys.). Pilnujemy: (1) koszt zapytania poniżej progu JIT;
 * (2) wynik i kolejność bez zmian względem poprzedniego zapytania na macierzy
 * przypadków (własne, wspólne, zbanowany właściciel, blokady, domyślny).
 */
final class ZeszytyDoWyboruKosztPlanuTest extends TestCase
{
    use RefreshDatabase;

    /** Połączenie z bazą, gdy test zasiał duży zbiór (do posprzątania po wycofaniu transakcji). */
    private ?\PDO $poZasiewie = null;

    /** Patrz `FeedObserwowanychKosztPlanuTest::tearDown()`. */
    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->poZasiewie !== null) {
            $this->poZasiewie->exec('VACUUM (ANALYZE) users, profiles, collections, collection_members, blocks');
            $this->poZasiewie = null;
        }
    }

    public function test_koszt_planu_przy_9000_zeszytow_nie_przekracza_progu_jit(): void
    {
        $this->poZasiewie = DB::connection()->getPdo();
        $ja = $this->user('ja_zeszyty_w1');
        $this->zasiej($ja, 9000);
        DB::statement('ANALYZE');

        $this->assertGreaterThan(9000, Collection::query()->count(), 'Kontrola sceny: za mało zeszytów, żeby próg JIT miał znaczenie.');
        $this->assertGreaterThan(3, app(ZeszytyDoWyboru::class)->dla($ja)->count());

        $prog = (float) DB::selectOne("SELECT current_setting('jit_above_cost')::float8 AS prog")->prog;

        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if (preg_match('/^\s*select\b/i', $zapytanie->sql) && str_contains($zapytanie->sql, 'from "collections"')) {
                $zapytania[] = [$zapytanie->sql, $zapytanie->bindings];
            }
        });
        app(ZeszytyDoWyboru::class)->dla($ja);
        $this->assertNotEmpty($zapytania, 'Kontrola testu: nie złapano zapytania o zeszyty.');

        $najdrozsze = ['koszt' => 0.0, 'sql' => ''];
        foreach ($zapytania as [$sql, $parametry]) {
            $plan = json_decode((string) DB::selectOne('EXPLAIN (FORMAT JSON) '.$sql, $parametry)->{'QUERY PLAN'}, true);
            $koszt = (float) $plan[0]['Plan']['Total Cost'];
            if ($koszt > $najdrozsze['koszt']) {
                $najdrozsze = ['koszt' => $koszt, 'sql' => mb_substr($sql, 0, 200)];
            }
        }

        fwrite(STDERR, sprintf("\n[W1 zeszyty do wyboru] najwyższy szacunek %.0f (próg JIT %.0f)\n", $najdrozsze['koszt'], $prog));

        $this->assertLessThan(
            $prog,
            $najdrozsze['koszt'],
            "Zapytanie o zeszyty do wyboru ma szacowany koszt {$najdrozsze['koszt']} ≥ jit_above_cost ({$prog}), więc PostgreSQL kompiluje je przez JIT na każdej stronie z kartami: {$najdrozsze['sql']}",
        );
    }

    public function test_wynik_i_kolejnosc_sa_te_same_co_w_zapytaniu_sprzed_zmiany(): void
    {
        $ja = $this->user('ja_rownowaznosc');
        $wlasciciele = [];
        foreach (['active', 'suspended', 'banned', 'pending_delete', 'erased', 'blokuje_mnie', 'zablokowany_przeze_mnie', 'blokada_obca'] as $nazwa) {
            $wlasciciele[$nazwa] = $this->user('w_'.$nazwa);
        }
        foreach (['suspended', 'banned', 'pending_delete', 'erased'] as $status) {
            DB::table('users')->where('id', $wlasciciele[$status]->getKey())->update(['status' => $status] + ($status === 'erased' ? ['data_erased_at' => now()] : []));
        }
        DB::table('blocks')->insert([
            ['blocker_id' => $wlasciciele['blokuje_mnie']->getKey(), 'blocked_id' => $ja->getKey(), 'created_at' => now()],
            ['blocker_id' => $ja->getKey(), 'blocked_id' => $wlasciciele['zablokowany_przeze_mnie']->getKey(), 'created_at' => now()],
            ['blocker_id' => $wlasciciele['blokada_obca']->getKey(), 'blocked_id' => $wlasciciele['active']->getKey(), 'created_at' => now()],
        ]);

        $oczekiwane = [];
        $zeszyt = function (User $wlasciciel, string $nazwa, bool $domyslny = false, string $widocznosc = 'private') use (&$oczekiwane): Collection {
            $z = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => $widocznosc]);
            if ($domyslny) {
                DB::table('collections')->where('id', $z->getKey())->update(['is_default' => true]);
            }
            $oczekiwane[$nazwa] = $z;

            return $z;
        };
        $wspolny = fn (Collection $z): bool => DB::table('collection_members')->insert(['collection_id' => $z->getKey(), 'user_id' => $ja->getKey(), 'created_at' => now()]);

        $zeszyt($ja, 'Moje B');
        $zeszyt($ja, 'Moje A', widocznosc: 'public');
        $zeszyt($ja, 'Zapisane', domyslny: true, widocznosc: 'public');
        foreach ($wlasciciele as $nazwa => $wlasciciel) {
            $wspolny($zeszyt($wlasciciel, 'Wspolny '.$nazwa));
        }
        $zeszyt($wlasciciele['active'], 'Niezaproszony');
        $zeszyt($wlasciciele['active'], 'Domyslny cudzy', domyslny: true);
        $zeszyt($wlasciciele['active'], 'Wspolny publiczny', widocznosc: 'public');
        $wspolny($oczekiwane['Wspolny publiczny']);
        // Członkostwo INNEJ osoby nie daje dostępu mnie.
        DB::table('collection_members')->insert(['collection_id' => $oczekiwane['Niezaproszony']->getKey(), 'user_id' => $wlasciciele['suspended']->getKey(), 'created_at' => now()]);

        foreach ([$ja, $wlasciciele['suspended'], $wlasciciele['active']] as $osoba) {
            $nowe = Collection::query()->dostepneDoZapisuDla($osoba)->orderBy('id')->pluck('id')->all();
            $stare = $this->zakresSprzedZmiany(Collection::query(), $osoba)->orderBy('id')->pluck('id')->all();
            $this->assertSame($stare, $nowe, 'Zakres „dostępne do zapisu” różni się od poprzedniego zapytania dla '.$osoba->getKey());
        }

        // Kontrola sceny: oczekiwany zbiór dla `ja` (nie same puste listy).
        $nazwy = Collection::query()->dostepneDoZapisuDla($ja)->pluck('name')->all();
        sort($nazwy);
        $this->assertSame([
            'Moje A', 'Moje B', 'Wspolny active', 'Wspolny blokada_obca', 'Wspolny publiczny', 'Wspolny suspended', 'Zapisane',
        ], $nazwy);

        // Lista do wyboru: ta sama kolejność co w poprzednim zapytaniu.
        foreach ([$ja, $wlasciciele['suspended']] as $osoba) {
            $stara = $this->zakresSprzedZmiany(Collection::query(), $osoba)
                ->when($osoba->isSuspended(), fn ($q) => $q->where('visibility', 'private'))
                ->orderByRaw('(collections.owner_id = ?) DESC', [$osoba->getKey()])
                ->orderByDesc('is_default')->orderBy('name')->orderBy('id')
                ->pluck('id')->all();
            $this->assertSame($stara, app(ZeszytyDoWyboru::class)->dla($osoba)->pluck('id')->all(), 'Kolejność listy do wyboru zmieniona dla '.$osoba->getKey());
        }
        $this->assertSame(['Zapisane', 'Moje A', 'Moje B'], array_slice(app(ZeszytyDoWyboru::class)->dla($ja)->pluck('name')->all(), 0, 3));
    }

    /**
     * Zakres dokładnie tak, jak był przed zmianą (skorelowany `EXISTS`).
     *
     * @param  Builder<Collection>  $query
     * @return Builder<Collection>
     */
    private function zakresSprzedZmiany(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('collections.owner_id', $user->getKey())
            ->orWhere(fn (Builder $wspolne) => $wspolne
                ->where('collections.is_default', false)
                ->whereExists(fn ($czlonek) => $czlonek->selectRaw('1')
                    ->from('collection_members')
                    ->whereColumn('collection_members.collection_id', 'collections.id')
                    ->where('collection_members.user_id', $user->getKey()))
                ->whereHas('owner', fn (Builder $wlasciciel) => $wlasciciel->widocznyJakoOsoba())
                ->whereNotExists(fn ($blokada) => $blokada->selectRaw('1')
                    ->from('blocks')
                    ->where(fn ($para) => $para
                        ->where(fn ($a) => $a->whereColumn('blocks.blocker_id', 'collections.owner_id')->where('blocks.blocked_id', $user->getKey()))
                        ->orWhere(fn ($b) => $b->where('blocks.blocker_id', $user->getKey())->whereColumn('blocks.blocked_id', 'collections.owner_id'))))));
    }

    /**
     * Zeszyty hurtem: `$ile` cudzych zeszytów od 100 właścicieli (po jednym
     * domyślnym), kilka własnych i kilka wspólnych dla `$ja` — tyle, ile ma
     * zwykła osoba. Nazwy unikalne (indeks na `lower(name)` per właściciel).
     */
    private function zasiej(User $ja, int $ile): void
    {
        User::factory()->count(100)->create();
        $wlasciciele = User::query()->where('id', '!=', $ja->getKey())->orderBy('id')->pluck('id')->all();
        $lista = "'".implode("','", $wlasciciele)."'";
        $n = count($wlasciciele);

        DB::insert(<<<SQL
            INSERT INTO collections (id, owner_id, name, visibility, is_default, created_at, updated_at)
            SELECT gen_random_uuid(), (ARRAY[{$lista}]::uuid[])[1 + g % {$n}], 'Zeszyt ' || g,
                   CASE g % 3 WHEN 0 THEN 'public' ELSE 'private' END, false, now(), now()
            FROM generate_series(1, {$ile}) AS g
            SQL);
        DB::insert(<<<SQL
            INSERT INTO collections (id, owner_id, name, visibility, is_default, created_at, updated_at)
            SELECT gen_random_uuid(), u, 'Zapisane', 'private', true, now(), now()
            FROM unnest(ARRAY[{$lista}]::uuid[]) AS u
            SQL);
        foreach (['Moje 1', 'Moje 2'] as $nazwa) {
            Collection::create(['owner_id' => $ja->getKey(), 'name' => $nazwa, 'visibility' => 'private']);
        }
        DB::insert(<<<'SQL'
            INSERT INTO collection_members (collection_id, user_id, created_at)
            SELECT id, ?, now() FROM collections
            WHERE owner_id != ? AND is_default = false ORDER BY name LIMIT 3
            SQL, [$ja->getKey(), $ja->getKey()]);
        // Tło: członkostwa i blokady innych osób (statystyki tych tabel też wchodzą w plan).
        DB::insert(<<<SQL
            INSERT INTO collection_members (collection_id, user_id, created_at)
            SELECT c.id, (ARRAY[{$lista}]::uuid[])[1 + (substring(c.name from 8)::int + 1) % {$n}], now() FROM collections c
            WHERE c.is_default = false AND c.name LIKE 'Zeszyt %' AND substring(c.name from 8)::int % 2 = 0
            ON CONFLICT DO NOTHING
            SQL);
        DB::insert(<<<SQL
            INSERT INTO blocks (blocker_id, blocked_id, created_at)
            SELECT (ARRAY[{$lista}]::uuid[])[1 + g % {$n}], (ARRAY[{$lista}]::uuid[])[1 + (g * 7 + 1) % {$n}], now()
            FROM generate_series(1, 1000) AS g WHERE (g % {$n}) != ((g * 7 + 1) % {$n})
            ON CONFLICT DO NOTHING
            SQL);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Kogo obserwować" — kształt zapytania, nie tylko wynik (B4).
 *
 * Ta lista liczy się na TRZECH ekranach, w tym na publicznym landingu,
 * czyli także dla każdego robota indeksującego. Wcześniej sortowała
 * skorelowanym podzapytaniem: baza liczyła `max(published_at)` osobno dla
 * KAŻDEGO konta, sortowała całość i dopiero potem brała cztery pozycje.
 *
 * Zmierzone na syntetycznych 5000 kont i 20 000 wpisów: 28,6 ms wobec
 * 12,9 ms po przepisaniu na złączenie z agregatem. Obie wersje rosną
 * z liczbą wpisów, ale tylko stara rosła także z liczbą KONT — a objaw
 * nie wyglądałby jak błąd, tylko jak „serwis czasem wolno chodzi".
 */
class PropozycjeOsobDoObserwowaniaTest extends TestCase
{
    use RefreshDatabase;

    private function autorZWpisem(string $nazwa, string $kiedy): User
    {
        $user = $this->user($nazwa);

        Post::factory()->create([
            'author_id' => $user->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => $kiedy,
        ]);

        return $user;
    }

    public function test_propozycje_ida_od_ostatnio_publikujacych(): void
    {
        $this->autorZWpisem('dawno', '2026-01-01 10:00:00');
        $this->autorZWpisem('wczoraj', now()->subDay()->toDateTimeString());
        $this->autorZWpisem('dzisiaj', now()->toDateTimeString());

        $propozycje = app(DailyBoard::class)->peopleToFollow(null, 3);

        // Sortujemy po tym, KIEDY ktoś ostatnio coś pokazał. Obserwowanie
        // osoby, która nic nie wrzuca, nie zapełnia feedu.
        $this->assertSame(
            ['dzisiaj', 'wczoraj', 'dawno'],
            $propozycje->map(fn (User $u) => $u->profile->username)->all(),
        );
    }

    public function test_konto_bez_publicznego_wpisu_nie_trafia_do_propozycji(): void
    {
        $this->autorZWpisem('publikuje', now()->toDateTimeString());
        $this->user('milczek');

        $nazwy = app(DailyBoard::class)->peopleToFollow(null, 5)
            ->map(fn (User $u) => $u->profile->username)->all();

        // Złączenie wewnętrzne zastąpiło `whereHas`: konto bez ani jednego
        // publicznego wpisu nie ma z czym się złączyć. To jest ta asercja,
        // która by złapała zamianę złączenia na zewnętrzne.
        $this->assertSame(['publikuje'], $nazwy);
    }

    public function test_widz_nie_dostaje_propozycji_kogos_kogo_juz_obserwuje(): void
    {
        $obserwowany = $this->autorZWpisem('obserwowany', now()->toDateTimeString());
        $nowy = $this->autorZWpisem('nowy', now()->subHour()->toDateTimeString());
        $basia = $this->user('basia');

        DB::table('follows')->insert([
            'follower_id' => $basia->getKey(),
            'followed_id' => $obserwowany->getKey(),
            'created_at' => now(),
        ]);

        $nazwy = app(DailyBoard::class)->peopleToFollow($basia, 5)
            ->map(fn (User $u) => $u->profile->username)->all();

        $this->assertContains('nowy', $nazwy);
        $this->assertNotContains('obserwowany', $nazwy);
        $this->assertNotContains('basia', $nazwy);
        $this->assertNotNull($nowy);
    }

    public function test_zapytanie_nie_liczy_agregatu_dla_kazdego_konta_osobno(): void
    {
        foreach (range(1, 5) as $numer) {
            $this->autorZWpisem('autor'.$numer, now()->subMinutes($numer)->toDateTimeString());
        }

        DB::enableQueryLog();
        app(DailyBoard::class)->peopleToFollow(null, 4);
        $zapytania = DB::getQueryLog();
        DB::disableQueryLog();

        $glowne = collect($zapytania)
            ->first(fn (array $q) => str_contains($q['query'], 'ostatnie'));

        $this->assertNotNull($glowne, 'Nie znalazłem głównego zapytania o propozycje.');

        $plan = collect(DB::select('EXPLAIN (FORMAT JSON) '.$glowne['query'], $glowne['bindings']))
            ->first()->{'QUERY PLAN'};

        // TO JEST WŁAŚCIWY TEST TEJ NAPRAWY.
        //
        // Wynik jest identyczny w obu wersjach, więc żaden test sprawdzający
        // KOLEJNOŚĆ nie odróżni skorelowanego podzapytania od złączenia
        // z agregatem. Różnica jest wyłącznie w planie zapytania — i tylko
        // ona rośnie z liczbą kont.
        //
        // `SubPlan` w planie PostgreSQL to właśnie podzapytanie wykonywane
        // dla każdego wiersza. Jego brak jest jedynym dowodem, że naprawa
        // nadal działa.
        $this->assertStringNotContainsString(
            'SubPlan',
            $plan,
            "Plan zapytania zawiera skorelowane podzapytanie — baza liczy agregat dla każdego konta osobno.\n".$plan,
        );
    }
}

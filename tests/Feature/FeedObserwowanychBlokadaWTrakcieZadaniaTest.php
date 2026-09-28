<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\FollowingFeed;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Blokada zatwierdzona W TRAKCIE składania strony Obserwowanych (issue #2026).
 *
 * `FollowingFeed` najpierw pobiera listę obserwowanych osób, a dopiero potem
 * wykonuje zapytanie o wpisy. Gałąź osób ufała tej liście: „blokada kasuje
 * obserwowanie, więc bramki blokad nie trzeba". Między tymi dwoma
 * zapytaniami (READ COMMITTED) inne żądanie może jednak zablokować autora
 * — lista w PHP ma go dalej, a wpis, także „tylko dla obserwujących",
 * wychodził na stronę.
 *
 * Wyścig odtwarzamy deterministycznie w zwykłym zestawie: `DB::listen`
 * odpala się tuż po zapytaniu o listę obserwowanych i wykonuje PRAWDZIWĄ
 * blokadę akcją domenową (`BlockUser`: wiersz `blocks` + odcięcie follow
 * w obie strony). Następne zapytanie feedu widzi już stan po blokadzie —
 * tak samo jak widziałoby go drugie połączenie po zatwierdzeniu.
 */
class FeedObserwowanychBlokadaWTrakcieZadaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_widz_blokuje_autora_miedzy_lista_obserwowanych_a_wpisami(): void
    {
        [$ala, $bartek, $celina] = $this->obserwacje();
        [$publiczny, $dlaObserwujacych] = $this->wpisy($bartek);
        $wpisCeliny = Post::factory()->followersOnly()->create(['author_id' => $celina->getKey()]);

        $blokady = $this->zablokujPoListachObserwowanych($ala, fn () => app(BlockUser::class)->handle($ala, $bartek));

        $ids = $this->idsFeedu($ala);

        $this->assertSame(1, $blokady(), 'Blokada nie wydarzyła się między zapytaniami feedu — test nic nie mierzy.');
        $this->assertNotContains($publiczny->getKey(), $ids, 'Publiczny wpis zablokowanej osoby przeciekł do Obserwowanych.');
        $this->assertNotContains($dlaObserwujacych->getKey(), $ids, 'Wpis „tylko dla obserwujących” przeciekł po blokadzie.');
        // Kontrola dodatnia na tej samej stronie: obserwowana, niezablokowana
        // osoba zostaje — poprawka nie może wyciąć całej gałęzi osób.
        $this->assertContains($wpisCeliny->getKey(), $ids);
    }

    public function test_autor_blokuje_widza_miedzy_lista_obserwowanych_a_wpisami(): void
    {
        [$ala, $bartek, $celina] = $this->obserwacje();
        [$publiczny, $dlaObserwujacych] = $this->wpisy($bartek);
        $wpisCeliny = Post::factory()->create(['author_id' => $celina->getKey()]);

        $blokady = $this->zablokujPoListachObserwowanych($ala, fn () => app(BlockUser::class)->handle($bartek, $ala));

        $ids = $this->idsFeedu($ala);

        $this->assertSame(1, $blokady());
        $this->assertNotContains($publiczny->getKey(), $ids);
        $this->assertNotContains($dlaObserwujacych->getKey(), $ids);
        $this->assertContains($wpisCeliny->getKey(), $ids);
    }

    public function test_is_empty_for_liczy_blokade_w_trakcie_zadania(): void
    {
        // `isEmptyFor()` dzieli źródła z `paginate()` — ten sam wyścig nie
        // może meldować „jest treść", gdy jedyną treścią jest wpis osoby
        // zablokowanej przed chwilą (Start pokazałby pustą listę).
        $ala = $this->user('ala');
        $bartek = $this->user('bartek');
        $ala->following()->attach($bartek->getKey(), ['created_at' => now()]);
        Post::factory()->followersOnly()->create(['author_id' => $bartek->getKey()]);

        $blokady = $this->zablokujPoListachObserwowanych($ala, fn () => app(BlockUser::class)->handle($ala, $bartek));

        $pusty = app(FollowingFeed::class)->isEmptyFor($ala->fresh());

        $this->assertSame(1, $blokady());
        $this->assertTrue($pusty);
    }

    public function test_odobserwowanie_w_trakcie_zadania_zamyka_wpis_tylko_dla_obserwujacych(): void
    {
        // Ta sama nieaktualna lista, bez blokady: autor zniknął z obserwowanych
        // po jej pobraniu. „Tylko dla obserwujących" liczy się z relacji
        // w chwili zapytania o wpisy, nie z listy w PHP — jak w `widoczneDla()`.
        [$ala, $bartek] = $this->obserwacje();
        [, $dlaObserwujacych] = $this->wpisy($bartek);

        $wywolania = $this->zablokujPoListachObserwowanych($ala, fn () => $ala->following()->detach($bartek->getKey()));

        $ids = $this->idsFeedu($ala);

        $this->assertSame(1, $wywolania());
        $this->assertNotContains($dlaObserwujacych->getKey(), $ids);
    }

    public function test_kontrola_dodatnia_bez_blokady_obie_widocznosci_zostaja(): void
    {
        // Ten sam podsłuch, ta sama kolejność zapytań — tylko bez blokady.
        // Dowodzi, że asercje wyżej łapią blokadę, a nie zepsutą konfigurację.
        [$ala, $bartek] = $this->obserwacje();
        [$publiczny, $dlaObserwujacych] = $this->wpisy($bartek);
        $wlasny = Post::factory()->private()->create(['author_id' => $ala->getKey(), 'published_at' => now()->subHour()]);

        $wywolania = $this->zablokujPoListachObserwowanych($ala, fn () => null);

        $ids = $this->idsFeedu($ala);

        $this->assertSame(1, $wywolania());
        $this->assertSame([$dlaObserwujacych->getKey(), $publiczny->getKey()], array_slice($ids, 0, 2), 'Chronologia wpisów obserwowanej osoby.');
        // Własne wpisy widza nie przechodzą bramki obserwowania — ale
        // prywatnego i tak nie było w feedzie (gałąź osób: public + followers).
        $this->assertNotContains($wlasny->getKey(), $ids);
    }

    /** @return array{0: User, 1: User, 2: User} */
    private function obserwacje(): array
    {
        $ala = $this->user('ala');
        $bartek = $this->user('bartek');
        $celina = $this->user('celina');
        $ala->following()->attach($bartek->getKey(), ['created_at' => now()]);
        $ala->following()->attach($celina->getKey(), ['created_at' => now()]);

        return [$ala, $bartek, $celina];
    }

    /** @return array{0: Post, 1: Post} */
    private function wpisy(User $autor): array
    {
        return [
            Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => now()->subMinutes(20)]),
            Post::factory()->followersOnly()->create(['author_id' => $autor->getKey(), 'published_at' => now()->subMinutes(10)]),
        ];
    }

    /** @return list<string> */
    private function idsFeedu(User $widz): array
    {
        return array_map(
            fn (Post $post): string => (string) $post->getKey(),
            app(FollowingFeed::class)->paginate($widz->fresh())->items(),
        );
    }

    /**
     * Wykonuje `$blokada` raz, zaraz po zapytaniu o obserwowanych widza
     * (`$viewer->following()->pluck('users.id')`), czyli dokładnie w oknie
     * między listą autorów a zapytaniem o wpisy. Zwraca licznik wywołań.
     *
     * @param  callable(): mixed  $blokada
     * @return callable(): int
     */
    private function zablokujPoListachObserwowanych(User $widz, callable $blokada): callable
    {
        $uzbrojony = true;
        $wywolania = 0;

        DB::listen(function (QueryExecuted $zapytanie) use (&$uzbrojony, &$wywolania, $widz, $blokada): void {
            if (! $uzbrojony
                || ! str_contains($zapytanie->sql, 'inner join "follows"')
                || ! str_contains($zapytanie->sql, '"follows"."follower_id" = ?')
                || $zapytanie->bindings !== [$widz->getKey()]) {
                return;
            }

            $uzbrojony = false;
            $wywolania++;
            $blokada();
        });

        return function () use (&$wywolania): int {
            return $wywolania;
        };
    }
}

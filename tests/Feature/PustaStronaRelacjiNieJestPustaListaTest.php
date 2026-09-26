<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #748 — usunięcie ostatniej karty strony udawało brak wszystkich relacji.
 *
 * „Przestań obserwować" przy jedynej osobie na drugiej stronie wracało przez
 * `back()` na `?page=2`, już pustą. Widok pytał `count()` BIEŻĄCEJ strony
 * i mówił „Jeszcze nikogo nie obserwuje", choć na pierwszej zostawało
 * dwadzieścia osób — bez żadnej drogi powrotu do nich.
 *
 * Testy mierzą odpowiedź PO AKCJI, a nie obecność warunku w źródle.
 */
class PustaStronaRelacjiNieJestPustaListaTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<User> */
    private function obserwuj(User $kto, int $ile): array
    {
        $osoby = [];
        for ($i = 0; $i < $ile; $i++) {
            $osoba = $this->user('osoba748_'.$i);
            app(FollowUser::class)->handle($kto, $osoba);
            $osoby[] = $osoba;
        }

        return $osoby;
    }

    public function test_przestan_obserwowac_ostatnia_osobe_drugiej_strony_wraca_do_pozostalych(): void
    {
        $ja = $this->user('ja748');
        $osoby = $this->obserwuj($ja, 21);

        // Lista od najnowszej relacji: pierwsza obserwowana stoi sama na stronie 2.
        $druga = route('social.following', ['username' => 'ja748', 'page' => 2]);
        $this->actingAs($ja)->get($druga)
            ->assertOk()
            ->assertSee($osoby[0]->displayName());

        $odpowiedz = $this->actingAs($ja)->from($druga)
            ->followingRedirects()
            ->delete(route('social.unfollow', $osoby[0]->profile->username), [
                'oczekiwany_id' => $osoby[0]->getKey(),
            ]);

        $this->assertSame(20, DB::table('follows')->where('follower_id', $ja->getKey())->count());

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('Jeszcze nikogo nie obserwuje');
        // Potwierdzenie akcji przeżywa dodatkowe przekierowanie.
        $odpowiedz->assertSee('Nie obserwujesz już '.$osoby[0]->displayName().'.', false);
        $odpowiedz->assertSee($osoby[20]->displayName());
        $odpowiedz->assertSee($osoby[1]->displayName());
    }

    public function test_wejscie_na_strone_poza_zakresem_przenosi_na_ostatnia_istniejaca(): void
    {
        $ja = $this->user('ja748');
        $this->obserwuj($ja, 20);

        $this->actingAs($ja)->get(route('social.following', ['username' => 'ja748', 'page' => 2]))
            ->assertRedirect(route('social.following', ['username' => 'ja748']));

        $this->actingAs($ja)->get(route('social.following', ['username' => 'ja748', 'page' => 9]))
            ->assertRedirect(route('social.following', ['username' => 'ja748']));
    }

    public function test_lista_obserwujacych_tez_nie_udaje_pustej(): void
    {
        $gwiazda = $this->user('gwiazda748');
        for ($i = 0; $i < 41; $i++) {
            app(FollowUser::class)->handle($this->user('fan748_'.$i), $gwiazda);
        }

        $this->get(route('social.followers', ['username' => 'gwiazda748', 'page' => 5]))
            ->assertRedirect(route('social.followers', ['username' => 'gwiazda748', 'page' => 3]));
    }

    public function test_prawdziwie_pusta_lista_dalej_mowi_prawde(): void
    {
        $this->user('pusta748');

        $this->get(route('social.following', ['username' => 'pusta748']))
            ->assertOk()
            ->assertSee('Jeszcze nikogo nie obserwuje');

        $this->followingRedirects()->get(route('social.followers', ['username' => 'pusta748', 'page' => 2]))
            ->assertOk()
            ->assertSee('Jeszcze nikt nie obserwuje');
    }
}

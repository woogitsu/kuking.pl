<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pusta dalsza strona listy relacji nie jest pustą listą (#748).
 *
 * „Przestań obserwować" przy ostatniej osobie na drugiej stronie wracało
 * na tę samą, już pustą stronę — a widok mówił „Jeszcze nikogo nie
 * obserwuje", choć na pierwszej stronie stało dwadzieścia osób.
 */
class PustaDalszaStronaRelacjiTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<User> */
    private function obserwowani(User $kto, int $ile): array
    {
        $osoby = [];
        for ($i = 0; $i < $ile; $i++) {
            $osoba = $this->user("osoba748nr{$i}", ['display_name' => "Osoba numer {$i}"]);
            app(FollowUser::class)->handle($kto, $osoba);
            $osoby[] = $osoba;
        }

        return $osoby;
    }

    public function test_usuniecie_ostatniej_karty_drugiej_strony_wraca_do_pozostalych_osob(): void
    {
        $ja = $this->user('ja748');
        $this->obserwowani($ja, 21);
        $this->actingAs($ja);

        $druga = route('social.following', ['username' => 'ja748', 'page' => 2]);
        $strona = $this->get($druga)->assertOk();
        $this->assertCount(1, $strona->viewData('people'));
        $ostatnia = $strona->viewData('people')->first();

        $this->from($druga)
            ->delete(route('social.unfollow', $ostatnia->profile->username), ['oczekiwany_id' => $ostatnia->getKey()])
            ->assertRedirect($druga)
            ->assertSessionHasNoErrors();

        $this->assertSame(20, $ja->following()->count(), 'Relacje innych osób nie mogą zniknąć.');

        // Pusta druga strona odsyła na ostatnią istniejącą — i nie gubi komunikatu.
        $this->get($druga)->assertRedirect(route('social.following', 'ja748'));

        $pozostali = $ja->following()->pluck('users.id')->sort()->values()->all();
        $this->get(route('social.following', 'ja748'))
            ->assertOk()
            ->assertViewHas('people', fn ($people) => $people->pluck('id')->sort()->values()->all() === $pozostali)
            ->assertSee('Nie obserwujesz już '.$ostatnia->displayName())
            ->assertDontSee('Jeszcze nikogo nie obserwuje');
    }

    public function test_wejscie_na_strone_poza_zakresem_odsyla_na_ostatnia_istniejaca(): void
    {
        $ja = $this->user('ja748');
        $this->obserwowani($ja, 20);

        $this->get(route('social.following', ['username' => 'ja748', 'page' => 2]))
            ->assertRedirect(route('social.following', 'ja748'));

        // Lista obserwujących: 21 osób to dwie strony, więc strona 5 → strona 2.
        $gwiazda = $this->user('gwiazda748');
        for ($i = 0; $i < 21; $i++) {
            app(FollowUser::class)->handle($this->user("fan748nr{$i}"), $gwiazda);
        }

        $this->get(route('social.followers', ['username' => 'gwiazda748', 'page' => 5]))
            ->assertRedirect(route('social.followers', ['username' => 'gwiazda748', 'page' => 2]));
        $this->get(route('social.followers', ['username' => 'gwiazda748', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('people', fn ($people) => $people->count() === 1);
    }

    public function test_prawdziwie_pusta_lista_dalej_mowi_ze_nikogo_nie_ma(): void
    {
        $this->user('pusta748');

        $this->get(route('social.following', ['username' => 'pusta748', 'page' => 2]))
            ->assertRedirect(route('social.following', 'pusta748'));

        $this->get(route('social.following', 'pusta748'))->assertOk()->assertSee('Jeszcze nikogo nie obserwuje');
        $this->get(route('social.followers', 'pusta748'))->assertOk()->assertSee('Jeszcze nikt nie obserwuje');
    }
}

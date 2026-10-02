<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\MojeWpisy;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Szukaj w moich wpisach” na ekranie „Moje wpisy” (#2465, V2).
 */
class MojeWpisySzukajTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $atrybuty */
    private function wpis(User $autor, array $atrybuty = [], ?string $stan = null): Post
    {
        $fabryka = Post::factory();
        if ($stan !== null) {
            $fabryka = $fabryka->{$stan}();
        }

        return $fabryka->create(['author_id' => $autor->getKey()] + $atrybuty);
    }

    private function adres(string $fraza, array $reszta = []): string
    {
        return route('collections.own-posts', ['szukaj' => $fraza] + $reszta);
    }

    /** @return list<string> */
    private function idKart(string $html): array
    {
        preg_match_all('/data-klucz="moj-wpis-([0-9a-f-]+)"/', $html, $m);

        return $m[1];
    }

    public function test_fraza_zaweza_liste_po_opisie_i_tytule_pytania_z_polskimi_znakami(): void
    {
        // Tytuł ma tylko pytanie (CHECK w bazie), a dział pytań jest domyślnie wyłączony.
        config(['kuking.questions.enabled' => true]);
        $autor = $this->user('autorka');
        $opis = $this->wpis($autor, ['body' => 'Pierogi z mamą na niedzielę', 'published_at' => now()->subDays(2)]);
        $tytul = $this->wpis($autor, ['title' => 'Żurek babci czy mamy?', 'body' => 'Bez związku', 'published_at' => now()->subDay()], 'question');
        $inny = $this->wpis($autor, ['body' => 'Rosół', 'published_at' => now()]);

        $this->assertSame([$opis->getKey()], $this->idKart($this->actingAs($autor)->get($this->adres('pierogi z mama'))->assertOk()->getContent()));
        $this->assertSame([$tytul->getKey()], $this->idKart($this->actingAs($autor)->get($this->adres('zurek'))->getContent()));

        // Bez frazy: pełna lista, od najnowszego (kontrola dodatnia).
        $this->assertSame(
            [$inny->getKey(), $tytul->getKey(), $opis->getKey()],
            $this->idKart($this->actingAs($autor)->get(route('collections.own-posts'))->getContent()),
        );
    }

    public function test_cudze_wpisy_nigdy_nie_pasuja_do_frazy(): void
    {
        $autor = $this->user('autorka');
        $obca = $this->user('obca');
        $this->wpis($autor, ['body' => 'Moje pierogi']);
        $this->wpis($obca, ['body' => 'Pierogi obcej osoby, publiczne']);
        $this->wpis($obca, ['body' => 'Pierogi obcej prywatne'], 'private');
        // Tytuł pytania innej osoby: drugi człon `title OR body` nie może wyjść poza autora.
        config(['kuking.questions.enabled' => true]);
        $this->wpis($obca, ['title' => 'Pierogi obcej: jak lepić?'], 'question');

        $html = $this->actingAs($autor)->get($this->adres('pierogi'))->getContent();

        $this->assertCount(1, $this->idKart($html));
        $this->assertStringNotContainsString('obcej', $html);
    }

    public function test_stany_i_wylaczenia_listy_zostaja_przy_frazie(): void
    {
        $autor = $this->user('autorka');
        $prywatny = $this->wpis($autor, ['body' => 'Barszcz prywatny'], 'private');
        $szkic = $this->wpis($autor, ['body' => 'Barszcz szkic'], 'draft');
        $ukryty = $this->wpis($autor, ['body' => 'Barszcz ukryty', 'status' => Post::STATUS_HIDDEN]);
        $this->wpis($autor, ['body' => 'Barszcz usunięty'])->delete();
        // Dział pytań wyłączony (domyślnie): pytanie nie wchodzi na listę ani przez frazę.
        $this->wpis($autor, ['body' => 'Barszcz pytanie'], 'question');

        $ids = $this->idKart($this->actingAs($autor)->get($this->adres('barszcz'))->getContent());

        $this->assertEqualsCanonicalizing([$prywatny->getKey(), $szkic->getKey(), $ukryty->getKey()], $ids);
    }

    public function test_brak_dopasowan_ma_komunikat_i_droge_do_wszystkich_wpisow(): void
    {
        $autor = $this->user('autorka');
        $this->wpis($autor, ['body' => 'Rosół']);

        $this->actingAs($autor)->get($this->adres('pierogi'))
            ->assertOk()
            ->assertSee('Nie znaleźliśmy wśród Twoich wpisów niczego z „pierogi” w opisie ani w tytule.', false)
            ->assertSee('Pokaż wszystkie wpisy')
            ->assertSee('value="pierogi"', false)
            ->assertDontSee('Nie masz jeszcze żadnego wpisu');
    }

    public function test_pole_jest_tylko_gdy_sa_wpisy_a_pusta_fraza_to_cala_lista(): void
    {
        $nowa = $this->user('nowa');
        $this->actingAs($nowa)->get(route('collections.own-posts'))
            ->assertSee('Nie masz jeszcze żadnego wpisu')
            ->assertDontSee('Szukaj w moich wpisach');

        $autor = $this->user('autorka');
        $this->wpis($autor, ['body' => 'Rosół']);
        $this->actingAs($autor)->get($this->adres('   '))
            ->assertOk()
            ->assertSee('<label for="f-szukaj-wpisy">Szukaj w moich wpisach</label>', false)
            ->assertSee('Rosół')
            ->assertDontSee('Wyniki dla');
    }

    public function test_za_krotka_i_za_dluga_fraza_mowi_co_zrobic_a_lista_zostaje(): void
    {
        $autor = $this->user('autorka');
        $this->wpis($autor, ['body' => 'Rosół']);

        $this->actingAs($autor)->get($this->adres('a'))
            ->assertSee('Wpisz co najmniej dwie litery z opisu albo tytułu wpisu.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('Rosół')
            ->assertDontSee('Wyniki dla');

        $this->actingAs($autor)->get($this->adres(str_repeat('x', 200)))
            ->assertSee('Skróć tekst w polu „Szukaj w moich wpisach” do 120 znaków i spróbuj ponownie.', false)
            ->assertSee('Rosół');
    }

    public function test_metaznaki_sa_zwyklym_tekstem(): void
    {
        $autor = $this->user('autorka');
        $procent = $this->wpis($autor, ['body' => 'Dodałem 50% cukru_mniej']);
        $this->wpis($autor, ['body' => 'Inny wpis zwykły']);

        $this->assertSame([$procent->getKey()], $this->idKart($this->actingAs($autor)->get($this->adres('50% cukru_'))->getContent()));
        $this->assertSame([], $this->idKart($this->actingAs($autor)->get($this->adres('%%'))->getContent()));
        $this->assertSame([], $this->idKart($this->actingAs($autor)->get($this->adres('wpis_zwykly'))->getContent()));
    }

    public function test_paginacja_niesie_fraze_bez_dubli_a_stary_adres_wraca_z_fraza(): void
    {
        $autor = $this->user('autorka');
        for ($i = 0; $i < MojeWpisy::NA_STRONE + 2; $i++) {
            $this->wpis($autor, ['body' => "Placek numer {$i}", 'published_at' => now()->subMinutes($i)]);
        }
        $this->wpis($autor, ['body' => 'Coś innego', 'published_at' => now()]);

        $pierwsza = $this->actingAs($autor)->get($this->adres('placek'))->getContent();
        $druga = $this->actingAs($autor)->get($this->adres('placek', ['page' => 2]))->getContent();

        $this->assertCount(MojeWpisy::NA_STRONE, $this->idKart($pierwsza));
        $this->assertCount(2, $this->idKart($druga));
        $this->assertSame([], array_intersect($this->idKart($pierwsza), $this->idKart($druga)));
        $this->assertMatchesRegularExpression('/href="[^"]*szukaj=placek[^"]*page=2|href="[^"]*page=2[^"]*szukaj=placek/', html_entity_decode($pierwsza));

        // Strona poza zakresem wraca na ostatnią, nie gubiąc frazy.
        $this->actingAs($autor)->get($this->adres('placek', ['page' => 9]))
            ->assertRedirect(route('collections.own-posts', ['page' => 2, 'szukaj' => 'placek']));
    }

    public function test_gosc_trafia_do_logowania_nawet_z_fraza(): void
    {
        $this->get($this->adres('pierogi'))->assertRedirect(route('login'));
    }
}

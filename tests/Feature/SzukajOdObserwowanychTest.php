<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Filtr „Od osób, które obserwuję” w wyszukiwaniu przepisów (#2440, V2, D-275).
 */
class SzukajOdObserwowanychTest extends TestCase
{
    use RefreshDatabase;

    private function zupa(User $autor, string $tytul, string $widocznosc = 'public'): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'visibility' => $widocznosc,
        ]);
        $przepis->forceFill(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()])->save();

        return $przepis;
    }

    private function obserwuje(User $kto, User $kogo): void
    {
        DB::table('follows')->insert(['follower_id' => $kto->getKey(), 'followed_id' => $kogo->getKey(), 'created_at' => now()]);
    }

    public function test_filtr_zaweza_do_obserwowanych_autorow_i_zachowuje_pozostale_filtry(): void
    {
        $widz = $this->user('widz1');
        $znana = $this->user('znana1');
        $obca = $this->user('obca1');
        $this->obserwuje($widz, $znana);
        $this->zupa($znana, 'Zupa pomidorowa znajomej');
        $this->zupa($obca, 'Zupa pomidorowa obca');

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa pomidorowa', 'obserwowani' => 1]))
            ->assertOk()
            ->assertSee('Zupa pomidorowa znajomej')
            ->assertDontSee('Zupa pomidorowa obca')
            ->assertSee('Pokazujemy tylko przepisy osób, które obserwujesz.')
            ->assertSee('Od osób, które obserwuję')
            ->assertSee('name="obserwowani" value="1"', false);

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa pomidorowa']))
            ->assertSee('Zupa pomidorowa znajomej')
            ->assertSee('Zupa pomidorowa obca');
    }

    public function test_kierunek_relacji_obserwowanie_widza_przez_autora_nie_wystarcza(): void
    {
        $widz = $this->user('widz2');
        $fan = $this->user('fan2');
        $this->obserwuje($fan, $widz);
        $this->zupa($fan, 'Zupa fana');

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa fana', 'obserwowani' => 1]))
            ->assertOk()
            ->assertDontSee('Zupa fana</a>', false)
            ->assertSee('Nie obserwujesz jeszcze nikogo');
    }

    public function test_brak_obserwowanych_i_brak_dopasowan_maja_rozne_komunikaty_z_drogą_do_wszystkich(): void
    {
        $widz = $this->user('widz3');
        $znana = $this->user('znana3');
        $this->zupa($this->user('obca3'), 'Zupa obca trzecia');

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'obserwowani' => 1]))
            ->assertSee('Nie obserwujesz jeszcze nikogo')
            ->assertSee('Pokaż przepisy wszystkich autorów')
            ->assertDontSee('Zupa obca trzecia');

        $this->obserwuje($widz, $znana);

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'obserwowani' => 1]))
            ->assertSee('Żadna z osób, które obserwujesz, nie ma przepisu pasującego')
            ->assertDontSee('Nie obserwujesz jeszcze nikogo')
            ->assertSee('Pokaż przepisy wszystkich autorów')
            ->assertDontSee('Zupa obca trzecia');
    }

    public function test_gosc_nie_dostaje_spersonalizowanego_zapytania_i_widzi_wyjasnienie(): void
    {
        $this->zupa($this->user('autor4'), 'Zupa gościa');

        $this->get(route('search', ['q' => 'zupa', 'obserwowani' => 1]))
            ->assertOk()
            ->assertSee('Zupa gościa')
            ->assertSee('działa po zalogowaniu')
            ->assertDontSee('Czyje przepisy?');
    }

    public function test_zasieg_widocznosci_blokady_i_status_autora_zostaja(): void
    {
        $widz = $this->user('widz5');
        $prywatna = $this->user('prywatna5');
        $dlaObs = $this->user('dlaobs5');
        $zablokowana = $this->user('zablokowana5');
        $zbanowana = $this->user('zbanowana5');
        foreach ([$prywatna, $dlaObs, $zablokowana, $zbanowana] as $autor) {
            $this->obserwuje($widz, $autor);
        }
        $this->zupa($prywatna, 'Zupa prywatna cudza', 'private');
        $this->zupa($dlaObs, 'Zupa dla obserwujacych', 'followers');
        $this->zupa($zablokowana, 'Zupa zablokowanej');
        $this->zupa($zbanowana, 'Zupa zbanowanej');
        DB::table('blocks')->insert(['blocker_id' => $widz->getKey(), 'blocked_id' => $zablokowana->getKey(), 'created_at' => now()]);
        $zbanowana->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'obserwowani' => 1]))
            ->assertOk()
            ->assertDontSee('Zupa prywatna cudza')
            ->assertSee('Zupa dla obserwujacych')
            ->assertDontSee('Zupa zablokowanej')
            ->assertDontSee('Zupa zbanowanej');
    }

    public function test_wlasny_przepis_nie_omija_wybranego_zbioru(): void
    {
        $widz = $this->user('widz6');
        $znana = $this->user('znana6');
        $this->obserwuje($widz, $znana);
        $this->zupa($widz, 'Zupa moja własna', 'private');
        $this->zupa($znana, 'Zupa znajomej szósta');

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'obserwowani' => 1]))
            ->assertSee('Zupa znajomej szósta')
            ->assertDontSee('Zupa moja własna');
    }

    public function test_utrata_relacji_miedzy_zadaniami_uzywa_swiezego_stanu(): void
    {
        $widz = $this->user('widz7');
        $znana = $this->user('znana7');
        $this->obserwuje($widz, $znana);
        $this->zupa($znana, 'Zupa siódma');

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'obserwowani' => 1]))->assertSee('Zupa siódma');

        DB::table('follows')->where('follower_id', $widz->getKey())->delete();

        $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'obserwowani' => 1]))
            ->assertDontSee('Zupa siódma')
            ->assertSee('Nie obserwujesz jeszcze nikogo');
    }

    public function test_wybor_przezywa_zakresy_czas_i_pokaz_wiecej_a_ludzie_go_gubia(): void
    {
        $widz = $this->user('widz8');
        $znana = $this->user('znana8');
        $this->obserwuje($widz, $znana);
        for ($i = 1; $i <= 21; $i++) {
            $this->zupa($znana, 'Zupa ósma numer '.$i);
        }

        $odpowiedz = $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'sekcja' => 'wszystko', 'obserwowani' => 1]));
        $html = html_entity_decode($odpowiedz->getContent());

        $this->assertStringContainsString('sekcja=przepisy', $html);
        $this->assertMatchesRegularExpression('/Pokaż więcej przepisów/u', $html);
        $this->assertMatchesRegularExpression('/href="[^"]*obserwowani=1[^"]*ile_przepisow=40|href="[^"]*ile_przepisow=40[^"]*obserwowani=1/', $html);

        $zCzasem = html_entity_decode($this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'obserwowani' => 1, 'czas' => 30]))->getContent());
        $this->assertMatchesRegularExpression('/href="[^"]*czas=30[^"]*obserwowani=1|href="[^"]*obserwowani=1[^"]*czas=30/', $zCzasem);

        $ludzie = $this->actingAs($widz)->get(route('search', ['q' => 'zupa', 'sekcja' => 'ludzie', 'obserwowani' => 1]));
        $ludzie->assertDontSee('Czyje przepisy?');
        $this->assertStringNotContainsString('name="obserwowani"', $ludzie->getContent());
    }

    public function test_zapytanie_domenowe_bez_flagi_nie_zawęża(): void
    {
        $widz = $this->user('widz9');
        $this->zupa($this->user('autor9'), 'Zupa dziewiąta');

        $this->assertCount(1, app(SearchQuery::class)->recipes('zupa dziewiata', $widz));
        $this->assertCount(0, app(SearchQuery::class)->recipes('zupa dziewiata', $widz, 20, null, 0, null, null, [], true));
    }
}

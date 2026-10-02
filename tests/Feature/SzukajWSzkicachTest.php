<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Szukaj w szkicach” na prywatnej stronie „Wszystkie szkice” (#2435, V2).
 */
class SzukajWSzkicachTest extends TestCase
{
    use RefreshDatabase;

    private function szkic(User $autor, string $tytul, ?\DateTimeInterface $zmieniony = null, bool $odlozony = false): Recipe
    {
        $szkic = Recipe::factory()->draft()->create(['author_id' => $autor->getKey(), 'title' => $tytul]);
        $szkic->forceFill([
            'updated_at' => $zmieniony ?? now(),
            'odlozony_at' => $odlozony ? now() : null,
        ])->saveQuietly();

        return $szkic;
    }

    private function adres(?string $fraza = null, array $reszta = []): string
    {
        return route('recipes.drafts', ($fraza === null ? [] : ['szukaj' => $fraza]) + $reszta);
    }

    /** @return list<string> */
    private function idSzkicow(string $html): array
    {
        preg_match_all('/szkic=([0-9a-f-]{36})/', $html, $m);

        return array_values(array_unique($m[1]));
    }

    public function test_fraza_znajduje_szkic_na_dalszej_porcji_z_polskimi_znakami_i_bez_wielkosci_liter(): void
    {
        $autorka = $this->user('autorka');
        $stary = $this->szkic($autorka, 'Żurek babci Zosi', now()->subYears(1));
        foreach (range(1, 25) as $i) {
            $this->szkic($autorka, "Nowy szkic numer {$i}", now()->subMinutes($i));
        }

        // Kontrola dodatnia: bez frazy stary szkic nie mieści się na pierwszej stronie.
        $this->assertNotContains($stary->getKey(), $this->idSzkicow($this->actingAs($autorka)->get($this->adres())->getContent()));

        foreach (['zurek', 'ŻUREK', 'żurek babci'] as $fraza) {
            $html = $this->actingAs($autorka)->get($this->adres($fraza))->assertOk()->getContent();
            $this->assertSame([$stary->getKey()], $this->idSzkicow($html), $fraza);
        }
    }

    public function test_cudze_szkice_nie_pasuja_do_frazy_ani_nie_pokazuja_tytulow(): void
    {
        $autorka = $this->user('autorka');
        $obca = $this->user('obca');
        $moj = $this->szkic($autorka, 'Sernik mój');
        $this->szkic($obca, 'Sernik cudzy tajny');

        $html = $this->actingAs($autorka)->get($this->adres('sernik'))->getContent();

        $this->assertSame([$moj->getKey()], $this->idSzkicow($html));
        $this->assertStringNotContainsString('cudzy tajny', $html);
    }

    public function test_opublikowany_przepis_nie_jest_szkicem_w_wynikach(): void
    {
        $autorka = $this->user('autorka');
        $this->szkic($autorka, 'Barszcz szkic');
        Recipe::factory()->create(['author_id' => $autorka->getKey(), 'title' => 'Barszcz opublikowany']);

        $html = $this->actingAs($autorka)->get($this->adres('barszcz'))->getContent();

        $this->assertCount(1, $this->idSzkicow($html));
        $this->assertStringNotContainsString('Barszcz opublikowany', $html);
    }

    public function test_moderator_na_tym_ekranie_widzi_tylko_wlasne_szkice(): void
    {
        $moderator = $this->user('moderator');
        $moderator->forceFill(['role' => User::ROLE_MODERATOR])->save();
        $this->szkic($this->user('autorka'), 'Pierogi cudze');
        $wlasny = $this->szkic($moderator, 'Pierogi moderatora');

        $this->assertSame([$wlasny->getKey()], $this->idSzkicow($this->actingAs($moderator)->get($this->adres('pierogi'))->getContent()));
    }

    public function test_pusty_wynik_mowi_ze_nie_znaleziono_i_zostawia_fraze(): void
    {
        $autorka = $this->user('autorka');
        $this->szkic($autorka, 'Rosół');

        $this->actingAs($autorka)->get($this->adres('pierogi'))
            ->assertOk()
            ->assertSee('Nie znaleźliśmy wśród bieżących szkiców tytułu z „pierogi”.', false)
            ->assertSee('value="pierogi"', false)
            ->assertSee('Wyczyść szukanie')
            ->assertDontSee('Nie masz teraz niedokończonych przepisów');
    }

    public function test_wynik_w_drugiej_zakladce_jest_zapowiedziany_a_odlozone_nie_znikaja(): void
    {
        $autorka = $this->user('autorka');
        $odlozony = $this->szkic($autorka, 'Makowiec odłożony', null, true);

        $this->actingAs($autorka)->get($this->adres('makowiec'))
            ->assertSee('Pasujące szkice (1) są w zakładce „Odłożone na później”.', false);

        $html = $this->actingAs($autorka)->get($this->adres('makowiec', ['odlozone' => 1]))->getContent();
        $this->assertSame([$odlozony->getKey()], $this->idSzkicow($html));
        // Zakładka niesie ukryte pole, żeby szukanie zostało w tym samym widoku.
        $this->assertStringContainsString('name="odlozone" value="1"', $html);
    }

    public function test_za_krotka_za_dluga_i_nietekstowa_fraza_nie_daje_500_i_lista_zostaje(): void
    {
        $autorka = $this->user('autorka');
        $szkic = $this->szkic($autorka, 'Rosół');

        $this->actingAs($autorka)->get($this->adres('a'))
            ->assertOk()
            ->assertSee('Wpisz co najmniej dwie litery z tytułu szkicu.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('szkic='.$szkic->getKey(), false);

        $this->actingAs($autorka)->get($this->adres(str_repeat('x', 200)))
            ->assertOk()
            ->assertSee('Skróć tekst w polu „Szukaj w szkicach” do 120 znaków i spróbuj ponownie.', false)
            ->assertSee('szkic='.$szkic->getKey(), false);

        $this->actingAs($autorka)->get(route('recipes.drafts').'?szukaj[]=rosol')
            ->assertOk()
            ->assertSee('szkic='.$szkic->getKey(), false);
    }

    public function test_metaznaki_sa_zwyklym_tekstem(): void
    {
        $autorka = $this->user('autorka');
        $procent = $this->szkic($autorka, 'Ciasto 100% maślane_domowe');
        $this->szkic($autorka, 'Zwykłe ciasto');

        $this->assertSame([$procent->getKey()], $this->idSzkicow($this->actingAs($autorka)->get($this->adres('100% maslane_'))->getContent()));
        $this->assertSame([], $this->idSzkicow($this->actingAs($autorka)->get($this->adres('%%'))->getContent()));
    }

    public function test_pokaz_wiecej_niesie_fraze_bez_dubli(): void
    {
        $autorka = $this->user('autorka');
        foreach (range(1, 22) as $i) {
            $this->szkic($autorka, "Placek {$i}", now()->subMinutes($i));
        }
        $this->szkic($autorka, 'Coś innego');

        $pierwsza = $this->actingAs($autorka)->get($this->adres('placek'))->getContent();
        $this->assertCount(20, $this->idSzkicow($pierwsza));

        preg_match('/href="([^"]*)"[^>]*>\s*Pokaż więcej/u', $pierwsza, $m);
        $this->assertNotEmpty($m);
        $nastepny = html_entity_decode($m[1]);
        $this->assertStringContainsString('szukaj=placek', $nastepny);

        $druga = $this->actingAs($autorka)->get($nastepny)->getContent();
        $this->assertCount(2, $this->idSzkicow($druga));
        $this->assertSame([], array_intersect($this->idSzkicow($pierwsza), $this->idSzkicow($druga)));
    }

    public function test_gosc_trafia_do_logowania(): void
    {
        $this->get($this->adres('rosol'))->assertRedirect(route('login'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Szukaj w moich zeszytach” — po tytule przepisu, tylko wśród zapisów
 * zalogowanej osoby i tylko tego, co ona dziś może otworzyć (issue #779).
 *
 * Każda scena ujemna ma obok kontrolę dodatnią: ten sam przepis przed zmianą
 * widoczności JEST znajdowany — inaczej „nie znaleziono” mogłoby znaczyć
 * „wyszukiwarka nie działa w ogóle”.
 */
class SzukajWMoichZeszytachTest extends TestCase
{
    use RefreshDatabase;

    public function test_znajduje_wlasny_zapis_po_tytule_bez_polskich_znakow_i_podaje_zeszyty_raz(): void
    {
        $basia = $this->user('basia');
        $zurek = Recipe::factory()->create(['title' => 'Żurek babci Heleny']);
        $inny = Recipe::factory()->create(['title' => 'Sernik na zimno']);

        $a = $this->zeszyt($basia, 'Obiady');
        $b = $this->zeszyt($basia, 'Święta');
        $a->recipes()->attach([$zurek->getKey(), $inny->getKey()]);
        $b->recipes()->attach($zurek->getKey());

        $html = $this->actingAs($basia)
            ->get(route('collections.index', ['szukaj' => 'ZUREK']))
            ->assertOk()
            ->assertSee('Wyniki dla „ZUREK”', false)
            ->assertSee('Żurek babci Heleny')
            ->assertSee('W zeszytach:')
            ->assertSee('Wyczyść wyszukiwanie')
            ->getContent();

        // Jeden wynik na przepis, choć leży w dwóch zeszytach.
        // (Liczymy w sekcji wyników — szyna „Ostatnio zapisane” niżej też linkuje przepis.)
        $this->assertSame(1, substr_count($this->sekcjaWynikow($html), 'href="'.e(route('recipes.show', $zurek->slug)).'"'));
        $this->assertStringContainsString('href="'.e(route('collections.show', $a)).'">Obiady</a>', $html);
        $this->assertStringContainsString('href="'.e(route('collections.show', $b)).'">Święta</a>', $html);
        // Sernik nie pasuje do frazy — nie ma go w wynikach.
        $this->assertStringNotContainsString('Sernik na zimno', $this->sekcjaWynikow($html));
    }

    public function test_nie_szuka_w_cudzych_zeszytach(): void
    {
        $basia = $this->user('basia');
        $obca = $this->user('obca');
        $przepis = Recipe::factory()->create(['title' => 'Pierogi z kaszą']);
        $this->zeszyt($obca, 'Cudzy')->recipes()->attach($przepis->getKey());
        $this->zeszyt($basia, 'Mój');

        $this->actingAs($basia)
            ->get(route('collections.index', ['szukaj' => 'pierogi']))
            ->assertOk()
            ->assertSee('Nie znaleźliśmy w Twoich zeszytach przepisu')
            ->assertDontSee('Pierogi z kaszą')
            ->assertDontSee('Cudzy');

        // Kontrola dodatnia: właścicielka tamtego zeszytu go znajduje.
        $html = $this->actingAs($obca)->get(route('collections.index', ['szukaj' => 'pierogi']))->getContent();
        $this->assertStringContainsString('Pierogi z kaszą', $this->sekcjaWynikow($html));
    }

    public function test_przepis_ktory_przestal_byc_widoczny_nie_zdradza_tytulu(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create(['title' => 'Nalewka tajemna', 'visibility' => 'public']);
        $this->zeszyt($basia, 'Zapasy')->recipes()->attach($przepis->getKey());

        $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'nalewka']))->getContent();
        $this->assertStringContainsString('Nalewka tajemna', $this->sekcjaWynikow($html));

        $przepis->forceFill(['visibility' => 'private'])->save();

        $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'nalewka']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Nalewka tajemna', $html);
        $this->assertStringContainsString('Nie znaleźliśmy w Twoich zeszytach przepisu', $html);
    }

    public function test_zablokowany_i_ukryty_autor_wypadaja_z_wynikow(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create(['title' => 'Bigos myśliwski', 'author_id' => $autor->getKey()]);
        $this->zeszyt($basia, 'Zima')->recipes()->attach($przepis->getKey());

        $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'bigos']))->getContent();
        $this->assertStringContainsString('Bigos myśliwski', $this->sekcjaWynikow($html));

        DB::table('blocks')->insert(['blocker_id' => $autor->getKey(), 'blocked_id' => $basia->getKey(), 'created_at' => now()]);
        $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'bigos']))->assertDontSee('Bigos myśliwski');

        DB::table('blocks')->delete();
        $autor->forceFill(['status' => User::STATUSY_UKRYWAJACE_TRESC[0]])->save();
        $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'bigos']))->assertDontSee('Bigos myśliwski');
    }

    public function test_procent_i_podkreslnik_sa_zwyklym_tekstem(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create(['title' => 'Chleb razowy']);
        $this->zeszyt($basia, 'Chleby')->recipes()->attach($przepis->getKey());

        // W sekcji wyników — szyna „Ostatnio zapisane” pokazuje ten przepis zawsze.
        foreach (['%%', '__'] as $metaznaki) {
            $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => $metaznaki]))->getContent();
            $this->assertStringNotContainsString('Chleb razowy', $this->sekcjaWynikow($html), "Fraza „{$metaznaki}” zadziałała jak wzorzec LIKE.");
        }

        $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'razo']))->getContent();
        $this->assertStringContainsString('Chleb razowy', $this->sekcjaWynikow($html));
    }

    public function test_pusta_fraza_to_zwykla_lista_a_jedna_litera_mowi_co_zrobic(): void
    {
        $basia = $this->user('basia');
        $this->zeszyt($basia, 'Obiady');

        $this->actingAs($basia)->get(route('collections.index'))
            ->assertOk()
            ->assertSee('Szukaj w moich zeszytach')
            ->assertDontSee('data-wyniki-w-zeszytach', false);

        $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'a']))
            ->assertOk()
            ->assertSee('Wpisz co najmniej dwie litery z tytułu przepisu.')
            ->assertSee('value="a"', false)
            ->assertDontSee('data-wyniki-w-zeszytach', false);
    }

    private function zeszyt(User $wlasciciel, string $nazwa): Collection
    {
        return Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa, 'visibility' => 'private']);
    }

    private function sekcjaWynikow(string $html): string
    {
        $start = strpos($html, 'data-wyniki-w-zeszytach');
        $this->assertNotFalse($start, 'Kontrola: brak sekcji wyników.');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }
}

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
 * „Szukaj w moich zeszytach” także po SKŁADNIKU zapisanego przepisu
 * (issue #2068). Tytuł działa jak w #779 (`SzukajWMoichZeszytachTest`);
 * tu tylko to, co dochodzi ze składnikami.
 */
class SzukajWZapisanychPoSkladnikachTest extends TestCase
{
    use RefreshDatabase;

    public function test_znajduje_zapis_po_skladniku_bez_polskich_znakow_i_jeden_raz(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepis('Zapiekanka babci', ['2 cukinie', 'cukinia w plasterkach', 'sól']);
        $obiady = $this->zeszyt($basia, 'Obiady');
        $lato = $this->zeszyt($basia, 'Lato');
        $obiady->recipes()->attach($przepis->getKey());
        $lato->recipes()->attach($przepis->getKey());
        $this->przepis('Sernik', ['twaróg']);

        $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'CUKINIA']))
            ->assertOk()->getContent();
        $wyniki = $this->sekcjaWynikow($html);

        // Dwa pasujące składniki i dwa zeszyty — jedna karta.
        $this->assertSame(1, substr_count($wyniki, 'href="'.e(route('recipes.show', $przepis->slug)).'"'));
        $this->assertStringContainsString('Pasuje przez składnik.', $wyniki);
        $this->assertStringContainsString('W zeszytach:', $wyniki);
        $this->assertStringNotContainsString('Sernik', $wyniki);

        // Polskie znaki po stronie składnika: „mąki” znajdzie się przez „maki”.
        $mak = $this->przepis('Makowiec', ['300 g mąki']);
        $obiady->recipes()->attach($mak->getKey());
        $this->assertStringContainsString('Makowiec', $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'maki']))->getContent(),
        ));
    }

    public function test_tytul_i_skladnik_naraz_to_jeden_wynik_bez_dopisku_o_skladniku(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepis('Cukinia z patelni', ['cukinia', 'cukinia młoda']);
        $this->zeszyt($basia, 'Lato')->recipes()->attach($przepis->getKey());

        $wyniki = $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'cukinia']))->getContent(),
        );

        $this->assertSame(1, substr_count($wyniki, 'href="'.e(route('recipes.show', $przepis->slug)).'"'));
        $this->assertStringNotContainsString('Pasuje przez składnik', $wyniki);
    }

    public function test_nie_szuka_skladnikow_w_cudzych_zeszytach(): void
    {
        $basia = $this->user('basia');
        $obca = $this->user('obca');
        $przepis = $this->przepis('Zapiekanka', ['cukinia']);
        $this->zeszyt($obca, 'Cudzy')->recipes()->attach($przepis->getKey());
        $this->zeszyt($basia, 'Mój');

        $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'cukinia']))
            ->assertOk()
            ->assertSee('Nie znaleźliśmy w Twoich zeszytach przepisu, który ma w tytule albo w składnikach')
            ->assertDontSee('Zapiekanka')
            ->assertDontSee('Cudzy');

        // Kontrola dodatnia: właścicielka tamtego zeszytu go znajduje.
        $this->assertStringContainsString('Zapiekanka', $this->sekcjaWynikow(
            $this->actingAs($obca)->get(route('collections.index', ['szukaj' => 'cukinia']))->getContent(),
        ));
    }

    public function test_schowany_zablokowany_i_usuniety_przepis_nie_wychodzi_przez_skladnik(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create(['title' => 'Tajna zapiekanka', 'visibility' => 'public', 'author_id' => $autor->getKey()]);
        $przepis->ingredients()->create(['ingredient_text' => 'cukinia', 'position' => 0]);
        $this->zeszyt($basia, 'Zapasy')->recipes()->attach($przepis->getKey());
        $adres = route('collections.index', ['szukaj' => 'cukinia']);

        $this->assertStringContainsString('Tajna zapiekanka', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));

        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->actingAs($basia)->get($adres)->assertOk()->assertDontSee('Tajna zapiekanka');

        $przepis->forceFill(['visibility' => 'public'])->save();
        DB::table('blocks')->insert(['blocker_id' => $autor->getKey(), 'blocked_id' => $basia->getKey(), 'created_at' => now()]);
        $this->actingAs($basia)->get($adres)->assertDontSee('Tajna zapiekanka');

        DB::table('blocks')->delete();
        $this->assertStringContainsString('Tajna zapiekanka', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));

        $przepis->delete();
        $this->actingAs($basia)->get($adres)->assertDontSee('Tajna zapiekanka');
    }

    public function test_metaznaki_like_i_fraza_z_samych_emoji_nie_dopasowuja_skladnikow(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepis('Zupa', ['100% pomidorów', 'sól_morska']);
        $this->zeszyt($basia, 'Zupy')->recipes()->attach($przepis->getKey());

        foreach (['%%', '__', '\\\\', 'so_ mor', '0%p'] as $fraza) {
            $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => $fraza]))->assertOk()->getContent();
            if (! str_contains($html, 'data-wyniki-w-zeszytach')) {
                continue; // fraza za krótka po normalizacji — błąd zamiast listy
            }
            $this->assertStringNotContainsString('Zupa', $this->sekcjaWynikow($html), "Fraza „{$fraza}” zadziałała jak wzorzec LIKE.");
        }

        // Kontrola dodatnia: dosłowne „%” i „_” są znajdowane.
        $this->assertStringContainsString('Zupa', $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => '100% pom']))->getContent(),
        ));
        $this->assertStringContainsString('Zupa', $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'sol_morska']))->getContent(),
        ));

        // Emoji znikają w transliteracji: to błąd z instrukcją, nie „wszystko”.
        $this->actingAs($basia)->get(route('collections.index', ['szukaj' => '😀😀']))
            ->assertOk()
            ->assertSee('Wpisz co najmniej dwie litery z tytułu przepisu albo ze składnika.')
            ->assertDontSee('data-wyniki-w-zeszytach', false);
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wynikow(): void
    {
        $basia = $this->user('basia');
        $zeszyt = $this->zeszyt($basia, 'Lato');
        $licz = function () use ($basia): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'cukinia']))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $zeszyt->recipes()->attach($this->przepis('Jeden', ['cukinia'])->getKey());
        $malo = $licz();
        for ($i = 0; $i < 8; $i++) {
            $zeszyt->recipes()->attach($this->przepis("Kolejny {$i}", ['cukinia', 'cukinia druga'])->getKey());
        }

        $this->assertSame($malo, $licz(), 'Liczba zapytań rośnie z liczbą wyników.');
    }

    /** @param  list<string>  $skladniki */
    private function przepis(string $tytul, array $skladniki): Recipe
    {
        $przepis = Recipe::factory()->create(['title' => $tytul]);
        foreach ($skladniki as $i => $tekst) {
            $przepis->ingredients()->create(['ingredient_text' => $tekst, 'position' => $i]);
        }

        return $przepis;
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

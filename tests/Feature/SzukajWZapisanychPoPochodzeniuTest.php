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
 * „Szukaj w moich zeszytach” także po polu „Od kogo albo skąd masz ten
 * przepis” (`source_person`, issue #2504). Tytuł i składniki mają własne testy
 * (`SzukajWMoichZeszytachTest`, `SzukajWZapisanychPoSkladnikachTest`); tu tylko
 * to, co dochodzi z pochodzeniem. Każda scena ujemna ma kontrolę dodatnią.
 */
class SzukajWZapisanychPoPochodzeniuTest extends TestCase
{
    use RefreshDatabase;

    public function test_znajduje_zapis_po_samym_pochodzeniu_bez_polskich_znakow_i_jeden_raz(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create(['title' => 'Kapusta zasmażana', 'source_person' => 'Od cioci Zosi z Łodzi']);
        $this->zeszyt($basia, 'Obiady')->recipes()->attach($przepis->getKey());
        $this->zeszyt($basia, 'Święta')->recipes()->attach($przepis->getKey());
        $inny = Recipe::factory()->create(['title' => 'Sernik', 'source_person' => 'Od mamy']);
        $this->zeszyt($basia, 'Obiady')->recipes()->attach($inny->getKey());

        $wyniki = $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'LODZI']))->assertOk()->getContent(),
        );

        $this->assertSame(1, substr_count($wyniki, 'href="'.e(route('recipes.show', $przepis->slug)).'"'));
        $this->assertStringContainsString('W zeszytach:', $wyniki);
        $this->assertStringContainsString('Pasuje przez pole „Od kogo albo skąd masz ten przepis”.', $wyniki);
        $this->assertStringNotContainsString('Pasuje przez składnik', $wyniki);
        $this->assertStringNotContainsString('Sernik', $wyniki);
        // Karta nie cytuje dowolnego tekstu pochodzenia.
        $this->assertStringNotContainsString('Od cioci Zosi', $wyniki);
    }

    public function test_pusty_i_pusty_po_przycieciu_zrodlo_nie_tworzy_dopasowania_a_tytul_nie_dostaje_dopisku(): void
    {
        $basia = $this->user('basia');
        $bez = Recipe::factory()->create(['title' => 'Zupa ogórkowa', 'source_person' => null]);
        $puste = Recipe::factory()->create(['title' => 'Zupa grzybowa', 'source_person' => '  ']);
        $wTytule = Recipe::factory()->create(['title' => 'Zosia pierogi', 'source_person' => 'od cioci Zosi']);
        $zeszyt = $this->zeszyt($basia, 'Zupy');
        $zeszyt->recipes()->attach([$bez->getKey(), $puste->getKey(), $wTytule->getKey()]);

        $wyniki = $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'zosi']))->getContent(),
        );

        $this->assertStringNotContainsString('Zupa ogórkowa', $wyniki);
        $this->assertStringNotContainsString('Zupa grzybowa', $wyniki);
        // Trafienie w tytule: jedna karta, bez dopisku o pochodzeniu.
        $this->assertStringContainsString('Zosia pierogi', $wyniki);
        $this->assertStringNotContainsString('Pasuje przez', $wyniki);
    }

    public function test_nie_szuka_pochodzenia_w_cudzych_zeszytach_ani_w_przepisach_poza_zeszytem(): void
    {
        $basia = $this->user('basia');
        $obca = $this->user('obca');
        $cudzy = Recipe::factory()->create(['title' => 'Cudza zapiekanka', 'source_person' => 'od cioci Zosi']);
        $this->zeszyt($obca, 'Cudzy')->recipes()->attach($cudzy->getKey());
        Recipe::factory()->create(['title' => 'Niezapisany barszcz', 'source_person' => 'od cioci Zosi']);
        $this->zeszyt($basia, 'Mój');
        $adres = route('collections.index', ['szukaj' => 'zosi']);

        $html = $this->actingAs($basia)->get($adres)->assertOk()->getContent();
        $this->assertStringContainsString('Nie znaleźliśmy w Twoich zeszytach przepisu', $this->sekcjaWynikow($html));
        $this->assertStringNotContainsString('Cudza zapiekanka', $html);
        $this->assertStringNotContainsString('Niezapisany barszcz', $html);

        // Kontrola dodatnia: właścicielka tamtego zeszytu go znajduje.
        $this->assertStringContainsString('Cudza zapiekanka', $this->sekcjaWynikow(
            $this->actingAs($obca)->get($adres)->getContent(),
        ));
    }

    public function test_nie_szuka_w_source_note(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create(['title' => 'Kasza z cebulką', 'source_person' => 'od mamy', 'source_note' => 'Zapisane przez wujka Stefana']);
        $this->zeszyt($basia, 'Kasze')->recipes()->attach($przepis->getKey());

        $this->assertStringNotContainsString('Kasza z cebulką', $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'stefana']))->getContent(),
        ));
        $this->assertStringContainsString('Kasza z cebulką', $this->sekcjaWynikow(
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'od mamy']))->getContent(),
        ));
    }

    public function test_schowany_zablokowany_i_usuniety_przepis_nie_zdradza_pochodzenia(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create(['title' => 'Tajny placek', 'source_person' => 'od teściowej Wandy', 'author_id' => $autor->getKey()]);
        $this->zeszyt($basia, 'Zapasy')->recipes()->attach($przepis->getKey());
        $adres = route('collections.index', ['szukaj' => 'wandy']);

        $this->assertStringContainsString('Tajny placek', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));

        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->assertStringNotContainsString('Tajny placek', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));

        $przepis->forceFill(['visibility' => 'public'])->save();
        DB::table('blocks')->insert(['blocker_id' => $autor->getKey(), 'blocked_id' => $basia->getKey(), 'created_at' => now()]);
        $this->assertStringNotContainsString('Tajny placek', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));

        DB::table('blocks')->delete();
        $autor->forceFill(['status' => User::STATUSY_UKRYWAJACE_TRESC[0]])->save();
        $this->assertStringNotContainsString('Tajny placek', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));

        $autor->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->assertStringContainsString('Tajny placek', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));

        $przepis->delete();
        $this->assertStringNotContainsString('Tajny placek', $this->sekcjaWynikow($this->actingAs($basia)->get($adres)->getContent()));
    }

    public function test_metaznaki_like_w_pochodzeniu_sa_dosownym_tekstem(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create(['title' => 'Ciasto rodzinne', 'source_person' => '100% babcia_Ela']);
        $this->zeszyt($basia, 'Ciasta')->recipes()->attach($przepis->getKey());

        foreach (['%%', '__', 'babc_a', '0%b'] as $fraza) {
            $this->assertStringNotContainsString('Ciasto rodzinne', $this->sekcjaWynikow(
                $this->actingAs($basia)->get(route('collections.index', ['szukaj' => $fraza]))->getContent(),
            ), "Fraza „{$fraza}” zadziałała jak wzorzec LIKE.");
        }

        foreach (['100% bab', 'babcia_ela'] as $fraza) {
            $this->assertStringContainsString('Ciasto rodzinne', $this->sekcjaWynikow(
                $this->actingAs($basia)->get(route('collections.index', ['szukaj' => $fraza]))->getContent(),
            ), "Dosłowna fraza „{$fraza}” nie została znaleziona.");
        }
    }

    public function test_tresc_pochodzenia_nie_jest_interpretowana_jako_html_a_zla_fraza_daje_instrukcje(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create(['title' => 'Naleśniki', 'source_person' => '<b>Zosia</b>']);
        $this->zeszyt($basia, 'Śniadania')->recipes()->attach($przepis->getKey());

        $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => '<b>zosia']))->assertOk()->getContent();
        $this->assertStringContainsString('Naleśniki', $this->sekcjaWynikow($html));
        $this->assertStringNotContainsString('<b>Zosia</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;zosia', $html);

        $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'z']))
            ->assertOk()
            ->assertSee('albo z tego, od kogo masz przepis', false);
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wynikow_z_pochodzenia(): void
    {
        $basia = $this->user('basia');
        $zeszyt = $this->zeszyt($basia, 'Rodzina');
        $licz = function () use ($basia): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($basia)->get(route('collections.index', ['szukaj' => 'zosia']))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $zeszyt->recipes()->attach(Recipe::factory()->create(['source_person' => 'ciocia Zosia'])->getKey());
        $malo = $licz();
        for ($i = 0; $i < 8; $i++) {
            $zeszyt->recipes()->attach(Recipe::factory()->create(['source_person' => 'ciocia Zosia'])->getKey());
        }

        $this->assertSame($malo, $licz(), 'Liczba zapytań rośnie z liczbą wyników.');
    }

    private function zeszyt(User $wlasciciel, string $nazwa): Collection
    {
        return Collection::firstOrCreate(
            ['owner_id' => $wlasciciel->getKey(), 'name' => $nazwa],
            ['visibility' => 'private'],
        );
    }

    private function sekcjaWynikow(string $html): string
    {
        $start = strpos($html, 'data-wyniki-w-zeszytach');
        $this->assertNotFalse($start, 'Kontrola: brak sekcji wyników.');

        return substr($html, $start, (int) strpos($html, '</section>', $start) - $start);
    }
}

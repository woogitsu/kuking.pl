<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
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
            ->assertSee('Wpisz co najmniej dwie litery z tytułu przepisu, ze składnika albo z tego, od kogo masz przepis.')
            ->assertSee('value="a"', false)
            ->assertDontSee('data-wyniki-w-zeszytach', false);
    }

    public function test_blad_frazy_jest_przy_polu_i_w_podsumowaniu_bez_utraty_filtrow(): void
    {
        $basia = $this->user('basia2850');
        $this->zeszyt($basia, 'Obiady');

        foreach (['a', str_repeat('a', 121)] as $fraza) {
            $html = $this->actingAs($basia)->get(route('collections.index', [
                'szukaj' => $fraza,
                'ugotowane' => '1',
                'kolejnosc' => 'ostatnio-ugotowane',
            ]))->assertOk()->getContent();
            $xpath = $this->html($html);
            $link = $xpath->query('//form[@role="search"]//div[contains(concat(" ", normalize-space(@class), " "), " error-summary ")]//a[@href="#f-szukaj"]')->item(0);
            $pole = $xpath->query('//*[@id="f-szukaj"]')->item(0);
            $blad = $xpath->query('//*[@id="f-szukaj-error"]')->item(0);

            $this->assertInstanceOf(DOMElement::class, $link, 'ZESZYTY_2850_BLAD_W_PODSUMOWANIU');
            $this->assertInstanceOf(DOMElement::class, $pole);
            $this->assertInstanceOf(DOMElement::class, $blad);
            $this->assertSame($blad->textContent, $link->textContent);
            $this->assertSame($fraza, $pole->getAttribute('value'));
            $this->assertSame('true', $pole->getAttribute('aria-invalid'));
            $this->assertStringContainsString('f-szukaj-error', $pole->getAttribute('aria-describedby'));
            $this->assertSame(1, $xpath->query('//*[@id="f-zeszyt-ugotowane" and @checked]')->length);
            $this->assertSame(1, $xpath->query('//input[@name="kolejnosc" and @value="ostatnio-ugotowane" and @checked]')->length);
            $this->assertSame(0, $xpath->query('//details[.//summary[normalize-space(.)="Załóż nowy zeszyt"]][@open]')->length);
        }
    }

    public function test_sesyjny_blad_zakladania_zeszytu_pozostaje_obok_bledu_get(): void
    {
        $basia = $this->user('basia2850sesja');
        $this->zeszyt($basia, 'Obiady');
        $this->actingAs($basia)->from(route('collections.index'))
            ->post(route('collections.store'), ['name' => '', 'visibility' => 'private'])
            ->assertRedirect(route('collections.index'));

        $html = $this->get(route('collections.index', ['szukaj' => 'a']))->assertOk()->getContent();
        $xpath = $this->html($html);

        $this->assertSame(1, $xpath->query('//form[@role="search"]//div[contains(@class, "error-summary")]//a[@href="#f-szukaj"]')->length);
        $this->assertSame(1, $xpath->query('//div[contains(@class, "error-summary")]//a[@href="#f-name"]')->length);
        $this->assertSame(1, $xpath->query('//details[.//summary[normalize-space(.)="Załóż nowy zeszyt"]][@open]')->length);
    }

    public function test_pusta_i_poprawna_fraza_nie_tworza_pustego_podsumowania(): void
    {
        $basia = $this->user('basia2850dodatnie');
        $this->zeszyt($basia, 'Obiady');

        foreach (['', 'obiad'] as $fraza) {
            $html = $this->actingAs($basia)->get(route('collections.index', ['szukaj' => $fraza]))->assertOk()->getContent();
            $this->assertSame(0, $this->html($html)->query('//div[contains(@class, "error-summary")]')->length);
        }
    }

    private function html(string $html): DOMXPath
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($dokument);
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

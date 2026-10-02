<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\PlanerTygodnia;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Wydrukuj ten tydzień” w Planerze (issue #2498, decyzja właściciela z
 * 2.10.2026, D-333): kartka z siedmioma dniami wybranego tygodnia, pełnymi
 * datami z rokiem i wszystkimi pozycjami w kolejności planera. Odczyt
 * własnego planu, bez zapisu; prywatne dopiski i „Zrobione” zostają w planerze.
 *
 * Czas zamrożony na wtorek 6 października 2026 (tydzień 5–11.10.2026).
 */
class WydrukPlanuTygodniaTest extends TestCase
{
    use RefreshDatabase;

    private User $halina;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-06 10:00:00', 'UTC'));
        $this->halina = $this->user('halina');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function przepis(User $autor, string $tytul, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);
        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'ingredient_text' => "Skladnik tajny: {$tytul}"]);

        return $przepis;
    }

    private function wpis(User $kto, string $dzien, ?Recipe $przepis = null, ?string $tekst = null): MealPlanEntry
    {
        $wpis = new MealPlanEntry(['day' => $dzien, 'recipe_id' => $przepis?->getKey(), 'label' => $tekst]);
        $wpis->user_id = $kto->getKey();
        $wpis->save();

        return $wpis;
    }

    /**
     * @return array<string, list<string>> data => pozycje
     */
    private function dniKartki(string $html): array
    {
        preg_match('/<article class="sciagawka kartka-planu.*?<\/article>/s', $html, $blok);
        preg_match_all('/<section class="kartka-planu-dzien" aria-labelledby="kartka-planu-(\d{4}-\d{2}-\d{2})">(.*?)<\/section>/s', $blok[0] ?? '', $dni, PREG_SET_ORDER);

        $wynik = [];
        foreach ($dni as $dzien) {
            preg_match_all('/<li>\s*(.*?)\s*<\/li>/s', $dzien[2], $li);
            $wynik[$dzien[1]] = array_map(fn (string $t): string => html_entity_decode(trim($t)), $li[1]);
        }

        return $wynik;
    }

    public function test_kartka_ma_siedem_dni_z_pelnymi_datami_zakres_z_rokiem_i_pozycje_w_kolejnosci(): void
    {
        $zupa = $this->przepis($this->halina, 'Zupa pomidorowa');
        $this->wpis($this->halina, '2026-10-05', $zupa);
        $this->wpis($this->halina, '2026-10-05', null, 'obiad u mamy');
        $this->wpis($this->halina, '2026-10-11', $this->przepis($this->halina, 'Rosół'));

        $html = $this->actingAs($this->halina)->get(route('planer.print', ['tydzien' => '2026-10-08']))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('5 października 2026 – 11 października 2026', $html);
        $this->assertStringContainsString('Poniedziałek, 5 października 2026', $html);
        $this->assertStringContainsString('Niedziela, 11 października 2026', $html);

        $dni = $this->dniKartki($html);
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'], array_keys($dni));
        $this->assertSame(['Zupa pomidorowa', 'obiad u mamy'], $dni['2026-10-05']);
        $this->assertSame([], $dni['2026-10-07'], 'pusty dzień bez wymyślonych posiłków');
        $this->assertSame(['Rosół'], $dni['2026-10-11']);
        $this->assertStringContainsString('Nic nie zaplanowano.', $html);
        $this->assertStringContainsString('Stan z: 6 października 2026, 12:00.', $html);
        $this->assertStringContainsString('To kopia — nie zmienia się razem z planerem.', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_wybrany_tydzien_a_nie_biezacy_i_zly_parametr_daje_biezacy(): void
    {
        $this->wpis($this->halina, '2026-09-30', null, 'kolacja we wrześniu');
        $this->wpis($this->halina, '2026-10-06', null, 'obiad w tym tygodniu');

        $wrzesien = $this->actingAs($this->halina)->get(route('planer.print', ['tydzien' => '2026-09-30']))->getContent();
        $this->assertIsString($wrzesien);
        $this->assertStringContainsString('kolacja we wrześniu', $wrzesien);
        $this->assertStringNotContainsString('obiad w tym tygodniu', $wrzesien);
        $this->assertStringContainsString('28 września 2026 – 4 października 2026', $wrzesien, 'zakres przez granicę miesiąca ma rok przy obu datach');

        foreach (['', 'śmieci', '2026-13-45', '2026-10-06x'] as $zly) {
            $biezacy = $this->actingAs($this->halina)->get(route('planer.print', ['tydzien' => $zly]))->assertOk()->getContent();
            $this->assertIsString($biezacy);
            $this->assertStringContainsString('obiad w tym tygodniu', $biezacy, "„{$zly}”");
        }

        $tablica = $this->actingAs($this->halina)->get('/planer/do-druku?tydzien[]=2026-09-30')->assertOk()->getContent();
        $this->assertIsString($tablica);
        $this->assertStringContainsString('obiad w tym tygodniu', $tablica);
    }

    public function test_przycisk_na_planerze_zachowuje_wybrany_tydzien(): void
    {
        $html = $this->actingAs($this->halina)->get(route('planer.show', ['tydzien' => '2026-09-30']))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('>Wydrukuj ten tydzień</a>', $html);
        $this->assertStringContainsString('href="'.route('planer.print', ['tydzien' => '2026-09-28']).'"', $html);
    }

    public function test_kartka_nie_ma_formularzy_przyciskow_adresow_skladnikow_dopiskow_ani_zrobione(): void
    {
        $zupa = $this->przepis($this->halina, 'Zupa pomidorowa');
        $wpis = $this->wpis($this->halina, '2026-10-05', $zupa);
        $wpis->forceFill(['note' => 'kolacja dla taty — prywatnie', 'done_at' => now()])->save();

        $html = $this->actingAs($this->halina)->get(route('planer.print'))->assertOk()->getContent();

        $this->assertIsString($html);
        $kartka = (string) preg_replace('/^.*?(<article class="sciagawka kartka-planu)/s', '$1', $html);
        $kartka = (string) preg_replace('/<\/article>.*$/s', '', $kartka);
        $this->assertStringContainsString('Zupa pomidorowa', $kartka);
        foreach (['<form', '<button', '<a ', '<input', 'kolacja dla taty', 'Zrobione', 'Skladnik tajny', 'halina', $zupa->slug] as $niechciane) {
            $this->assertStringNotContainsString($niechciane, $kartka, $niechciane);
        }
        $this->assertStringNotContainsString('kolacja dla taty', $html, 'prywatny dopisek nie jest w HTML w ogóle');
        $this->assertStringNotContainsString('Dodaj do planera', $html);
    }

    public function test_niedostepny_i_usuniety_przepis_sa_neutralne_bez_tytulu_a_wlasny_wpis_wierny(): void
    {
        $inny = $this->user('inny');
        $prywatny = $this->przepis($inny, 'Sekretna zupa');
        $usuniety = $this->przepis($inny, 'Usunięty bigos');
        $this->wpis($this->halina, '2026-10-05', $prywatny);
        $this->wpis($this->halina, '2026-10-06', $usuniety);
        $this->wpis($this->halina, '2026-10-07', null, 'gołąbki & <i>pierogi</i>');

        $przed = (string) $this->actingAs($this->halina)->get(route('planer.print'))->getContent();
        $this->assertSame(['Sekretna zupa'], $this->dniKartki($przed)['2026-10-05'], 'kontrola dodatnia: przed zmianą tytuł jest widoczny');

        $prywatny->forceFill(['visibility' => 'private'])->save();
        $usuniety->forceDelete();

        $html = $this->actingAs($this->halina)->get(route('planer.print'))->assertOk()->getContent();
        $this->assertIsString($html);
        $dni = $this->dniKartki($html);
        $this->assertSame(['Przepis niedostępny'], $dni['2026-10-05']);
        $this->assertSame(['Przepis usunięty'], $dni['2026-10-06']);
        $this->assertSame(['gołąbki & <i>pierogi</i>'], $dni['2026-10-07']);
        $this->assertStringNotContainsString('Sekretna zupa', $html);
        $this->assertStringNotContainsString('Usunięty bigos', $html);
        $this->assertStringNotContainsString('<i>pierogi</i>', $html, 'tekst jest escapowany');
    }

    public function test_pelny_dzien_z_maksymalna_liczba_pozycji_i_dlugie_wpisy_sa_wszystkie(): void
    {
        $dluga = str_repeat('bardzo długi własny wpis ', 4).'KONIEC';
        $max = PlanerTygodnia::wpisowNaDzien();
        for ($i = 0; $i < $max; $i++) {
            $this->wpis($this->halina, '2026-10-08', null, $i === 0 ? $dluga : "wpis $i");
        }

        $html = $this->actingAs($this->halina)->get(route('planer.print'))->assertOk()->getContent();

        $this->assertIsString($html);
        $pozycje = $this->dniKartki($html)['2026-10-08'];
        $this->assertCount($max, $pozycje);
        $this->assertSame($dluga, $pozycje[0]);
    }

    public function test_pusty_tydzien_mowi_o_tym_a_odczyt_niczego_nie_zmienia(): void
    {
        $html = $this->actingAs($this->halina)->get(route('planer.print'))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('W tym tygodniu nic jeszcze nie zaplanowano', $html);
        $this->assertSame(7, substr_count($html, 'Nic nie zaplanowano.'));

        $this->wpis($this->halina, '2026-10-05', null, 'obiad');
        $przed = DB::table('meal_plan_entries')->get()->toArray();
        $this->actingAs($this->halina)->get(route('planer.print', ['druk' => 1]))->assertOk()->assertSee('Jak wydrukować tę kartkę');
        $this->assertEquals($przed, DB::table('meal_plan_entries')->get()->toArray());
    }

    public function test_tylko_wlasny_plan_a_gosc_idzie_do_logowania(): void
    {
        $this->wpis($this->halina, '2026-10-05', null, 'moj obiad');
        $obca = $this->user('obca');
        $this->wpis($obca, '2026-10-05', null, 'cudza kolacja');

        $html = $this->actingAs($obca)->get(route('planer.print', ['user' => $this->halina->getKey(), 'user_id' => $this->halina->getKey()]))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertSame(['cudza kolacja'], $this->dniKartki($html)['2026-10-05']);
        $this->assertStringNotContainsString('moj obiad', $html);

        auth()->logout();
        $this->get(route('planer.print'))->assertRedirect(route('login'));
    }

    public function test_liczba_zapytan_nie_zalezy_od_liczby_pozycji(): void
    {
        $zlicz = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->halina)->get(route('planer.print'))->assertOk();

            return count(DB::getQueryLog());
        };

        $this->wpis($this->halina, '2026-10-05', $this->przepis($this->halina, 'Pierwsza'));
        $jedna = $zlicz();
        foreach (['2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'] as $dzien) {
            $this->wpis($this->halina, $dzien, $this->przepis($this->halina, "Przepis $dzien"));
            $this->wpis($this->halina, $dzien, null, "własny $dzien");
        }

        $this->assertSame($jedna, $zlicz());
    }
}

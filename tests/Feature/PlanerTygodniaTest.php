<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Planer tygodnia (#27, D-310): dzień + przepis albo własny wpis, kopiowanie
 * tygodnia, bez listy zakupów.
 *
 * Czas zamrożony na czwartek 1 października 2026, 10:00 czasu polskiego —
 * tydzień to poniedziałek 28 września – niedziela 4 października.
 *
 * Każdy pomiar idzie przez HTTP i końcowy HTML (albo przez prawdziwą paczkę
 * danych i prawdziwą akcję wymazania), nie przez wołanie akcji obok ekranu.
 */
final class PlanerTygodniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function przepis(User $autor, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);
    }

    private function pozycja(User $kto, string $dzien, ?Recipe $przepis = null, ?string $tekst = null): MealPlanEntry
    {
        $wpis = new MealPlanEntry(['day' => $dzien, 'recipe_id' => $przepis?->getKey(), 'label' => $tekst]);
        $wpis->user_id = $kto->getKey();
        $wpis->save();

        return $wpis;
    }

    /** HTML jednej sekcji dnia w planerze — żeby „jest w środę” nie przechodziło dzięki wtorkowi. */
    private function sekcjaDnia(string $html, string $data): string
    {
        $this->assertSame(1, preg_match('~<section[^>]*aria-labelledby="dzien-'.$data.'"[^>]*>(.*?)</section>~s', $html, $m),
            "Nie ma sekcji dnia {$data} w planerze.");

        return $m[1];
    }

    public function test_gosc_nie_wchodzi_do_planera(): void
    {
        $this->get(route('planer.show'))->assertRedirect(route('login'));
        $this->post(route('planer.store'), ['day' => '2026-10-01', 'label' => 'Zupa'])->assertRedirect(route('login'));
        $this->assertSame(0, MealPlanEntry::query()->count());
    }

    public function test_przepis_dodany_ze_strony_przepisu_stoi_w_planerze_we_wlasciwym_dniu(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($this->user('kucharka'), ['title' => 'Pierogi ruskie babci']);

        $strona = $this->actingAs($ja)->get(route('recipes.show', $przepis->slug))->assertOk();
        $strona->assertSee('Dodaj do planera')
            ->assertSee('value="2026-10-02"', escape: false)
            ->assertSee('Jutro — piątek, 2 października');

        $this->actingAs($ja)
            ->from(route('recipes.show', $przepis->slug))
            ->post(route('planer.store'), ['day' => '2026-10-02', 'recipe_id' => $przepis->getKey(), '_wiersz' => 'planer-'.$przepis->getKey()])
            ->assertRedirect(route('recipes.show', $przepis->slug))
            ->assertSessionHas('status', 'Dodane do planu na piątek, 2 października.');

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        $this->assertStringContainsString('Pierogi ruskie babci', $this->sekcjaDnia($html, '2026-10-02'));
        $this->assertStringContainsString(route('recipes.show', $przepis->slug), $this->sekcjaDnia($html, '2026-10-02'));
        $this->assertStringNotContainsString('Pierogi ruskie babci', $this->sekcjaDnia($html, '2026-10-01'));
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_ten_sam_przepis_drugi_raz_tego_samego_dnia_nie_mnozy_pozycji(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($this->user('kucharka'));

        foreach ([1, 2] as $_) {
            $this->actingAs($ja)->post(route('planer.store'), ['day' => '2026-10-02', 'recipe_id' => $przepis->getKey()]);
        }

        $this->assertSame(1, MealPlanEntry::query()->count());
        $this->actingAs($ja)->post(route('planer.store'), ['day' => '2026-10-02', 'recipe_id' => $przepis->getKey()])
            ->assertSessionHas('status', 'Ten przepis już jest w planie na piątek, 2 października.');
    }

    public function test_wlasny_wpis_trafia_do_swojego_dnia(): void
    {
        $ja = $this->user('planujaca');

        $this->actingAs($ja)
            ->post(route('planer.store'), ['day' => '2026-09-30', 'label' => '  Obiad   u mamy ', '_wiersz' => '2026-09-30'])
            ->assertRedirect(route('planer.show', ['tydzien' => '2026-09-30']));

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();
        $this->assertStringContainsString('Obiad u mamy', $this->sekcjaDnia($html, '2026-09-30'));
        $this->assertSame('Obiad u mamy', MealPlanEntry::query()->sole()->label);
    }

    public function test_za_dlugi_wpis_wraca_z_bledem_przy_polu_i_tekstem_tylko_w_swoim_dniu(): void
    {
        $ja = $this->user('planujaca');
        $dlugi = str_repeat('Rosół z makaronem ', 8);

        $this->actingAs($ja)
            ->from(route('planer.show'))
            ->post(route('planer.store'), ['day' => '2026-09-30', 'label' => $dlugi, '_wiersz' => '2026-09-30'])
            ->assertRedirect(route('planer.show'));

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();
        $sroda = $this->sekcjaDnia($html, '2026-09-30');
        $this->assertStringContainsString('Skróć wpis do 120 znaków', $sroda);
        $this->assertStringContainsString(e(trim($dlugi)), $sroda, 'Wpisany tekst zniknął po błędzie.');
        $this->assertStringNotContainsString('Skróć wpis', $this->sekcjaDnia($html, '2026-10-01'));
        $this->assertStringNotContainsString('Rosół z makaronem', $this->sekcjaDnia($html, '2026-10-01'));
        $this->assertStringContainsString('error-summary', $html);
        $this->assertSame(0, MealPlanEntry::query()->count());
    }

    public function test_pusty_wpis_mowi_co_zrobic(): void
    {
        $this->actingAs($this->user('planujaca'))
            ->post(route('planer.store'), ['day' => '2026-09-30', 'label' => '   '])
            ->assertSessionHasErrors(['label' => 'Wpisz, co planujesz na ten dzień, np. „obiad u mamy”.']);
    }

    public function test_nie_da_sie_dodac_przepisu_ktorego_sie_nie_widzi(): void
    {
        $ja = $this->user('planujaca');
        $prywatny = $this->przepis($this->user('kucharka'), ['visibility' => 'private']);

        $this->actingAs($ja)->post(route('planer.store'), ['day' => '2026-10-02', 'recipe_id' => $prywatny->getKey()])
            ->assertForbidden();
        $this->actingAs($ja)->post(route('planer.store'), ['day' => '2026-10-02', 'recipe_id' => '0199a7c6-0000-7000-8000-000000000000'])
            ->assertNotFound();

        // Kontrola dodatnia: autor swój prywatny przepis dodaje.
        $this->actingAs($prywatny->author)->post(route('planer.store'), ['day' => '2026-10-02', 'recipe_id' => $prywatny->getKey()])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, MealPlanEntry::query()->count());
        $this->assertSame($prywatny->author_id, MealPlanEntry::query()->sole()->user_id);
    }

    public function test_przepis_ktory_przestal_byc_widoczny_zostaje_bez_tytulu(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('kucharka');
        $zawezony = $this->przepis($autorka, ['title' => 'Sekretny bigos']);
        $blokujaca = $this->user('blokujaca');
        $zablokowany = $this->przepis($blokujaca, ['title' => 'Placki od blokujacej']);
        $usuniety = $this->przepis($autorka, ['title' => 'Kasza skasowana miekko']);
        $zniszczony = $this->przepis($autorka, ['title' => 'Kasza skasowana twardo']);
        $widoczny = $this->przepis($autorka, ['title' => 'Zupa ogorkowa']);

        foreach ([$zawezony, $zablokowany, $usuniety, $zniszczony, $widoczny] as $przepis) {
            $this->pozycja($ja, '2026-10-01', $przepis);
        }

        $zawezony->forceFill(['visibility' => 'private'])->save();
        $blokujaca->blocking()->attach($ja->getKey(), ['created_at' => now()]);
        $usuniety->delete();
        $zniszczony->forceDelete();

        $czwartek = $this->sekcjaDnia((string) $this->actingAs($ja)->get(route('planer.show'))->getContent(), '2026-10-01');

        $this->assertStringContainsString('Zupa ogorkowa', $czwartek);
        foreach (['Sekretny bigos', 'Placki od blokujacej', 'Kasza skasowana miekko', 'Kasza skasowana twardo'] as $tytul) {
            $this->assertStringNotContainsString($tytul, $czwartek);
        }
        $this->assertSame(3, substr_count($czwartek, 'Przepis jest już niedostępny.'));
        $this->assertSame(1, substr_count($czwartek, 'Przepis został usunięty.'));
        $this->assertSame(5, MealPlanEntry::query()->count(), 'Plan nie może tracić pozycji, gdy przepis znika.');
    }

    public function test_cudzy_plan_jest_niewidoczny_i_nieusuwalny(): void
    {
        $ja = $this->user('planujaca');
        $obca = $this->user('obca');
        $moja = $this->pozycja($ja, '2026-10-01', tekst: 'Tajny obiad');

        $this->actingAs($obca)->get(route('planer.show'))->assertOk()->assertDontSee('Tajny obiad');
        $this->actingAs($obca)->delete(route('planer.destroy', $moja))->assertForbidden();
        $this->assertModelExists($moja);

        $this->actingAs($ja)->delete(route('planer.destroy', $moja))
            ->assertRedirect(route('planer.show', ['tydzien' => '2026-10-01']))
            ->assertSessionHas('status', 'Usunięte z planu na czwartek, 1 października.');
        $this->assertModelMissing($moja);
    }

    public function test_kopia_poprzedniego_tygodnia_dopisuje_bez_powtorzen_i_bez_niedostepnych(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('kucharka');
        $gulasz = $this->przepis($autorka, ['title' => 'Gulasz wegierski']);
        $schowany = $this->przepis($autorka, ['title' => 'Schowany sernik']);

        // Poprzedni tydzień: 21–27 września.
        $this->pozycja($ja, '2026-09-21', $gulasz);
        $this->pozycja($ja, '2026-09-23', tekst: 'Obiad u mamy');
        $this->pozycja($ja, '2026-09-24', $schowany);
        $schowany->forceFill(['visibility' => 'private'])->save();
        // Ten tydzień już coś ma — zostaje.
        $this->pozycja($ja, '2026-09-30', tekst: 'Pizza na wynos');

        $this->actingAs($ja)->get(route('planer.show'))->assertSee('Skopiuj poprzedni tydzień');

        $this->actingAs($ja)->post(route('planer.copy'), ['tydzien' => '2026-09-28'])
            ->assertRedirect(route('planer.show', ['tydzien' => '2026-09-28']))
            ->assertSessionHas('status', 'Skopiowane z poprzedniego tygodnia: 2 pozycje. Pominięte: 1 pozycja — przepis jest już niedostępny albo dzień ma komplet.');

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();
        $this->assertStringContainsString('Gulasz wegierski', $this->sekcjaDnia($html, '2026-09-28'));
        $this->assertStringContainsString('Obiad u mamy', $this->sekcjaDnia($html, '2026-09-30'));
        $this->assertStringContainsString('Pizza na wynos', $this->sekcjaDnia($html, '2026-09-30'));
        $this->assertStringNotContainsString('Schowany sernik', $html);

        // Drugie kliknięcie niczego nie mnoży.
        $this->actingAs($ja)->post(route('planer.copy'), ['tydzien' => '2026-09-28'])
            ->assertSessionHas('status', 'Wszystko z poprzedniego tygodnia już jest w tym tygodniu. Pominięte: 1 pozycja — przepis jest już niedostępny albo dzień ma komplet.');
        $this->assertSame(6, MealPlanEntry::query()->count());
    }

    public function test_dzien_ma_limit_pozycji(): void
    {
        $ja = $this->user('planujaca');
        $limit = (int) config('kuking.planer.wpisow_na_dzien');
        $this->assertGreaterThan(1, $limit);

        for ($i = 1; $i <= $limit; $i++) {
            $this->pozycja($ja, '2026-10-01', tekst: 'Danie '.$i);
        }

        $this->actingAs($ja)->post(route('planer.store'), ['day' => '2026-10-01', 'label' => 'Jeszcze jedno'])
            ->assertSessionHasErrors(['label' => "Ten dzień ma już {$limit} pozycji. Usuń którąś albo wybierz inny dzień."]);
        $this->assertSame($limit, MealPlanEntry::query()->count());

        $czwartek = $this->sekcjaDnia((string) $this->actingAs($ja)->get(route('planer.show'))->getContent(), '2026-10-01');
        $this->assertStringContainsString('Ten dzień ma komplet', $czwartek);
        $this->assertStringNotContainsString('Dopisz coś własnego', $czwartek);
    }

    public function test_dzien_spoza_okna_jest_odrzucany(): void
    {
        $this->actingAs($this->user('planujaca'))
            ->post(route('planer.store'), ['day' => '2028-01-01', 'label' => 'Za rok z okładem'])
            ->assertSessionHasErrors(['day' => 'Wybierz dzień z najbliższego roku.']);
        $this->assertSame(0, MealPlanEntry::query()->count());
    }

    public function test_nawigacja_po_tygodniach_i_zly_parametr(): void
    {
        $ja = $this->user('planujaca');

        $this->actingAs($ja)->get(route('planer.show', ['tydzien' => '2026-10-08']))
            ->assertOk()
            ->assertSee('5–11 października')
            ->assertSee('Wróć do tego tygodnia');

        $this->actingAs($ja)->get(route('planer.show', ['tydzien' => '2026-02-31']))
            ->assertOk()
            ->assertSee('28 września – 4 października')
            ->assertDontSee('Wróć do tego tygodnia');
    }

    /** Niedziela 23:30 w Polsce to w UTC jeszcze niedziela 21:30 — ale też nie poniedziałek. */
    public function test_tydzien_liczy_sie_w_strefie_czlowieka(): void
    {
        // Poniedziałek 5 października, 00:30 w Polsce = niedziela 22:30 UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-04 22:30:00', 'UTC'));

        $this->actingAs($this->user('planujaca'))->get(route('planer.show'))
            ->assertSee('5–11 października');
    }

    public function test_plan_jest_w_paczce_danych_bez_tytulow_niedostepnych_przepisow(): void
    {
        $ja = $this->user('planujaca');
        $autorka = $this->user('kucharka');
        $widoczny = $this->przepis($autorka, ['title' => 'Zupa ogorkowa']);
        $schowany = $this->przepis($autorka, ['title' => 'Schowany sernik']);
        $this->pozycja($ja, '2026-10-01', $widoczny);
        $this->pozycja($ja, '2026-10-02', $schowany);
        $this->pozycja($ja, '2026-10-03', tekst: 'Obiad u mamy');
        $schowany->forceFill(['visibility' => 'private'])->save();

        $paczka = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), now());
        $plan = $paczka['planer'];

        $this->assertCount(3, $plan);
        $this->assertSame(['2026-10-01', 'Zupa ogorkowa', false], [$plan[0]['dzien'], $plan[0]['przepis'], $plan[0]['przepis_niedostepny']]);
        $this->assertSame([null, true], [$plan[1]['przepis'], $plan[1]['przepis_niedostepny']]);
        $this->assertSame('Obiad u mamy', $plan[2]['wlasny_wpis']);
        $this->assertStringNotContainsString('Schowany sernik', json_encode($paczka, JSON_UNESCAPED_UNICODE));
    }

    public function test_wymazanie_konta_kasuje_plan_tylko_tej_osoby(): void
    {
        $odchodzi = $this->user('odchodzi', ['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(40)]);
        $zostaje = $this->user('zostaje');
        $this->pozycja($odchodzi, '2026-10-01', tekst: 'Plan do skasowania');
        $this->pozycja($zostaje, '2026-10-01', tekst: 'Plan zostaje');

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(['Plan zostaje'], MealPlanEntry::query()->pluck('label')->all());
    }

    public function test_cofniecie_migracji_odmawia_przy_planach_ludzi_i_przechodzi_na_pustej(): void
    {
        $migracja = require base_path('database/migrations/2026_09_26_100000_create_meal_plan_entries_table.php');
        $this->pozycja($this->user('planujaca'), '2026-10-01', tekst: 'Obiad u mamy');

        try {
            $migracja->down();
            $this->fail('Rollback skasował plany ludzi bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pg_dump -t meal_plan_entries', $e->getMessage());
        }
        $this->assertSame(1, DB::table('meal_plan_entries')->count());

        // Kontrola dodatnia: na pustej tabeli rollback przechodzi.
        DB::table('meal_plan_entries')->delete();
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('meal_plan_entries'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('meal_plan_entries'));
    }

    public function test_baza_pilnuje_jednego_z_dwoch_i_pustego_tekstu(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($this->user('kucharka'));

        foreach ([
            ['recipe_id' => $przepis->getKey(), 'label' => 'I to, i to'],
            ['recipe_id' => null, 'label' => '   '],
        ] as $wiersz) {
            try {
                DB::transaction(fn () => DB::table('meal_plan_entries')->insert([
                    'user_id' => $ja->getKey(), 'day' => '2026-10-01', ...$wiersz,
                    'created_at' => now(), 'updated_at' => now(),
                ]));
                $this->fail('Baza przyjęła wiersz, który łamie CHECK: '.json_encode($wiersz));
            } catch (QueryException $e) {
                $this->assertStringContainsString('meal_plan_entries_', $e->getMessage());
            }
        }
    }
}

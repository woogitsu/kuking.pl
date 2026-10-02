<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Zakupy\ListaZakupow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\ShoppingListItem;
use App\Models\ShoppingListUndo;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Cofnij usunięcie” z listy zakupów (#2630, rozszerzenie D-333; decyzja
 * właściciela z 2 października 2026).
 *
 * Pomiar idzie przez HTTP i końcowy HTML: usunięcie to zwykły formularz, a
 * cofnięcie — zwykły POST bez skryptu. Czas zamrożony na piątek
 * 2 października 2026, 10:00 UTC (12:00 w Polsce).
 *
 * @bez-kontroli-dodatniej base_path() służy wyłącznie do wykonania pliku migracji (up/down) na bazie testowej; asercje tekstowe dotyczą komunikatu wyjątku rollbacku i HTML odpowiedzi, nie treści źródeł aplikacji.
 */
final class CofnijUsuniecieZakupowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-02 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        putenv('KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW');
        parent::tearDown();
    }

    private function przepis(User $autor): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
        RecipeIngredient::create(['recipe_id' => $przepis->getKey(), 'ingredient_text' => '2 jajka', 'position' => 0]);

        return $przepis;
    }

    private function pozycja(User $kto, string $tekst, int $miejsce = 0, bool $odhaczona = false, ?Recipe $przepis = null): ShoppingListItem
    {
        $p = new ShoppingListItem(['text' => $tekst]);
        $p->user_id = $kto->getKey();
        $p->source = $przepis !== null ? ShoppingListItem::SOURCE_RECIPE : ShoppingListItem::SOURCE_MANUAL;
        $p->recipe_id = $przepis?->getKey();
        $p->position = $miejsce;
        $p->checked_at = $odhaczona ? now()->subHour() : null;
        $p->save();

        return $p;
    }

    /** @return list<string> */
    private function teksty(User $kto): array
    {
        return ShoppingListItem::query()->where('user_id', $kto->getKey())
            ->orderBy('position')->orderBy('created_at')->orderBy('id')->pluck('text')->all();
    }

    public function test_po_usunieciu_pozycji_lista_pokazuje_trwaly_przycisk_i_cofniecie_przywraca_ja(): void
    {
        $ja = $this->user('kupujaca');
        $this->pozycja($ja, 'mleko 2 l, tylko świeże', 0);
        $srodek = $this->pozycja($ja, 'chleb razowy', 1);
        $this->pozycja($ja, 'ser', 2);

        $this->actingAs($ja)->delete(route('shopping.destroy', $srodek))->assertRedirect(route('shopping.index'));
        $this->assertSame(['mleko 2 l, tylko świeże', 'ser'], $this->teksty($ja));

        // Informacja nie jest jednorazowym komunikatem: stoi też po odświeżeniu.
        foreach ([1, 2] as $_) {
            $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->assertOk()->getContent();
            $this->assertStringContainsString('Cofnij usunięcie', $html);
            $this->assertStringContainsString('<strong>chleb razowy</strong>', $html);
            $this->assertStringContainsString('do godziny 12:15', $html);
            $this->assertStringContainsString(route('shopping.undo'), $html);
        }

        $this->actingAs($ja)->post(route('shopping.undo'))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', 'Przywrócone pozycje: 1.');

        // Wraca ta sama pozycja, na swoje miejsce, z tym samym identyfikatorem.
        $this->assertSame(['mleko 2 l, tylko świeże', 'chleb razowy', 'ser'], $this->teksty($ja));
        $this->assertModelExists($srodek);
        $this->assertSame(0, ShoppingListUndo::query()->count());
        $this->actingAs($ja)->get(route('shopping.index'))->assertDontSee('Cofnij usunięcie');
    }

    public function test_wyczysc_odhaczone_mozna_cofnac_a_pozycje_wracaja_odhaczone_i_mowi_to_wprost(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'));
        $this->pozycja($ja, 'do kupienia', 0);
        $a = $this->pozycja($ja, '2 jajka', 1, true, $przepis);
        $b = $this->pozycja($ja, 'papier toaletowy', 2, true);
        $stare = $a->created_at;

        $this->actingAs($ja)->delete(route('shopping.clear'))->assertSessionHas('status', 'Usunięte odhaczone pozycje: 2.');
        $this->assertSame(['do kupienia'], $this->teksty($ja));

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('Z listy usunięto 2 odhaczone pozycje.', $html);

        $this->actingAs($ja)->post(route('shopping.undo'))
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'Przywrócone pozycje: 2.')
                && str_contains($s, 'Wróciły jako odhaczone'));

        $this->assertSame(['do kupienia', '2 jajka', 'papier toaletowy'], $this->teksty($ja));
        $wrocona = ShoppingListItem::query()->findOrFail($a->getKey());
        $this->assertNotNull($wrocona->checked_at, 'Odhaczona pozycja ma wrócić odhaczona.');
        $this->assertSame($przepis->getKey(), $wrocona->recipe_id);
        $this->assertSame(ShoppingListItem::SOURCE_RECIPE, $wrocona->source);
        $this->assertSame($stare?->toIso8601String(), $wrocona->created_at?->toIso8601String());
        $this->assertNotNull(ShoppingListItem::query()->findOrFail($b->getKey())->checked_at);
        $this->assertSame(ShoppingListItem::SOURCE_MANUAL, ShoppingListItem::query()->findOrFail($b->getKey())->source);

        $odhaczone = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('Odhaczone (2)', $odhaczone);
    }

    public function test_cofniecie_dotyczy_tylko_ostatniej_operacji(): void
    {
        $ja = $this->user('kupujaca');
        $a = $this->pozycja($ja, 'pierwsza', 0);
        $b = $this->pozycja($ja, 'druga', 1);

        $this->actingAs($ja)->delete(route('shopping.destroy', $a));
        $this->actingAs($ja)->delete(route('shopping.destroy', $b));
        $this->assertSame(1, ShoppingListUndo::query()->count(), 'Jedna ostatnia operacja, bez historii.');

        $this->actingAs($ja)->post(route('shopping.undo'));

        $this->assertSame(['druga'], $this->teksty($ja));
    }

    public function test_wyczysc_bez_odhaczonych_nie_zastepuje_migawki_pojedynczego_usuniecia(): void
    {
        $ja = $this->user('kupujaca');
        $a = $this->pozycja($ja, 'pierwsza', 0);
        $this->pozycja($ja, 'druga', 1);

        $this->actingAs($ja)->delete(route('shopping.destroy', $a));
        $this->actingAs($ja)->delete(route('shopping.clear'))
            ->assertSessionHas('status', 'Nie ma odhaczonych pozycji do usunięcia.');
        $this->actingAs($ja)->post(route('shopping.undo'));

        $this->assertSame(['pierwsza', 'druga'], $this->teksty($ja));
    }

    public function test_cofniecie_nie_nadpisuje_nowych_pozycji_ani_pozniejszych_korekt(): void
    {
        $ja = $this->user('kupujaca');
        $this->pozycja($ja, 'zostaje', 0);
        $usuwana = $this->pozycja($ja, 'omyłkowo usunięta', 1);

        $this->actingAs($ja)->delete(route('shopping.destroy', $usuwana));
        $this->actingAs($ja)->post(route('shopping.store'), ['text' => 'nowa pozycja']);
        $zostaje = ShoppingListItem::query()->where('text', 'zostaje')->firstOrFail();
        $this->actingAs($ja)->patch(route('shopping.toggle', $zostaje), ['odhaczona' => '1']);

        $this->actingAs($ja)->post(route('shopping.undo'));

        $this->assertEqualsCanonicalizing(['zostaje', 'omyłkowo usunięta', 'nowa pozycja'], $this->teksty($ja));
        $this->assertNotNull($zostaje->fresh()?->checked_at, 'Późniejsze odhaczenie zostaje.');
        $this->assertNull(ShoppingListItem::query()->where('text', 'omyłkowo usunięta')->firstOrFail()->checked_at);
    }

    public function test_podwojne_cofniecie_nie_powiela_pozycji(): void
    {
        $ja = $this->user('kupujaca');
        $a = $this->pozycja($ja, 'mleko', 0);
        $this->actingAs($ja)->delete(route('shopping.destroy', $a));

        $this->actingAs($ja)->post(route('shopping.undo'))->assertSessionHas('status', 'Przywrócone pozycje: 1.');
        $this->actingAs($ja)->post(route('shopping.undo'))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'Nie ma już czego cofać'));

        $this->assertSame(['mleko'], $this->teksty($ja));
    }

    public function test_po_uplywie_czasu_cofniecie_odmawia_a_dane_znikaja(): void
    {
        $ja = $this->user('kupujaca');
        $a = $this->pozycja($ja, 'mleko', 0);
        $this->actingAs($ja)->delete(route('shopping.destroy', $a));

        Carbon::setTestNow(Carbon::parse('2026-10-02 10:15:01', 'UTC'));

        $this->actingAs($ja)->get(route('shopping.index'))->assertDontSee('Cofnij usunięcie');
        $this->assertSame(0, ShoppingListUndo::query()->count(), 'Wygasła migawka ma być skasowana.');

        // Przycisk z otwartej wcześniej strony też już nic nie robi.
        $this->actingAs($ja)->post(route('shopping.undo'))
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'Nie ma już czego cofać'));
        $this->assertSame([], $this->teksty($ja));
    }

    public function test_wygasla_migawka_nie_przywroci_pozycji_nawet_gdy_zadanie_jej_jeszcze_nie_skasowalo(): void
    {
        $ja = $this->user('kupujaca');
        $a = $this->pozycja($ja, 'mleko', 0);
        $this->actingAs($ja)->delete(route('shopping.destroy', $a));

        Carbon::setTestNow(Carbon::parse('2026-10-02 10:15:01', 'UTC'));
        $wynik = app(ListaZakupow::class)->cofnijUsuniecie($ja);

        $this->assertSame('brak', $wynik['wynik']);
        $this->assertSame([], $this->teksty($ja));
    }

    public function test_zadanie_sprzatajace_kasuje_tylko_wygasle_migawki(): void
    {
        $ja = $this->user('kupujaca');
        $inna = $this->user('inna');
        $this->actingAs($ja)->delete(route('shopping.destroy', $this->pozycja($ja, 'mleko')));

        Carbon::setTestNow(Carbon::parse('2026-10-02 10:10:00', 'UTC'));
        $this->actingAs($inna)->delete(route('shopping.destroy', $this->pozycja($inna, 'ser')));

        Carbon::setTestNow(Carbon::parse('2026-10-02 10:16:00', 'UTC'));
        $this->assertSame(0, Artisan::call('kuking:sprzataj-cofniecia-zakupow'));

        $this->assertSame([$inna->getKey()], ShoppingListUndo::query()->pluck('user_id')->all());
    }

    public function test_limit_konta_daje_jasny_komunikat_i_nie_zjada_migawki(): void
    {
        config(['kuking.zakupy.pozycji_max' => 3]);
        $ja = $this->user('kupujaca');
        $this->pozycja($ja, 'kupione 1', 0, true);
        $this->pozycja($ja, 'kupione 2', 1, true);
        $this->pozycja($ja, 'zostaje', 2);

        $this->actingAs($ja)->delete(route('shopping.clear'));
        // Lista ma 1 pozycję; dopisujemy dwie, więc do 3 brakuje miejsca na 2 wracające.
        $this->actingAs($ja)->post(route('shopping.store'), ['text' => 'nowa 1']);
        $this->actingAs($ja)->post(route('shopping.store'), ['text' => 'nowa 2']);

        $this->actingAs($ja)->post(route('shopping.undo'))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'Nie ma miejsca')
                && str_contains($s, 'do 12:15')
                && str_contains($s, 'najwyżej 1'));

        $this->assertCount(3, $this->teksty($ja), 'Odmowa nie może dopisać nawet części pozycji.');
        $this->assertSame(1, ShoppingListUndo::query()->count(), 'Odmowa nie może zjadać tego, co jeszcze można odzyskać.');

        // Po zrobieniu miejsca (usunięcie zastępuje migawkę, więc próbujemy
        // inną drogą: wracamy do stanu z miejscem przez zmianę limitu).
        config(['kuking.zakupy.pozycji_max' => 5]);
        $this->actingAs($ja)->post(route('shopping.undo'))->assertSessionHas('status', fn (string $s): bool => str_starts_with($s, 'Przywrócone pozycje: 2.'));
        $this->assertCount(5, $this->teksty($ja));
    }

    public function test_cudzej_operacji_nie_da_sie_cofnac_ani_zobaczyc(): void
    {
        $ja = $this->user('kupujaca');
        $obca = $this->user('obca');
        $tajna = $this->pozycja($ja, 'tajna lista', 0);
        $this->actingAs($ja)->delete(route('shopping.destroy', $tajna));

        $this->flushSession();
        $this->actingAs($obca)->get(route('shopping.index'))->assertOk()
            ->assertDontSee('tajna lista')->assertDontSee('Cofnij usunięcie');
        $this->actingAs($obca)->post(route('shopping.undo'))
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'Nie ma już czego cofać'));

        $this->assertSame([], $this->teksty($obca));
        $this->assertSame([], $this->teksty($ja), 'Cudze żądanie nie przywraca pozycji właścicielowi.');
        $this->assertSame(1, ShoppingListUndo::query()->where('user_id', $ja->getKey())->count());

        auth()->logout();
        $this->post(route('shopping.undo'))->assertRedirect(route('login'));
    }

    public function test_wymazanie_konta_kasuje_materialu_oczekujacy_na_cofniecie(): void
    {
        $odchodzi = $this->user('odchodzi', ['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(40)]);
        $zostaje = $this->user('zostaje');
        $this->actingAs($odchodzi)->delete(route('shopping.destroy', $this->pozycja($odchodzi, 'do skasowania')));
        $this->actingAs($zostaje)->delete(route('shopping.destroy', $this->pozycja($zostaje, 'zostaje na chwilę')));

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame([$zostaje->getKey()], ShoppingListUndo::query()->pluck('user_id')->all());
        $this->assertSame([], $this->teksty($odchodzi), 'Po wymazaniu nic nie wraca na listę.');
        $this->assertSame(0, DB::table('shopping_list_undos')->where('user_id', $odchodzi->getKey())->count());
    }

    public function test_przepis_usuniety_w_miedzyczasie_wraca_jako_sam_tekst_bez_tytulu_i_linku(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'));
        $p = $this->pozycja($ja, '2 jajka', 0, false, $przepis);
        $tytul = $przepis->title;

        $this->actingAs($ja)->delete(route('shopping.destroy', $p));
        $przepis->forceDelete();
        $this->actingAs($ja)->post(route('shopping.undo'))->assertSessionHas('status', 'Przywrócone pozycje: 1.');

        $wrocona = ShoppingListItem::query()->findOrFail($p->getKey());
        $this->assertNull($wrocona->recipe_id);
        $this->assertSame('2 jajka', $wrocona->text);
        $this->assertSame(ShoppingListItem::SOURCE_RECIPE, $wrocona->source);
        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('Przepis został usunięty.', $html);
        $this->assertStringNotContainsString($tytul, $html);
    }

    public function test_przepis_ktory_przestal_byc_widoczny_nie_odzyskuje_tytulu_po_cofnieciu(): void
    {
        $ja = $this->user('kupujaca');
        $przepis = $this->przepis($this->user('kucharka'));
        $p = $this->pozycja($ja, '2 jajka', 0, false, $przepis);

        $this->actingAs($ja)->delete(route('shopping.destroy', $p));
        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->actingAs($ja)->post(route('shopping.undo'));

        $html = (string) $this->actingAs($ja)->get(route('shopping.index'))->getContent();
        $this->assertStringContainsString('Przepis jest już niedostępny.', $html);
        $this->assertStringNotContainsString($przepis->title, $html);
    }

    public function test_schemat_pilnuje_zakresu_i_migawki_a_rollback_odmawia_przy_swiezych_wierszach(): void
    {
        $ja = $this->user('kupujaca');
        $wiersz = ['id' => (string) Str::uuid(), 'user_id' => $ja->getKey(), 'scope' => 'single',
            'items' => '[{"x":1}]', 'items_count' => 1, 'expires_at' => now()->addMinutes(5), 'created_at' => now()];

        foreach ([['scope' => 'inny'], ['items_count' => 2], ['items' => '{"a":1}']] as $zepsute) {
            try {
                DB::transaction(fn () => DB::table('shopping_list_undos')->insert([...$wiersz, ...$zepsute]));
                $this->fail('Baza przyjęła niepoprawną migawkę: '.json_encode($zepsute));
            } catch (QueryException $e) {
                $this->assertStringContainsString('shopping_list_undos', $e->getMessage());
            }
        }

        DB::table('shopping_list_undos')->insert($wiersz);
        $migracja = require base_path('database/migrations/2026_10_02_100000_create_shopping_list_undos_table.php');

        try {
            $migracja->down();
            $this->fail('Rollback skasował oczekujące cofnięcia bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW=1', $e->getMessage());
        }
        $this->assertSame(1, DB::table('shopping_list_undos')->count());

        // Wygasły wiersz nie blokuje, a wymuszenie działa.
        DB::table('shopping_list_undos')->update(['expires_at' => now()->subMinute()]);
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('shopping_list_undos'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('shopping_list_undos'));
        DB::table('shopping_list_undos')->insert($wiersz);
        putenv('KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW=1');
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('shopping_list_undos'));
        putenv('KUKING_ROLLBACK_KASUJE_COFNIECIA_ZAKUPOW');
        $migracja->up();
    }

    public function test_pole_sterujace_migawki_nie_wchodzi_masowo(): void
    {
        $this->expectException(MassAssignmentException::class);
        (new ShoppingListUndo)->fill(['user_id' => 'x', 'items' => []]);
    }
}

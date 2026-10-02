<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Pantry\DodajKupioneDoSpizarni;
use App\Models\PantryItem;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * „Dodaj kupione do »Co mam w domu«" (V2, #2481): wybrane odhaczone pozycje
 * własnej listy zakupów, z nazwą poprawianą przez człowieka.
 */
final class ZakupyDoSpizarniTest extends TestCase
{
    use RefreshDatabase;

    private User $ja;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
        $this->ja = $this->user('kupujaca');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pozycja(User $kto, string $tekst, bool $odhaczona = true, int $miejsce = 0): ShoppingListItem
    {
        $p = new ShoppingListItem(['text' => $tekst]);
        $p->user_id = $kto->getKey();
        $p->source = ShoppingListItem::SOURCE_MANUAL;
        $p->position = $miejsce;
        $p->checked_at = $odhaczona ? now() : null;
        $p->save();

        return $p;
    }

    /** @param  array<string, string>  $nazwy */
    private function wyslij(array $nazwy, ?User $kto = null)
    {
        return $this->actingAs($kto ?? $this->ja)->post(route('shopping.pantry.store'), [
            'pozycje' => array_keys($nazwy),
            'nazwy' => $nazwy,
        ]);
    }

    /** @return list<string> */
    private function spizarnia(): array
    {
        return PantryItem::query()->where('user_id', $this->ja->getKey())->orderBy('name')->pluck('name')->all();
    }

    public function test_odhaczenie_samo_w_sobie_nie_zmienia_spizarni(): void
    {
        $p = $this->pozycja($this->ja, 'mleko', false);

        $this->actingAs($this->ja)->patch(route('shopping.toggle', $p), ['odhaczona' => 1])->assertRedirect();

        $this->assertSame(0, PantryItem::query()->count());
    }

    public function test_formularz_pokazuje_tylko_wlasne_odhaczone_z_nazwa_do_poprawy_i_bez_domyslnego_zaznaczenia(): void
    {
        $this->pozycja($this->ja, '2 szklanki mąki');
        $this->pozycja($this->ja, 'papier do pieczenia', false);
        $this->pozycja($this->user('obca'), 'CUDZA-POZYCJA');

        $html = (string) $this->actingAs($this->ja)->get(route('shopping.pantry.form'))
            ->assertOk()
            ->assertSee('Dodaj: 2 szklanki mąki')
            ->assertSee('Nazwa produktu dla pozycji „2 szklanki mąki”')
            ->assertDontSee('papier do pieczenia')
            ->assertDontSee('CUDZA-POZYCJA')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="pozycje\[\]"[^>]*\schecked/', $html, 'Domyślnie nic nie jest zaznaczone.');
        $this->assertMatchesRegularExpression('/<form class="panel-formularza" method="POST"[^>]*\snovalidate/', $html);
    }

    public function test_brak_odhaczonych_odsyla_do_listy_z_wyjasnieniem(): void
    {
        $this->pozycja($this->ja, 'mleko', false);

        $this->actingAs($this->ja)->get(route('shopping.pantry.form'))
            ->assertRedirect(route('shopping.index'))
            ->assertSessionHas('status', fn ($t) => str_contains((string) $t, 'Nie ma odhaczonych pozycji'));
    }

    public function test_wybrane_pozycje_trafiaja_do_spizarni_pod_poprawionymi_nazwami_a_lista_zostaje(): void
    {
        $mleko = $this->pozycja($this->ja, 'mleko', true, 0);
        $maka = $this->pozycja($this->ja, '2 szklanki mąki', true, 1);
        $papier = $this->pozycja($this->ja, 'papier do pieczenia', true, 2);

        $this->wyslij([(string) $mleko->getKey() => 'mleko', (string) $maka->getKey() => 'mąka'])
            ->assertRedirect(route('pantry.index'))
            ->assertSessionHas('status', fn ($t) => str_contains((string) $t, 'Dodane do „Co mam w domu”: mleko, mąka.'));

        $this->assertSame(['mleko', 'mąka'], $this->spizarnia());
        $this->assertSame(3, ShoppingListItem::query()->count());
        $this->assertSame(['mleko', '2 szklanki mąki', 'papier do pieczenia'], ShoppingListItem::query()->orderBy('position')->pluck('text')->all());
        $this->assertSame(3, ShoppingListItem::query()->whereNotNull('checked_at')->count(), 'Odhaczenia zostają.');
        $this->assertNotNull($papier->fresh());
    }

    public function test_istniejacy_produkt_zostaje_bez_zmian_ilosci_terminy_i_zamrozenie(): void
    {
        $istniejacy = $this->ja->pantryItems()->create(['name' => 'Mleko']);
        DB::table('pantry_items')->where('id', $istniejacy->getKey())->update([
            'expires_on' => '2026-10-10',
            'expiry_kind' => 'use_by',
            'frozen' => true,
        ]);
        $p = $this->pozycja($this->ja, 'mleko');

        $this->wyslij([(string) $p->getKey() => 'mleko'])
            ->assertRedirect(route('pantry.index'))
            ->assertSessionHas('status', fn ($t) => str_contains((string) $t, 'Nic nowego nie zostało dodane')
                && str_contains((string) $t, 'Już były na liście i zostały bez zmian: Mleko.'));

        $po = PantryItem::query()->sole();
        $this->assertSame('Mleko', $po->name);
        $this->assertSame('2026-10-10', $po->expires_on?->toDateString());
        $this->assertTrue($po->frozen);
    }

    public function test_dwie_pozycje_z_ta_sama_nazwa_i_ponowne_wyslanie_nie_tworza_duplikatow(): void
    {
        $a = $this->pozycja($this->ja, 'jajka', true, 0);
        $b = $this->pozycja($this->ja, '6 jajek', true, 1);
        $nazwy = [(string) $a->getKey() => 'jajka', (string) $b->getKey() => 'jajko'];

        $this->wyslij($nazwy)->assertRedirect(route('pantry.index'));
        $this->assertCount(1, $this->spizarnia());

        $this->wyslij($nazwy)->assertRedirect(route('pantry.index'));
        $this->assertCount(1, $this->spizarnia());
    }

    public function test_zla_nazwa_daje_blad_przy_polu_zachowuje_wpisy_i_nic_nie_zapisuje(): void
    {
        $a = $this->pozycja($this->ja, 'mleko', true, 0);
        $b = $this->pozycja($this->ja, '12', true, 1);

        $this->wyslij([(string) $a->getKey() => 'mleko', (string) $b->getKey() => '12'])
            ->assertRedirect(route('shopping.pantry.form'))
            ->assertSessionHasErrors(['nazwy.'.$b->getKey()])
            ->assertSessionHasInput('nazwy', [(string) $a->getKey() => 'mleko', (string) $b->getKey() => '12']);

        $this->assertSame(0, PantryItem::query()->count(), 'Poprawna pozycja nie zapisuje się, gdy druga ma błąd.');

        // Po przekierowaniu formularz pokazuje błąd przy polu i zachowuje wpisane wartości.
        $html = (string) $this->actingAs($this->ja)->followingRedirects()->post(route('shopping.pantry.store'), [
            'pozycje' => [(string) $a->getKey(), (string) $b->getKey()],
            'nazwy' => [(string) $a->getKey() => 'moje mleko', (string) $b->getKey() => '12'],
        ])->getContent();
        $this->assertStringContainsString('value="moje mleko"', $html);
        $this->assertStringContainsString('value="12"', $html);
        $this->assertStringContainsString('Wpisz nazwę produktu słowami', $html);
        $this->assertMatchesRegularExpression('/value="'.$a->getKey().'"\s+checked/', $html);
    }

    public function test_za_dluga_nazwa_nie_jest_obcinana(): void
    {
        $a = $this->pozycja($this->ja, 'mleko');

        $this->wyslij([(string) $a->getKey() => str_repeat('ż', CoMamWDomu::MAKS_ZNAKOW + 1)])
            ->assertSessionHasErrors(['nazwy.'.$a->getKey()]);
        $this->assertSame(0, PantryItem::query()->count());
    }

    public function test_cudza_usunieta_i_nieodhaczona_pozycja_ze_starego_formularza_odrzuca_caly_zestaw(): void
    {
        $dobra = $this->pozycja($this->ja, 'mleko', true, 0);
        $cudza = $this->pozycja($this->user('obca'), 'cukier');
        $nieodhaczona = $this->pozycja($this->ja, 'sól', false, 1);
        $usunieta = $this->pozycja($this->ja, 'ocet', true, 2);
        $idUsunietej = (string) $usunieta->getKey();
        $usunieta->delete();

        foreach ([(string) $cudza->getKey(), (string) $nieodhaczona->getKey(), $idUsunietej] as $zly) {
            $this->wyslij([(string) $dobra->getKey() => 'mleko', $zly => 'cokolwiek'])
                ->assertRedirect(route('shopping.pantry.form'))
                ->assertSessionHasErrors('pozycje');
        }

        $this->assertSame(0, PantryItem::query()->count());
        $this->assertSame('cukier', ShoppingListItem::query()->whereKey($cudza->getKey())->sole()->text);
    }

    public function test_pusty_wybor_i_zle_identyfikatory_sa_odrzucone(): void
    {
        $this->actingAs($this->ja)->post(route('shopping.pantry.store'), [])->assertSessionHasErrors('pozycje');
        $this->actingAs($this->ja)->post(route('shopping.pantry.store'), ['pozycje' => ['nie-uuid'], 'nazwy' => []])->assertSessionHasErrors('pozycje.0');
        $this->assertSame(0, PantryItem::query()->count());
    }

    public function test_limit_spizarni_jest_atomowy_i_nic_nie_zapisuje(): void
    {
        foreach (range(1, CoMamWDomu::MAKS_PRODUKTOW - 1) as $i) {
            $this->ja->pantryItems()->create(['name' => 'produkt '.chr(97 + intdiv($i, 26) % 26).chr(97 + $i % 26).' '.str_repeat('x', $i % 7).' '.$i.'a']);
        }
        $przed = PantryItem::query()->count();
        $a = $this->pozycja($this->ja, 'mleko', true, 0);
        $b = $this->pozycja($this->ja, 'ser żółty', true, 1);

        $this->wyslij([(string) $a->getKey() => 'mleko', (string) $b->getKey() => 'ser żółty'])
            ->assertRedirect(route('shopping.pantry.form'))
            ->assertSessionHasErrors('pozycje');

        $this->assertSame($przed, PantryItem::query()->count(), 'Pierwsza pozycja nie zostaje po błędzie limitu.');
    }

    public function test_przycisk_na_liscie_widoczny_tylko_przy_odhaczonych(): void
    {
        $adres = route('shopping.pantry.form');

        $this->pozycja($this->ja, 'mleko', false);
        $this->actingAs($this->ja)->get(route('shopping.index'))->assertOk()->assertDontSee($adres, false);

        $this->pozycja($this->ja, 'masło', true, 1);
        $this->actingAs($this->ja)->get(route('shopping.index'))->assertOk()
            ->assertSee($adres, false)
            ->assertSee('Dodaj kupione do „Co mam w domu”');
    }

    public function test_akcja_domenowa_wymaga_wyboru_i_jest_prywatna(): void
    {
        $this->expectException(ValidationException::class);

        app(DodajKupioneDoSpizarni::class)->handle($this->ja, []);
    }
}

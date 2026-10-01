<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\PantryItem;
use App\Models\ProductSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ekran „Ustaw termin” (#1903, D-333): zwykły formularz, bez JavaScriptu.
 * „Dziś” to 10 października 2026, południe w Warszawie.
 */
class TerminProduktuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function produkt(User $wlasciciel, string $nazwa = 'mleko'): PantryItem
    {
        /** @var PantryItem */
        return $wlasciciel->pantryItems()->create(['name' => $nazwa]);
    }

    private function wyslij(User $ja, PantryItem $produkt, array $dane)
    {
        return $this->actingAs($ja)
            ->from(route('pantry.edit', $produkt))
            ->put(route('pantry.update', $produkt), ['_formularz' => 'termin', ...$dane]);
    }

    public function test_formularz_ma_trzy_listy_wyboru_szybkie_przyciski_i_uwage_o_terminie(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->actingAs($ja)->get(route('pantry.edit', $produkt))
            ->assertOk()
            ->assertSee('Ustaw termin: mleko')
            ->assertSee('Należy zużyć do')
            ->assertSee('Najlepiej spożyć przed')
            ->assertSee('Nie znam terminu')
            ->assertSee('name="termin_dzien"', false)
            ->assertSee('name="termin_miesiac"', false)
            ->assertSee('name="termin_rok"', false)
            ->assertSee('Za 3 dni')
            ->assertSee('Za tydzień')
            ->assertSee('Za 2 tygodnie')
            ->assertSee('Za miesiąc')
            ->assertSee('Mam to w zamrażarce')
            ->assertSee('Kuking nie ocenia, czy produkt nadaje się do jedzenia')
            ->assertSee('novalidate', false)
            ->assertDontSee('type="date"', false);
    }

    public function test_lista_lat_to_poprzedni_rok_do_dzis_plus_piec(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $html = $this->actingAs($ja)->get(route('pantry.edit', $produkt))->getContent();

        foreach ([2025, 2026, 2027, 2028, 2029, 2030, 2031] as $rok) {
            $this->assertStringContainsString('<option value="'.$rok.'"', $html);
        }
        $this->assertStringNotContainsString('<option value="2024"', $html);
        $this->assertStringNotContainsString('<option value="2032"', $html);
    }

    public function test_ustawienie_terminu_z_list_wyboru(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->wyslij($ja, $produkt, [
            'rodzaj' => 'use_by',
            'termin_dzien' => '3',
            'termin_miesiac' => '11',
            'termin_rok' => '2026',
            'ilosc' => '  1   litr ',
        ])->assertRedirect(route('pantry.index'))->assertSessionHasNoErrors();

        $produkt->refresh();
        $this->assertSame('2026-11-03', $produkt->expires_on?->toDateString());
        $this->assertSame('use_by', $produkt->expiry_kind);
        $this->assertSame('1 litr', $produkt->quantity_note);
        $this->assertFalse($produkt->frozen);

        $this->actingAs($ja)->get(route('pantry.index'))
            ->assertSee('Zapisano termin: mleko, do 3 listopada 2026.');
    }

    public function test_szybkie_przyciski_licza_date_od_dzis_po_stronie_serwera(): void
    {
        $ja = $this->user();

        foreach (['3' => '2026-10-13', '7' => '2026-10-17', '14' => '2026-10-24', 'miesiac' => '2026-11-10'] as $za => $oczekiwana) {
            $produkt = $this->produkt($ja, ['3' => 'mleko', '7' => 'jogurt', '14' => 'kefir', 'miesiac' => 'maslo'][$za]);
            $this->wyslij($ja, $produkt, ['rodzaj' => 'best_before', 'za' => (string) $za])
                ->assertRedirect(route('pantry.index'));

            $produkt->refresh();
            $this->assertSame($oczekiwana, $produkt->expires_on?->toDateString(), "za = {$za}");
            $this->assertSame('best_before', $produkt->expiry_kind);
        }
    }

    public function test_szybki_przycisk_bez_rodzaju_daje_blad_przy_grupie_a_nic_sie_nie_zapisuje(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->wyslij($ja, $produkt, ['za' => '3'])
            ->assertRedirect(route('pantry.edit', $produkt))
            ->assertSessionHasErrors(['rodzaj' => 'Zaznacz, jaki to termin: „Należy zużyć do” albo „Najlepiej spożyć przed”, albo wybierz „Nie znam terminu”.']);

        $this->assertNull($produkt->fresh()->expires_on);
    }

    public function test_termin_bez_daty_i_data_bez_rodzaju_to_bledy_a_nieznany_czysci(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by'])
            ->assertSessionHasErrors('termin_dzien');
        $this->wyslij($ja, $produkt, ['termin_dzien' => '3', 'termin_miesiac' => '11', 'termin_rok' => '2026'])
            ->assertSessionHasErrors('rodzaj');
        $this->assertNull($produkt->fresh()->expires_on);

        $this->wyslij($ja, $produkt, ['rodzaj' => 'best_before', 'za' => '7'])->assertSessionHasNoErrors();
        $this->assertNotNull($produkt->fresh()->expires_on);

        $this->wyslij($ja, $produkt, ['rodzaj' => 'nieznany', 'termin_dzien' => '3', 'termin_miesiac' => '11', 'termin_rok' => '2026'])
            ->assertSessionHasNoErrors();
        $produkt->refresh();
        $this->assertNull($produkt->expires_on);
        $this->assertNull($produkt->expiry_kind);
    }

    public function test_wyczysc_termin_usuwa_date_i_rodzaj_razem(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'za' => '3']);
        $this->assertNotNull($produkt->fresh()->expires_on);

        $this->wyslij($ja, $produkt, ['wyczysc' => '1'])->assertRedirect(route('pantry.index'));

        $produkt->refresh();
        $this->assertNull($produkt->expires_on);
        $this->assertNull($produkt->expiry_kind);
    }

    public function test_31_lutego_daje_blad_przy_polu_w_podsumowaniu_a_dane_wracaja(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $dane = [
            '_formularz' => 'termin',
            'rodzaj' => 'best_before',
            'termin_dzien' => '31',
            'termin_miesiac' => '2',
            'termin_rok' => '2027',
            'ilosc' => 'pół kostki',
            'mrozone' => '1',
        ];

        $this->actingAs($ja)->from(route('pantry.edit', $produkt))->put(route('pantry.update', $produkt), $dane)
            ->assertRedirect(route('pantry.edit', $produkt))
            ->assertSessionHasErrors(['termin_dzien' => 'W tym miesiącu nie ma takiego dnia. Wybierz inny dzień albo miesiąc.']);
        $this->assertNull($produkt->fresh()->expires_on);

        $strona = $this->actingAs($ja)->followingRedirects()->from(route('pantry.edit', $produkt))
            ->put(route('pantry.update', $produkt), $dane);
        $strona->assertOk()
            ->assertSee('Sprawdź formularz')
            ->assertSee('href="#f-termin_dzien"', false)
            ->assertSee('id="f-termin_dzien-error"', false)
            ->assertSee('W tym miesiącu nie ma takiego dnia. Wybierz inny dzień albo miesiąc.');

        $html = $strona->getContent();
        // Poprawnie wybrane pola nie znikają.
        $this->assertMatchesRegularExpression('/<option value="31"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="2"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="2027"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/value="best_before"\s+checked/', $html);
        $this->assertStringContainsString('value="pół kostki"', $html);
        $this->assertMatchesRegularExpression('/name="mrozone"[^>]*checked/', $html);
    }

    public function test_rok_spoza_listy_i_niepelna_data_to_bledy(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'termin_dzien' => '3', 'termin_miesiac' => '11', 'termin_rok' => '2040'])
            ->assertSessionHasErrors(['termin_rok' => 'Wybierz rok z listy.']);
        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'termin_dzien' => '3', 'termin_miesiac' => '', 'termin_rok' => '2026'])
            ->assertSessionHasErrors('termin_dzien');
        $this->assertNull($produkt->fresh()->expires_on);
    }

    public function test_termin_z_przeszlosci_wolno_wpisac(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'termin_dzien' => '1', 'termin_miesiac' => '10', 'termin_rok' => '2026'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-10-01', $produkt->fresh()->expires_on?->toDateString());
        $this->actingAs($ja)->get(route('pantry.index'))->assertSee('Termin minął 9 dni temu.');
    }

    /**
     * #2365: po błędzie w sąsiednim polu wpisana ilość nie znika — także wtedy,
     * gdy zapisana wcześniej ilość jest inna (widać ją dopiero przy pierwszym
     * wyświetleniu), a wyczyszczenie pola też jest zapamiętane.
     */
    public function test_wpisana_ilosc_wraca_po_bledzie_a_pierwsze_wyswietlenie_pokazuje_zapisana(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['ilosc' => '1 litr'])->assertSessionHasNoErrors();

        // Pierwsze wyświetlenie: zapisana wartość.
        $this->actingAs($ja)->get(route('pantry.edit', $produkt))->assertOk()
            ->assertSee('value="1 litr"', false);

        // Poprawna, NOWA ilość + błędna data: po błędzie widać to, co wpisano, a nie zapisane „1 litr”.
        $dane = [
            '_formularz' => 'termin', 'rodzaj' => 'use_by', 'termin_dzien' => '31', 'termin_miesiac' => '2',
            'termin_rok' => '2027', 'ilosc' => '2 litry',
        ];
        $strona = $this->actingAs($ja)->followingRedirects()->from(route('pantry.edit', $produkt))
            ->put(route('pantry.update', $produkt), $dane);
        $strona->assertOk()->assertSee('W tym miesiącu nie ma takiego dnia.')->assertSee('value="2 litry"', false);
        $this->assertStringNotContainsString('value="1 litr"', $strona->getContent());
        $this->assertSame('1 litr', $produkt->fresh()->quantity_note, 'Nieudany zapis niczego nie zmienia.');

        // Wyczyszczona ilość + błąd: pole zostaje puste, stara wartość nie wraca.
        $strona = $this->actingAs($ja)->followingRedirects()->from(route('pantry.edit', $produkt))
            ->put(route('pantry.update', $produkt), [...$dane, 'ilosc' => '']);
        $strona->assertOk()->assertSee('W tym miesiącu nie ma takiego dnia.');
        $this->assertStringNotContainsString('value="1 litr"', $strona->getContent());
        $this->assertMatchesRegularExpression('/name="ilosc"[^>]*value=""|value=""[^>]*name="ilosc"|name="ilosc"(?![^>]*value=)/', $strona->getContent());
    }

    public function test_pozostale_pola_formularza_tez_wracaja_po_bledzie_a_pierwsze_wyswietlenie_pokazuje_zapisane(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['rodzaj' => 'best_before', 'termin_dzien' => '5', 'termin_miesiac' => '11', 'termin_rok' => '2026', 'mrozone' => '1']);

        // Pierwsze wyświetlenie: zapisany stan.
        $html = $this->actingAs($ja)->get(route('pantry.edit', $produkt))->getContent();
        $this->assertMatchesRegularExpression('/<option value="5"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="11"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/value="best_before"\s+checked/', $html);
        $this->assertMatchesRegularExpression('/name="mrozone"[^>]*checked/', $html);

        // Po błędzie: to, co wpisano (inny rodzaj, inna data, odznaczone „mrożone”), a nie zapisane.
        $strona = $this->actingAs($ja)->followingRedirects()->from(route('pantry.edit', $produkt))->put(route('pantry.update', $produkt), [
            '_formularz' => 'termin', 'rodzaj' => 'use_by', 'termin_dzien' => '31', 'termin_miesiac' => '4',
            'termin_rok' => '2027', 'ilosc' => str_repeat('a', 41),
        ]);
        $html = $strona->getContent();
        $this->assertMatchesRegularExpression('/<option value="31"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="4"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/value="use_by"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="best_before"\s+checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="mrozone"[^>]*checked/', $html);
        $this->assertStringContainsString('Skróć opis ilości do 40 znaków', $html);
    }

    public function test_ilosc_dluzsza_niz_40_znakow_dostaje_blad_po_polsku(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->wyslij($ja, $produkt, ['ilosc' => str_repeat('a', 41)])
            ->assertSessionHasErrors(['ilosc' => 'Skróć opis ilości do 40 znaków, na przykład „pół kostki” albo „1 litr”.']);
        $this->assertNull($produkt->fresh()->quantity_note);

        $this->wyslij($ja, $produkt, ['ilosc' => str_repeat('a', 40)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('a', 40), $produkt->fresh()->quantity_note);

        // Pusta (same spacje) ilość czyści pole.
        $this->wyslij($ja, $produkt, ['ilosc' => '   '])->assertSessionHasNoErrors();
        $this->assertNull($produkt->fresh()->quantity_note);
    }

    public function test_mrozone_ustawia_sie_i_zdejmuje_a_termin_zostaje(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'za' => '3', 'mrozone' => '1']);

        $produkt->refresh();
        $this->assertTrue($produkt->frozen);
        $this->assertSame('2026-10-13', $produkt->expires_on?->toDateString());

        // Zapis samej ilości, bez ruszania terminu (radio odtworzone z formularza).
        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'termin_dzien' => '13', 'termin_miesiac' => '10', 'termin_rok' => '2026'])
            ->assertSessionHasNoErrors();
        $produkt->refresh();
        $this->assertFalse($produkt->frozen, 'Odznaczone pole „mrożone” znaczy: nie mrożone.');
        $this->assertSame('2026-10-13', $produkt->expires_on?->toDateString());
    }

    public function test_zapis_bez_zadnego_wyboru_nie_rusza_istniejacego_terminu(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['rodzaj' => 'best_before', 'za' => '7']);

        $this->wyslij($ja, $produkt, ['ilosc' => '2 sztuki'])->assertSessionHasNoErrors();

        $produkt->refresh();
        $this->assertSame('2026-10-17', $produkt->expires_on?->toDateString());
        $this->assertSame('best_before', $produkt->expiry_kind);
        $this->assertSame('2 sztuki', $produkt->quantity_note);
    }

    public function test_pola_sterujace_nie_wchodza_masowym_przypisaniem(): void
    {
        $ja = $this->user();

        // Tylko `name` i `quantity_note` są w `$fillable`; reszta ustawiana jest
        // wyłącznie akcją `ZmienTerminProduktu` (model rzuca przy próbie obejścia).
        foreach (['expires_on' => '2026-10-11', 'expiry_kind' => 'use_by', 'frozen' => true, 'user_id' => $ja->getKey()] as $pole => $wartosc) {
            try {
                $ja->pantryItems()->create(['name' => 'ser', 'quantity_note' => '100 g', $pole => $wartosc]);
                $this->fail("Pole {$pole} nie może wejść masowym przypisaniem.");
            } catch (MassAssignmentException $e) {
                $this->assertStringContainsString($pole, $e->getMessage());
            }
        }

        $produkt = $ja->pantryItems()->create(['name' => 'ser', 'quantity_note' => '100 g']);
        $this->assertSame('100 g', $produkt->fresh()->quantity_note);
        $this->assertSame(['name', 'quantity_note'], (new PantryItem)->getFillable());
    }

    public function test_produkt_usuniety_rownolegle_daje_404_z_komunikatem_po_polsku(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $id = $produkt->getKey();
        $produkt->delete();

        $this->actingAs($ja)->get(route('pantry.edit', $id))
            ->assertNotFound()
            ->assertSee('Tego produktu już nie ma na liście.');
        $this->actingAs($ja)->put(route('pantry.update', $id), ['rodzaj' => 'use_by', 'za' => '3'])
            ->assertNotFound()
            ->assertSee('Tego produktu już nie ma na liście.');
    }

    public function test_dwie_edycje_naraz_wygrywa_ostatnia_bez_utraty_innych_pol(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['ilosc' => 'pół kostki', 'rodzaj' => 'use_by', 'za' => '3']);

        // Druga karta ma stary odczyt produktu; UPDATE dotyka tylko pól formularza.
        $this->wyslij($ja, $produkt, ['rodzaj' => 'best_before', 'za' => '14', 'ilosc' => 'pół kostki']);

        $produkt->refresh();
        $this->assertSame('2026-10-24', $produkt->expires_on?->toDateString());
        $this->assertSame('best_before', $produkt->expiry_kind);
        $this->assertSame('pół kostki', $produkt->quantity_note);
        $this->assertSame('mleko', $produkt->name);
    }

    public function test_zapis_terminu_zostawia_anonimowy_sygnal_bez_konta_i_bez_nazwy(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja, 'mleko sekretne');

        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'za' => '3']);

        $wiersz = ProductSignal::query()->where('signal_name', ZapiszSygnal::PANTRY_EXPIRY_SET)->sole();
        $this->assertNull($wiersz->user_id);
        $this->assertSame([], $wiersz->properties);
        $this->assertStringNotContainsString('sekretne', json_encode($wiersz->getAttributes(), JSON_UNESCAPED_UNICODE));
    }

    public function test_sygnal_ustawienia_terminu_leci_tylko_gdy_termin_naprawde_sie_zmienil(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'za' => '3']);
        $this->assertSame(1, $this->liczbaSygnalowTerminu());

        // Sama ilość / „mrożone” przy tym samym terminie — bez sygnału.
        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'termin_dzien' => '13', 'termin_miesiac' => '10', 'termin_rok' => '2026', 'ilosc' => 'pół kostki', 'mrozone' => '1']);
        $this->assertSame(1, $this->liczbaSygnalowTerminu());

        // Zmiana daty albo rodzaju — sygnał.
        $this->wyslij($ja, $produkt, ['rodzaj' => 'best_before', 'termin_dzien' => '13', 'termin_miesiac' => '10', 'termin_rok' => '2026']);
        $this->assertSame(2, $this->liczbaSygnalowTerminu());

        // Wyczyszczenie terminu nie jest „ustawieniem”.
        $this->wyslij($ja, $produkt, ['wyczysc' => '1']);
        $this->assertSame(2, $this->liczbaSygnalowTerminu());
    }

    public function test_sygnal_ogladania_priorytetu_leci_raz_na_sesje_a_nie_przy_kazdym_get(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'za' => '3']);
        $liczba = fn (): int => ProductSignal::query()->where('signal_name', ZapiszSygnal::PANTRY_PRIORITY_VIEWED)->count();

        $this->actingAs($ja)->get(route('pantry.index'))->assertOk();
        $this->get(route('pantry.index'))->assertOk();
        $this->get(route('pantry.index'))->assertOk();
        $this->assertSame(1, $liczba());
    }

    public function test_produkt_z_terminem_spoza_listy_lat_zachowuje_rok_na_liscie_i_przyjmuje_zmiane_ilosci(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update(['expires_on' => '2020-05-05', 'expiry_kind' => 'best_before']);

        $this->actingAs($ja)->get(route('pantry.edit', $produkt))->assertOk()
            ->assertSee('<option value="2020" selected>2020</option>', false);

        $this->wyslij($ja, $produkt, [
            'rodzaj' => 'best_before', 'termin_dzien' => '5', 'termin_miesiac' => '5', 'termin_rok' => '2020', 'ilosc' => 'pół kostki',
        ])->assertSessionHasNoErrors();

        $produkt->refresh();
        $this->assertSame('pół kostki', $produkt->quantity_note);
        $this->assertSame('2020-05-05', $produkt->expires_on?->toDateString());

        // Inny rok spoza listy nadal jest błędem.
        $this->wyslij($ja, $produkt, ['rodzaj' => 'best_before', 'termin_dzien' => '5', 'termin_miesiac' => '5', 'termin_rok' => '2019'])
            ->assertSessionHasErrors('termin_rok');
    }

    public function test_szybki_przycisk_wygrywa_z_nie_znam_terminu_i_termin_nie_znika(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);
        $this->wyslij($ja, $produkt, ['rodzaj' => 'use_by', 'za' => '7'])->assertSessionHasNoErrors();
        $przed = $produkt->fresh()->expires_on?->toDateString();
        $this->assertNotNull($przed);

        $this->wyslij($ja, $produkt, ['rodzaj' => 'nieznany', 'za' => '3'])
            ->assertSessionHasErrors('rodzaj');

        $produkt->refresh();
        $this->assertSame($przed, $produkt->expires_on?->toDateString(), 'Termin nie może zniknąć po kliknięciu „Za 3 dni”.');
        $this->assertSame('use_by', $produkt->expiry_kind);
    }

    public function test_lista_jest_pogrupowana_i_pokazuje_regule_i_karty_stanu(): void
    {
        $ja = $this->user();
        $ja->pantryItems()->create(['name' => 'bułka']);
        $mleko = $this->produkt($ja, 'mleko');
        $szynka = $this->produkt($ja, 'szynka');
        $ocet = $this->produkt($ja, 'ocet');
        $kurczak = $this->produkt($ja, 'kurczak');
        $this->wyslij($ja, $mleko, ['rodzaj' => 'use_by', 'termin_dzien' => '8', 'termin_miesiac' => '10', 'termin_rok' => '2026']);
        $this->wyslij($ja, $szynka, ['rodzaj' => 'best_before', 'za' => '3']);
        $this->wyslij($ja, $ocet, ['rodzaj' => 'best_before', 'termin_dzien' => '1', 'termin_miesiac' => '3', 'termin_rok' => '2027']);
        $this->wyslij($ja, $kurczak, ['rodzaj' => 'use_by', 'za' => '3', 'mrozone' => '1']);

        $html = $this->actingAs($ja)->get(route('pantry.index'))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Później'), strpos($html, 'Zużyj w pierwszej kolejności'));
        $this->assertLessThan(strpos($html, 'Bez terminu'), strpos($html, 'Później'));
        $this->assertLessThan(strpos($html, 'Mrożone'), strpos($html, 'Bez terminu'));
        $this->assertLessThan(strpos($html, 'szynka'), strpos($html, 'mleko'), 'Wcześniejszy termin pierwszy.');
        $this->assertStringContainsString('Termin minął 2 dni temu.', $html);
        $this->assertStringContainsString('Termin za 3 dni (13 października).', $html);
        $this->assertStringContainsString('W zamrażarce.', $html);
        $this->assertStringContainsString('Bez terminu.', $html);
        $this->assertStringContainsString('Na górze są produkty z terminem, który minął albo upływa w ciągu 3 dni', $html);
        $this->assertStringContainsString('Najpierw to, co się psuje', $html);
        $this->assertStringContainsString('aria-label="Zmień termin: mleko"', $html);
        $this->assertStringContainsString('aria-label="Ustaw termin: bułka"', $html);
        $this->assertDoesNotMatchRegularExpression('/śwież|bezpiecz|zepsut|marnuj|uratuj/iu', strip_tags($html));
    }

    public function test_pusta_sekcja_pilnych_mowi_co_zrobic(): void
    {
        $ja = $this->user();
        $this->produkt($ja);

        $this->actingAs($ja)->get(route('pantry.index'))
            ->assertSee('Nic nie wymaga pilnego zużycia. Dodaj terminy do produktów, a pokażemy je tutaj.');
    }

    public function test_po_dodaniu_produktu_jest_link_ustaw_termin(): void
    {
        $ja = $this->user();

        $this->actingAs($ja)->post(route('pantry.store'), ['nazwa' => 'jogurt'])->assertRedirect(route('pantry.index'));
        $produkt = $ja->pantryItems()->sole();

        $this->actingAs($ja)->get(route('pantry.index'))
            ->assertSee('Dodano „jogurt” do listy.')
            ->assertSee(route('pantry.edit', $produkt), false);
    }

    public function test_enter_w_formularzu_odpowiada_ukrytym_zapisz_a_nie_szybkim_przyciskiem(): void
    {
        $ja = $this->user();
        $produkt = $this->produkt($ja);

        $html = $this->actingAs($ja)->get(route('pantry.edit', $produkt))->getContent();

        $pierwszy = strpos($html, 'type="submit"');
        $this->assertNotFalse($pierwszy);
        $this->assertLessThan(strpos($html, 'name="za"'), $pierwszy);
        $this->assertStringContainsString('class="sr-only" tabindex="-1" aria-hidden="true">Zapisz</button>', $html);
    }

    /**
     * Każde wywołanie czyta bazę od nowa — Larastan 3.12 inaczej pamięta wynik
     * `count()` z poprzedniej asercji (method.alreadyNarrowedType).
     *
     * @phpstan-impure
     */
    private function liczbaSygnalowTerminu(): int
    {
        return ProductSignal::query()->where('signal_name', ZapiszSygnal::PANTRY_EXPIRY_SET)->count();
    }
}

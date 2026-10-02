<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\CoMamWDomu;
use App\Domain\Pantry\CoUgotuje;
use App\Domain\Pantry\ZmienNazweProduktu;
use App\Models\PantryItem;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Poprawianie nazwy produktu w „Co mam w domu” bez utraty ilości i terminu
 * (#2448, V2).
 *
 * Pomiary idą przez HTTP i końcowy HTML; akcja domenowa jest wołana wprost
 * tylko tam, gdzie kryterium wymaga kontroli niezależnej od trasy.
 */
final class SpizarniaZmienNazweTest extends TestCase
{
    use RefreshDatabase;

    /** Produkt z ilością, terminem i „mrożone” — wszystko, co korekta nazwy ma zachować. */
    private function produkt(User $kto, string $nazwa = 'mleko'): PantryItem
    {
        /** @var PantryItem $produkt */
        $produkt = $kto->pantryItems()->create(['name' => $nazwa, 'quantity_note' => 'pół litra']);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update([
            'expires_on' => '2026-12-24', 'expiry_kind' => 'use_by', 'frozen' => true,
        ]);

        return $produkt->refresh();
    }

    private function blad(string $pole = 'nazwa'): string
    {
        $bledy = session('errors');
        $bag = $bledy instanceof ViewErrorBag
            ? $bledy->getBag('default')
            : new MessageBag((array) ($bledy['default']['messages'] ?? []));

        return (string) $bag->first($pole);
    }

    private function zmien(User $kto, PantryItem $produkt, string $nazwa, ?string $stara = null)
    {
        return $this->actingAs($kto)->put(route('pantry.name.update', $produkt), [
            'nazwa' => $nazwa,
            'stara_nazwa' => $stara ?? $produkt->refresh()->name,
        ]);
    }

    public function test_korekta_zmienia_tylko_nazwe_a_reszta_danych_i_tozsamosc_zostaja(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);
        $przed = (array) DB::table('pantry_items')->where('id', $produkt->getKey())->first();

        $this->zmien($ja, $produkt, '  mleko   kokosowe ')
            ->assertRedirect(route('pantry.index'))
            ->assertSessionHas('status', 'Nazwa zmieniona. Ilość, termin i oznaczenie „mrożone” zostały bez zmian.');

        $po = (array) DB::table('pantry_items')->where('id', $produkt->getKey())->first();
        $this->assertSame('mleko kokosowe', $po['name']);
        foreach (['id', 'user_id', 'created_at', 'expires_on', 'expiry_kind', 'quantity_note', 'frozen'] as $kolumna) {
            $this->assertEquals($przed[$kolumna], $po[$kolumna], "Kolumna {$kolumna} ma zostać bez zmian.");
        }
        $this->assertNotSame($przed['klucz'], $po['klucz'], 'Klucz przelicza baza w tym samym zapisie.');
        $this->assertSame(1, PantryItem::query()->count(), 'Korekta nie tworzy drugiego produktu.');
    }

    public function test_ekran_ma_etykiete_novalidate_obecna_nazwe_i_przycisk_przy_produkcie(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);

        $lista = (string) $this->actingAs($ja)->get(route('pantry.index'))->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('pantry.name.edit', $produkt).'"', $lista);
        $this->assertStringContainsString('>Zmień nazwę</a>', $lista);

        $html = (string) $this->actingAs($ja)->get(route('pantry.name.edit', $produkt))->assertOk()->getContent();
        $this->assertStringContainsString('value="mleko"', $html);
        $this->assertMatchesRegularExpression('~<form[^>]*novalidate[^>]*>~', $html);
        $this->assertStringContainsString('<label for="f-nazwa">', $html);
        $this->assertStringContainsString('Najwyżej '.CoMamWDomu::MAKS_ZNAKOW.' znaków.', $html);
        $this->assertStringContainsString('name="stara_nazwa" value="mleko"', $html);
        $this->assertStringContainsString('Anuluj, zostaw jak jest', $html);
        // Formularz nazwy nie niesie pól terminu, ilości ani „mrożone”.
        foreach (['name="ilosc"', 'name="mrozone"', 'name="rodzaj"', 'name="termin_rok"'] as $pole) {
            $this->assertStringNotContainsString($pole, $html);
        }
    }

    public function test_obca_osoba_gosc_i_moderator_nie_zmieniaja_cudzego_produktu_a_domena_traktuje_go_jak_nieistniejacy(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);

        foreach ([$this->user('obca'), $this->moderator()] as $obcy) {
            $this->zmien($obcy, $produkt, 'podmienione', 'mleko')->assertForbidden();
            $this->actingAs($obcy)->get(route('pantry.name.edit', $produkt))->assertForbidden();
        }
        auth()->logout();
        $this->put(route('pantry.name.update', $produkt), ['nazwa' => 'podmienione', 'stara_nazwa' => 'mleko'])->assertRedirect(route('login'));
        $this->get(route('pantry.name.edit', $produkt))->assertRedirect(route('login'));
        $this->assertSame('mleko', $produkt->refresh()->name);

        $wynik = app(ZmienNazweProduktu::class)->handle($this->user('obca2'), (string) $produkt->getKey(), 'podmienione', 'mleko');
        $this->assertSame(ZmienNazweProduktu::BRAK, $wynik);
        $this->assertSame('mleko', $produkt->refresh()->name);
    }

    public function test_te_same_reguly_nazwy_co_przy_dodawaniu_a_wpisany_tekst_nie_ginie(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);
        $dluga = str_repeat('a', CoMamWDomu::MAKS_ZNAKOW + 1);

        $this->zmien($ja, $produkt, '  ')->assertRedirect(route('pantry.name.edit', $produkt))->assertSessionHasErrors('nazwa');
        $this->zmien($ja, $produkt, 'a')->assertSessionHasErrors('nazwa');
        $this->assertSame('Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.', $this->blad());
        $this->zmien($ja, $produkt, $dluga)->assertSessionHasErrors('nazwa');
        $this->assertSame('Skróć nazwę produktu do '.CoMamWDomu::MAKS_ZNAKOW.' znaków. Wystarczy samo „mąka” albo „ser żółty”.', $this->blad());
        $this->zmien($ja, $produkt, '500 - 3')->assertSessionHasErrors('nazwa');
        $this->assertStringContainsString('Same liczby i znaki nie wystarczą', $this->blad());
        $this->actingAs($ja)->put(route('pantry.name.update', $produkt), ['nazwa' => ['x'], 'stara_nazwa' => 'mleko'])->assertSessionHasErrors('nazwa');
        $this->actingAs($ja)->put(route('pantry.name.update', $produkt), ['nazwa' => 'mleko kokosowe'])->assertSessionHasErrors('stara_nazwa');
        $this->assertSame('mleko', $produkt->refresh()->name);

        $html = (string) $this->followRedirects($this->actingAs($ja)->put(route('pantry.name.update', $produkt), [
            'nazwa' => $dluga, 'stara_nazwa' => 'mleko',
        ]))->getContent();
        $this->assertStringContainsString('error-summary', $html);
        $this->assertStringContainsString('field-error', $html);
        $this->assertStringContainsString('value="'.$dluga.'"', $html, 'Wpisana poprawka nie ginie.');
    }

    public function test_zmiana_samej_pisowni_jest_dozwolona_a_kolizja_z_innym_produktem_tej_osoby_odrzucona(): void
    {
        $ja = $this->user('kupujaca');
        $inna = $this->user('inna');
        $jajka = $this->produkt($ja, 'jajka');
        $maslo = $this->produkt($ja, 'masło');
        $this->produkt($inna, 'Jajko');

        // Ta sama nazwa u INNEJ osoby nie blokuje; zmiana pisowni własnego produktu (ten sam klucz) też nie.
        $klucz = $jajka->klucz;
        $this->zmien($ja, $jajka, 'Jajko')->assertSessionHas('status_rodzaj', 'sukces');
        $this->assertSame('Jajko', $jajka->refresh()->name);
        $this->assertSame($klucz, $jajka->klucz, 'Zmiana pisowni nie zmienia klucza.');

        // Kolizja z innym produktem tej samej osoby: odrzucona, nic nie scalone ani skasowane.
        $this->zmien($ja, $maslo, 'jajka')->assertRedirect(route('pantry.name.edit', $maslo))->assertSessionHasErrors('nazwa');
        $this->assertStringContainsString('Na Twojej liście jest już taki produkt „Jajko”', $this->blad());
        $this->assertSame('masło', $maslo->refresh()->name);
        $this->assertSame('pół litra', $maslo->quantity_note);
        $this->assertSame(2, $ja->pantryItems()->count());
        $this->assertSame(1, $inna->pantryItems()->count());
    }

    public function test_ta_sama_nazwa_nic_nie_zmienia(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);

        $this->zmien($ja, $produkt, ' mleko ')
            ->assertSessionHas('status', 'Ten produkt ma już taką nazwę. Nic nie zostało zmienione.');
        $this->assertSame('mleko', $produkt->refresh()->name);
    }

    public function test_korekta_dziala_przy_liscie_na_limicie_produktow(): void
    {
        $ja = $this->user('kupujaca');
        $pierwszy = null;
        for ($i = 0; $i < CoMamWDomu::MAKS_PRODUKTOW; $i++) {
            $nazwa = 'ba'.chr(97 + intdiv($i, 10)).'ko de'.chr(97 + $i % 10).'ru';
            $p = $ja->pantryItems()->create(['name' => $nazwa]);
            $pierwszy ??= $p;
        }
        $this->assertSame(CoMamWDomu::MAKS_PRODUKTOW, $ja->pantryItems()->count());

        $this->zmien($ja, $pierwszy, 'mleko kokosowe')->assertSessionHas('status_rodzaj', 'sukces');
        // Osobna zmienna: Larastan uznaje powtórzoną asercję na tym samym
        // wywołaniu za „zawsze prawdziwą” (STAN.md §3).
        $poZmianie = $ja->pantryItems()->count();
        $this->assertSame(CoMamWDomu::MAKS_PRODUKTOW, $poZmianie);
        $this->assertSame('mleko kokosowe', $pierwszy->refresh()->name);
    }

    public function test_dwie_karty_nie_nadpisuja_nowszej_nazwy_a_wpisany_tekst_zostaje_w_polu(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);

        $this->zmien($ja, $produkt, 'mleko kokosowe', 'mleko')->assertSessionHas('status_rodzaj', 'sukces');

        // Druga karta nadal widzi „mleko”.
        $this->zmien($ja, $produkt, 'mleko sojowe', 'mleko')
            ->assertRedirect(route('pantry.name.edit', $produkt))
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertStringContainsString('innym oknie', (string) session('status'));
        $this->assertSame('mleko kokosowe', $produkt->refresh()->name, 'Stara karta nie może nadpisać nowszej nazwy.');

        $html = (string) $this->followRedirects($this->actingAs($ja)->put(route('pantry.name.update', $produkt), [
            'nazwa' => 'mleko sojowe', 'stara_nazwa' => 'mleko',
        ]))->getContent();
        $this->assertStringContainsString('<strong>mleko kokosowe</strong>', $html);
        $this->assertStringContainsString('value="mleko sojowe"', $html);
        $this->assertStringContainsString('name="stara_nazwa" value="mleko kokosowe"', $html);

        $this->zmien($ja, $produkt, 'mleko sojowe')->assertSessionHas('status_rodzaj', 'sukces');
    }

    public function test_rownolegla_zmiana_ilosci_i_terminu_nie_jest_nadpisana_starym_formularzem_nazwy(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);
        // Formularz nazwy otwarty, zanim ilość i termin zostały zmienione w innej karcie.
        $stara = $produkt->name;
        DB::table('pantry_items')->where('id', $produkt->getKey())->update([
            'quantity_note' => '2 kartony', 'expires_on' => '2026-11-11', 'expiry_kind' => 'best_before', 'frozen' => false,
        ]);

        $this->zmien($ja, $produkt, 'mleko kokosowe', $stara)->assertSessionHas('status_rodzaj', 'sukces');

        $po = $produkt->refresh();
        $this->assertSame('mleko kokosowe', $po->name);
        $this->assertSame('2 kartony', $po->quantity_note);
        $this->assertSame('2026-11-11', $po->expires_on?->toDateString());
        $this->assertSame('best_before', $po->expiry_kind);
        $this->assertFalse($po->frozen);
    }

    public function test_produkt_usuniety_w_innej_karcie_daje_czytelny_stan_i_nie_wraca(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);
        $id = (string) $produkt->getKey();
        $produkt->delete();

        $this->actingAs($ja)->put(route('pantry.name.update', $id), ['nazwa' => 'mleko kokosowe', 'stara_nazwa' => 'mleko'])
            ->assertNotFound()
            ->assertSee('Tego produktu już nie ma na liście');
        $this->actingAs($ja)->get(route('pantry.name.edit', $id))->assertNotFound();
        $this->assertSame(0, PantryItem::query()->count(), 'Produkt nie powstaje z formularza.');

        $this->assertSame(ZmienNazweProduktu::BRAK, app(ZmienNazweProduktu::class)->handle($ja, $id, 'mleko kokosowe', 'mleko'));
        $this->assertSame(0, PantryItem::query()->count());
    }

    public function test_po_zmianie_nazwy_dopasowanie_przepisow_uzywa_nowej_nazwy(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja, 'masło');
        $przepis = Recipe::factory()->create(['title' => 'Zupa kokosowa', 'author_id' => $this->user('kucharka')->getKey()]);
        $przepis->ingredients()->create(['position' => 0, 'ingredient_text' => 'mleko kokosowe']);

        $przed = app(CoUgotuje::class)->dla($ja);
        $this->assertSame(['mleko kokosowe'], $przed['brakujace'][$przepis->getKey()] ?? ['mleko kokosowe']);
        $this->assertNotSame([], $przed['brakujace'][$przepis->getKey()] ?? ['jest'], 'Przed zmianą nazwy brakuje mleka kokosowego.');

        $this->zmien($ja, $produkt, 'mleko kokosowe');

        $po = app(CoUgotuje::class)->dla($ja);
        $this->assertSame([], $po['brakujace'][$przepis->getKey()], 'Z nową nazwą nic nie brakuje.');
        $this->actingAs($ja)->get(route('pantry.index'))->assertSee('mleko kokosowe')->assertDontSee('>mleko<', false);
    }

    public function test_korekta_nazwy_nie_jest_nowym_zdarzeniem_terminu(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);
        $sygnalyPrzed = DB::table((new ProductSignal)->getTable())->count();

        $this->zmien($ja, $produkt, 'mleko kokosowe');

        $this->assertSame($sygnalyPrzed, DB::table((new ProductSignal)->getTable())->count(), 'Zmiana nazwy nie zapisuje sygnału ustawienia terminu.');
        $this->assertSame('2026-12-24', $produkt->refresh()->expires_on?->toDateString());
    }

    public function test_zapis_bierze_blokade_konta_przed_blokada_produktu(): void
    {
        $ja = $this->user('kupujaca');
        $produkt = $this->produkt($ja);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->zmien($ja, $produkt, 'mleko kokosowe', 'mleko');
        $zapytania = array_map(fn (array $q): string => strtolower($q['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $blokadaKonta = null;
        $blokadaProduktu = null;
        foreach ($zapytania as $i => $sql) {
            if ($blokadaKonta === null && str_contains($sql, 'from "users"') && str_contains($sql, 'for update')) {
                $blokadaKonta = $i;
            }
            if ($blokadaProduktu === null && str_contains($sql, 'from "pantry_items"') && str_contains($sql, 'for update')) {
                $blokadaProduktu = $i;
            }
        }

        $this->assertNotNull($blokadaKonta, 'Brak blokady wiersza konta.');
        $this->assertNotNull($blokadaProduktu, 'Produkt musi być czytany pod blokadą.');
        $this->assertLessThan($blokadaProduktu, $blokadaKonta, 'Jedna kolejność blokad: najpierw konto, potem produkt.');
    }
}

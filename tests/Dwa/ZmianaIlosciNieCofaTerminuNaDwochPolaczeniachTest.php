<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1903: `ZmienTerminProduktu` czytał termin do zachowania z modelu wczytanego
 * PRZED blokadą wiersza. Gdy dwie edycje szły naraz — pierwsza ustawiała
 * termin, druga zmieniała samą ilość — druga zapisywała stary (pusty) termin
 * i po cichu cofała zapis pierwszej.
 *
 * BARIERA trzyma wiersz produktu `FOR UPDATE`; oba procesy wczytują model
 * PRZED zwolnieniem bariery i stają w kolejce do tej samej blokady, pierwszy
 * ustawia termin, drugi zmienia ilość.
 */
#[Group('dwa-polaczenia')]
final class ZmianaIlosciNieCofaTerminuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_zmiana_samej_ilosci_nie_cofa_terminu_zapisanego_rownolegle(): void
    {
        $user = $this->konto();
        $id = $user->pantryItems()->create(['name' => 'mleko'])->getKey();

        $bariera = $this->bariera('SELECT 1 FROM pantry_items WHERE id = ? FOR UPDATE', [(string) $id]);
        $termin = $this->wTle('ustaw-termin-pantry', [
            'produkt' => (string) $id,
            'dane' => json_encode(['rodzaj' => 'use_by', 'za' => '3']),
        ]);
        $this->czekajNaZablokowane(1);
        $ilosc = $this->wTle('ustaw-termin-pantry', [
            'produkt' => (string) $id,
            'dane' => json_encode(['ilosc' => '2 litry']),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$termin->wynik(), $ilosc->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'równoległa edycja produktu '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Edycja '.($numer + 1).' padła: '.$wynik['komunikat']);
        }

        $wiersz = DB::table('pantry_items')->where('id', $id)->first();
        $this->assertSame('2026-10-13', (string) $wiersz->expires_on, 'Zmiana samej ilości cofnęła termin zapisany przez drugą edycję.');
        $this->assertSame('use_by', $wiersz->expiry_kind);
        $this->assertSame('2 litry', $wiersz->quantity_note);
    }
}

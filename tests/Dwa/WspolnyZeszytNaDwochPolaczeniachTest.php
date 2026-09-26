<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Wspólny zeszyt na dwóch połączeniach (#1743, kryterium akceptacji):
 * równoległe przyjęcie zaproszenia oraz zapis kontra odebranie dostępu.
 */
#[Group('dwa-polaczenia')]
final class WspolnyZeszytNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_dwa_rownolegle_przyjecia_daja_jedno_czlonkostwo_i_jedno_powiadomienie(): void
    {
        $wlascicielka = $this->konto();
        $gosc = $this->konto();
        $zeszyt = Collection::create(['owner_id' => $wlascicielka->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $zaproszenie = app(ZaprosDoZeszytu::class)->poNazwie($wlascicielka, $zeszyt, (string) $gosc->profile->username);

        // Bariera na wierszu zaproszenia — oba procesy przechodzą zamki kont
        // po kolei i ustawiają się w kolejce po ten sam wiersz.
        $bariera = $this->bariera('SELECT 1 FROM collection_invitations WHERE id = ? FOR UPDATE', [(string) $zaproszenie->getKey()]);
        $argumenty = ['kto' => (string) $gosc->getKey(), 'zaproszenie' => (string) $zaproszenie->getKey()];
        $pierwszy = $this->wTle('przyjmij-zaproszenie', $argumenty);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('przyjmij-zaproszenie', $argumenty);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$pierwszy->wynik(), $drugi->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'przyjęcie '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Przyjęcie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(1, DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->count());
        $this->assertSame(1, DB::table('notifications')
            ->where('user_id', $wlascicielka->getKey())
            ->where('type', Notification::TYPE_COLLECTION_JOINED)
            ->count());
    }

    public function test_zapis_kontra_odebranie_dostepu_nie_zostawia_zapisu_po_odebraniu(): void
    {
        $wlascicielka = $this->konto();
        $gosc = $this->konto();
        $autor = $this->konto();
        $zeszyt = Collection::create(['owner_id' => $wlascicielka->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        app(OdpowiedzNaZaproszenie::class)->przyjmij(
            $gosc,
            app(ZaprosDoZeszytu::class)->poNazwie($wlascicielka, $zeszyt, (string) $gosc->profile->username),
        );
        $przepis = Recipe::factory()->for($autor, 'author')->create();

        // Odebranie dostępu trzyma zamek zeszytu; zapis czeka na niego pod
        // `ZamekZapisuDoZeszytu` i po zwolnieniu MUSI zobaczyć, że dostępu
        // już nie ma.
        $bariera = $this->bariera('SELECT 1 FROM collections WHERE id = ? FOR UPDATE', [(string) $zeszyt->getKey()]);
        $odebranie = $this->wTle('odbierz-dostep', [
            'wlasciciel' => (string) $wlascicielka->getKey(),
            'zeszyt' => (string) $zeszyt->getKey(),
            'czlonek' => (string) $gosc->getKey(),
        ]);
        $this->czekajNaZablokowane(1);
        $zapis = $this->wTle('zapisz-przepis', [
            'kto' => (string) $gosc->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'zeszyt' => (string) $zeszyt->getKey(),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikOdebrania = $odebranie->wynik();
        $wynikZapisu = $zapis->wynik();
        $this->assertBezZakleszczenia($wynikOdebrania, 'odebranie dostępu');
        $this->assertBezZakleszczenia($wynikZapisu, 'zapis');
        $this->assertTrue($wynikOdebrania['ok'], 'Odebranie padło: '.$wynikOdebrania['komunikat']);

        $czlonek = DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->exists();
        $pozycja = DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->exists();

        $this->assertFalse($czlonek, 'Członkostwo przetrwało odebranie dostępu.');

        // Kolejność zależy od tego, kto pierwszy dostał zamek zeszytu po
        // zwolnieniu bariery. Oba wyniki są poprawne, byle spójne: zapis
        // udany TYLKO wtedy, gdy wszedł przed odebraniem; odmowa — bez wiersza.
        if ($wynikZapisu['ok']) {
            $this->assertTrue($pozycja);
        } else {
            $this->assertFalse($pozycja, 'Zapis odmówił, a wiersz i tak powstał.');
        }
    }
}

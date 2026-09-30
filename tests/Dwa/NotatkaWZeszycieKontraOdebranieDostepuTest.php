<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Domain\Collections\Wspoldzielenie\DostepDoZeszytu;
use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Notatka współpracownika przy pozycji wspólnego zeszytu kontra odebranie
 * mu dostępu (#2311).
 *
 * Do poprawki `UpdateCollectionItemNote` pytał Policy raz, bez zamka,
 * i potem robił gołe `UPDATE collection_items`. Odebranie dostępu, które
 * zmieściło się między jednym a drugim, nie zatrzymywało zapisu — osoba
 * bez dostępu zmieniała (albo czyściła) notatkę właścicielki.
 *
 * Dwa przeploty, oba wymuszone barierą po rzeczywistym zapytaniu Policy
 * o członkostwo (`Collection::maCzlonka()`):
 *
 *  1. zatrzymanie po PIERWSZYM sprawdzeniu (bez zamka), odebranie dostępu
 *     zatwierdzone w tym czasie — zapis musi dostać odmowę i nie zmienić
 *     notatki (pilnuje ponownej Policy pod zamkiem);
 *  2. zatrzymanie po DRUGIM sprawdzeniu (pod zamkiem zeszytu) — odebranie
 *     musi czekać na zamek zeszytu, a zapis przejść, bo dostęp jeszcze był
 *     (pilnuje, że ponowna Policy stoi POD tym samym zamkiem co odebranie).
 *
 * Czego nie dowodzi: innych przeplotów (np. zmiany statusu konta) — te
 * pokrywa `ZamekZapisuDoZeszytu` i jego testy.
 */
#[Group('dwa-polaczenia')]
final class NotatkaWZeszycieKontraOdebranieDostepuTest extends TestDwochPolaczen
{
    /**
     * @return array{0: User, 1: User, 2: Collection, 3: Recipe}
     */
    private function wspolnyZeszytZNotatka(): array
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
        app(SaveRecipeToCollection::class)->handle($wlascicielka, $przepis, $zeszyt, 'Notatka właścicielki');

        return [$wlascicielka, $gosc, $zeszyt, $przepis];
    }

    private function notatka(Collection $zeszyt, Recipe $przepis): ?string
    {
        return DB::table('collection_items')
            ->where('collection_id', $zeszyt->getKey())
            ->where('recipe_id', $przepis->getKey())
            ->value('note');
    }

    public function test_odebranie_dostepu_po_pierwszym_sprawdzeniu_zatrzymuje_zapis_notatki(): void
    {
        [$wlascicielka, $gosc, $zeszyt, $przepis] = $this->wspolnyZeszytZNotatka();

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2311, 1)', []);
        $zapis = $this->wTle('notatka-w-zeszycie', [
            'kto' => (string) $gosc->getKey(),
            'zeszyt' => (string) $zeszyt->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'notatka' => '',
            'stop_po' => '1',
        ]);
        $this->czekajNaZablokowane(1);

        // Odebranie zatwierdzone, zanim zapis ruszy dalej.
        $this->assertTrue(app(DostepDoZeszytu::class)->odbierz($wlascicielka, $zeszyt, $gosc));
        $this->zwolnijBariere($bariera);

        $wynik = $zapis->wynik();
        $this->assertBezZakleszczenia($wynik, 'notatka');
        $this->assertFalse($wynik['ok'], 'Zapis notatki przeszedł po odebraniu dostępu.');
        $this->assertSame(AuthorizationException::class, $wynik['wyjatek'], 'Odmowa przyszła z innego powodu: '.$wynik['komunikat']);
        $this->assertSame('Notatka właścicielki', $this->notatka($zeszyt, $przepis), 'Osoba bez dostępu zmieniła notatkę.');
    }

    public function test_ponowne_sprawdzenie_stoi_pod_zamkiem_ktory_bierze_odebranie_dostepu(): void
    {
        [$wlascicielka, $gosc, $zeszyt, $przepis] = $this->wspolnyZeszytZNotatka();

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2311, 1)', []);
        $zapis = $this->wTle('notatka-w-zeszycie', [
            'kto' => (string) $gosc->getKey(),
            'zeszyt' => (string) $zeszyt->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'notatka' => 'Dopisek gościa',
            'stop_po' => '2',
        ]);
        $this->czekajNaZablokowane(1);

        $odebranie = $this->wTle('odbierz-dostep', [
            'wlasciciel' => (string) $wlascicielka->getKey(),
            'zeszyt' => (string) $zeszyt->getKey(),
            'czlonek' => (string) $gosc->getKey(),
        ]);
        // Odebranie MUSI stanąć w kolejce po zamek zeszytu trzymany przez zapis.
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikZapisu = $zapis->wynik();
        $wynikOdebrania = $odebranie->wynik();
        $this->assertBezZakleszczenia($wynikZapisu, 'notatka');
        $this->assertBezZakleszczenia($wynikOdebrania, 'odebranie dostępu');
        $this->assertTrue($wynikZapisu['ok'], 'Zapis z dostępem padł: '.$wynikZapisu['komunikat']);
        $this->assertTrue($wynikOdebrania['ok'], 'Odebranie padło: '.$wynikOdebrania['komunikat']);
        $this->assertSame('Dopisek gościa', $this->notatka($zeszyt, $przepis));
        $this->assertFalse(DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->exists());
    }
}

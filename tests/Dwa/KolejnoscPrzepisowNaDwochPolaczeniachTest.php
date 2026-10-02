<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\KolejnoscPrzepisow;
use App\Domain\Collections\KonfliktKolejnosci;
use App\Models\Collection;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2544: równoległe porządkowanie zeszytu w dwóch kartach nie nadpisuje po
 * cichu nowszego układu, a przesuwanie nie ściga się z dopisaniem przepisu
 * (unikalny indeks `(collection_id, position)` nie odbija żadnej operacji).
 *
 * Bariera trzyma wiersz konta właściciela tym samym `FOR UPDATE`, na którym
 * staje `ZamekZapisuDoZeszytu`, więc obie operacje czekają PRZED odczytem układu.
 */
#[Group('dwa-polaczenia')]
final class KolejnoscPrzepisowNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @return array{0: User, 1: Collection, 2: list<Recipe>} */
    private function zeszytZPrzepisami(int $ile, bool $uloz): array
    {
        $wlasciciel = $this->konto();
        $autor = $this->konto();
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Wyścig', 'visibility' => 'private']);
        $start = Carbon::parse('2026-09-01 10:00:00');
        $przepisy = [];

        for ($i = 0; $i < $ile; $i++) {
            $przepis = Recipe::factory()->for($autor, 'author')->create(['title' => 'Przepis '.$i]);
            DB::table('collection_items')->insert([
                'collection_id' => $zeszyt->getKey(),
                'recipe_id' => $przepis->getKey(),
                'created_at' => $start->copy()->addMinutes($i),
                'added_by_id' => $wlasciciel->getKey(),
                'position' => $uloz ? $i + 1 : null,
            ]);
            $przepisy[] = $przepis;
        }

        return [$wlasciciel, $zeszyt, $przepisy];
    }

    public function test_dwa_przesuniecia_ze_starym_odciskiem_daja_jedno_przesuniecie_i_jeden_konflikt(): void
    {
        [$wlasciciel, $zeszyt, $przepisy] = $this->zeszytZPrzepisami(4, true);
        $odcisk = KolejnoscPrzepisow::odcisk(KolejnoscPrzepisow::uklad($zeszyt));
        $ktoId = (string) $wlasciciel->getKey();

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [$ktoId]);
        $pierwsze = $this->wTle('przesun-przepis-2544', [
            'kto' => $ktoId, 'zeszyt' => (string) $zeszyt->getKey(), 'przepis' => (string) $przepisy[3]->getKey(), 'kierunek' => 'poczatek', 'odcisk' => $odcisk,
        ]);
        $this->czekajNaZablokowane(1);
        $drugie = $this->wTle('przesun-przepis-2544', [
            'kto' => $ktoId, 'zeszyt' => (string) $zeszyt->getKey(), 'przepis' => (string) $przepisy[2]->getKey(), 'kierunek' => 'poczatek', 'odcisk' => $odcisk,
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$pierwsze->wynik(), $drugie->wynik()];
        $this->assertBezZakleszczenia($wyniki[0], 'pierwsze przesunięcie');
        $this->assertBezZakleszczenia($wyniki[1], 'drugie przesunięcie');

        $udane = array_values(array_filter($wyniki, static fn (array $w): bool => $w['ok'] === true));
        $this->assertCount(1, $udane, 'Dokładnie jedno z dwóch przesunięć ze starym odciskiem może się udać.');
        $odmowa = array_values(array_filter($wyniki, static fn (array $w): bool => $w['ok'] === false))[0];
        $this->assertSame(KonfliktKolejnosci::class, $odmowa['wyjatek']);

        // Układ to dokładnie jedno przesunięcie: zwycięzca na początku, reszta bez zmian.
        $ids = KolejnoscPrzepisow::uklad($zeszyt);
        $zwyciezca = $wyniki[0]['ok'] === true ? $przepisy[3] : $przepisy[2];
        $this->assertSame((string) $zwyciezca->getKey(), $ids[0]);
        $this->assertCount(4, array_unique($ids));
        $pozycje = DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->orderBy('position')->pluck('position')->map(fn ($p): int => (int) $p)->all();
        $this->assertSame([1, 2, 3, 4], $pozycje);
    }

    public function test_przesuniecie_i_dopisanie_naraz_nie_psuja_pozycji(): void
    {
        [$wlasciciel, $zeszyt, $przepisy] = $this->zeszytZPrzepisami(3, true);
        $nowy = Recipe::factory()->for($this->konto(), 'author')->create(['title' => 'Dopisany']);
        $odcisk = KolejnoscPrzepisow::odcisk(KolejnoscPrzepisow::uklad($zeszyt));
        $ktoId = (string) $wlasciciel->getKey();

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [$ktoId]);
        $przesuniecie = $this->wTle('przesun-przepis-2544', [
            'kto' => $ktoId, 'zeszyt' => (string) $zeszyt->getKey(), 'przepis' => (string) $przepisy[2]->getKey(), 'kierunek' => 'poczatek', 'odcisk' => $odcisk,
        ]);
        $this->czekajNaZablokowane(1);
        $dopisanie = $this->wTle('zapisz-przepis-do-zeszytu-2544', [
            'kto' => $ktoId, 'zeszyt' => (string) $zeszyt->getKey(), 'przepis' => (string) $nowy->getKey(),
        ]);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$przesuniecie->wynik(), $dopisanie->wynik()];
        $this->assertBezZakleszczenia($wyniki[0], 'przesunięcie');
        $this->assertBezZakleszczenia($wyniki[1], 'dopisanie');

        // Dopisanie zawsze się udaje; przesunięcie — albo się udało, albo uczciwie
        // zgłosiło konflikt (dopisanie zmieniło układ pierwsze). Nigdy błąd bazy.
        $this->assertTrue($wyniki[1]['ok'], 'Dopisanie przepisu nie powinno przegrać z przesunięciem.');
        if ($wyniki[0]['ok'] !== true) {
            $this->assertSame(KonfliktKolejnosci::class, $wyniki[0]['wyjatek']);
        }

        $pozycje = DB::table('collection_items')->where('collection_id', $zeszyt->getKey())->orderBy('position')->pluck('position')->map(fn ($p): int => (int) $p)->all();
        $this->assertSame([1, 2, 3, 4], $pozycje, 'Pozycje muszą być różne i ciągłe.');

        $ids = KolejnoscPrzepisow::uklad($zeszyt);
        $this->assertContains((string) $nowy->getKey(), $ids);
        if ($wyniki[0]['ok'] !== true) {
            // Przesunięcie przegrało — dopisany przepis stoi na końcu.
            $this->assertSame((string) $nowy->getKey(), $ids[3]);
        }
    }
}

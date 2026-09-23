<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Przegląd PR #1213 (D-070): pierwszy zapis przepisu zamykał się
 * `SELECT ... FOR UPDATE` na otwartej partii — a gdy partii jeszcze nie ma,
 * `FOR UPDATE` nie blokuje niczego. Dwa równoległe pierwsze zapisy
 * zakładały dwa wiersze.
 *
 * BARIERA NIE ZALEŻY OD POPRAWKI. Trzymamy `FOR UPDATE` na wierszu przepisu;
 * wstawienie do `collection_items` sprawdza klucz obcy `FOR KEY SHARE` na tym
 * samym wierszu, więc oba procesy stają PRZED liczeniem zeszytów i szukaniem
 * partii — i ruszają naraz po zwolnieniu. Bez blokady doradczej partii oba
 * widzą „brak partii” (zmierzone na kodzie sprzed poprawki: dwa wiersze).
 */
#[Group('dwa-polaczenia')]
final class ZbiorczyZapisNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_dwie_rozne_osoby_naraz_daja_jedna_partie_z_dwiema_osobami(): void
    {
        $autor = $this->konto();
        $pierwsza = $this->konto();
        $druga = $this->konto();
        $przepis = Recipe::factory()->for($autor, 'author')->create();

        $this->dwaNaraz(
            $przepis,
            ['kto' => (string) $pierwsza->getKey(), 'przepis' => (string) $przepis->getKey()],
            ['kto' => (string) $druga->getKey(), 'przepis' => (string) $przepis->getKey()],
        );

        // Kontrola dodatnia: oba zapisy naprawdę doszły do zeszytów.
        $this->assertSame(2, DB::table('collection_items')->where('recipe_id', $przepis->getKey())->count());

        $partia = $this->jedynaPartia($autor);
        $savers = $partia->data['savers'];
        sort($savers);
        $oczekiwani = [(string) $pierwsza->getKey(), (string) $druga->getKey()];
        sort($oczekiwani);
        $this->assertSame($oczekiwani, $savers);
        $this->assertSame(1, $partia->data['others_count']);
    }

    public function test_jedna_osoba_do_dwoch_swoich_zeszytow_naraz_daje_jeden_zapis(): void
    {
        $autor = $this->konto();
        $osoba = $this->konto();
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        $zeszytA = $osoba->collections()->create(['name' => 'Na obiad', 'visibility' => 'private']);
        $zeszytB = $osoba->collections()->create(['name' => 'Na święta', 'visibility' => 'private']);

        $this->dwaNaraz(
            $przepis,
            ['kto' => (string) $osoba->getKey(), 'przepis' => (string) $przepis->getKey(), 'zeszyt' => (string) $zeszytA->getKey()],
            ['kto' => (string) $osoba->getKey(), 'przepis' => (string) $przepis->getKey(), 'zeszyt' => (string) $zeszytB->getKey()],
        );

        $this->assertSame(2, DB::table('collection_items')->where('recipe_id', $przepis->getKey())->count());
        $this->assertSame([(string) $osoba->getKey()], $this->jedynaPartia($autor)->data['savers']);
    }

    /**
     * @param  array<string, string>  $pierwszyZapis
     * @param  array<string, string>  $drugiZapis
     */
    private function dwaNaraz(Recipe $przepis, array $pierwszyZapis, array $drugiZapis): void
    {
        $bariera = $this->bariera('SELECT 1 FROM recipes WHERE id = ? FOR UPDATE', [(string) $przepis->getKey()]);
        $pierwszy = $this->wTle('zapisz-przepis', $pierwszyZapis);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('zapisz-przepis', $drugiZapis);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$pierwszy->wynik(), $drugi->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'równoległy zapis '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Równoległy zapis '.($numer + 1).' padł: '.$wynik['komunikat']);
        }
    }

    private function jedynaPartia(User $autor): Notification
    {
        $partie = Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_SAVED)
            ->get();

        $this->assertCount(1, $partie, 'Dwa równoległe pierwsze zapisy założyły więcej niż jedną partię.');

        return $partie->first();
    }
}

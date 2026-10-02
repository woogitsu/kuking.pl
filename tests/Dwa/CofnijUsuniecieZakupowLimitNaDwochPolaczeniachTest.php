<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Zakupy\ListaZakupow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2630: „Cofnij usunięcie” i równoległe dopisanie pozycji respektują limit
 * listy (`kuking.zakupy.pozycji_max`, 300). Lista ma 298 pozycji, a migawka
 * czeka z 2 — cofnięcie zmieściłoby się (300), dopisanie też (299), ale oba
 * naraz już nie. Bez blokady wiersza konta oba `count()` widziałyby 298.
 *
 * Bariera trzyma ten sam wiersz `users` tym samym `FOR UPDATE`, na którym
 * stają obie akcje (`ListaZakupow::zablokujListe()`), więc oba uczestniki
 * wyścigu czekają PRZED policzeniem pozycji.
 */
#[Group('dwa-polaczenia')]
final class CofnijUsuniecieZakupowLimitNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_cofniecie_i_dopisanie_naraz_nie_przekraczaja_limitu_i_nie_gubia_migawki(): void
    {
        $user = $this->konto();
        $ktoId = (string) $user->getKey();
        $limit = ListaZakupow::maksPozycji();

        $teraz = now();
        $wiersze = [];
        for ($i = 0; $i < $limit - 2; $i++) {
            $wiersze[] = ['id' => (string) Str::uuid(), 'user_id' => $ktoId, 'text' => 'pozycja '.$i,
                'source' => 'manual', 'position' => $i, 'created_at' => $teraz, 'updated_at' => $teraz];
        }
        DB::table('shopping_list_items')->insert($wiersze);

        $migawka = [];
        foreach ([0, 1] as $i) {
            $migawka[] = ['id' => (string) Str::uuid(), 'text' => 'wracajaca '.$i, 'source' => 'manual',
                'recipe_id' => null, 'position' => $limit + $i, 'checked_at' => null, 'created_at' => $teraz->toIso8601String()];
        }
        DB::table('shopping_list_undos')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $ktoId, 'scope' => 'checked',
            'items' => json_encode($migawka), 'items_count' => 2,
            'expires_at' => $teraz->copy()->addMinutes(15), 'created_at' => $teraz,
        ]);

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [$ktoId]);
        $cofniecie = $this->wTle('zakupy-cofnij', ['kto' => $ktoId]);
        $this->czekajNaZablokowane(1);
        $dopisanie = $this->wTle('zakupy-dopisz', ['kto' => $ktoId, 'tekst' => 'dopisana w tym samym czasie']);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wyniki = [$cofniecie->wynik(), $dopisanie->wynik()];
        $this->assertBezZakleszczenia($wyniki[0], 'cofnięcie usunięcia');
        $this->assertBezZakleszczenia($wyniki[1], 'dopisanie pozycji');

        $udane = array_filter($wyniki, static fn (array $w): bool => $w['ok'] === true);
        $this->assertCount(1, $udane, 'Dokładnie jedna z dwóch równoległych operacji mieści się w limicie.');
        $odmowa = array_values(array_filter($wyniki, static fn (array $w): bool => $w['ok'] === false))[0];
        $this->assertSame(ValidationException::class, $odmowa['wyjatek']);

        $ile = DB::table('shopping_list_items')->where('user_id', $ktoId)->count();
        $this->assertLessThanOrEqual($limit, $ile, 'Limit listy zakupów został przekroczony.');

        if ($wyniki[0]['ok'] === true) {
            $this->assertSame($limit, $ile);
            $this->assertSame(0, DB::table('shopping_list_undos')->where('user_id', $ktoId)->count());
        } else {
            // Cofnięcie przegrało: nic nie zostało przywrócone częściowo,
            // a migawka czeka dalej.
            $this->assertSame($limit - 1, $ile);
            $this->assertSame(1, DB::table('shopping_list_undos')->where('user_id', $ktoId)->count());
        }
    }
}

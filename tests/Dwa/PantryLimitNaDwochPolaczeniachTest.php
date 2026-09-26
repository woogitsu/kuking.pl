<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Pantry\CoMamWDomu;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1958: limit `CoMamWDomu::MAKS_PRODUKTOW` (150) dało się przekroczyć
 * dwoma równoległymi żądaniami — sprawdzenie `count() >= limit` i późniejszy
 * `create()` nie były atomowe. Konto z 149 produktami i dwa równoległe
 * dodania różnych nazw kończyły się 151 wierszami.
 *
 * BARIERA. `CoMamWDomu::dodaj()` bierze teraz `lockForUpdate()` na wierszu
 * `users` PRZED sprawdzeniem limitu. Bariera trzyma DOKŁADNIE ten sam
 * wiersz tym samym `FOR UPDATE` — oba uczestniki wyścigu stają w kolejce
 * PRZED policzeniem `pantry_items`, a nie po nim. To odtwarza dokładnie ten
 * przeplot, który był usterką: bez blokady oba `count()` widziałyby 149
 * niezależnie od kolejki tutaj.
 */
#[Group('dwa-polaczenia')]
final class PantryLimitNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    // Sprzątanie: `pantry_items.user_id` ma `ON DELETE CASCADE`, więc
    // usunięcie kont w `posprzatajKonta()` klasy bazowej zabiera też listy —
    // ten test nie potrzebuje własnego `tearDown()`.

    public function test_dwa_rozne_produkty_naraz_przy_149_koncza_sie_maksymalnie_150_wierszami(): void
    {
        $user = $this->konto();
        $this->wypelnijListe($user, CoMamWDomu::MAKS_PRODUKTOW - 1);

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $user->getKey()]);
        $pierwszy = $this->wTle('dodaj-do-pantry', ['kto' => (string) $user->getKey(), 'nazwa' => 'brukselka']);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('dodaj-do-pantry', ['kto' => (string) $user->getKey(), 'nazwa' => 'topinambur']);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikPierwszego = $pierwszy->wynik();
        $wynikDrugiego = $drugi->wynik();

        $this->assertBezZakleszczenia($wynikPierwszego, 'pierwsze dodanie do spiżarni');
        $this->assertBezZakleszczenia($wynikDrugiego, 'drugie dodanie do spiżarni');

        $udane = array_filter([$wynikPierwszego, $wynikDrugiego], static fn (array $w): bool => $w['ok'] === true);
        $odrzucone = array_filter([$wynikPierwszego, $wynikDrugiego], static fn (array $w): bool => $w['ok'] === false);

        $this->assertCount(
            1,
            $udane,
            'Dokładnie jedno z dwóch równoległych dodań przy koncie na 149 produktach ma się udać — '
            .'oba udane znaczy, że limit 150 znowu dało się przekroczyć.',
        );
        $this->assertCount(1, $odrzucone);

        $odmowa = array_values($odrzucone)[0];
        $this->assertSame(ValidationException::class, $odmowa['wyjatek']);
        $this->assertStringContainsString('150 produktów', $odmowa['komunikat']);

        $this->assertSame(
            CoMamWDomu::MAKS_PRODUKTOW,
            DB::table('pantry_items')->where('user_id', $user->getKey())->count(),
            'Po dwóch równoległych dodaniach na koncie z 149 produktami lista ma mieć dokładnie 150 wierszy, nie 151.',
        );
    }

    public function test_rownolegle_dodanie_tego_samego_produktu_konczy_sie_jednym_rekordem(): void
    {
        $user = $this->konto();
        $this->wypelnijListe($user, 5);

        $bariera = $this->bariera('SELECT 1 FROM users WHERE id = ? FOR UPDATE', [(string) $user->getKey()]);
        $pierwszy = $this->wTle('dodaj-do-pantry', ['kto' => (string) $user->getKey(), 'nazwa' => 'Pomidory']);
        $this->czekajNaZablokowane(1);
        $drugi = $this->wTle('dodaj-do-pantry', ['kto' => (string) $user->getKey(), 'nazwa' => 'pomidor']);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        foreach ([$pierwszy->wynik(), $drugi->wynik()] as $numer => $wynik) {
            $this->assertBezZakleszczenia($wynik, 'równoległe dodanie tego samego produktu '.($numer + 1));
            $this->assertTrue($wynik['ok'], 'Dodanie '.($numer + 1).' padło: '.$wynik['komunikat']);
        }

        $this->assertSame(
            $pierwszy->wynik()['wartosc'],
            $drugi->wynik()['wartosc'],
            'Dwie pisownie tego samego produktu, dodane naraz, mają wskazywać na JEDEN wiersz.',
        );
        $this->assertSame(6, DB::table('pantry_items')->where('user_id', $user->getKey())->count());
    }

    private function wypelnijListe(User $user, int $ile): void
    {
        // Rdzeń porównania obcina liczbę mnogą i samogłoskę na końcu słowa
        // (`kuking_rdzenie_skladnika`), więc np. „produkt testowy 0” i
        // „produkt testowy 1” dają TEN SAM klucz — numer musi być
        // przyklejony do słowa, żeby każdy wiersz miał inny rdzeń.
        for ($i = 0; $i < $ile; $i++) {
            $user->pantryItems()->create(['name' => 'produktxyz'.$i]);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KANDYDAT (audyt zewnętrzny, grupa 1): dwa niemal jednoczesne żądania
 * wołające `Ingredient::findOrCreateByName()` dla tej samej nazwy.
 *
 * METODA POMIARU: nie czekamy na prawdziwą współbieżność — PODKŁADAMY stan
 * "drugie żądanie już przeszło" dokładnie w szczelinie między odczytem
 * a zapisem pierwszego żądania. `DB::listen()` łapie SELECT, który
 * `firstOrCreate()` wykonuje jako pierwszy krok, i w jego callbacku (a więc
 * ZANIM ten sam wywołujący zdąży wykonać swój INSERT) wstawiamy konkurencyjny
 * wiersz bezpośrednio, inną drogą niż testowany kod. To jest dokładnie ten
 * przeplot, jaki dałyby dwa prawdziwe równoległe żądania HTTP.
 *
 * WYNIK: Laravel 13 (`Builder::createOrFirst()`, wołane przez
 * `firstOrCreate()`) łapie `UniqueConstraintViolationException` z bazy
 * i sam odczytuje istniejący wiersz — nie trzeba tego pisać ręcznie.
 * `ingredients.normalized_name` ma UNIQUE (migracja `create_recipes_tables`),
 * więc baza rzeczywiście odrzuca drugi INSERT, a `firstOrCreate()` łapie
 * ten wyjątek i oddaje wiersz, który "wygrał" — bez wyjątku dla wywołującego.
 *
 * WNIOSEK: kandydat OBALONY. Brak wyścigu do naprawienia w tym miejscu.
 */
class IngredientRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwa_niemal_jednoczesne_zadania_nie_tworza_duplikatu_ani_bledu(): void
    {
        $konkurencyjnyWstawiony = false;

        $listener = function ($query) use (&$konkurencyjnyWstawiony): void {
            if ($konkurencyjnyWstawiony) {
                return;
            }

            $sql = strtolower($query->sql);

            if (! str_starts_with(trim($sql), 'select') || ! str_contains($sql, '"ingredients"') || ! str_contains($sql, 'normalized_name')) {
                return;
            }

            $konkurencyjnyWstawiony = true;

            // "Drugie żądanie" wygrywa wyścig: wstawia wiersz DOKŁADNIE
            // w momencie, gdy pierwsze żądanie już sprawdziło, że wiersza
            // jeszcze nie ma, ale jeszcze nie zdążyło go utworzyć.
            DB::table('ingredients')->insert([
                'id' => (string) Str::uuid(),
                'canonical_name' => 'Mąka pszenna',
                'normalized_name' => Ingredient::normalize('Mąka pszenna'),
                'created_at' => now(),
            ]);
        };

        DB::listen($listener);

        $wynik = Ingredient::findOrCreateByName('mąka   pszenna');

        $this->assertSame(1, Ingredient::query()->count(), 'Wyścig nie ma prawa utworzyć drugiego wiersza dla tej samej znormalizowanej nazwy.');
        $this->assertSame('Mąka pszenna', $wynik->canonical_name, 'Wywołujący ma dostać wiersz, który "wygrał" wyścig, nie wyjątek.');
    }
}

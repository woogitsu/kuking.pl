<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Jednostki miary są danymi referencyjnymi — potrzebne także na produkcji.
        $this->call(UnitSeeder::class);

        // Początkowa baza tagów (SPEC §1.4, D-021) — zastępuje Tematy
        // (issue #31, usunięte w całości razem z `TopicSeeder`).
        // Idempotentny (`updateOrCreate` po znormalizowanej nazwie), patrz
        // komentarz klasy.
        $this->call(TagSeeder::class);

        // Startowa lista tagów promowanych — „lista gospodarza" (D-021).
        // Bezpieczne na produkcji: seeder działa TYLKO na pustej liście, więc
        // nie ma jak nadpisać ani przywrócić tego, co gospodarz zdjął
        // w panelu (uzasadnienie w komentarzu tamtej klasy).
        $this->call(TagPromotionSeeder::class);

        // Treść zalążkowa, JAWNIE OZNACZONA (D-025, `docs/DECISIONS.md`) —
        // wołany TAKŻE na produkcji, bo to jest dosłownie treść decyzji: pusty
        // serwis był odrzucony, treść bez oznaczenia też. Idempotentny (patrz
        // komentarz klasy) — drugie i kolejne uruchomienia nic nie zmieniają.
        // Wołany PO `TagSeeder`, żeby tagi wpisów rozwiązywały się do
        // kanonicznych nazw ze słownika, gdy ten jest już w bazie.
        $this->call(TrescZalazkowaSeeder::class);

        // Dane demo wyłącznie poza produkcją.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}

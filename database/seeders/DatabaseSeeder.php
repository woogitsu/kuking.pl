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

        // Dane demo wyłącznie poza produkcją.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}

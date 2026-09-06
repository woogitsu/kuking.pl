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

        // Tematy też są danymi referencyjnymi (issue #31): bez nich pole
        // „Temat" przy publikacji jest puste, a strona tematu nie istnieje.
        // Seeder jest idempotentny (`updateOrCreate` po slugu) i NIE nadpisuje
        // `is_active`, więc temat wycofany ręcznie zostaje wycofany.
        $this->call(TopicSeeder::class);

        // Dane demo wyłącznie poza produkcją.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}

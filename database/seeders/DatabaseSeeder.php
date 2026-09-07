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
        //
        // TYMCZASOWO OBOK TAGÓW (D-021, etap 1/5). Tematy znikają dopiero
        // w etapie 4, gdy żaden kod już ich nie używa — do tego czasu obie
        // taksonomie muszą działać naraz, żeby każdy etap kończył się
        // pełnym zielonym przebiegiem testów.
        $this->call(TopicSeeder::class);

        // Początkowa baza tagów (SPEC §1.4, D-021) — zastępuje Tematy.
        // Idempotentny (`updateOrCreate` po znormalizowanej nazwie), patrz
        // komentarz klasy.
        $this->call(TagSeeder::class);

        // Dane demo wyłącznie poza produkcją.
        if (! app()->environment('production')) {
            $this->call(DemoSeeder::class);
        }
    }
}

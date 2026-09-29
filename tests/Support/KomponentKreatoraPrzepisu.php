<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Kształt anonimowego komponentu `recipe-wizard` (klasa siedzi w Blade,
 * `resources/views/components/recipe-wizard.blade.php`), tak jak widzą go testy.
 *
 * Nie jest tworzony ani dziedziczony — służy tylko analizie statycznej:
 * `Livewire::test()->instance()` zwraca `Livewire\Component`, a testy wołają
 * metody i pola, które istnieją dopiero w klasie z widoku. Zamiast wyciszać
 * błąd, opisujemy to, czego testy używają. Opis nie chroni widoku przed
 * zmianą sygnatury — chroni go sam test, który wywołuje metodę naprawdę.
 */
abstract class KomponentKreatoraPrzepisu
{
    public int $wersjaStanu = 0;

    abstract public function stepForKey(string $key): int;

    abstract public function hydrate(): void;
}

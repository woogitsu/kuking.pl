<?php

declare(strict_types=1);

namespace App\Moderacja;

use Carbon\CarbonImmutable;

/**
 * WSPÓLNY BUDŻET CZASU NA OCENĘ MODELEM JEDNEJ TREŚCI (issue #829).
 *
 * Każde żądanie do OpenAI ma własny limit (`limit_czasu`, najwyżej 8 s), ale
 * żądań jest kilka: tekst i do `zdjec_na_wpis` zdjęć, a przed każdym
 * zdjęciem jeszcze odczyt z dysku i przekodowanie. Suma tych limitów przy
 * sześciu zdjęciach to ponad 50 s — przy zadaniu, które worker ubija po
 * 30 s. Ubity proces nie zapisuje niczego: ani już uzyskanych wyników
 * modelu, ani (przed #829) sygnałów lokalnych.
 *
 * Ten obiekt liczy czas CAŁEJ oceny, nie pojedynczego żądania: kolejne
 * żądanie dostaje najwyżej tyle, ile zostało, a gdy zostało mniej niż
 * `MIN_SEKUND`, reszta ocen jest pomijana i LICZONA — żeby moderator
 * dostał informację „ocena niepełna", a nie ciszę wyglądającą jak
 * „sprawdzone, czyste".
 *
 * Czas z `now()`, nie z `hrtime()`: testy przesuwają zegar Carbonem i tak
 * mierzą zachowanie przy wolnym dostawcy bez prawdziwego czekania.
 */
final class BudzetCzasu
{
    /** Krócej nie ma sensu pytać — samo nawiązanie połączenia może tyle trwać. */
    public const MIN_SEKUND = 2;

    private int $pominiete = 0;

    private function __construct(private readonly CarbonImmutable $koniec) {}

    public static function naSekund(int $sekund): self
    {
        return new self(CarbonImmutable::now()->addSeconds(max(0, $sekund)));
    }

    /** Pełne sekundy, które zostały do końca budżetu (nigdy ujemne). */
    public function zostalo(): int
    {
        return max(0, (int) floor(CarbonImmutable::now()->diffInMilliseconds($this->koniec, false) / 1000));
    }

    public function wyczerpany(): bool
    {
        return $this->zostalo() < self::MIN_SEKUND;
    }

    public function pomin(): void
    {
        $this->pominiete++;
    }

    /** Ile ocen (tekst lub zdjęcie) nie odbyło się, bo zabrakło czasu. */
    public function pominiete(): int
    {
        return $this->pominiete;
    }
}

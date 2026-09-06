<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OdmianaWalidacji;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #86 — komunikaty walidacji `min`/`max` muszą się odmieniać, nie tylko
 * podstawiać liczbę. „Musisz wpisać co najmniej 2 znaków" jest złą polszczyzną
 * (powinno być „2 znaki") równie mocno, jak angielski oryginał, którego to
 * łatało — a próg 2-4 akurat pada dokładnie na najkrótsze, najczęstsze pola
 * formularzy w Kuking (np. `username` ma `min:3`).
 *
 * BEZ TEJ KLASY komunikaty z `lang/pl/validation.php` zostawiałyby dosłowny
 * wzorzec `:odmiana(...)` w tekście (Laravel nie umie go sam podstawić —
 * `Lang::get()` nie robi pluralizacji, robi ją tylko `trans_choice()`,
 * którego walidator nie woła) — więc test bez naprawy pada na literalnym
 * `:odmiana(...)` w wyniku, nie na złej gramatyce.
 */
final class OdmianaWalidacjiTest extends TestCase
{
    /** @return list<array{0: int, 1: string}> */
    public static function liczbyZnakow(): array
    {
        return [
            // Dokładnie próg, na którym RegisterController łapie za krótką
            // nazwę użytkownika (`username` => min:3).
            [3, 'Pole jest za krótkie — potrzeba co najmniej 3 znaki.'],
            [2, 'Pole jest za krótkie — potrzeba co najmniej 2 znaki.'],
            [1, 'Pole jest za krótkie — potrzeba co najmniej 1 znak.'],
            [10, 'Pole jest za krótkie — potrzeba co najmniej 10 znaków.'],
            // Nastki: kończą się na 2-4, ale biorą formę „wiele", nie „kilka".
            [12, 'Pole jest za krótkie — potrzeba co najmniej 12 znaków.'],
        ];
    }

    #[DataProvider('liczbyZnakow')]
    public function test_podstawia_min_i_poprawna_odmiane(int $ile, string $oczekiwany): void
    {
        $wzorzec = 'Pole jest za krótkie — potrzeba co najmniej :min :odmiana(znak|znaki|znaków).';

        $this->assertSame($oczekiwany, OdmianaWalidacji::podstaw($wzorzec, $ile));
    }

    public function test_podstawia_max_z_inna_odmiana(): void
    {
        $wzorzec = 'Pole może zawierać najwyżej :max :odmiana(pozycję|pozycje|pozycji).';

        $this->assertSame(
            'Pole może zawierać najwyżej 6 pozycji.',
            OdmianaWalidacji::podstaw($wzorzec, 6),
        );
        $this->assertSame(
            'Pole może zawierać najwyżej 2 pozycje.',
            OdmianaWalidacji::podstaw($wzorzec, 2),
        );
    }

    /**
     * Komunikat bez wzorca (np. ręcznie napisany, ze statyczną liczbą wpisaną
     * już w treści) ma wyjść BEZ ZMIAN — replacer jest zarejestrowany
     * globalnie dla reguł `min`/`max`, więc dostaje też komunikaty inline,
     * które nigdy nie miały placeholderu.
     */
    public function test_komunikat_bez_wzorca_wychodzi_bez_zmian(): void
    {
        $statyczny = 'Hasło musi mieć co najmniej 10 znaków.';

        $this->assertSame($statyczny, OdmianaWalidacji::podstaw($statyczny, 10));
    }
}

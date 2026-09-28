<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\WynikWysylkiPush;
use App\Models\PushSubscription;

/**
 * Transport Web Push, który niczego nie wysyła — zapisuje, co MIAŁ wysłać.
 *
 * Testy nigdy nie łączą się z usługą push. Odpowiedź dla danego adresu
 * subskrypcji można ustawić (`odpowiadaj()`), żeby sprawdzić sprzątanie
 * wygasłych subskrypcji (404/410) i zachowanie przy błędzie.
 */
final class FalszywyTransportPush implements TransportPush
{
    /** @var list<array{endpoint: string, tresc: array<string, mixed>}> */
    public array $wyslane = [];

    /** @var array<string, WynikWysylkiPush> */
    private array $odpowiedzi = [];

    public function odpowiadaj(string $endpoint, WynikWysylkiPush $wynik): self
    {
        $this->odpowiedzi[$endpoint] = $wynik;

        return $this;
    }

    public function wyslij(PushSubscription $subskrypcja, string $tresc): WynikWysylkiPush
    {
        $this->wyslane[] = [
            'endpoint' => $subskrypcja->endpoint,
            'tresc' => (array) json_decode($tresc, true),
        ];

        return $this->odpowiedzi[$subskrypcja->endpoint] ?? WynikWysylkiPush::Wyslano;
    }
}
